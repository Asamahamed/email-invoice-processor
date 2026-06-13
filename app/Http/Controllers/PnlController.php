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
    try {
        $query = PnlRecord::with('items');
        
        // Apply filters
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('vendor_name', 'like', "%{$search}%")
                  ->orWhere('invoice_number', 'like', "%{$search}%")
                  ->orWhere('tour_ref', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%")
                  ->orWhere('from_email', 'like', "%{$search}%");
            });
        }
        
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        
        if ($request->filled('country')) {
            $query->where('country_code', $request->country);
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->filled('date_from')) {
            $query->whereDate('received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('received_at', '<=', $request->date_to);
        }
        
        $records = $query->orderBy('received_at', 'desc')->get();
        
        if ($records->isEmpty()) {
            return redirect()->back()->with('error', 'No records found to export.');
        }
        
        // Create filename
        $filename = "pnl_export_" . date('Y-m-d_His') . ".xlsx";
        
        // Create new Spreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PnL Details');
        
        // Set headers - Same as your Excel update structure
        $headers = [
            'A1' => 'S.No',
            'B1' => 'Tour Number',
            'C1' => 'Invoice Number',
            'D1' => 'Type',
            'E1' => 'Start Date',
            'F1' => 'End Date',
            'G1' => 'Credit Type',
            'H1' => 'Agent Name',
            'I1' => 'Hotel Name',
            'J1' => 'Amount (USD)',
            'K1' => 'Exchange Rate',
            'L1' => 'Amount (Local)',
            'M1' => 'Remarks'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // Style header row
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
        ];
        $sheet->getStyle('A1:M1')->applyFromArray($headerStyle);
        
        // Color definitions for different types
        $colors = [
            'INVOICE' => 'D5E8D4',      // Light Green
            'HOTEL' => 'FFF2CC',        // Light Yellow
            'TRANSPORT' => 'DDEBF7',    // Light Blue
            'TOUR TRANSFER' => 'E2EFDA', // Light Green
            'ATTRACTION' => 'FCE4D6',   // Light Orange
            'MEALS' => 'E1C699',        // Tan
            'OTHER RATES' => 'D9D9D9'   // Light Gray
        ];
        
        // Add data rows - EXPORT BY ITEMS (like update logic)
        $row = 2;
        $globalSno = 1;
        
        foreach ($records as $record) {
            $exchangeRate = $record->exchange_rate_used ?? 1;
            $countryCode = $record->country_code ?? 'VN';
            $tourRef = $record->tour_ref;
            $invoiceNumber = $record->invoice_number;
            $agentName = $record->agent_name;
            $startDate = $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
            $endDate = $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
            
            // Get items for this record
            $items = $record->items;
            
            if ($items->isEmpty()) {
                // If no items, still export main record as INVOICE
                $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                
                $sheet->setCellValue("A{$row}", $globalSno++);
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", 'INVOICE');
                $sheet->setCellValue("E{$row}", $startDate);
                $sheet->setCellValue("F{$row}", $endDate);
                $sheet->setCellValue("G{$row}", 'Credit');
                $sheet->setCellValue("H{$row}", $agentName ?? '-');
                $sheet->setCellValue("I{$row}", '-');
                $sheet->setCellValue("J{$row}", $record->amount);
                $sheet->setCellValue("K{$row}", $exchangeRate);
                $sheet->setCellValue("L{$row}", round($record->amount * $exchangeRate, 2));
                $sheet->setCellValue("M{$row}", $remarks);
                
                // Apply color
                if (isset($colors['INVOICE'])) {
                    $sheet->getStyle("A{$row}:M{$row}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($colors['INVOICE']);
                }
                
                $row++;
            } else {
                // Export each item as a separate row (same as update logic)
                foreach ($items as $item) {
                    $remarks = '';
                    $itemDetails = json_decode($item->item_details, true);
                    $hotelName = $item->hotel_name ?? '-';
                    
                    // Determine remarks based on type
                    if ($item->type == 'INVOICE') {
                        $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                    } elseif ($item->type == 'HOTEL') {
                        $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
                        $hotelName = $item->service_name ?? $item->hotel_name ?? '-';
                    } elseif ($item->type == 'ATTRACTION') {
                        $remarks = $itemDetails['remarks'] ?? $item->service_name ?? 'Attraction fees';
                    } elseif ($item->type == 'TOUR TRANSFER') {
                        $remarks = 'Total tour transfer expenses';
                    } elseif ($item->type == 'TRANSPORT') {
                        $remarks = 'Total transport expenses';
                    } elseif ($item->type == 'MEALS') {
                        $remarks = 'Meals expenses';
                    } elseif ($item->type == 'OTHER RATES') {
                        $remarks = $itemDetails['remarks'] ?? 'Other fees';
                    }
                    
                    $sheet->setCellValue("A{$row}", $globalSno++);
                    $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                    $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                    $sheet->setCellValue("D{$row}", $item->type);
                    $sheet->setCellValue("E{$row}", $startDate);
                    $sheet->setCellValue("F{$row}", $endDate);
                    $sheet->setCellValue("G{$row}", $item->credit_type ?? 'Credit');
                    $sheet->setCellValue("H{$row}", $agentName ?? '-');
                    $sheet->setCellValue("I{$row}", $hotelName);
                    $sheet->setCellValue("J{$row}", $item->amount_original);
                    $sheet->setCellValue("K{$row}", $exchangeRate);
                    $sheet->setCellValue("L{$row}", round($item->amount_original * $exchangeRate, 2));
                    $sheet->setCellValue("M{$row}", $remarks);
                    
                    // Apply color based on type
                    if (isset($colors[$item->type])) {
                        $sheet->getStyle("A{$row}:M{$row}")->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB($colors[$item->type]);
                    }
                    
                    $row++;
                }
            }
            
            // Add a blank row as separator between different records
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Add borders to all data cells
        $highestRow = $row - 1;
        if ($highestRow >= 2) {
            $sheet->getStyle("A2:M{$highestRow}")->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC']
                    ]
                ]
            ]);
        }
        
        // Create Excel file
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        
        // Save to temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'pnl_');
        $writer->save($tempFile);
        
        // Return as download
        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
        
    } catch (\Exception $e) {
        Log::error('Export to Excel failed: ' . $e->getMessage());
        return redirect()->back()->with('error', 'Failed to export: ' . $e->getMessage());
    }
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

