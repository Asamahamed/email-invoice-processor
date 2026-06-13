<?php

namespace App\Services;

use App\Models\PnlRecord;
use App\Models\PnlItem;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use App\Services\GoogleSheetsService;

class PnLExcelService
{
    private $exchangeRates = [
        'LK' => 330,
        'VN' => 25500,
        'SG' => 1.35,
        'MY' => 4.70,
    ];

    private $excelColumns = [
        'A' => 'S.No',
        'B' => 'Tour Number',
        'C' => 'Invoice Number',
        'D' => 'Type',
        'E' => 'Start Date',
        'F' => 'End Date',
        'G' => 'Credit Type',
        'H' => 'Agent Name',
        'I' => 'Hotel Name',
        'J' => 'Amount (USD)',
        'K' => 'Exchange Rate',
        'L' => 'Amount (Local)',
        'M' => 'Remarks'
    ];

    // Replace the processAndUpdateExcel method in PnLExcelService.php with:

public function processAndUpdateExcel(PnlRecord $record)
{
    try {
        Log::info("Processing PnL Email ID: " . $record->id);
        
        // Get items already saved in database
        $items = PnlItem::where('pnl_record_id', $record->id)->get();
        
        if ($items->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No items found in database. Please fetch emails first.'
            ];
        }
        
        $countryCode = $record->country_code ?? 'VN';
        $exchangeRate = $this->exchangeRates[$countryCode] ?? 25500;
        $tourRef = $record->tour_ref;
        $invoiceNumber = $record->invoice_number;
        $agentName = $record->agent_name;
        
        // Build items for Excel from database
        $allItems = [];
        $sno = 1;
        
        foreach ($items as $item) {
            $remarks = '';
            $itemDetails = json_decode($item->item_details, true);
            
            if ($item->type == 'INVOICE') {
                $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
            } elseif ($item->type == 'HOTEL') {
                $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
            } elseif ($item->type == 'ATTRACTION') {
                $remarks = $itemDetails['remarks'] ?? $item->service_name;
            } elseif ($item->type == 'TOUR TRANSFER') {
                $remarks = 'Total tour transfer expenses';
            } elseif ($item->type == 'TRANSPORT') {
                $remarks = 'Total transport expenses';
            }
            
            $allItems[] = [
                'sno' => $sno++,
                'type' => $item->type,
                'start_date' => $record->start_date ?? date('Y-m-d'),
                'end_date' => $record->end_date ?? date('Y-m-d'),
                'credit_type' => $item->credit_type,
                'agent_name' => $agentName,
                'hotel_name' => $item->hotel_name,
                'amount_usd' => $item->amount_original,
                'exchange_rate' => $exchangeRate,
                'amount_local' => round($item->amount_original * $exchangeRate, 2),
                'remarks' => $remarks
            ];
        }
        
        Log::info("Total items to insert: " . count($allItems));
        
        if (empty($allItems)) {
            return [
                'success' => false,
                'message' => 'No items to process'
            ];
        }
        
        // ========== CRITICAL: Check for existing entries before inserting ==========
        $excelPath = $this->getExcelFilePath($countryCode);
        $spreadsheet = $this->loadOrCreateSpreadsheet($excelPath, $countryCode);
        
        // Check if this Tour Number + Invoice Number combination already exists
        $existingRows = $this->checkExistingEntries($spreadsheet, $tourRef, $invoiceNumber);
        
        if ($existingRows['found']) {
            // UPDATE existing entries
            Log::info("Updating existing entries for Tour: {$tourRef}, Invoice: {$invoiceNumber}");
            $this->updateExistingEntries($spreadsheet, $allItems, $tourRef, $invoiceNumber, $existingRows['rows']);
            $action = 'updated';
        } else {
            // INSERT new entries
            Log::info("Inserting new entries for Tour: {$tourRef}, Invoice: {$invoiceNumber}");
            $this->addItemsToSpreadsheet($spreadsheet, $allItems, $tourRef, $invoiceNumber);
            $action = 'inserted';
        }
        
        $this->saveSpreadsheet($spreadsheet, $excelPath);
        
        // Update the record
        $record->update([
            'status' => 'approved',
            'processing_status' => 'completed'
        ]);
        
        return [
            'success' => true,
            'action' => $action,
            'message' => $action == 'updated' ? "✅ PnL Updated successfully!" : "✅ New PnL Inserted!",
            'items_count' => count($allItems),
            'hotels_count' => $items->where('type', 'HOTEL')->count(),
            'attraction_count' => $items->where('type', 'ATTRACTION')->count(),
            'tour_transfers_amount' => $items->where('type', 'TOUR TRANSFER')->sum('amount_original'),
            'items' => $allItems,
            'excel_path' => $excelPath
        ];
        
    } catch (\Exception $e) {
        Log::error('PnL Excel processing failed: ' . $e->getMessage());
        Log::error($e->getTraceAsString());
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Check if entries already exist for given Tour Number and Invoice Number
 * Returns array with 'found' boolean and 'rows' array of row numbers
 */
private function checkExistingEntries($spreadsheet, $tourRef, $invoiceNumber)
{
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestRow();
    
    $existingRows = [];
    
    if ($highestRow < 2) {
        return ['found' => false, 'rows' => []];
    }
    
    // Scan through all rows to find matching Tour Number + Invoice Number
    for ($row = 2; $row <= $highestRow; $row++) {
        $existingTourRef = $sheet->getCell("B{$row}")->getValue();
        $existingInvoice = $sheet->getCell("C{$row}")->getValue();
        
        // Match both Tour Number AND Invoice Number
        if ($existingTourRef == $tourRef && $existingInvoice == $invoiceNumber) {
            $existingRows[] = $row;
        }
    }
    
    return [
        'found' => !empty($existingRows),
        'rows' => $existingRows
    ];
}

/**
 * Update existing entries in the spreadsheet
 * First delete old entries, then insert new ones at the same positions
 */
private function updateExistingEntries($spreadsheet, $newItems, $tourRef, $invoiceNumber, $existingRows)
{
    $sheet = $spreadsheet->getActiveSheet();
    
    // Sort rows in descending order to delete from bottom up (preserves row numbers)
    rsort($existingRows);
    
    // Delete all existing rows for this combination
    foreach ($existingRows as $row) {
        $sheet->removeRow($row);
        Log::info("Removed existing row {$row} for Tour: {$tourRef}, Invoice: {$invoiceNumber}");
    }
    
    // Now add the new items at the bottom
    $currentRow = $sheet->getHighestRow() + 1;
    if ($currentRow < 2) {
        $currentRow = 2;
    }
    
    foreach ($newItems as $item) {
        $sheet->setCellValue("A{$currentRow}", $item['sno']);
        $sheet->setCellValue("B{$currentRow}", $tourRef ?? '-');
        $sheet->setCellValue("C{$currentRow}", $invoiceNumber ?? '-');
        $sheet->setCellValue("D{$currentRow}", $item['type']);
        $sheet->setCellValue("E{$currentRow}", $item['start_date']);
        $sheet->setCellValue("F{$currentRow}", $item['end_date']);
        $sheet->setCellValue("G{$currentRow}", $item['credit_type']);
        $sheet->setCellValue("H{$currentRow}", $item['agent_name']);
        $sheet->setCellValue("I{$currentRow}", $item['hotel_name'] ?? '-');
        $sheet->setCellValue("J{$currentRow}", $item['amount_usd']);
        $sheet->setCellValue("K{$currentRow}", $item['exchange_rate']);
        $sheet->setCellValue("L{$currentRow}", $item['amount_local']);
        $sheet->setCellValue("M{$currentRow}", $item['remarks']);
        
        // Color coding
        $colors = [
            'INVOICE' => 'D5E8D4',
            'HOTEL' => 'FFF2CC',
            'TRANSPORT' => 'DDEBF7',
            'TOUR TRANSFER' => 'E2EFDA',
            'ATTRACTION' => 'FCE4D6'
        ];
        
        if (isset($colors[$item['type']])) {
            $sheet->getStyle("A{$currentRow}:M{$currentRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB($colors[$item['type']]);
        }
        
        $sheet->getStyle("A{$currentRow}:M{$currentRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
        ]);
        
        Log::info("Added updated row {$currentRow}: Type={$item['type']}, Amount=\${$item['amount_usd']}");
        $currentRow++;
    }
}

/**
 * Insert new entries (no existing found)
 */
private function addItemsToSpreadsheet($spreadsheet, $items, $tourRef, $invoiceNumber)
{
    $sheet = $spreadsheet->getActiveSheet();
    $row = $sheet->getHighestRow() + 1;
    
    if ($row < 2) {
        $row = 2;
    }
    
    foreach ($items as $item) {
        $sheet->setCellValue("A{$row}", $item['sno']);
        $sheet->setCellValue("B{$row}", $tourRef ?? '-');
        $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
        $sheet->setCellValue("D{$row}", $item['type']);
        $sheet->setCellValue("E{$row}", $item['start_date']);
        $sheet->setCellValue("F{$row}", $item['end_date']);
        $sheet->setCellValue("G{$row}", $item['credit_type']);
        $sheet->setCellValue("H{$row}", $item['agent_name']);
        $sheet->setCellValue("I{$row}", $item['hotel_name'] ?? '-');
        $sheet->setCellValue("J{$row}", $item['amount_usd']);
        $sheet->setCellValue("K{$row}", $item['exchange_rate']);
        $sheet->setCellValue("L{$row}", $item['amount_local']);
        $sheet->setCellValue("M{$row}", $item['remarks']);
        
        // Color coding
        $colors = [
            'INVOICE' => 'D5E8D4',
            'HOTEL' => 'FFF2CC',
            'TRANSPORT' => 'DDEBF7',
            'TOUR TRANSFER' => 'E2EFDA',
            'ATTRACTION' => 'FCE4D6'
        ];
        
        if (isset($colors[$item['type']])) {
            $sheet->getStyle("A{$row}:M{$row}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB($colors[$item['type']]);
        }
        
        $sheet->getStyle("A{$row}:M{$row}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
        ]);
        
        Log::info("Added new row {$row}: Type={$item['type']}, Amount=\${$item['amount_usd']}");
        $row++;
    }
    
    foreach (range('A', 'M') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}
    /**
     * Extract hotels from Hotels/Cruises table - IMPROVED for Vietnam format
     */
    private function extractHotelsFromTable($text)
    {
        $hotels = [];
        
        if (!preg_match('/Hotels\/Cruises/i', $text)) {
            Log::info("No Hotels/Cruises section found in email");
            return $hotels;
        }
        
        // Find the Hotels/Cruises section
        if (!preg_match('/Hotels\/Cruises(.*?)(?:Attraction|Tour Transfers|Transport|Meals|Cost Per Person|$)/is', $text, $sectionMatch)) {
            Log::warning("Hotels/Cruises section found but cannot parse");
            return $hotels;
        }
        
        $section = $sectionMatch[1];
        Log::info("Hotels section found: " . substr($section, 0, 500));
        
        // Split into lines
        $lines = explode("\n", $section);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip header lines
            if (preg_match('/^(NAME|SGL|DBL|TPL|CWB|CNB|NIGHTS|ROOM NIGHT|TOTAL)/i', $line)) {
                continue;
            }
            
            // Skip Total row
            if (preg_match('/^Total/i', $line)) {
                continue;
            }
            
            // Look for hotel pattern: Name followed by numbers
            // Example: "La Passion Classic Hotel    0   37 / 37   0   0   0   2   74.00"
            
            // Clean the line
            $cleanLine = preg_replace('/\s+/', ' ', $line);
            $cleanLine = trim($cleanLine);
            
            // Check if this looks like a hotel row (has a name and numbers)
            if (preg_match('/^([A-Za-z\s]+?)\s+(\d+)\s+([\d\s\/]+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+([\d\.]+)/i', $cleanLine, $match)) {
                $name = trim($match[1]);
                $nights = intval($match[7]);
                $amount = floatval($match[8]);
                
                if ($amount > 0 && strlen($name) > 3 && !str_contains(strtolower($name), 'total')) {
                    // Check for duplicate
                    $exists = false;
                    foreach ($hotels as $existing) {
                        if ($existing['name'] === $name) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $hotels[] = [
                            'name' => $name,
                            'amount' => $amount,
                            'nights' => $nights
                        ];
                        Log::info("✓ Found hotel: {$name} - \${$amount}, {$nights} nights");
                    }
                }
            }
            // Alternative pattern for simpler hotel rows
            elseif (preg_match('/^([A-Za-z\s]+?)\s+[\d\s\/]+\s+(\d+)\s+([\d\.]+)$/i', $cleanLine, $match)) {
                $name = trim($match[1]);
                $nights = intval($match[2]);
                $amount = floatval($match[3]);
                
                if ($amount > 0 && strlen($name) > 3) {
                    $exists = false;
                    foreach ($hotels as $existing) {
                        if ($existing['name'] === $name) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $hotels[] = [
                            'name' => $name,
                            'amount' => $amount,
                            'nights' => $nights
                        ];
                        Log::info("✓ Found hotel (alt): {$name} - \${$amount}, {$nights} nights");
                    }
                }
            }
        }
        
        Log::info("Total hotels extracted: " . count($hotels));
        return $hotels;
    }

    /**
     * Extract individual attractions from Attraction table
     */
    private function extractIndividualAttractions($text)
    {
        $attractions = [];
        
        if (!preg_match('/Attraction(.*?)(?:Tour Transfers|Transport|Meals|Total Tour Cost|$)/is', $text, $sectionMatch)) {
            return $attractions;
        }
        
        $section = $sectionMatch[1];
        Log::info("Attraction section found");
        
        // Split into lines
        $lines = explode("\n", $section);
        $currentAttraction = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip header
            if (preg_match('/^#DAY\(S\)|CITY|ATTRACTION|ADULT ENTRANCE|CHILD ENTRANCE|TRANSFER|RATE/i', $line)) {
                continue;
            }
            
            // Look for attraction pattern: Day X, City, Attraction Name, then rate
            // Example: "Day 2    Hanoi    Ninh Binh Bai Dinh Trang An Hang Mua SIC    SIC Transfer    0    0    0    Adult: 35.0319 Child: 0"
            if (preg_match('/Day\s+(\d+)\s+([A-Za-z\s]+?)\s+([A-Za-z\s]+?)\s+(?:SIC Transfer|Private Transfers)?\s*\d+\s+\d+\s+\d+\s+Adult:\s*([\d\.]+)/i', $line, $match)) {
                $day = $match[1];
                $city = trim($match[2]);
                $name = trim($match[3]);
                $amount = floatval($match[4]);
                
                if ($amount > 0) {
                    $attractions[] = [
                        'day' => $day,
                        'city' => $city,
                        'name' => $name,
                        'amount' => $amount
                    ];
                    Log::info("✓ Found attraction: Day {$day} - {$name} - \${$amount}");
                }
            }
            // Simpler pattern
            elseif (preg_match('/Day\s+(\d+)\s+([A-Za-z\s]+?)\s+Adult:\s*([\d\.]+)/i', $line, $match)) {
                $day = $match[1];
                $name = trim($match[2]);
                $amount = floatval($match[3]);
                
                if ($amount > 0) {
                    $attractions[] = [
                        'day' => $day,
                        'city' => '',
                        'name' => $name,
                        'amount' => $amount
                    ];
                    Log::info("✓ Found attraction (simple): Day {$day} - \${$amount}");
                }
            }
        }
        
        return $attractions;
    }

