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

        $filename = "pnl_export_" . date('Y-m-d_His') . ".xlsx";
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PnL Details');

        // ✅ UPDATED HEADERS WITH CLIENT NAME
        $headers = [
            'A1' => 'S.No', 
            'B1' => 'Tour Number', 
            'C1' => 'Invoice Number',
            'D1' => 'Client Name',        // ✅ NEW
            'E1' => 'Type', 
            'F1' => 'Start Date', 
            'G1' => 'End Date',
            'H1' => 'Credit Type', 
            'I1' => 'Agent Name', 
            'J1' => 'Description',
            'K1' => 'Amount (USD)', 
            'L1' => 'Exchange Rate', 
            'M1' => 'Amount (Local)',
            'N1' => 'Remarks'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // ✅ UPDATED RANGE TO N
        $sheet->getStyle('A1:N1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
        ]);

        $colors = [
            'INVOICE' => 'D5E8D4', 'HOTEL' => 'FFF2CC', 'TRANSPORT' => 'DDEBF7',
            'TOUR TRANSFER' => 'E2EFDA', 'ATTRACTION' => 'FCE4D6', 'MEALS' => 'E1C699',
            'OTHER RATES' => 'D9D9D9'
        ];

        $row = 2;
        $globalSno = 1;

        foreach ($records as $record) {
            $exchangeRate = $record->exchange_rate_used ?? 1;
            $tourRef = $record->tour_ref;
            $invoiceNumber = $record->invoice_number;
            $agentName = $record->agent_name;
            
            $items = $record->items;
            
            if ($items->isEmpty()) {
                $startDate = $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
                $endDate = $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
                
                $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                
                // ✅ UPDATED WITH CLIENT NAME
                $sheet->setCellValue("A{$row}", $globalSno++);
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", $record->vendor_name ?? '');  // Client Name
                $sheet->setCellValue("E{$row}", 'INVOICE');
                $sheet->setCellValue("F{$row}", $startDate);
                $sheet->setCellValue("G{$row}", $endDate);
                $sheet->setCellValue("H{$row}", 'Credit');
                $sheet->setCellValue("I{$row}", $agentName ?? '-');
                $sheet->setCellValue("J{$row}", 'INVOICE');
                $sheet->setCellValue("K{$row}", $this->formatAmountForExport($record->amount));
                $sheet->setCellValue("L{$row}", $exchangeRate);
                $sheet->setCellValue("M{$row}", $this->formatAmountForExport($record->amount * $exchangeRate));
                $sheet->setCellValue("N{$row}", $remarks);
                
                if (isset($colors['INVOICE'])) {
                    $sheet->getStyle("A{$row}:N{$row}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($colors['INVOICE']);
                }
                $row++;
            } else {
               foreach ($items as $item) {
    $remarks = '';
    $itemDetails = json_decode($item->item_details, true);
    
    // ✅ Use service_name as description by default
    $description = $item->service_name ?? $item->type;
    
    $amount = $item->amount_original;
    
    $startDate = $item->start_date ?? $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
    $endDate = $item->end_date ?? $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));

    if ($item->type == 'INVOICE') {
        $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
        $description = 'INVOICE';
    } else {
        $amount = -abs($amount);
        // For HOTEL, fallback to hotel_name if service_name is empty
        if ($item->type == 'HOTEL' && empty($description)) {
            $description = $item->hotel_name ?? $item->type;
        }
        // Build remarks
        if ($item->type == 'HOTEL') {
            $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
        } elseif ($item->type == 'ATTRACTION') {
            $remarks = $itemDetails['remarks'] ?? $item->service_name ?? 'Attraction fees';
        } elseif ($item->type == 'TOUR TRANSFER') {
            $remarks = $itemDetails['remarks'] ?? 'Total tour transfer expenses';
        } elseif ($item->type == 'TRANSPORT') {
            $remarks = $itemDetails['remarks'] ?? 'Total transport expenses';
        } elseif ($item->type == 'MEALS') {
            $remarks = 'Meals expenses';
        } elseif ($item->type == 'OTHER RATES') {
            $remarks = $itemDetails['remarks'] ?? 'Other fees';
        }
    }


                    
                    $amount = $item->amount_original;
                    
                    $startDate = $item->start_date ?? $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
                    $endDate = $item->end_date ?? $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));

                    if ($item->type == 'INVOICE') {
                        $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                        $description = 'INVOICE';
                    } else {
                        $amount = -abs($amount);
                        if ($item->type == 'HOTEL') {
                            $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
                        } elseif ($item->type == 'ATTRACTION') {
                            $remarks = $itemDetails['remarks'] ?? $item->service_name ?? 'Attraction fees';
                        } elseif ($item->type == 'TOUR TRANSFER') {
                            $remarks = $itemDetails['remarks'] ?? 'Tour transfer';
                        } elseif ($item->type == 'TRANSPORT') {
                            $remarks = $itemDetails['remarks'] ?? 'Transport expenses';
                        } elseif ($item->type == 'MEALS') {
                            $remarks = 'Meals expenses';
                        } elseif ($item->type == 'OTHER RATES') {
                            $remarks = $itemDetails['remarks'] ?? 'Other fees';
                        }
                    }

                    // ✅ UPDATED WITH CLIENT NAME AND SERVICE NAME
                    $sheet->setCellValue("A{$row}", $globalSno++);
                    $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                    $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                    $sheet->setCellValue("D{$row}", $item->client_name ?? $record->vendor_name ?? '');  // Client Name
                    $sheet->setCellValue("E{$row}", $item->type);
                    $sheet->setCellValue("F{$row}", $startDate);
                    $sheet->setCellValue("G{$row}", $endDate);
                    $sheet->setCellValue("H{$row}", $item->credit_type ?? 'Credit');
                    $sheet->setCellValue("I{$row}", $agentName ?? '-');
                    $sheet->setCellValue("J{$row}", $description);  // ✅ Service Name in Description
                    $sheet->setCellValue("K{$row}", $this->formatAmountForExport($amount));
                    $sheet->setCellValue("L{$row}", $exchangeRate);
                    $sheet->setCellValue("M{$row}", $this->formatAmountForExport($amount * $exchangeRate));
                    $sheet->setCellValue("N{$row}", $remarks);

                    if (isset($colors[$item->type])) {
                        $sheet->getStyle("A{$row}:N{$row}")->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB($colors[$item->type]);
                    }
                    $row++;
                }
            }

            // Profit / Loss row
            if ($record->profit_loss !== null) {
                $pl = $record->profit_loss;
                $sheet->setCellValue("A{$row}", '');
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", '');
                $sheet->setCellValue("E{$row}", 'PROFIT / (LOSS)');
                $sheet->setCellValue("F{$row}", '');
                $sheet->setCellValue("G{$row}", '');
                $sheet->setCellValue("H{$row}", '');
                $sheet->setCellValue("I{$row}", $agentName ?? '-');
                $sheet->setCellValue("J{$row}", '');
                $sheet->setCellValue("K{$row}", $this->formatAmountForExport($pl));
                $sheet->setCellValue("L{$row}", $exchangeRate);
                $sheet->setCellValue("M{$row}", $this->formatAmountForExport($pl * $exchangeRate));
                $sheet->setCellValue("N{$row}", $pl >= 0 ? 'Profit from email' : 'Loss from email');

                $sheet->getStyle("A{$row}:N{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']]
                ]);
                $row++;
            }

            // Blank row between different tours
            $row++;
        }

        // ✅ UPDATED: Auto-size columns A to N
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $highestRow = $row - 1;
        if ($highestRow >= 2) {
            $sheet->getStyle("A2:N{$highestRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ]);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'pnl_');
        $writer->save($tempFile);
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
 * View Excel file in browser - For specific record or whole country
 */
public function viewExcel($country, $id = null)
{
    try {
        $excelService = new PnLExcelService();
        
        // If ID is provided, show specific record preview
        if ($id) {
            $record = PnlRecord::with('items')->findOrFail($id);
            $html = $excelService->getRecordPreview($record);
            
            // Get record details for title
            $countryNames = [
                'SG' => 'Singapore',
                'MY' => 'Malaysia',
                'VN' => 'Vietnam',
                'LK' => 'Sri Lanka'
            ];
            $countryName = $countryNames[$country] ?? $country;
            $recordTitle = $record->tour_ref ? " - {$record->tour_ref}" : '';
            
            return view('pnl.excel-preview', compact('html', 'countryName', 'country', 'record'));
        } else {
            // Show full country Excel
            $html = $excelService->getExcelPreview($country);
            
            $countryNames = [
                'SG' => 'Singapore',
                'MY' => 'Malaysia',
                'VN' => 'Vietnam',
                'LK' => 'Sri Lanka'
            ];
            $countryName = $countryNames[$country] ?? $country;
            
            return view('pnl.excel-preview', compact('html', 'countryName', 'country'));
        }
        
    } catch (\Exception $e) {
        Log::error('View Excel failed: ' . $e->getMessage());
        return redirect()->back()->with('error', 'Failed to load Excel: ' . $e->getMessage());
    }
}

/**
 * Get HTML preview for a single record
 */
/**
 * View selected records together
 */
public function viewSelected(Request $request)
{
    try {
        $ids = explode(',', $request->ids);
        $records = PnlRecord::with('items')->whereIn('id', $ids)->get();
        
        if ($records->isEmpty()) {
            return redirect()->back()->with('error', 'No records found.');
        }
        
        $excelService = new PnLExcelService();
        $html = '';
        $totalRecords = $records->count();
        $recordIndex = 1;
        
        foreach ($records as $record) {
            $html .= '<div class="record-section mb-5">';
            $html .= '<div class="record-header">';
            $html .= '<h3 class="record-title">Record ' . $recordIndex . ' of ' . $totalRecords . '</h3>';
            $html .= '<div class="record-meta">';
            $html .= '<span class="badge bg-primary me-2">' . ($record->tour_ref ?? 'N/A') . '</span>';
            $html .= '<span class="badge bg-secondary me-2">' . ($record->country_code ?? '') . '</span>';
            $html .= '<span class="badge bg-info">$' . number_format($record->amount, 2) . '</span>';
            $html .= '</div>';
            $html .= '</div>';
            
            // Get record preview HTML
            $html .= $excelService->getRecordPreview($record);
            $html .= '</div>';
            $recordIndex++;
        }
        
        $country = $records->first()->country_code ?? 'VN';
        $countryNames = [
            'SG' => 'Singapore',
            'MY' => 'Malaysia',
            'VN' => 'Vietnam',
            'LK' => 'Sri Lanka'
        ];
        $countryName = $countryNames[$country] ?? $country;
        
        return view('pnl.selected-view', compact('html', 'countryName', 'country', 'records'));
        
    } catch (\Exception $e) {
        Log::error('View selected failed: ' . $e->getMessage());
        return redirect()->back()->with('error', 'Failed to load selected records: ' . $e->getMessage());
    }
}
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
        
        // ✅ UPDATED HEADERS WITH CLIENT NAME
        $headers = [
            'A1' => 'S.No',
            'B1' => 'Tour Number',
            'C1' => 'Invoice Number',
            'D1' => 'Client Name',        // ✅ NEW
            'E1' => 'Type',
            'F1' => 'Start Date',
            'G1' => 'End Date',
            'H1' => 'Credit Type',
            'I1' => 'Agent Name',
            'J1' => 'Description',
            'K1' => 'Amount (USD)',
            'L1' => 'Exchange Rate',
            'M1' => 'Amount (Local)',
            'N1' => 'Remarks'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // ✅ UPDATED RANGE TO N
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
        ];
        $sheet->getStyle('A1:N1')->applyFromArray($headerStyle);
        
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
            
            $items = $record->items;
            
            if ($items->isEmpty()) {
                $startDate = $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
                $endDate = $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
                
                $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                
                // ✅ UPDATED WITH CLIENT NAME
                $sheet->setCellValue("A{$row}", $globalSno++);
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", $record->vendor_name ?? '');  // Client Name
                $sheet->setCellValue("E{$row}", 'INVOICE');
                $sheet->setCellValue("F{$row}", $startDate);
                $sheet->setCellValue("G{$row}", $endDate);
                $sheet->setCellValue("H{$row}", 'Credit');
                $sheet->setCellValue("I{$row}", $agentName ?? '-');
                $sheet->setCellValue("J{$row}", 'INVOICE');
                $sheet->setCellValue("K{$row}", $this->formatAmountForExport($record->amount));
                $sheet->setCellValue("L{$row}", $exchangeRate);
                $sheet->setCellValue("M{$row}", $this->formatAmountForExport($record->amount * $exchangeRate));
                $sheet->setCellValue("N{$row}", $remarks);
                
                if (isset($colors['INVOICE'])) {
                    $sheet->getStyle("A{$row}:N{$row}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($colors['INVOICE']);
                }
                $row++;
            } else {
foreach ($items as $item) {
    $remarks = '';
    $itemDetails = json_decode($item->item_details, true);
    
    // ✅ Use service_name as description by default
    $description = $item->service_name ?? $item->type;
    
    $amount = $item->amount_original;
    
    $startDate = $item->start_date ?? $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
    $endDate = $item->end_date ?? $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));

    if ($item->type == 'INVOICE') {
        $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
        $description = 'INVOICE';
    } else {
        $amount = -abs($amount);
        // For HOTEL, fallback to hotel_name if service_name is empty
        if ($item->type == 'HOTEL' && empty($description)) {
            $description = $item->hotel_name ?? $item->type;
        }
        // Build remarks
        if ($item->type == 'HOTEL') {
            $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
        } elseif ($item->type == 'ATTRACTION') {
            $remarks = $itemDetails['remarks'] ?? $item->service_name ?? 'Attraction fees';
        } elseif ($item->type == 'TOUR TRANSFER') {
            $remarks = $itemDetails['remarks'] ?? 'Total tour transfer expenses';
        } elseif ($item->type == 'TRANSPORT') {
            $remarks = $itemDetails['remarks'] ?? 'Total transport expenses';
        } elseif ($item->type == 'MEALS') {
            $remarks = 'Meals expenses';
        } elseif ($item->type == 'OTHER RATES') {
            $remarks = $itemDetails['remarks'] ?? 'Other fees';
        }
    }



                    // ✅ UPDATED WITH CLIENT NAME
                    $sheet->setCellValue("A{$row}", $globalSno++);
                    $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                    $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                    $sheet->setCellValue("D{$row}", $item->client_name ?? $record->vendor_name ?? '');  // Client Name
                    $sheet->setCellValue("E{$row}", $item->type);
                    $sheet->setCellValue("F{$row}", $startDate);
                    $sheet->setCellValue("G{$row}", $endDate);
                    $sheet->setCellValue("H{$row}", $item->credit_type ?? 'Credit');
                    $sheet->setCellValue("I{$row}", $agentName ?? '-');
                    $sheet->setCellValue("J{$row}", $description);
                    $sheet->setCellValue("K{$row}", $this->formatAmountForExport($amount));
                    $sheet->setCellValue("L{$row}", $exchangeRate);
                    $sheet->setCellValue("M{$row}", $this->formatAmountForExport($amount * $exchangeRate));
                    $sheet->setCellValue("N{$row}", $remarks);

                    if (isset($colors[$item->type])) {
                        $sheet->getStyle("A{$row}:N{$row}")->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB($colors[$item->type]);
                    }
                    $row++;
                }
            }

            // Profit/Loss row
            if ($record->profit_loss !== null) {
                $pl = $record->profit_loss;
                $sheet->setCellValue("A{$row}", '');
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", '');  // Client Name - blank
                $sheet->setCellValue("E{$row}", 'PROFIT / (LOSS)');
                $sheet->setCellValue("F{$row}", '');
                $sheet->setCellValue("G{$row}", '');
                $sheet->setCellValue("H{$row}", '');
                $sheet->setCellValue("I{$row}", $agentName ?? '-');
                $sheet->setCellValue("J{$row}", '');
                $sheet->setCellValue("K{$row}", $this->formatAmountForExport($pl));
                $sheet->setCellValue("L{$row}", $exchangeRate);
                $sheet->setCellValue("M{$row}", $this->formatAmountForExport($pl * $exchangeRate));
                $sheet->setCellValue("N{$row}", $pl >= 0 ? 'Profit from email' : 'Loss from email');

                $sheet->getStyle("A{$row}:N{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']]
                ]);
                $row++;
            }

            // Blank separator
            $row++;
        }
        
        // ✅ UPDATED: Auto-size columns A to N
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // ✅ UPDATED: Borders A to N
        $highestRow = $row - 1;
        if ($highestRow >= 2) {
            $sheet->getStyle("A2:N{$highestRow}")->applyFromArray([
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
        
        // ✅ UPDATED HEADERS WITH CLIENT NAME
        $headers = [
            'A1' => 'S.No',
            'B1' => 'Tour Number',
            'C1' => 'Invoice Number',
            'D1' => 'Client Name',        // ✅ NEW
            'E1' => 'Type',
            'F1' => 'Start Date',
            'G1' => 'End Date',
            'H1' => 'Credit Type',
            'I1' => 'Agent Name',
            'J1' => 'Description',
            'K1' => 'Amount (USD)',
            'L1' => 'Exchange Rate',
            'M1' => 'Amount (Local)',
            'N1' => 'Remarks'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // ✅ UPDATED RANGE TO N
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
        ];
        $sheet->getStyle('A1:N1')->applyFromArray($headerStyle);
        
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
            
            $items = $record->items;
            
            if ($items->isEmpty()) {
                $startDate = $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
                $endDate = $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
                
                $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                
                // ✅ UPDATED WITH CLIENT NAME
                $sheet->setCellValue("A{$row}", $globalSno++);
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", $record->vendor_name ?? '');  // Client Name
                $sheet->setCellValue("E{$row}", 'INVOICE');
                $sheet->setCellValue("F{$row}", $startDate);
                $sheet->setCellValue("G{$row}", $endDate);
                $sheet->setCellValue("H{$row}", 'Credit');
                $sheet->setCellValue("I{$row}", $agentName ?? '-');
                $sheet->setCellValue("J{$row}", 'INVOICE');
                $sheet->setCellValue("K{$row}", $this->formatAmountForExport($record->amount));
                $sheet->setCellValue("L{$row}", $exchangeRate);
                $sheet->setCellValue("M{$row}", $this->formatAmountForExport($record->amount * $exchangeRate));
                $sheet->setCellValue("N{$row}", $remarks);
                
                if (isset($colors['INVOICE'])) {
                    $sheet->getStyle("A{$row}:N{$row}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($colors['INVOICE']);
                }
                $row++;
            } else {
               foreach ($items as $item) {
    $remarks = '';
    $itemDetails = json_decode($item->item_details, true);
    
    // ✅ Use service_name as description by default
    $description = $item->service_name ?? $item->type;
    
    $amount = $item->amount_original;
    
    $startDate = $item->start_date ?? $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
    $endDate = $item->end_date ?? $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));

    if ($item->type == 'INVOICE') {
        $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
        $description = 'INVOICE';
    } else {
        $amount = -abs($amount);
        // For HOTEL, fallback to hotel_name if service_name is empty
        if ($item->type == 'HOTEL' && empty($description)) {
            $description = $item->hotel_name ?? $item->type;
        }
        // Build remarks
        if ($item->type == 'HOTEL') {
            $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
        } elseif ($item->type == 'ATTRACTION') {
            $remarks = $itemDetails['remarks'] ?? $item->service_name ?? 'Attraction fees';
        } elseif ($item->type == 'TOUR TRANSFER') {
            $remarks = $itemDetails['remarks'] ?? 'Total tour transfer expenses';
        } elseif ($item->type == 'TRANSPORT') {
            $remarks = $itemDetails['remarks'] ?? 'Total transport expenses';
        } elseif ($item->type == 'MEALS') {
            $remarks = 'Meals expenses';
        } elseif ($item->type == 'OTHER RATES') {
            $remarks = $itemDetails['remarks'] ?? 'Other fees';
        }
    }


                    // ✅ UPDATED WITH CLIENT NAME
                    $sheet->setCellValue("A{$row}", $globalSno++);
                    $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                    $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                    $sheet->setCellValue("D{$row}", $item->client_name ?? $record->vendor_name ?? '');  // Client Name
                    $sheet->setCellValue("E{$row}", $item->type);
                    $sheet->setCellValue("F{$row}", $startDate);
                    $sheet->setCellValue("G{$row}", $endDate);
                    $sheet->setCellValue("H{$row}", $item->credit_type ?? 'Credit');
                    $sheet->setCellValue("I{$row}", $agentName ?? '-');
                    $sheet->setCellValue("J{$row}", $description);
                    $sheet->setCellValue("K{$row}", $this->formatAmountForExport($amount));
                    $sheet->setCellValue("L{$row}", $exchangeRate);
                    $sheet->setCellValue("M{$row}", $this->formatAmountForExport($amount * $exchangeRate));
                    $sheet->setCellValue("N{$row}", $remarks);

                    if (isset($colors[$item->type])) {
                        $sheet->getStyle("A{$row}:N{$row}")->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB($colors[$item->type]);
                    }
                    $row++;
                }
            }

            // Profit/Loss row
            if ($record->profit_loss !== null) {
                $pl = $record->profit_loss;
                $sheet->setCellValue("A{$row}", '');
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", '');  // Client Name - blank
                $sheet->setCellValue("E{$row}", 'PROFIT / (LOSS)');
                $sheet->setCellValue("F{$row}", '');
                $sheet->setCellValue("G{$row}", '');
                $sheet->setCellValue("H{$row}", '');
                $sheet->setCellValue("I{$row}", $agentName ?? '-');
                $sheet->setCellValue("J{$row}", '');
                $sheet->setCellValue("K{$row}", $this->formatAmountForExport($pl));
                $sheet->setCellValue("L{$row}", $exchangeRate);
                $sheet->setCellValue("M{$row}", $this->formatAmountForExport($pl * $exchangeRate));
                $sheet->setCellValue("N{$row}", $pl >= 0 ? 'Profit from email' : 'Loss from email');

                $sheet->getStyle("A{$row}:N{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']]
                ]);
                $row++;
            }

            // Blank separator
            $row++;
        }
        
        // ✅ UPDATED: Auto-size columns A to N
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // ✅ UPDATED: Borders A to N
        $highestRow = $row - 1;
        if ($highestRow >= 2) {
            $sheet->getStyle("A2:N{$highestRow}")->applyFromArray([
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
/**
 * Format amount: positive = number, negative = (number)
 */
private function formatAmountForExport($amount)
{
    if ($amount >= 0) {
        return number_format($amount, 2);
    }
    return '(' . number_format(abs($amount), 2) . ')';
}
/**
 * Export selected PnL records
 */
public function exportSelected(Request $request)
{
    try {
        // Handle both POST and GET requests
        $ids = $request->input('ids');
        
        // If it's a GET request, IDs might be a comma-separated string
        if (empty($ids) && $request->has('ids')) {
            $ids = $request->query('ids');
            if (is_string($ids)) {
                $ids = explode(',', $ids);
            }
        }
        
        // If still empty, check if it's a JSON string
        if (empty($ids) && $request->has('ids')) {
            $ids = $request->ids;
            if (is_string($ids) && strpos($ids, ',') !== false) {
                $ids = explode(',', $ids);
            }
        }
        
        if (empty($ids)) {
            // If it's a GET request from selected-view, redirect back with error
            if ($request->isMethod('get')) {
                return redirect()->back()->with('error', 'No records selected to export.');
            }
            return response()->json(['success' => false, 'message' => 'No records selected'], 400);
        }
        
        // Convert to array if it's a string
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        
        // Ensure IDs are integers
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);
        
        if (empty($ids)) {
            if ($request->isMethod('get')) {
                return redirect()->back()->with('error', 'Invalid record IDs.');
            }
            return response()->json(['success' => false, 'message' => 'Invalid record IDs'], 400);
        }
        
        $records = PnlRecord::with('items')
            ->whereIn('id', $ids)
            ->orderBy('received_at', 'desc')
            ->get();
        
        if ($records->isEmpty()) {
            if ($request->isMethod('get')) {
                return redirect()->back()->with('error', 'No records found.');
            }
            return response()->json(['success' => false, 'message' => 'No records found'], 404);
        }
        
        // Create filename
        $filename = "pnl_selected_" . date('Y-m-d_His') . ".xlsx";
        
        // Create new Spreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Selected PnL');
        
        // Headers
        $headers = [
            'A1' => 'S.No',
            'B1' => 'Tour Number',
            'C1' => 'Invoice Number',
            'D1' => 'Client Name',
            'E1' => 'Type',
            'F1' => 'Start Date',
            'G1' => 'End Date',
            'H1' => 'Credit Type',
            'I1' => 'Agent Name',
            'J1' => 'Description',
            'K1' => 'Amount (USD)',
            'L1' => 'Exchange Rate',
            'M1' => 'Amount (Local)',
            'N1' => 'Remarks'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        $sheet->getStyle('A1:N1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
        ]);
        
        $colors = [
            'INVOICE' => 'D5E8D4',
            'HOTEL' => 'FFF2CC',
            'TRANSPORT' => 'DDEBF7',
            'TOUR TRANSFER' => 'E2EFDA',
            'ATTRACTION' => 'FCE4D6',
            'MEALS' => 'E1C699',
            'OTHER RATES' => 'D9D9D9'
        ];
        
        $row = 2;
        $globalSno = 1;
        
        foreach ($records as $record) {
            $exchangeRate = $record->exchange_rate_used ?? 1;
            $tourRef = $record->tour_ref;
            $invoiceNumber = $record->invoice_number;
            $agentName = $record->agent_name;
            
            $items = $record->items;
            
            if ($items->isEmpty()) {
                $startDate = $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
                $endDate = $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
                $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                
                $sheet->setCellValue("A{$row}", $globalSno++);
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", $record->vendor_name ?? '');
                $sheet->setCellValue("E{$row}", 'INVOICE');
                $sheet->setCellValue("F{$row}", $startDate);
                $sheet->setCellValue("G{$row}", $endDate);
                $sheet->setCellValue("H{$row}", 'Credit');
                $sheet->setCellValue("I{$row}", $agentName ?? '-');
                $sheet->setCellValue("J{$row}", 'INVOICE');
                $sheet->setCellValue("K{$row}", $this->formatAmountForExport($record->amount));
                $sheet->setCellValue("L{$row}", $exchangeRate);
                $sheet->setCellValue("M{$row}", $this->formatAmountForExport($record->amount * $exchangeRate));
                $sheet->setCellValue("N{$row}", $remarks);
                
                if (isset($colors['INVOICE'])) {
                    $sheet->getStyle("A{$row}:N{$row}")->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($colors['INVOICE']);
                }
                $row++;
            } else {
                foreach ($items as $item) {
                    $remarks = '';
                    $itemDetails = json_decode($item->item_details, true);
                    $description = $item->service_name ?? $item->type;
                    $amount = $item->amount_original;
                    
                    $startDate = $item->start_date ?? $record->start_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));
                    $endDate = $item->end_date ?? $record->end_date ?? ($record->received_at ? $record->received_at->format('Y-m-d') : date('Y-m-d'));

                    if ($item->type == 'INVOICE') {
                        $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                        $description = 'INVOICE';
                    } else {
                        $amount = -abs($amount);
                        if ($item->type == 'HOTEL' && empty($description)) {
                            $description = $item->hotel_name ?? $item->type;
                        }
                        if ($item->type == 'HOTEL') {
                            $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
                        } elseif ($item->type == 'ATTRACTION') {
                            $remarks = $itemDetails['remarks'] ?? $item->service_name ?? 'Attraction fees';
                        } elseif ($item->type == 'TOUR TRANSFER') {
                            $remarks = $itemDetails['remarks'] ?? 'Total tour transfer expenses';
                        } elseif ($item->type == 'TRANSPORT') {
                            $remarks = $itemDetails['remarks'] ?? 'Total transport expenses';
                        } elseif ($item->type == 'MEALS') {
                            $remarks = 'Meals expenses';
                        } elseif ($item->type == 'OTHER RATES') {
                            $remarks = $itemDetails['remarks'] ?? 'Other fees';
                        }
                    }

                    $sheet->setCellValue("A{$row}", $globalSno++);
                    $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                    $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                    $sheet->setCellValue("D{$row}", $item->client_name ?? $record->vendor_name ?? '');
                    $sheet->setCellValue("E{$row}", $item->type);
                    $sheet->setCellValue("F{$row}", $startDate);
                    $sheet->setCellValue("G{$row}", $endDate);
                    $sheet->setCellValue("H{$row}", $item->credit_type ?? 'Credit');
                    $sheet->setCellValue("I{$row}", $agentName ?? '-');
                    $sheet->setCellValue("J{$row}", $description);
                    $sheet->setCellValue("K{$row}", $this->formatAmountForExport($amount));
                    $sheet->setCellValue("L{$row}", $exchangeRate);
                    $sheet->setCellValue("M{$row}", $this->formatAmountForExport($amount * $exchangeRate));
                    $sheet->setCellValue("N{$row}", $remarks);

                    if (isset($colors[$item->type])) {
                        $sheet->getStyle("A{$row}:N{$row}")->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB($colors[$item->type]);
                    }
                    $row++;
                }
            }
            
            // Profit/Loss row
            if ($record->profit_loss !== null) {
                $pl = $record->profit_loss;
                $sheet->setCellValue("A{$row}", '');
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", '');
                $sheet->setCellValue("E{$row}", 'PROFIT / (LOSS)');
                $sheet->setCellValue("F{$row}", '');
                $sheet->setCellValue("G{$row}", '');
                $sheet->setCellValue("H{$row}", '');
                $sheet->setCellValue("I{$row}", $agentName ?? '-');
                $sheet->setCellValue("J{$row}", '');
                $sheet->setCellValue("K{$row}", $this->formatAmountForExport($pl));
                $sheet->setCellValue("L{$row}", $exchangeRate);
                $sheet->setCellValue("M{$row}", $this->formatAmountForExport($pl * $exchangeRate));
                $sheet->setCellValue("N{$row}", $pl >= 0 ? 'Profit' : 'Loss');
                
                $sheet->getStyle("A{$row}:N{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']]
                ]);
                $row++;
            }
            
            // Blank separator
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Borders
        $highestRow = $row - 1;
        if ($highestRow >= 2) {
            $sheet->getStyle("A2:N{$highestRow}")->applyFromArray([
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
        Log::error('Export selected failed: ' . $e->getMessage());
        if ($request->isMethod('get')) {
            return redirect()->back()->with('error', 'Failed to export: ' . $e->getMessage());
        }
        return response()->json([
            'success' => false,
            'message' => 'Failed to export: ' . $e->getMessage()
        ], 500);
    }
}
}