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

class PnLExcelService
{
    private $exchangeRates = [];
    
    public function __construct()
    {
        // Get today's USD to SGD rate (you can update this daily)
        $this->exchangeRates['USD_SGD'] = $this->getTodayUsdToSgdRate();
    }
    
    private function getTodayUsdToSgdRate()
    {
        // Default rate - 1 USD = 1.35 SGD
        // You can replace this with API call to get live rate
        return 1.35;
    }
    
    /**
     * Parse email content and extract line items
     * Each attraction = separate row
     * Each transfer = separate row  
     * Each hotel = separate row
     */
    public function parseEmailToItems(PnlRecord $record)
    {
        $items = [];
        $content = $record->body_html ?: $record->body;
        $plainText = strip_tags($content);
        
        // Determine country from IS number
        $countryCode = $this->detectCountry($record);
        $localCurrency = $this->getLocalCurrency($countryCode);
        $exchangeRate = $this->exchangeRates['USD_SGD'];
        
        // 1. Parse Attractions - EACH ATTRACTION = SEPARATE ROW
        $attractions = $this->parseAttractionsFromEmail($content, $record, $countryCode, $localCurrency, $exchangeRate);
        $items = array_merge($items, $attractions);
        
        // 2. Parse Transfers - EACH TRANSFER = SEPARATE ROW
        $transfers = $this->parseTransfersFromEmail($content, $record, $countryCode, $localCurrency, $exchangeRate);
        $items = array_merge($items, $transfers);
        
        // 3. Parse Hotels - EACH HOTEL = SEPARATE ROW (if multiple hotels)
        $hotels = $this->parseHotelsFromEmail($content, $record, $countryCode, $localCurrency, $exchangeRate);
        $items = array_merge($items, $hotels);
        
        // 4. Parse Other Rates (Packages) - EACH PACKAGE = SEPARATE ROW
        $packages = $this->parseOtherRatesFromEmail($content, $record, $countryCode, $localCurrency, $exchangeRate);
        $items = array_merge($items, $packages);
        
        // 5. Add Total Tour Cost as summary row
        $totalTourCost = $this->extractTotalTourCost($plainText);
        if ($totalTourCost > 0) {
            $items[] = [
                'type' => 'Total Tour Cost',
                'service_name' => 'COMPLETE TOUR PACKAGE',
                'amount_original' => $totalTourCost,
                'currency_original' => 'USD',
                'amount_converted' => round($totalTourCost * $exchangeRate, 2),
                'currency' => $localCurrency,
                'exchange_rate' => $exchangeRate,
                'country_code' => $countryCode,
                'control_number' => $record->is_number,
                'invoice_number' => $record->invoice_number,
                'agent_name' => $record->vendor_name,
                'credit_type' => 'Credit',
                'item_details' => "Total tour cost including all services (Attractions + Transfers + Packages)"
            ];
        }
        
        return $items;
    }
    
    /**
     * Parse attractions - EACH ATTRACTION = SEPARATE ROW
     */
    private function parseAttractionsFromEmail($content, $record, $countryCode, $localCurrency, $exchangeRate)
    {
        $items = [];
        
        // Pattern to match attraction rows
        $pattern = '/Day\s+(\d+)\s+([^\n]+?)\s+([^\n]+?)\s+(\d+)\s+(\d+)\s+(\d+)\s+Adult:\s*([\d.]+)\s*Child:\s*([\d.]+)/i';
        
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $day = $match[1];
            $city = trim($match[2]);
            $attractionName = trim($match[3]);
            $adultCount = intval($match[4]);
            $childCount = intval($match[5]);
            $adultPrice = floatval($match[7]);
            $childPrice = floatval($match[8]);
            
            // Calculate total amount for this attraction
            $totalAmount = ($adultCount * $adultPrice) + ($childCount * $childPrice);
            
            if ($totalAmount > 0) {
                $items[] = [
                    'type' => 'Attraction',
                    'service_name' => substr("{$attractionName}", 0, 200),
                    'hotel_name' => null,
                    'transport_name' => null,
                    'amount_original' => $totalAmount,
                    'currency_original' => 'USD',
                    'amount_converted' => round($totalAmount * $exchangeRate, 2),
                    'currency' => $localCurrency,
                    'exchange_rate' => $exchangeRate,
                    'country_code' => $countryCode,
                    'control_number' => $record->is_number,
                    'invoice_number' => $record->invoice_number,
                    'agent_name' => $record->vendor_name,
                    'credit_type' => 'Credit',
                    'item_details' => "Day {$day}: {$adultCount} Adults @ {$adultPrice} USD, {$childCount} Children @ {$childPrice} USD",
                ];
            }
        }
        
