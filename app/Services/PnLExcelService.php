<?php

namespace App\Services;

use App\Models\PnlRecord;
use App\Models\PnlItem;
use Illuminate\Support\Facades\Http;
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
    private $baseCurrency = 'SGD';
    private $apiKey; // Get from env
    
    public function __construct()
    {
        $this->apiKey = env('EXCHANGE_RATE_API_KEY', '');
    }
    
    /**
     * Get exchange rate for a specific currency on a specific date
     */
    public function getExchangeRate($fromCurrency, $toCurrency = 'SGD', $date = null)
    {
        // Cache key for the day
        $cacheKey = "exchange_rate_{$fromCurrency}_{$toCurrency}_" . ($date ?? date('Y-m-d'));
        
        if (isset($this->exchangeRates[$cacheKey])) {
            return $this->exchangeRates[$cacheKey];
        }
        
        $dateStr = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
        
        try {
            // Using exchangerate-api.com (free tier)
            $url = "https://api.exchangerate-api.com/v4/latest/{$fromCurrency}";
            
            $response = Http::timeout(10)->get($url);
            
            if ($response->successful()) {
                $data = $response->json();
                $rate = $data['rates'][$toCurrency] ?? 1;
                
                // Cache for this request
                $this->exchangeRates[$cacheKey] = $rate;
                
                Log::info("Exchange rate fetched: 1 {$fromCurrency} = {$rate} {$toCurrency}");
                return $rate;
            }
        } catch (\Exception $e) {
            Log::warning("Failed to fetch exchange rate: " . $e->getMessage());
        }
        
        // Fallback rates
        $fallbackRates = [
            'USD' => 1.35,
            'MYR' => 0.30,
            'VND' => 0.000057,
            'LKR' => 0.0045,
            'EUR' => 1.45,
            'GBP' => 1.70,
        ];
        
        $rate = $fallbackRates[$fromCurrency] ?? 1;
        $this->exchangeRates[$cacheKey] = $rate;
        
        return $rate;
    }
    
    /**
     * Parse email content and extract line items
     */
    public function parseEmailToItems(PnlRecord $record)
    {
        $items = [];
        $content = $record->body_html ?: $record->body;
        
        // Determine country from IS number or subject
        $countryCode = $this->detectCountry($record);
        
        // Parse attractions/tickets
        $items = array_merge($items, $this->parseAttractions($content, $countryCode));
        
        // Parse transfers
        $items = array_merge($items, $this->parseTransfers($content, $countryCode));
        
        // Parse hotels (if present)
        $items = array_merge($items, $this->parseHotels($content, $countryCode));
        
        // Parse meals
        $items = array_merge($items, $this->parseMeals($content, $countryCode));
        
        // Parse transport
        $items = array_merge($items, $this->parseTransport($content, $countryCode));
        
        return $items;
    }
    
    private function detectCountry(PnlRecord $record)
    {
        $subject = $record->subject;
        $isNumber = $record->is_number;
        
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
    
    private function parseAttractions($content, $countryCode)
    {
        $items = [];
        
        // Pattern to match attraction rows
        $pattern = '/Day\s+(\d+)\s+([^\n]+?)\s+([^\n]+?)\s+(\d+)\s+(\d+)\s+(\d+)\s+Adult:\s*([\d.]+)\s*Child:\s*([\d.]+)/i';
        
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $attractionName = trim($match[3]);
            $adultPrice = floatval($match[7]);
            $childPrice = floatval($match[8]);
            
            // Determine currency (default USD from email, convert to local)
            $originalCurrency = 'USD';
            $localCurrency = $this->getLocalCurrency($countryCode);
            $exchangeRate = $this->getExchangeRate($originalCurrency, $localCurrency);
            
            $items[] = [
                'type' => 'Attraction',
                'name' => $attractionName,
                'day' => intval($match[1]),
                'adults' => intval($match[4]),
                'children' => intval($match[5]),
                'nights' => intval($match[6]),
                'amount_original' => $adultPrice,
                'currency_original' => $originalCurrency,
                'amount_local' => $adultPrice * $exchangeRate,
                'currency_local' => $localCurrency,
                'exchange_rate' => $exchangeRate,
                'exchange_date' => date('Y-m-d'),
                'description' => "Adult: {$adultPrice} {$originalCurrency}, Child: {$childPrice} {$originalCurrency}"
            ];
        }
        
        return $items;
    }
    
    private function parseTransfers($content, $countryCode)
    {
        $items = [];
        
        // Parse transport section
        $pattern = '/Transport\s+\(PVT\)([^\n]+?)\s+([\d.]+)/i';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $transferName = trim($match[1]);
            $amount = floatval($match[2]);
            
            $originalCurrency = 'USD';
            $localCurrency = $this->getLocalCurrency($countryCode);
            $exchangeRate = $this->getExchangeRate($originalCurrency, $localCurrency);
            
            $items[] = [
                'type' => 'Transfer',
                'name' => $transferName,
                'amount_original' => $amount,
                'currency_original' => $originalCurrency,
                'amount_local' => $amount * $exchangeRate,
                'currency_local' => $localCurrency,
                'exchange_rate' => $exchangeRate,
                'exchange_date' => date('Y-m-d'),
                'description' => "Private Transfer"
            ];
        }
        
        return $items;
    }
    
    private function parseHotels($content, $countryCode)
    {
        $items = [];
        
        // Look for hotel information (customize based on your email format)
        $hotelPattern = '/Hotel[:\s]+([^\n]+?)(?:\n|$)/i';
        preg_match_all($hotelPattern, $content, $matches);
        
        foreach ($matches[1] as $hotelName) {
            $items[] = [
                'type' => 'Hotel',
                'name' => trim($hotelName),
                'amount_original' => 0, // Will be updated when found in pricing
                'currency_original' => 'USD',
                'amount_local' => 0,
                'currency_local' => $this->getLocalCurrency($countryCode),
                'exchange_rate' => 1,
                'exchange_date' => date('Y-m-d'),
                'description' => 'Hotel accommodation'
            ];
        }
        
        return $items;
    }
    
    private function parseMeals($content, $countryCode)
    {
        $items = [];
        
        // Parse meals section
        $mealPattern = '/Day\s+-\s+(\d+)\s+Adult[^:]*:\s*([\d.]+)\s*\*\s*(\d+)[^C]*Child[^:]*:\s*([\d.]+)\s*\*\s*(\d+)/i';
        preg_match_all($mealPattern, $content, $matches, PREG_SET_ORDER);
        
        $localCurrency = $this->getLocalCurrency($countryCode);
        
        foreach ($matches as $match) {
            $adultRate = floatval($match[2]);
            $adultCount = intval($match[3]);
            $childRate = floatval($match[4]);
            $childCount = intval($match[5]);
            
            $totalAmount = ($adultRate * $adultCount) + ($childRate * $childCount);
            
            if ($totalAmount > 0) {
                $items[] = [
                    'type' => 'Meals',
                    'name' => "Day {$match[1]} Meals",
                    'amount_original' => $totalAmount,
                    'currency_original' => 'USD',
                    'amount_local' => $totalAmount,
                    'currency_local' => $localCurrency,
                    'exchange_rate' => 1,
                    'exchange_date' => date('Y-m-d'),
                    'description' => "Breakfast/Lunch/Dinner"
                ];
            }
        }
        
        return $items;
    }
    
    private function parseTransport($content, $countryCode)
    {
        $items = [];
        
        // Parse Other Rates section
        $pattern = '/pvt- us\+ns\+ct\+sent\+\s+([^:]+):\s*([\d.]+)/i';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        
        $localCurrency = $this->getLocalCurrency($countryCode);
        
        foreach ($matches as $match) {
            $paxType = trim($match[1]);
            $amount = floatval($match[2]);
            
            $items[] = [
                'type' => 'Transport Package',
                'name' => "PVT Package - {$paxType}",
                'amount_original' => $amount,
                'currency_original' => 'USD',
                'amount_local' => $amount,
                'currency_local' => $localCurrency,
                'exchange_rate' => 1,
                'exchange_date' => date('Y-m-d'),
                'description' => "US+NS+CT+SENT+ Package"
            ];
        }
        
        return $items;
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
            
            // Get or create Excel file path
            $excelPath = $this->getExcelFilePath($record->country_code);
            
            // Load or create spreadsheet
            $spreadsheet = $this->loadOrCreateSpreadsheet($excelPath, $record->country_code);
            
            // Add items to spreadsheet
            $addedCount = $this->addItemsToSpreadsheet($spreadsheet, $items, $record);
            
            // Save the spreadsheet
            $this->saveSpreadsheet($spreadsheet, $excelPath);
            
            // Save items to database for reference
            foreach ($items as $itemData) {
                PnlItem::updateOrCreate(
                    [
                        'pnl_record_id' => $record->id,
                        'type' => $itemData['type'],
                        'service_name' => $itemData['name'],
                    ],
                    [
                        'hotel_name' => $itemData['type'] == 'Hotel' ? $itemData['name'] : null,
                        'transport_name' => $itemData['type'] == 'Transfer' ? $itemData['name'] : null,
                        'amount_original' => $itemData['amount_original'],
                        'currency' => $itemData['currency_local'],
                        'exchange_rate_used' => $itemData['exchange_rate'],
                        'status' => 'processed',
                    ]
                );
            }
            
            return [
                'success' => true,
                'items_count' => count($items),
                'items' => $items
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
        
        // Set headers
        $headers = [
            'A1' => 'S.No',
            'B1' => 'Date',
            'C1' => 'IS Number',
            'D1' => 'Confirmation #',
            'E1' => 'Start Date',
            'F1' => 'End Date',
            'G1' => 'Type',
            'H1' => 'Credit/Non Credit',
            'I1' => 'Agent Name',
            'J1' => 'Client Name',
            'K1' => 'Service Name',
            'L1' => 'Total Cost (Original)',
            'M1' => 'Original Currency',
            'N1' => 'Exchange Rate',
            'O1' => 'Total Cost (Local)',
            'P1' => 'Local Currency',
            'Q1' => 'Status'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // Style headers
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ];
        $sheet->getStyle('A1:Q1')->applyFromArray($headerStyle);
        
        // Auto-size columns
        foreach (range('A', 'Q') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        return $spreadsheet;
    }
    
    private function addItemsToSpreadsheet($spreadsheet, $items, $record)
    {
        $sheet = $spreadsheet->getActiveSheet();
        $lastRow = $sheet->getHighestRow();
        $addedCount = 0;
        
        // Get the next S.No
        $nextSno = $lastRow;
        
        foreach ($items as $item) {
            $nextSno++;
            $row = $nextSno;
            
            $sheet->setCellValue("A{$row}", $nextSno);
            $sheet->setCellValue("B{$row}", date('Y-m-d'));
            $sheet->setCellValue("C{$row}", $record->is_number);
            $sheet->setCellValue("D{$row}", $record->invoice_number ?? '-');
            $sheet->setCellValue("E{$row}", ''); // Start date - to be filled manually
            $sheet->setCellValue("F{$row}", ''); // End date - to be filled manually
            $sheet->setCellValue("G{$row}", $item['type']);
            $sheet->setCellValue("H{$row}", 'Credit'); // Default to Credit
            $sheet->setCellValue("I{$row}", $record->vendor_name);
            $sheet->setCellValue("J{$row}", ''); // Client name - to be filled
            $sheet->setCellValue("K{$row}", $item['name']);
            $sheet->setCellValue("L{$row}", $item['amount_original']);
            $sheet->setCellValue("M{$row}", $item['currency_original']);
            $sheet->setCellValue("N{$row}", $item['exchange_rate']);
            $sheet->setCellValue("O{$row}", $item['amount_local']);
            $sheet->setCellValue("P{$row}", $item['currency_local']);
            $sheet->setCellValue("Q{$row}", 'Pending');
            
            // Add borders
            $sheet->getStyle("A{$row}:Q{$row}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
            
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
}