/**
 * Export by specific country (for excel-preview page)
 */
public function exportByCountry($country, Request $request)
{
    try {
        $query = PnlRecord::with('items')->where('country_code', $country);
        
        // Apply date filters if any
        if ($request->filled('date_from')) {
            $query->whereDate('received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('received_at', '<=', $request->date_to);
        }
        
        $records = $query->orderBy('received_at', 'desc')->get();
        
        if ($records->isEmpty()) {
            return redirect()->back()->with('error', "No records found for country: {$country}");
        }
        
        // Create filename with country
        $countryNames = [
            'SG' => 'Singapore',
            'MY' => 'Malaysia', 
            'VN' => 'Vietnam',
            'LK' => 'Sri Lanka'
        ];
        $countryName = $countryNames[$country] ?? $country;
        $filename = "pnl_export_{$countryName}_" . date('Y-m-d_His') . ".xlsx";
        
        // Create new Spreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PnL - ' . $countryName);
        
        // Set headers
        $headers = [
            'A1' => 'S.No',
            'B1' => 'Tour Number',
            'C1' => 'Invoice Number',
            'D1' => 'Type',
            'E1' => 'Start Date',
            'F1' => 'End Date',
            'G1' => 'Credit Type',
            'H1' => 'Agent Name',
            'I1' => 'Hotel Name',
            'J1' => 'Amount (USD)',
            'K1' => 'Exchange Rate',
            'L1' => 'Amount (Local)',
            'M1' => 'Remarks'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // Style header row
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
        ];
        $sheet->getStyle('A1:M1')->applyFromArray($headerStyle);
        
        // Color definitions
        $colors = [
            'INVOICE' => 'D5E8D4',
            'HOTEL' => 'FFF2CC',
            'TRANSPORT' => 'DDEBF7',
            'TOUR TRANSFER' => 'E2EFDA',
            'ATTRACTION' => 'FCE4D6',
            'MEALS' => 'E1C699',
            'OTHER RATES' => 'D9D9D9'
        ];
        
        // Add data rows
        $row = 2;
        $globalSno = 1;
        
        foreach ($records as $record) {
            $exchangeRate = $record->exchange_rate_used ?? 1;
            $tourRef = $record->tour_ref;
            $invoiceNumber = $record->invoice_number;
            $agentName = $record->agent_name;
            $startDate = $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
            $endDate = $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
            
            $items = $record->items;
            
            if ($items->isEmpty()) {
                $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                
                $sheet->setCellValue("A{$row}", $globalSno++);
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", 'INVOICE');
                $sheet->setCellValue("E{$row}", $startDate);
                $sheet->setCellValue("F{$row}", $endDate);
                $sheet->setCellValue("G{$row}", 'Credit');
                $sheet->setCellValue("H{$row}", $agentName ?? '-');
                $sheet->setCellValue("I{$row}", '-');
                $sheet->setCellValue("J{$row}", $record->amount);
                $sheet->setCellValue("K{$row}", $exchangeRate);
                $sheet->setCellValue("L{$row}", round($record->amount * $exchangeRate, 2));
                $sheet->setCellValue("M{$row}", $remarks);
                
                if (isset($colors['INVOICE'])) {
                    $sheet->getStyle("A{$row}:M{$row}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($colors['INVOICE']);
                }
                $row++;
            } else {
                foreach ($items as $item) {
                    $remarks = '';
                    $itemDetails = json_decode($item->item_details, true);
                    $hotelName = $item->hotel_name ?? '-';
                    
                    if ($item->type == 'INVOICE') {
                        $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                    } elseif ($item->type == 'HOTEL') {
                        $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
                        $hotelName = $item->service_name ?? $item->hotel_name ?? '-';
                    } elseif ($item->type == 'ATTRACTION') {
                        $remarks = $itemDetails['remarks'] ?? $item->service_name ?? 'Attraction fees';
                    } elseif ($item->type == 'TOUR TRANSFER') {
                        $remarks = 'Total tour transfer expenses';
                    } elseif ($item->type == 'TRANSPORT') {
                        $remarks = 'Total transport expenses';
                    } elseif ($item->type == 'MEALS') {
                        $remarks = 'Meals expenses';
                    } elseif ($item->type == 'OTHER RATES') {
                        $remarks = $itemDetails['remarks'] ?? 'Other fees';
                    }
                    
                    $sheet->setCellValue("A{$row}", $globalSno++);
                    $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                    $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                    $sheet->setCellValue("D{$row}", $item->type);
                    $sheet->setCellValue("E{$row}", $startDate);
                    $sheet->setCellValue("F{$row}", $endDate);
                    $sheet->setCellValue("G{$row}", $item->credit_type ?? 'Credit');
                    $sheet->setCellValue("H{$row}", $agentName ?? '-');
                    $sheet->setCellValue("I{$row}", $hotelName);
                    $sheet->setCellValue("J{$row}", $item->amount_original);
                    $sheet->setCellValue("K{$row}", $exchangeRate);
                    $sheet->setCellValue("L{$row}", round($item->amount_original * $exchangeRate, 2));
                    $sheet->setCellValue("M{$row}", $remarks);
                    
                    if (isset($colors[$item->type])) {
                        $sheet->getStyle("A{$row}:M{$row}")->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB($colors[$item->type]);
                    }
                    $row++;
                }
            }
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Add borders
        $highestRow = $row - 1;
        if ($highestRow >= 2) {
            $sheet->getStyle("A2:M{$highestRow}")->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC']
                    ]
                ]
            ]);
        }
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'pnl_');
        $writer->save($tempFile);
        
        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
        
    } catch (\Exception $e) {
        Log::error('Export by country failed: ' . $e->getMessage());
        return redirect()->back()->with('error', 'Failed to export: ' . $e->getMessage());
    }
}
/**
 * Export ONLY approved/updated records by specific country
 */