        Log::info("Found " . count($items) . " attractions");
        return $items;
    }
    
    /**
     * Parse transfers - EACH TRANSFER = SEPARATE ROW
     */
    private function parseTransfersFromEmail($content, $record, $countryCode, $localCurrency, $exchangeRate)
    {
        $items = [];
        
        // Pattern for transport transfers
        $pattern = '/Transport\s+\(PVT\)([^\n]+?)\s+([\d.]+)/i';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $transferName = trim(strip_tags($match[1]));
            $amount = floatval($match[2]);
            
            if ($amount > 0) {
                $items[] = [
                    'type' => 'Transfer',
                    'service_name' => substr($transferName, 0, 200),
                    'hotel_name' => null,
                    'transport_name' => $transferName,
                    'amount_original' => $amount,
                    'currency_original' => 'USD',
                    'amount_converted' => round($amount * $exchangeRate, 2),
                    'currency' => $localCurrency,
                    'exchange_rate' => $exchangeRate,
                    'country_code' => $countryCode,
                    'control_number' => $record->is_number,
                    'invoice_number' => $record->invoice_number,
                    'agent_name' => $record->vendor_name,
                    'credit_type' => 'Credit',
                    'item_details' => "Private transfer service",
                ];
            }
        }
        
        Log::info("Found " . count($items) . " transfers");
        return $items;
    }
    
    /**
     * Parse hotels - EACH HOTEL = SEPARATE ROW
     */
    private function parseHotelsFromEmail($content, $record, $countryCode, $localCurrency, $exchangeRate)
    {
        $items = [];
        
        // Look for hotel names in the content
        $hotelPattern = '/([A-Z][a-z\s]+Hotel[^\n]*?)(?:\n|$)/i';
        preg_match_all($hotelPattern, $content, $matches);
        
        $uniqueHotels = [];
        foreach ($matches[1] as $hotelName) {
            $hotelName = trim($hotelName);
            if (!empty($hotelName) && !in_array($hotelName, $uniqueHotels)) {
                $uniqueHotels[] = $hotelName;
            }
        }
        
        // If no hotels found in pattern, try alternative
        if (empty($uniqueHotels)) {
            $hotelPattern2 = '/Hotel\s*[:]\s*([^\n]+)/i';
            preg_match_all($hotelPattern2, $content, $matches2);
            foreach ($matches2[1] as $hotelName) {
                $hotelName = trim($hotelName);
                if (!empty($hotelName) && !in_array($hotelName, $uniqueHotels)) {
                    $uniqueHotels[] = $hotelName;
                }
            }
        }
        
        foreach ($uniqueHotels as $index => $hotelName) {
            // Hotel amount might be in the total tour cost, so we distribute
            $totalTourCost = $this->extractTotalTourCost(strip_tags($content));
            $hotelAmount = $totalTourCost > 0 ? round($totalTourCost / count($uniqueHotels), 2) : 0;
            
            $items[] = [
                'type' => 'Hotel',
                'service_name' => substr($hotelName, 0, 200),
                'hotel_name' => $hotelName,
                'transport_name' => null,
                'amount_original' => $hotelAmount,
                'currency_original' => 'USD',
                'amount_converted' => round($hotelAmount * $exchangeRate, 2),
                'currency' => $localCurrency,
                'exchange_rate' => $exchangeRate,
                'country_code' => $countryCode,
                'control_number' => $record->is_number,
                'invoice_number' => $record->invoice_number,
                'agent_name' => $record->vendor_name,
                'credit_type' => 'Credit',
                'item_details' => "Hotel accommodation",
            ];
        }
        
        Log::info("Found " . count($items) . " hotels");
        return $items;
    }
    
    /**
     * Parse other rates (packages) - EACH PACKAGE = SEPARATE ROW
     */
    private function parseOtherRatesFromEmail($content, $record, $countryCode, $localCurrency, $exchangeRate)
    {
        $items = [];
        
        // Pattern for other rates packages
        $pattern = '/pvt-\s*([^\n]+?)\s+([a-z\s]+):\s*(\d+)\s+([\d.]+)/i';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $packageName = trim($match[1]);
            $paxType = trim($match[2]);
            $paxCount = intval($match[3]);
            $rate = floatval($match[4]);
            $totalAmount = $paxCount * $rate;
            
            if ($totalAmount > 0) {
                $items[] = [
                    'type' => 'Package',
                    'service_name' => substr("{$packageName} ({$paxType})", 0, 200),
                    'hotel_name' => null,
                    'transport_name' => null,
                    'amount_original' => $totalAmount,
                    'currency_original' => 'USD',
                    'amount_converted' => round($totalAmount * $exchangeRate, 2),
                    'currency' => $localCurrency,
                    'exchange_rate' => $exchangeRate,
                    'country_code' => $countryCode,
                    'control_number' => $record->is_number,
                    'invoice_number' => $record->invoice_number,
                    'agent_name' => $record->vendor_name,
                    'credit_type' => 'Credit',
                    'item_details' => "{$paxCount} {$paxType} @ {$rate} USD each",
                ];
            }
        }
        
        return $items;
    }
    
    private function detectCountry(PnlRecord $record)
    {
        $isNumber = $record->is_number;
        $subject = $record->subject;
        
        if (stripos($isNumber, 'SG') !== false || stripos($subject, 'Singapore') !== false) {
            return 'SG';
        } elseif (stripos($isNumber, 'MY') !== false || stripos($subject, 'Malaysia') !== false) {
            return 'MY';
        } elseif (stripos($isNumber, 'VN') !== false || stripos($subject, 'Vietnam') !== false) {
            return 'VN';
        } elseif (stripos($isNumber, 'LK') !== false || stripos($subject, 'Sri Lanka') !== false) {
            return 'LK';
        }
        
        return $record->country_code ?: 'SG';
    }
    
    private function getLocalCurrency($countryCode)
    {
        $currencies = [
            'SG' => 'SGD',
            'MY' => 'MYR',
            'VN' => 'VND',
            'LK' => 'LKR'
        ];
        
        return $currencies[$countryCode] ?? 'SGD';
    }
    
    private function extractTotalTourCost($text)
    {
        // Try to find Total Tour Cost
        if (preg_match('/Total Tour Cost\s*[:]?\s*([0-9,]+\.?[0-9]*)/i', $text, $match)) {
            return floatval(str_replace(',', '', $match[1]));
        }
        
        // Try to find Total amount
        if (preg_match('/Total\s*[:]?\s*\$?\s*([0-9,]+\.?[0-9]*)\s*USD/i', $text, $match)) {
            return floatval(str_replace(',', '', $match[1]));
        }
        
        return 0;
    }
    
    /**
     * Process email and update Excel file
     */
    public function processAndUpdateExcel(PnlRecord $record)
    {
        try {
            // Parse email to get items
            $items = $this->parseEmailToItems($record);
            
            if (empty($items)) {
                return [
                    'success' => false,
                    'message' => 'No line items found in the email to process.'
                ];
            }
            
            // Get Excel file path based on country
            $countryCode = $record->country_code ?: $this->detectCountry($record);
            $excelPath = $this->getExcelFilePath($countryCode);
            
            // Load or create spreadsheet
            $spreadsheet = $this->loadOrCreateSpreadsheet($excelPath, $countryCode);
            
            // Add items to spreadsheet
            $addedCount = $this->addItemsToSpreadsheet($spreadsheet, $items, $record);
            
            // Save the spreadsheet
            $this->saveSpreadsheet($spreadsheet, $excelPath);
            
            // Save items to database
            $savedCount = 0;
            foreach ($items as $itemData) {
                try {
                    PnlItem::updateOrCreate(
                        [
                            'pnl_record_id' => $record->id,
                            'service_name' => $itemData['service_name'],
                            'type' => $itemData['type'],
                        ],
                        [
                            'hotel_name' => $itemData['hotel_name'],
                            'transport_name' => $itemData['transport_name'],
                            'amount_original' => $itemData['amount_original'],
                            'currency' => $itemData['currency'],
                            'exchange_rate' => $itemData['exchange_rate'],
                            'amount_converted' => $itemData['amount_converted'],
                            'country_code' => $itemData['country_code'],
                            'invoice_number' => $itemData['invoice_number'],
                            'agent_name' => $itemData['agent_name'],
                            'credit_type' => $itemData['credit_type'],
                            'item_details' => $itemData['item_details'],
                            'control_number' => $itemData['control_number'],
                            'status' => 'processed',
                        ]
                    );
                    $savedCount++;
                } catch (\Exception $e) {
                    Log::warning("Failed to save item: " . $e->getMessage());
                }
            }
            
            return [
                'success' => true,
                'items_count' => $savedCount,
                'items' => $items,
                'excel_path' => $excelPath,
                'country_code' => $countryCode
            ];
            
        } catch (\Exception $e) {
            Log::error('Excel processing failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to process Excel: ' . $e->getMessage()
            ];
        }
    }
    
    private function getExcelFilePath($countryCode)
    {
        $files = [
            'SG' => storage_path('app/pnl/singapore_pnl.xlsx'),
            'MY' => storage_path('app/pnl/malaysia_pnl.xlsx'),
            'VN' => storage_path('app/pnl/vietnam_pnl.xlsx'),
            'LK' => storage_path('app/pnl/srilanka_pnl.xlsx'),
        ];
        
        $path = $files[$countryCode] ?? storage_path('app/pnl/default_pnl.xlsx');
        
        // Ensure directory exists
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
        
        // Create new spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("PnL - {$countryCode}");
        
        // Set headers
        $headers = [
            'A1' => 'S.No',
            'B1' => 'Date',
            'C1' => 'IS Number',
            'D1' => 'Invoice #',
            'E1' => 'Type',
            'F1' => 'Service Name',
            'G1' => 'Hotel Name',
            'H1' => 'Transport Name',
            'I1' => 'Agent Name',
            'J1' => 'Credit Type',
            'K1' => 'Amount (USD)',
            'L1' => 'Exchange Rate',
            'M1' => 'Amount (' . $this->getLocalCurrency($countryCode) . ')',
            'N1' => 'Status'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // Style headers
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
        ];
        $sheet->getStyle('A1:N1')->applyFromArray($headerStyle);
        
        // Auto-size columns
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        return $spreadsheet;
    }
    
    private function addItemsToSpreadsheet($spreadsheet, $items, $record)
    {
        $sheet = $spreadsheet->getActiveSheet();
        $lastRow = $sheet->getHighestRow();
        $addedCount = 0;
        $currentDate = date('Y-m-d');
        $exchangeRate = $this->exchangeRates['USD_SGD'];
        
        foreach ($items as $index => $item) {
            $row = $lastRow + $index + 1;
            
            $sheet->setCellValue("A{$row}", $row - 1);
            $sheet->setCellValue("B{$row}", $currentDate);
            $sheet->setCellValue("C{$row}", $item['control_number']);
            $sheet->setCellValue("D{$row}", $item['invoice_number'] ?? '-');
            $sheet->setCellValue("E{$row}", $item['type']);
            $sheet->setCellValue("F{$row}", $item['service_name']);
            $sheet->setCellValue("G{$row}", $item['hotel_name'] ?? '');
            $sheet->setCellValue("H{$row}", $item['transport_name'] ?? '');
            $sheet->setCellValue("I{$row}", $item['agent_name']);
            $sheet->setCellValue("J{$row}", $item['credit_type']);
            $sheet->setCellValue("K{$row}", $item['amount_original']);
            $sheet->setCellValue("L{$row}", $exchangeRate);
            $sheet->setCellValue("M{$row}", $item['amount_converted']);
            $sheet->setCellValue("N{$row}", 'Pending');
            
            // Add borders
            $sheet->getStyle("A{$row}:N{$row}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
            
            // Color code by type
            $colorMap = [
                'Attraction' => 'E2F0D9',
                'Transfer' => 'DDEBF7',
                'Hotel' => 'FFF2CC',
                'Package' => 'FCE4D6',
                'Total Tour Cost' => 'D5E8D4'
            ];
            
            if (isset($colorMap[$item['type']])) {
                $sheet->getStyle("A{$row}:N{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($colorMap[$item['type']]);
            }
            
            $addedCount++;
        }
        
        return $addedCount;
    }
    
    private function saveSpreadsheet($spreadsheet, $path)
    {
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        Log::info("Excel file saved: {$path}");
    }
    
    /**
     * Get HTML preview of Excel file
     */
    public function getExcelPreview($countryCode)
    {
        $excelPath = $this->getExcelFilePath($countryCode);
        
        if (!file_exists($excelPath)) {
            return '<div class="alert alert-warning">No Excel file found for this country. Please update some PnL records first.</div>';
        }
        
        $spreadsheet = IOFactory::load($excelPath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        
        $html = '<div class="table-responsive"><table class="table table-bordered table-striped table-sm">';
        $html .= '<thead class="table-dark"><tr>';
        
        // Headers
        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $value = $sheet->getCell($col . '1')->getValue();
            $html .= '<th>' . htmlspecialchars($value) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        
        // Data rows
        for ($row = 2; $row <= min($highestRow, 100); $row++) { // Limit to 100 rows for preview
            $html .= '<tr>';
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $value = $sheet->getCell($col . $row)->getValue();
                $html .= '<td>' . htmlspecialchars($value) . '</td>';
            }
            $html .= '</tr>';
        }
        
        if ($highestRow > 100) {
            $html .= '<tr><td colspan="' . (ord($highestColumn) - 64) . '" class="text-center text-muted">... and ' . ($highestRow - 100) . ' more rows</td></tr>';
        }
        
        $html .= '</tbody></table></div>';
        $html .= '<div class="mt-2 text-muted small">📊 Total rows: ' . ($highestRow - 1) . ' | Last updated: ' . date('Y-m-d H:i:s', filemtime($excelPath)) . '</div>';
        
        return $html;
    }
}