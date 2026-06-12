<?php
// app/Http/Controllers/PnlController.php

namespace App\Http\Controllers;

use App\Models\PnlRecord;
use App\Models\PnlItem;
use App\Services\PnlEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\PnLExcelService;


class PnlController extends Controller
{
    public function index(Request $request)
    {
        $query = PnlRecord::query();
        
        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('vendor_name', 'like', "%{$search}%")
                  ->orWhere('invoice_number', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%")
                  ->orWhere('from_email', 'like', "%{$search}%")
                  ->orWhere('is_number', 'like', "%{$search}%");
            });
        }
        
        // Filter by category
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        
        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Filter by read status
        if ($request->filled('read_filter')) {
            $query->where('read_status', $request->read_filter);
        }
        
        // Filter by country
        if ($request->filled('country')) {
            $query->where('country_code', $request->country);
        }
        
        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('received_at', '<=', $request->date_to);
        }
        
        $pnlRecords = $query->orderBy('received_at', 'desc')->paginate(20);
        
        // Stats
        $stats = [
            'total' => PnlRecord::count(),
            'total_amount' => PnlRecord::sum('amount'),
            'pending' => PnlRecord::where('status', 'pending')->count(),
            'approved' => PnlRecord::where('status', 'approved')->count(),
        ];
        
        $categories = ['Hotel', 'Transport', 'Ticket', 'Activities', 'Meals', 'Other', 'Multi'];
        $countries = ['SG' => 'Singapore (SGD)', 'MY' => 'Malaysia (MYR)', 'VN' => 'Vietnam (VND)', 'LK' => 'Sri Lanka (LKR)'];
        
        return view('pnl.index', compact('pnlRecords', 'stats', 'categories', 'countries'));
    }
    
    public function fetchEmails()
    {
        try {
            $service = new PnlEmailService();
            $count = $service->fetchPnLEmails();
            
            if ($count > 0) {
                return redirect()->back()->with('success', "✅ Fetched {$count} new PnL records!");
            } else {
                return redirect()->back()->with('info', '📭 No new PnL emails found.');
            }
        } catch (\Exception $e) {
            Log::error('Fetch PnL emails failed: ' . $e->getMessage());
            return redirect()->back()->with('error', '❌ Failed to fetch PnL emails: ' . $e->getMessage());
        }
    }
    
    public function updateStatus(Request $request, $id)
    {
        $record = PnlRecord::findOrFail($id);
        $record->update(['status' => $request->status]);
        
        return redirect()->back()->with('success', 'PnL record updated successfully!');
    }
    
    public function markAsRead($id)
    {
        try {
            $record = PnlRecord::findOrFail($id);
            $record->update(['read_status' => 'read']);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    public function viewEmail($id)
    {
        try {
            // Only get the record, don't load items for email view
            $record = PnlRecord::findOrFail($id);
            
            Log::info('Viewing PnL email', ['id' => $id, 'has_html' => !empty($record->body_html)]);
            
            return response()->json([
                'success' => true,
                'email' => [
                    'subject' => $record->subject,
                    'from_name' => $record->from_name,
                    'from_email' => $record->from_email,
                    'body_html' => $record->body_html,
                    'body' => $record->body,
                    'received_at' => $record->received_at ? $record->received_at->format('d/m/Y H:i:s') : null,
                    'vendor_name' => $record->vendor_name,
                    'invoice_number' => $record->invoice_number,
                    'is_number' => $record->is_number,
                    'amount' => $record->amount,
                    'category' => $record->category,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('View email failed: ' . $e->getMessage(), ['id' => $id]);
            return response()->json([
                'success' => false,
                'message' => 'Email not found: ' . $e->getMessage()
            ], 404);
        }
    }
    
    public function viewItems($id)
    {
        try {
            $items = PnlItem::where('pnl_record_id', $id)->get();
            
            return response()->json([
                'success' => true,
                'items' => $items
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    public function exportToExcel(Request $request)
    {
        $query = PnlRecord::with('items');
        
        if ($request->filled('date_from')) {
            $query->whereDate('received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('received_at', '<=', $request->date_to);
        }
        
        $records = $query->get();
        
        $filename = "pnl_export_" . date('Y-m-d_His') . ".csv";
        $handle = fopen('php://temp', 'w+');
        
        // Headers
        fputcsv($handle, ['S.No', 'Date', 'Vendor', 'Invoice #', 'Tour ref', 'Country', 'Amount', 'Currency', 'Converted Amount', 'Status', 'Items Count']);
        
        foreach ($records as $record) {
            fputcsv($handle, [
                $record->sno,
                $record->received_at ? $record->received_at->format('d/m/Y') : '',
                $record->vendor_name,
                $record->invoice_number,
                $record->tour_ref,
                $record->country_code,
                $record->amount,
                $record->currency,
                $record->amount * ($record->exchange_rate_used ?? 1),
                $record->status,
                $record->items->count(),
            ]);
        }
        
        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);
        
        return response($csvContent, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"$filename\"");
    }

public function updateExcel(Request $request)
{
    try {
        $id = $request->input('id');
        $record = PnlRecord::findOrFail($id);
        
        $excelService = new PnLExcelService();
        $result = $excelService->processAndUpdateExcel($record);
        
        if ($result['success']) {
            // Update record status
            $record->update([
                'status' => 'approved',
                'processing_status' => 'completed'
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "✅ PnL Updated! {$result['items_count']} items added to Excel.\n\n📊 " . 
                            "Attractions: " . collect($result['items'])->where('type', 'Attraction')->count() . "\n" .
                            "Transfers: " . collect($result['items'])->where('type', 'Transfer')->count() . "\n" .
                            "Hotels: " . collect($result['items'])->where('type', 'Hotel')->count(),
                'items_count' => $result['items_count']
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to update PnL'
            ], 500);
        }
        
    } catch (\Exception $e) {
        Log::error('Update Excel failed: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * View Excel file in browser
 */
public function viewExcel($country)
{
    try {
        $excelService = new PnLExcelService();
        $html = $excelService->getExcelPreview($country);
        
        $countryNames = [
            'SG' => 'Singapore',
            'MY' => 'Malaysia',
            'VN' => 'Vietnam',
            'LK' => 'Sri Lanka'
        ];
        
        $countryName = $countryNames[$country] ?? $country;
        
        
        return view('pnl.excel-preview', compact('html', 'countryName', 'country'));
        
    } catch (\Exception $e) {
        Log::error('View Excel failed: ' . $e->getMessage());
        return redirect()->back()->with('error', 'Failed to load Excel: ' . $e->getMessage());
    }
}



}