    /**
     * Get total transport amount
     */
    private function getTotalTransportAmount($text)
    {
        // Look for Transport section total
        if (preg_match('/Transport.*?Total\s+Transport\s+[\d\.]+\s+([\d\.]+)/is', $text, $match)) {
            $total = floatval($match[1]);
            Log::info("Transport total found: " . $total);
            return $total;
        }
        
        if (preg_match('/Transport.*?(?:Total:|Total Transport)\s*([\d\.]+)\s*USD/is', $text, $match)) {
            $total = floatval($match[1]);
            Log::info("Transport total found (alt): " . $total);
            return $total;
        }
        
        return 0;
    }

    /**
     * Get total tour transfers amount
     */
    private function getTotalTourTransfersAmount($text)
    {
        if (!preg_match('/Tour Transfers/i', $text)) {
            return 0;
        }
        
        // Look for Total at the bottom of Tour Transfers table
        // From your email: "Total | 148.52 USD"
        if (preg_match('/Tour Transfers.*?Total\s*\|\s*([\d\.]+)\s*USD/is', $text, $match)) {
            $total = floatval($match[1]);
            Log::info("Tour Transfers total found: " . $total);
            return $total;
        }
        
        if (preg_match('/Tour Transfers.*?Total\s*:?\s*([\d\.]+)\s*USD/is', $text, $match)) {
            $total = floatval($match[1]);
            Log::info("Tour Transfers total found (alt): " . $total);
            return $total;
        }
        
        return 0;
    }