public function exportByCountryApproved($country, Request $request)
{
    try {
        // ONLY get records that are approved and completed
        $query = PnlRecord::with('items')
            ->where('country_code', $country)
            ->where('status', 'approved')
            ->where('processing_status', 'completed');
        
        // Apply date filters if any
        if ($request->filled('date_from')) {
            $query->whereDate('received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('received_at', '<=', $request->date_to);
        }
        
        $records = $query->orderBy('received_at', 'desc')->get();
        
        if ($records->isEmpty()) {
            return redirect()->back()->with('error', "No approved/updated records found for country: {$country}");
        }
        
        // Create filename with country and "approved" suffix
        $countryNames = [
            'SG' => 'Singapore',
            'MY' => 'Malaysia', 
            'VN' => 'Vietnam',
            'LK' => 'Sri Lanka'
        ];
        $countryName = $countryNames[$country] ?? $country;
        $filename = "pnl_export_{$countryName}_updated_only_" . date('Y-m-d_His') . ".xlsx";
        
        // Create new Spreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PnL - ' . $countryName . ' (Updated)');
        
        // Set headers
        $headers = [
            'A1' => 'S.No',
            'B1' => 'Tour Number',
            'C1' => 'Invoice Number',
            'D1' => 'Type',
            'E1' => 'Start Date',
            'F1' => 'End Date',
            'G1' => 'Credit Type',
            'H1' => 'Agent Name',
            'I1' => 'Hotel Name',
            'J1' => 'Amount (USD)',
            'K1' => 'Exchange Rate',
            'L1' => 'Amount (Local)',
            'M1' => 'Remarks'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // Style header row
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
        ];
        $sheet->getStyle('A1:M1')->applyFromArray($headerStyle);
        
        // Color definitions
        $colors = [
            'INVOICE' => 'D5E8D4',
            'HOTEL' => 'FFF2CC',
            'TRANSPORT' => 'DDEBF7',
            'TOUR TRANSFER' => 'E2EFDA',
            'ATTRACTION' => 'FCE4D6',
            'MEALS' => 'E1C699',
            'OTHER RATES' => 'D9D9D9'
        ];
        
        // Add data rows
        $row = 2;
        $globalSno = 1;
        
        foreach ($records as $record) {
            $exchangeRate = $record->exchange_rate_used ?? 1;
            $tourRef = $record->tour_ref;
            $invoiceNumber = $record->invoice_number;
            $agentName = $record->agent_name;
            $startDate = $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
            $endDate = $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
            
            $items = $record->items;
            
            if ($items->isEmpty()) {
                $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                
                $sheet->setCellValue("A{$row}", $globalSno++);
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", 'INVOICE');
                $sheet->setCellValue("E{$row}", $startDate);
                $sheet->setCellValue("F{$row}", $endDate);
                $sheet->setCellValue("G{$row}", 'Credit');
                $sheet->setCellValue("H{$row}", $agentName ?? '-');
                $sheet->setCellValue("I{$row}", '-');
                $sheet->setCellValue("J{$row}", $record->amount);
                $sheet->setCellValue("K{$row}", $exchangeRate);
                $sheet->setCellValue("L{$row}", round($record->amount * $exchangeRate, 2));
                $sheet->setCellValue("M{$row}", $remarks);
                
                if (isset($colors['INVOICE'])) {
                    $sheet->getStyle("A{$row}:M{$row}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($colors['INVOICE']);
                }
                $row++;
            } else {
                foreach ($items as $item) {
                    $remarks = '';
                    $itemDetails = json_decode($item->item_details, true);
                    $hotelName = $item->hotel_name ?? '-';
                    
                    if ($item->type == 'INVOICE') {
                        $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                    } elseif ($item->type == 'HOTEL') {
                        $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
                        $hotelName = $item->service_name ?? $item->hotel_name ?? '-';
                    } elseif ($item->type == 'ATTRACTION') {
                        $remarks = $itemDetails['remarks'] ?? $item->service_name ?? 'Attraction fees';
                    } elseif ($item->type == 'TOUR TRANSFER') {
                        $remarks = 'Total tour transfer expenses';
                    } elseif ($item->type == 'TRANSPORT') {
                        $remarks = 'Total transport expenses';
                    } elseif ($item->type == 'MEALS') {
                        $remarks = 'Meals expenses';
                    } elseif ($item->type == 'OTHER RATES') {
                        $remarks = $itemDetails['remarks'] ?? 'Other fees';
                    }
                    
                    $sheet->setCellValue("A{$row}", $globalSno++);
                    $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                    $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                    $sheet->setCellValue("D{$row}", $item->type);
                    $sheet->setCellValue("E{$row}", $startDate);
                    $sheet->setCellValue("F{$row}", $endDate);
                    $sheet->setCellValue("G{$row}", $item->credit_type ?? 'Credit');
                    $sheet->setCellValue("H{$row}", $agentName ?? '-');
                    $sheet->setCellValue("I{$row}", $hotelName);
                    $sheet->setCellValue("J{$row}", $item->amount_original);
                    $sheet->setCellValue("K{$row}", $exchangeRate);
                    $sheet->setCellValue("L{$row}", round($item->amount_original * $exchangeRate, 2));
                    $sheet->setCellValue("M{$row}", $remarks);
                    
                    if (isset($colors[$item->type])) {
                        $sheet->getStyle("A{$row}:M{$row}")->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB($colors[$item->type]);
                    }
                    $row++;
                }
            }
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Add borders
        $highestRow = $row - 1;
        if ($highestRow >= 2) {
            $sheet->getStyle("A2:M{$highestRow}")->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC']
                    ]
                ]
            ]);
        }
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'pnl_');
        $writer->save($tempFile);
        
        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
        
    } catch (\Exception $e) {
        Log::error('Export approved by country failed: ' . $e->getMessage());
        return redirect()->back()->with('error', 'Failed to export: ' . $e->getMessage());
    }
}
}