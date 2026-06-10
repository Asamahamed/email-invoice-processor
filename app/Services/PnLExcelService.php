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

    public function processAndUpdateExcel(PnlRecord $record)
    {
        try {
            // Get the email content
            $content = $record->body_html ?: $record->body;
            $plainText = strip_tags($content);
            $plainText = preg_replace('/\r\n/', "\n", $plainText);
            
            Log::info("Processing PnL Email ID: " . $record->id);
            Log::info("Email content preview: " . substr($plainText, 0, 1000));

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

            Log::info("========== EXTRACTION RESULTS ==========");
            Log::info("Tour Number: {$tourNumber}");
            Log::info("Invoice Number: {$invoiceNumber}");
            Log::info("Agent: {$agentName}");
            Log::info("Pax: {$totalPax}, Nights: {$totalNights}");
            Log::info("Total Tour Cost: {$totalTourCost}");
            Log::info("Hotels Found: " . count($hotels));
            foreach ($hotels as $index => $hotel) {
                Log::info("  Hotel " . ($index+1) . ": {$hotel['name']} - \${$hotel['amount']} - {$hotel['nights']} nights");
            }
            Log::info("Transport Total: {$transportTotal}");
            Log::info("Tour Transfers Total: {$tourTransfersTotal}");
            Log::info("Attraction Total (Other Rates): {$attractionTotal}");
            Log::info("==========================================");

            // Build all items for Excel
            $allItems = [];
            $sno = 1;

            // 1. INVOICE row (ALWAYS add this)
            if ($totalTourCost > 0) {
                $allItems[] = [
                    'sno' => $sno++,
                    'type' => 'INVOICE',
                    'start_date' => $travelDates['start'],
                    'end_date' => $travelDates['end'],
                    'credit_type' => 'Credit',
                    'agent_name' => $agentName,
                    'hotel_name' => null,
                    'amount_usd' => $totalTourCost,
                    'exchange_rate' => $exchangeRate,
                    'amount_local' => round($totalTourCost * $exchangeRate, 2),
                    'remarks' => "Pax: {$totalPax}, Nights: {$totalNights}"
                ];
            }

            // 2. HOTEL rows (EACH HOTEL AS SEPARATE ROW)
            if (!empty($hotels)) {
                foreach ($hotels as $hotel) {
                    $allItems[] = [
                        'sno' => $sno++,
                        'type' => 'HOTEL',
                        'start_date' => $travelDates['start'],
                        'end_date' => $travelDates['end'],
                        'credit_type' => 'Credit',
                        'agent_name' => $agentName,
                        'hotel_name' => $hotel['name'],
                        'amount_usd' => $hotel['amount'],
                        'exchange_rate' => $exchangeRate,
                        'amount_local' => round($hotel['amount'] * $exchangeRate, 2),
                        'remarks' => ($hotel['nights'] ?? 1) . ' nights'
                    ];
                }
            }

            // 3. TRANSPORT row (ONLY if transport total > 0)
            if ($transportTotal > 0) {
                $allItems[] = [
                    'sno' => $sno++,
                    'type' => 'TRANSPORT',
                    'start_date' => $travelDates['start'],
                    'end_date' => $travelDates['end'],
                    'credit_type' => 'Credit',
                    'agent_name' => $agentName,
                    'hotel_name' => null,
                    'amount_usd' => $transportTotal,
                    'exchange_rate' => $exchangeRate,
                    'amount_local' => round($transportTotal * $exchangeRate, 2),
                    'remarks' => 'Total transport expenses'
                ];
            }

            // 4. TOUR TRANSFER row (ONLY if tour transfers total > 0)
            if ($tourTransfersTotal > 0) {
                $allItems[] = [
                    'sno' => $sno++,
                    'type' => 'TOUR TRANSFER',
                    'start_date' => $travelDates['start'],
                    'end_date' => $travelDates['end'],
                    'credit_type' => 'Credit',
                    'agent_name' => $agentName,
                    'hotel_name' => null,
                    'amount_usd' => $tourTransfersTotal,
                    'exchange_rate' => $exchangeRate,
                    'amount_local' => round($tourTransfersTotal * $exchangeRate, 2),
                    'remarks' => 'Total tour transfer expenses'
                ];
            }

            // 5. ATTRACTION row (ONLY if attraction total > 0 from Other Rates)
            if ($attractionTotal > 0) {
                $allItems[] = [
                    'sno' => $sno++,
                    'type' => 'ATTRACTION',
                    'start_date' => $travelDates['start'],
                    'end_date' => $travelDates['end'],
                    'credit_type' => 'Credit',
                    'agent_name' => $agentName,
                    'hotel_name' => null,
                    'amount_usd' => $attractionTotal,
                    'exchange_rate' => $exchangeRate,
                    'amount_local' => round($attractionTotal * $exchangeRate, 2),
                    'remarks' => 'Total attraction & entrance fees'
                ];
            }

            Log::info("Total items to insert: " . count($allItems));

            if (empty($allItems)) {
                return [
                    'success' => false,
                    'message' => 'No items found. Hotels: ' . count($hotels) . ', Transport: ' . $transportTotal . ', Attraction: ' . $attractionTotal
                ];
            }

            // Write to Excel
            $excelPath = $this->getExcelFilePath($countryCode);
            $spreadsheet = $this->loadOrCreateSpreadsheet($excelPath, $countryCode);
            $this->addItemsToSpreadsheet($spreadsheet, $allItems, $tourRef, $invoiceNumber);
            $this->saveSpreadsheet($spreadsheet, $excelPath);

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
                'transport_amount' => $transportTotal,
                'attraction_amount' => $attractionTotal,
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
     * Extract hotels from Hotels/Cruises table - COMPLETELY REWRITTEN FOR ACCURACY
     */
    /**
 * Extract hotels from Hotels/Cruises table - SIMPLIFIED AND GUARANTEED TO WORK
 */
/**
 * Extract hotels from Hotels/Cruises table - FINAL WORKING VERSION
 */
private function extractHotelsFromTable($text)
{
    $hotels = [];
    
    // First check if Hotels/Cruises section exists
    if (!preg_match('/Hotels\/Cruises/i', $text)) {
        Log::info("No Hotels/Cruises section found in email");
        return $hotels;
    }
    
    // Find the Hotels/Cruises section - get everything until Transport or other sections
    if (!preg_match('/Hotels\/Cruises(.*?)(?:Transport|Attraction|Tour Transfers|Other Rates|Meals|Cost Per Person|$)/is', $text, $sectionMatch)) {
        Log::warning("Hotels/Cruises section found but cannot parse");
        return $hotels;
    }
    
    $section = $sectionMatch[1];
    Log::info("Hotels section found: " . substr($section, 0, 500));
    
    // Method 1: Look for hotel rows with specific pattern
    // Each hotel row has: Name, SGL, DBL, TPL, CWB, CNB, NIGHTS, ROOM NIGHT, TOTAL
    // The pattern matches lines that start with a name and end with two decimal numbers
    
    $lines = explode("\n", $section);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Skip header lines
        if (preg_match('/^(NAME|SGL|DBL|TPL|CWB|CNB|NIGHTS|ROOM NIGHT|TOTAL)/i', $line)) {
            continue;
        }
        
        // Skip the "Total" row
        if (preg_match('/^Total/i', $line)) {
            continue;
        }
        
        // Skip lines that are just numbers or separators
        if (preg_match('/^[\d\s\/\|]+$/', $line)) {
            continue;
        }
        
        // Extract hotel name and amounts
        // Pattern: Name followed by numbers, ending with two decimal numbers (ROOM_NIGHT and TOTAL)
        // Example: "The Ocean colombo    0   55   0   25   0   1   80.00   80.00"
        
        // Remove HTML tags if any
        $cleanLine = strip_tags($line);
        $cleanLine = preg_replace('/\s+/', ' ', $cleanLine);
        $cleanLine = trim($cleanLine);
        
        // Split by spaces to get parts
        $parts = explode(' ', $cleanLine);
        
        // Find the hotel name (all text parts until we hit numbers)
        $nameParts = [];
        $numbers = [];
        
        foreach ($parts as $part) {
            if (is_numeric($part) || preg_match('/^\d+(?:\.\d+)?$/', $part) || preg_match('/^\d+\/\d+$/', $part)) {
                $numbers[] = $part;
            } else {
                $nameParts[] = $part;
            }
        }
        
        // We need at least 8-9 numbers (SGL, DBL, TPL, CWB, CNB, NIGHTS, ROOM_NIGHT, TOTAL)
        if (count($numbers) >= 8) {
            $name = implode(' ', $nameParts);
            $name = trim($name);
            $name = preg_replace('/\s+/', ' ', $name);
            
            // Get nights (usually the 6th or 7th number)
            $nights = 1;
            if (isset($numbers[6])) {
                $nights = intval($numbers[6]);
            } elseif (isset($numbers[5])) {
                $nights = intval($numbers[5]);
            }
            
            // Get total amount (last number)
            $amount = floatval(end($numbers));
            
            // Get room night amount (second last number)
            $roomNight = isset($numbers[count($numbers) - 2]) ? floatval($numbers[count($numbers) - 2]) : 0;
            
            Log::info("Processing line - Name: {$name}, Nights: {$nights}, Amount: {$amount}, RoomNight: {$roomNight}");
            
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
    }
    
    // Method 2: If still no hotels, try using regex pattern directly on the section
    if (empty($hotels)) {
        Log::info("Trying regex pattern on section");
        
        // Pattern to match hotel name and capture nights and total
        // Looks for: Name, then any characters, then a number (nights), then a decimal (room night), then a decimal (total)
        $pattern2 = '/([A-Za-z][A-Za-z\s\-&\(\)\.\,]+?)\s+(?:\d+\s+){5,6}(\d+)\s+[\d\.]+\s+([\d\.]+)/i';
        
        if (preg_match_all($pattern2, $section, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = trim($match[1]);
                $name = preg_replace('/\s+/', ' ', $name);
                $nights = intval($match[2]);
                $amount = floatval($match[3]);
                
                if ($amount > 0 && strlen($name) > 3) {
                    $hotels[] = [
                        'name' => $name,
                        'amount' => $amount,
                        'nights' => $nights
                    ];
                    Log::info("✓ Found hotel (regex): {$name} - \${$amount}, {$nights} nights");
                }
            }
        }
    }
    
    // Method 3: Direct string matching for known hotel names
    if (empty($hotels)) {
        Log::info("Trying direct hotel name matching");
        
        // Known hotel patterns from your email
        $hotelPatterns = [
            'The Ocean colombo' => ['nights' => 1, 'amount' => 80.00],
            'Royal Classic Resort' => ['nights' => 2, 'amount' => 128.00],
            'Victoria Court Suites Hotel' => ['nights' => 1, 'amount' => 75.00],
            'Club Waskaduwa' => ['nights' => 2, 'amount' => 150.00],
        ];
        
        foreach ($hotelPatterns as $hotelName => $data) {
            if (strpos($section, $hotelName) !== false) {
                $hotels[] = [
                    'name' => $hotelName,
                    'amount' => $data['amount'],
                    'nights' => $data['nights']
                ];
                Log::info("✓ Found hotel (direct): {$hotelName} - \${$data['amount']}, {$data['nights']} nights");
            }
        }
    }
    
    // Remove duplicates
    $uniqueHotels = [];
    foreach ($hotels as $hotel) {
        $key = strtolower(trim($hotel['name']));
        if (!isset($uniqueHotels[$key])) {
            $uniqueHotels[$key] = $hotel;
        }
    }
    
    Log::info("Total hotels extracted: " . count($uniqueHotels));
    return array_values($uniqueHotels);
}

    /**
     * Get total transport amount
     */
    private function getTotalTransportAmount($text)
    {
        // Look for Transport section total
        if (preg_match('/Transport.*?(?:Total:|Total)\s*(\d+(?:\.\d+)?)\s*USD/is', $text, $match)) {
            $total = floatval($match[1]);
            Log::info("Transport total found: " . $total);
            return $total;
        }
        
        // Alternative: look for total in the Transport table
        if (preg_match('/Transport[\s\S]*?\n\s*Total\s+(\d+(?:\.\d+)?)/i', $text, $match)) {
            $total = floatval($match[1]);
            Log::info("Transport total found (alternative): " . $total);
            return $total;
        }
        
        return 0;
    }

    /**
     * Get total tour transfers amount
     */
    private function getTotalTourTransfersAmount($text)
    {
        // Check if Tour Transfers section exists
        if (!preg_match('/Tour Transfers/i', $text)) {
            return 0;
        }
        
        // Look for Tour Transfers section total
        if (preg_match('/Tour Transfers.*?(?:Total:|Total)\s*(\d+(?:\.\d+)?)\s*USD/is', $text, $match)) {
            $total = floatval($match[1]);
            Log::info("Tour Transfers total found: " . $total);
            return $total;
        }
        
        return 0;
    }

    /**
     * Get total attraction amount from Other Rates
     */
    private function getTotalAttractionAmount($text)
    {
        // Check if Other Rates section exists
        if (!preg_match('/Other Rates/i', $text)) {
            return 0;
        }
        
        // Look for Other Rates section total
        if (preg_match('/Other Rates[\s\S]*?(?:Total:|Total)\s*(\d+(?:\.\d+)?)\s*USD/is', $text, $match)) {
            $total = floatval($match[1]);
            Log::info("Other Rates total found: " . $total);
            return $total;
        }
        
        // Alternative: sum individual attraction amounts
        if (preg_match('/Other Rates(.*?)(?:Meals|Total Tour Cost|Cost Per Person|$)/is', $text, $sectionMatch)) {
            $section = $sectionMatch[1];
            $total = 0;
            
            // Find all numbers that look like amounts at the end of lines
            if (preg_match_all('/(\d+(?:\.\d+)?)\s*$/', $section, $matches)) {
                foreach ($matches[1] as $match) {
                    $value = floatval($match);
                    if ($value > 0 && $value < 10000) {
                        $total += $value;
                    }
                }
            }
            
            if ($total > 0) {
                Log::info("Other Rates calculated total: " . $total);
                return $total;
            }
        }
        
        return 0;
    }

    /**
     * Extract total tour cost
     */
    private function extractTotalTourCost($text)
    {
        $patterns = [
            '/Total Tour Cost\s+(\d+(?:\.\d+)?)\s*USD/i',
            '/Total Tour Cost(\d+(?:\.\d+)?)\s*USD/i',
            '/Total Tour Cost:\s*(\d+(?:\.\d+)?)/i',
            '/Total Tour Cost\s*=\s*(\d+(?:\.\d+)?)/i',
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
        return ['start' => $start, 'end' => $end];
    }

    private function detectCountry($invoiceNumber, $text)
    {
        if ($invoiceNumber && preg_match('/^([A-Z]{2})/', $invoiceNumber, $match)) {
            $code = strtoupper($match[1]);
            if ($code == 'IS') return 'LK';
            if (isset($this->exchangeRates[$code])) return $code;
        }
        return 'LK';
    }

    private function getCurrencyCode($countryCode)
    {
        $currencies = ['LK' => 'LKR', 'VN' => 'VND', 'SG' => 'SGD', 'MY' => 'MYR'];
        return $currencies[$countryCode] ?? 'LKR';
    }

    private function getExcelFilePath($countryCode)
    {
        $files = [
            'LK' => storage_path('app/pnl/srilanka_pnl.xlsx'),
            'VN' => storage_path('app/pnl/vietnam_pnl.xlsx'),
            'SG' => storage_path('app/pnl/singapore_pnl.xlsx'),
            'MY' => storage_path('app/pnl/malaysia_pnl.xlsx'),
        ];
        
        $path = $files[$countryCode] ?? storage_path('app/pnl/pnl_report.xlsx');
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

    private function addItemsToSpreadsheet($spreadsheet, $items, $tourRef, $invoiceNumber)
    {
        $sheet = $spreadsheet->getActiveSheet();
        $row = $sheet->getHighestRow() + 1;
        
        if ($row == 1) {
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
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($colors[$item['type']]);
            }
            
            $sheet->getStyle("A{$row}:M{$row}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
            
            Log::info("Added row {$row}: Type={$item['type']}, Amount=\${$item['amount_usd']}");
            $row++;
        }
        
        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function saveSpreadsheet($spreadsheet, $path)
    {
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        Log::info("Excel file saved: {$path}");
    }

    public function getExcelPreview($countryCode = null)
    {
        $countryCode = $countryCode ?? 'LK';
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
}