    /**
     * Get total attraction amount
     */
    private function getTotalAttractionAmount($text)
    {
        // Look for Total at the bottom of Attraction table
        // From your email: "Total | 358.93 USD"
        if (preg_match('/Attraction.*?Total\s*\|\s*([\d\.]+)\s*USD/is', $text, $match)) {
            $total = floatval($match[1]);
            Log::info("Attraction total found: " . $total);
            return $total;
        }
        
        // Alternative: Total row in table
        if (preg_match('/Attraction[\s\S]*?\|\s*Total\s*\|\s*([\d\.]+)\s*USD/i', $text, $match)) {
            $total = floatval($match[1]);
            Log::info("Attraction total found (alt): " . $total);
            return $total;
        }
        
        // Check for Other Rates section
        if (preg_match('/Other Rates.*?Total\s*:?\s*([\d\.]+)\s*USD/is', $text, $match)) {
            $total = floatval($match[1]);
            Log::info("Other Rates total found: " . $total);
            return $total;
        }
        
        return 0;
    }

    /**
     * Extract total tour cost
     */
    private function extractTotalTourCost($text)
    {
        $patterns = [
            '/Total Tour Cost\s+([\d\.]+)\s*USD/i',
            '/Total Tour Cost\s*:?\s*([\d\.]+)\s*USD/i',
            '/Total Tour Cost\s*=\s*([\d\.]+)/i',
            '/Total Tour Cost Without Markup\s*([\d\.]+)\s*USD/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $amount = floatval($match[1]);
                if ($amount > 0) {
                    Log::info("Found Total Tour Cost: " . $amount);
                    return $amount;
                }
            }
        }
        
        return 0;
    }

    private function extractTourNumber($text)
    {
        if (preg_match('/Tour No:\s*#?(\d+)/i', $text, $match)) {
            return $match[1];
        }
        return null;
    }

    private function extractInvoiceNumber($text)
    {
        if (preg_match('/Is Number:\s*([A-Z]{2})\s*(\d+)/i', $text, $match)) {
            return $match[1] . $match[2];
        }
        return null;
    }

    private function extractAgentName($text)
    {
        if (preg_match('/Agent:\s*([^\n]+)/i', $text, $match)) {
            $agent = trim($match[1]);
            $agent = preg_replace('/\s+No\..*$/i', '', $agent);
            $agent = preg_replace('/\s+\d+.*$/i', '', $agent);
            return trim($agent);
        }
        return 'Unknown';
    }

    private function extractTotalPax($text)
    {
        if (preg_match('/No\.\s*Adult:\s*(\d+)/i', $text, $match)) {
            return intval($match[1]);
        }
        if (preg_match('/No\.\s*P(?:ass|ax):\s*(\d+)/i', $text, $match)) {
            return intval($match[1]);
        }
        return 0;
    }

    private function extractTotalNights($text)
    {
        if (preg_match('/No\.\s*Night:\s*(\d+)/i', $text, $match)) {
            return intval($match[1]);
        }
        return 0;
    }

    private function extractTravelDates($text, $totalNights)
    {
        $start = date('Y-m-d');
        $end = date('Y-m-d', strtotime("+{$totalNights} days"));
        
        // Try to extract actual dates from email
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $text, $match)) {
            $start = date('Y-m-d', strtotime($match[1]));
            $end = date('Y-m-d', strtotime($match[1] . " + {$totalNights} days"));
        }
        
        return ['start' => $start, 'end' => $end];
    }

    private function detectCountry($invoiceNumber, $text)
    {
        if ($invoiceNumber && preg_match('/^([A-Z]{2})/', $invoiceNumber, $match)) {
            $code = strtoupper($match[1]);
            if ($code == 'IS') return 'LK';
            if ($code == 'VN') return 'VN';
            if ($code == 'SG') return 'SG';
            if ($code == 'MY') return 'MY';
        }
        return 'VN'; // Default to Vietnam for your example
    }

    private function getCurrencyCode($countryCode)
    {
        $currencies = ['LK' => 'LKR', 'VN' => 'VND', 'SG' => 'SGD', 'MY' => 'MYR'];
        return $currencies[$countryCode] ?? 'VND';
    }

    private function getExcelFilePath($countryCode)
    {
        $files = [
            'LK' => storage_path('app/pnl/srilanka_pnl.xlsx'),
            'VN' => storage_path('app/pnl/vietnam_pnl.xlsx'),
            'SG' => storage_path('app/pnl/singapore_pnl.xlsx'),
            'MY' => storage_path('app/pnl/malaysia_pnl.xlsx'),
        ];
        
        $path = $files[$countryCode] ?? storage_path('app/pnl/vietnam_pnl.xlsx');
        $dir = dirname($path);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        
        return $path;
    }

    private function loadOrCreateSpreadsheet($path, $countryCode)
    {
        if (file_exists($path)) {
            return IOFactory::load($path);
        }
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("PnL - " . $countryCode);
        
        foreach ($this->excelColumns as $col => $header) {
            $sheet->setCellValue($col . '1', $header);
        }
        
        $sheet->getStyle('A1:M1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
        
        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        return $spreadsheet;
    }



    private function saveSpreadsheet($spreadsheet, $path)
    {
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        Log::info("Excel file saved: {$path}");
    }

    public function getExcelPreview($countryCode = null)
    {
        $countryCode = $countryCode ?? 'VN';
        $excelPath = $this->getExcelFilePath($countryCode);
        
        if (!file_exists($excelPath)) {
            return '<div class="alert alert-warning">No Excel file found for ' . $countryCode . '.</div>';
        }
        
        try {
            $spreadsheet = IOFactory::load($excelPath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();
            
            $html = '<div class="table-responsive"><table class="table table-bordered table-striped table-sm">';
            $html .= '<thead class="table-dark"><tr>';
            
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $value = $sheet->getCell($col . '1')->getValue();
                $html .= '<th>' . htmlspecialchars($value) . '</th>';
            }
            $html .= '</thead><tbody>';
            
            for ($row = 2; $row <= min($highestRow, 100); $row++) {
                $html .= '<tr>';
                for ($col = 'A'; $col <= $highestColumn; $col++) {
                    $value = $sheet->getCell($col . $row)->getCalculatedValue();
                    $html .= '<td>' . htmlspecialchars($value) . '</td>';
                }
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table></div>';
            return $html;
            
        } catch (\Exception $e) {
            Log::error('Excel preview error: ' . $e->getMessage());
            return '<div class="alert alert-danger">Error loading Excel file: ' . $e->getMessage() . '</div>';
        }
    }

    public function processAndUpdateGoogleSheets(PnlRecord $record)
{
    try {
        // Get the email content
        $content = $record->body_html ?: $record->body;
        $plainText = strip_tags($content);
        $plainText = preg_replace('/\r\n/', "\n", $plainText);
        
        Log::info("Processing PnL for Google Sheets: " . $record->id);

        // Extract header information
        $tourNumber = $this->extractTourNumber($plainText);
        $invoiceNumber = $this->extractInvoiceNumber($plainText);
        $agentName = $this->extractAgentName($plainText);
        $totalPax = $this->extractTotalPax($plainText);
        $totalNights = $this->extractTotalNights($plainText);
        $totalTourCost = $this->extractTotalTourCost($plainText);

        $countryCode = $this->detectCountry($invoiceNumber, $plainText);
        $exchangeRate = $this->exchangeRates[$countryCode] ?? 330;
        $tourRef = $tourNumber ? $tourNumber . 'CNTL' : null;
        $travelDates = $this->extractTravelDates($plainText, $totalNights);

        // Extract ALL data from different sections
        $hotels = $this->extractHotelsFromTable($plainText);
        $transportTotal = $this->getTotalTransportAmount($plainText);
        $tourTransfersTotal = $this->getTotalTourTransfersAmount($plainText);
        $attractionTotal = $this->getTotalAttractionAmount($plainText);

        // Build all items for Google Sheets
        $allItems = [];
        $sno = 1;

        // 1. INVOICE row
        if ($totalTourCost > 0) {
            $allItems[] = [
                $sno++,
                $tourRef ?? '-',
                $invoiceNumber ?? '-',
                'INVOICE',
                $travelDates['start'],
                $travelDates['end'],
                'Credit',
                $agentName,
                '-',
                $totalTourCost,
                $exchangeRate,
                round($totalTourCost * $exchangeRate, 2),
                "Pax: {$totalPax}, Nights: {$totalNights}"
            ];
        }

        // 2. HOTEL rows
        foreach ($hotels as $hotel) {
            $allItems[] = [
                $sno++,
                $tourRef ?? '-',
                $invoiceNumber ?? '-',
                'HOTEL',
                $travelDates['start'],
                $travelDates['end'],
                'Credit',
                $agentName,
                $hotel['name'],
                $hotel['amount'],
                $exchangeRate,
                round($hotel['amount'] * $exchangeRate, 2),
                ($hotel['nights'] ?? 1) . ' nights'
            ];
        }

        // 3. TRANSPORT row
        if ($transportTotal > 0) {
            $allItems[] = [
                $sno++,
                $tourRef ?? '-',
                $invoiceNumber ?? '-',
                'TRANSPORT',
                $travelDates['start'],
                $travelDates['end'],
                'Credit',
                $agentName,
                '-',
                $transportTotal,
                $exchangeRate,
                round($transportTotal * $exchangeRate, 2),
                'Total transport expenses'
            ];
        }

        // 4. TOUR TRANSFER row
        if ($tourTransfersTotal > 0) {
            $allItems[] = [
                $sno++,
                $tourRef ?? '-',
                $invoiceNumber ?? '-',
                'TOUR TRANSFER',
                $travelDates['start'],
                $travelDates['end'],
                'Credit',
                $agentName,
                '-',
                $tourTransfersTotal,
                $exchangeRate,
                round($tourTransfersTotal * $exchangeRate, 2),
                'Total tour transfer expenses'
            ];
        }

        // 5. ATTRACTION row
        if ($attractionTotal > 0) {
            $allItems[] = [
                $sno++,
                $tourRef ?? '-',
                $invoiceNumber ?? '-',
                'ATTRACTION',
                $travelDates['start'],
                $travelDates['end'],
                'Credit',
                $agentName,
                '-',
                $attractionTotal,
                $exchangeRate,
                round($attractionTotal * $exchangeRate, 2),
                'Total attraction & entrance fees'
            ];
        }

        if (empty($allItems)) {
            return [
                'success' => false,
                'message' => 'No items found to insert'
            ];
        }

        // Update Google Sheets
        $googleSheets = new GoogleSheetsService();
        
        // Headers
        $headers = array_values($this->excelColumns);
        
        // Get sheet name based on country
        $sheetName = $this->getSheetNameForCountry($countryCode);
        
        // Ensure headers exist
        $googleSheets->ensureHeaders($headers, $sheetName);
        
        // Append rows
        $rowsUpdated = $googleSheets->appendRows($allItems, $sheetName);

        // Update the record
        $record->update([
            'tour_ref' => $tourRef,
            'agent_name' => $agentName,
            'start_date' => $travelDates['start'],
            'end_date' => $travelDates['end'],
            'amount' => $totalTourCost,
            'exchange_rate_used' => $exchangeRate,
            'currency' => $this->getCurrencyCode($countryCode),
            'country_code' => $countryCode,
            'status' => 'approved',
            'processing_status' => 'completed'
        ]);

        return [
            'success' => true,
            'items_count' => count($allItems),
            'hotels_count' => count($hotels),
            'rows_updated' => $rowsUpdated,
            'sheet_name' => $sheetName
        ];

    } catch (\Exception $e) {
        Log::error('Google Sheets processing failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Get sheet name based on country code
 */
private function getSheetNameForCountry($countryCode)
{
    $sheets = [
        'LK' => 'Sri Lanka PnL',
        'VN' => 'Vietnam PnL',
        'SG' => 'Singapore PnL',
        'MY' => 'Malaysia PnL',
    ];
    
    return $sheets[$countryCode] ?? 'Master PnL';
}
}