<?php

namespace App\Services;

use App\Models\PnlRecord;
use App\Models\PnlItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PnlEmailService
{
    protected $accessToken;
    
    private $exchangeRates = [
        'LK' => 330,
        'VN' => 25500,
        'SG' => 1.35,
        'MY' => 4.70,
    ];
    
    public function __construct()
    {
        $this->authenticate();
    }
    
    protected function authenticate()
    {
        try {
            $response = Http::asForm()->post(
                'https://login.microsoftonline.com/' . env('GRAPH_TENANT_ID') . '/oauth2/v2.0/token',
                [
                    'client_id' => env('GRAPH_CLIENT_ID'),
                    'client_secret' => env('GRAPH_CLIENT_SECRET'),
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]
            );
            
            if ($response->ok()) {
                $this->accessToken = $response->json()['access_token'];
                Log::info('PnL Graph authenticated successfully');
                return true;
            }
        } catch (\Exception $e) {
            Log::error('PnL Graph auth error: ' . $e->getMessage());
        }
        return false;
    }
    
public function fetchPnLEmails()
{
    try {
        // STEP 1: Get all existing message IDs from database (FAST)
        $existingIds = PnlRecord::pluck('message_id')->toArray();
        Log::info("📊 Existing emails in DB: " . count($existingIds));
        
        // STEP 2: Fetch ONLY the last 50 emails (LIMITED)
        $url = 'https://graph.microsoft.com/v1.0/users/' . env('GRAPH_PNL_USER') . '/messages'
            . '?$top=50'  // ONLY 50 emails!
            . '&$orderby=receivedDateTime desc'
            . '&$select=id,subject,body,bodyPreview,from,receivedDateTime,isRead,hasAttachments';
        
        Log::info("📧 Fetching latest 50 emails only...");
        
        $response = Http::withToken($this->accessToken)
            ->timeout(30)  // 30 seconds timeout
            ->get($url);
        
        if (!$response->ok()) {
            Log::error('Failed to fetch emails: ' . $response->body());
            return 0;
        }
        
        $data = $response->json();
        $messages = $data['value'] ?? [];
        
        Log::info("📬 Found " . count($messages) . " emails in API");
        
        // STEP 3: Filter to find NEW emails only (FAST)
        $newMessages = [];
        foreach ($messages as $message) {
            if (!in_array($message['id'], $existingIds)) {
                $newMessages[] = $message;
            }
        }
        
        Log::info("🆕 New emails to process: " . count($newMessages));
        
        // STEP 4: If no new emails, return early (NO PROCESSING)
        if (empty($newMessages)) {
            Log::info("📭 No new emails to process");
            return 0;
        }
        
        // STEP 5: Process ONLY new emails
        $sno = PnlRecord::max('sno') ?? 0;
        $newCount = 0;
        $failedEmails = [];
        
        foreach ($newMessages as $message) {
            $sno++;
            Log::info("📝 Processing: " . ($message['subject'] ?? 'No Subject'));
            
            try {
                // Fetch FULL body ONLY for new emails
                $fullMessage = $this->fetchFullMessage($message['id']);
                if ($fullMessage) {
                    $saved = $this->savePnLEmail($fullMessage, $sno);
                    if ($saved) {
                        $newCount++;
                        Log::info("✅ Saved: " . ($message['subject'] ?? 'No Subject'));
                    } else {
                        $failedEmails[] = $message['subject'] ?? 'Unknown';
                        Log::error("❌ FAILED to save: " . ($message['subject'] ?? 'No Subject'));
                    }
                }
            } catch (\Exception $e) {
                $failedEmails[] = $message['subject'] ?? 'Unknown';
                Log::error("❌ EXCEPTION: " . $e->getMessage());
            }
        }
        
        Log::info("📊 SUMMARY: " . $newCount . " new emails saved, " . count($failedEmails) . " failed");
        
        return $newCount;
        
    } catch (\Exception $e) {
        Log::error('Error fetching PnL emails: ' . $e->getMessage());
        return 0;
    }
}
    
protected function savePnLEmail($message, $sno)
{
    try {
        $subject = $message['subject'] ?? 'No Subject';
        $htmlBody = $message['body']['content'] ?? $message['bodyPreview'] ?? '';
        
        $plainText = strip_tags($htmlBody);
        $plainText = preg_replace('/\r\n/', "\n", $plainText);
        
        Log::info("Processing email: " . $subject);
        
        $fromEmail = $message['from']['emailAddress']['address'] ?? '';
        $fromName = $message['from']['emailAddress']['name'] ?? '';
        $receivedAt = Carbon::parse($message['receivedDateTime']);
        $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
        
        // ========== EXTRACT HEADER DATA ==========
        $tourNumber = null;
        if (preg_match('/Tour No:\s*#?(\d+)/i', $plainText, $match)) {
            $tourNumber = $match[1];
        }
        
        $isNumber = null;
        if (preg_match('/Is Number:\s*([A-Z]{2})\s*(\d+)/i', $plainText, $match)) {
            $isNumber = $match[1] . $match[2];
        }
        
        // ========== SET COUNTRY CODE ==========
        $countryCode = 'VN';
        if ($isNumber && strpos($isNumber, 'IS') === 0) {
            $countryCode = 'LK';
        } elseif ($isNumber && strpos($isNumber, 'VN') === 0) {
            $countryCode = 'VN';
        } elseif ($isNumber && strpos($isNumber, 'SG') === 0) {
            $countryCode = 'SG';
        } elseif ($isNumber && strpos($isNumber, 'MY') === 0) {
            $countryCode = 'MY';
        }
        
        $agentName = 'Unknown';
        if (preg_match('/Agent:\s*([^\n]+?)(?:\s+No\.|\s+Currency|$)/i', $plainText, $match)) {
            $agentName = trim($match[1]);
        }
        
        $totalPax = 0;
        if (preg_match('/No\.\s*Adult:\s*(\d+)/i', $plainText, $match)) {
            $totalPax = intval($match[1]);
        } elseif (preg_match('/No\.\s*Pax:\s*(\d+)/i', $plainText, $match)) {
            $totalPax = intval($match[1]);
        }
        
        $totalNights = 0;
        if (preg_match('/No\.\s*Night:\s*(\d+)/i', $plainText, $match)) {
            $totalNights = intval($match[1]);
        }
        
        // Extract Total Tour Cost
        $totalTourCost = 0;
        if (preg_match('/Total Tour Cost\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/i', $plainText, $match)) {
            $totalTourCost = floatval(str_replace(',', '', $match[1]));
        }
        
        // Extract Profit/Loss
        $profitLoss = $this->extractProfitLossFromEmail($htmlBody);
        if ($profitLoss === null) {
            $profitLoss = $this->extractProfitLossFromEmail($plainText);
        }
        
        // ========== EXTRACT CATEGORIES ==========
        $categoriesFound = [];
        $pnlItemsToSave = [];
        
        // 1. Hotels/Cruises
        if (preg_match('/Hotels\/Cruises/i', $plainText)) {
            $hotels = $this->extractHotelsFromEmail($htmlBody);
            if (!empty($hotels)) {
                $categoriesFound[] = 'Hotels/Cruises';
                foreach ($hotels as $hotel) {
                    $pnlItemsToSave[] = [
                        'type' => 'HOTEL',
                        'service_name' => $hotel['name'],
                        'hotel_name' => $hotel['name'],
                        'amount' => $hotel['amount'],
                        'details' => ['nights' => $hotel['nights'], 'remarks' => $hotel['nights'] . ' nights']
                    ];
                }
            }
        }
        
        // 2. Transport - Extract INDIVIDUAL ITEMS
        $transportItems = $this->extractTransportItemsForCountry($htmlBody, $plainText, $countryCode);

        if (!empty($transportItems)) {
            $categoriesFound[] = 'Transport';
            foreach ($transportItems as $transport) {
                $pnlItemsToSave[] = [
                    'type' => 'TRANSPORT',
                    'service_name' => $transport['service_name'],
                    'hotel_name' => null,
                    'amount' => $transport['amount'],
                    'details' => $transport['details']
                ];
                Log::info("✅ Added transport item: {$transport['service_name']} - \${$transport['amount']}");
            }
        }
        
        // ✅ FIX 1: Calculate transport total
        $transportTotal = 0;
        foreach ($transportItems as $item) {
            $transportTotal += $item['amount'];
        }
        
        // 3. Other Rates - Country specific
        $otherRatesTotal = 0;
        if ($countryCode == 'VN' || $countryCode == 'SG' || $countryCode == 'MY') {
            $otherRatesTotal = $this->extractOtherRatesTotalForSouthEastAsia($htmlBody);
            if ($otherRatesTotal == 0) {
                $otherRatesTotal = $this->extractOtherRatesTotalFromEmail($plainText);
            }
        } else {
            $otherRatesTotal = $this->extractOtherRatesTotalFromEmail($plainText);
        }
        
        // ✅ FIX 2: Use $transportTotal which is now defined
        if ($otherRatesTotal > 0 && $otherRatesTotal != $transportTotal) {
            $categoriesFound[] = 'Other Rates';
            $pnlItemsToSave[] = [
                'type' => 'OTHER RATES',
                'service_name' => 'Other Rates (Entrance Tickets, etc.)',
                'hotel_name' => null,
                'amount' => $otherRatesTotal,
                'details' => ['remarks' => 'Other attraction & entrance fees']
            ];
        }
        
        // 4. Attraction
        $attractionTotal = $this->extractAttractionTotalFromEmail($plainText);
        if ($attractionTotal > 0) {
            $categoriesFound[] = 'Attraction';
            $pnlItemsToSave[] = [
                'type' => 'ATTRACTION',
                'service_name' => 'Attractions Total',
                'hotel_name' => null,
                'amount' => $attractionTotal,
                'details' => ['remarks' => 'Total attraction & entrance fees']
            ];
        }
        
        // 5. Tour Transfers
        $tourTransfersTotal = $this->extractTourTransfersTotalFromEmail($plainText);
        if ($tourTransfersTotal > 0) {
            $categoriesFound[] = 'Tour Transfers';
            $pnlItemsToSave[] = [
                'type' => 'TOUR TRANSFER',
                'service_name' => 'Tour Transfer Expenses',
                'hotel_name' => null,
                'amount' => $tourTransfersTotal,
                'details' => ['remarks' => 'Total tour transfer expenses']
            ];
        }
        
        // 6. Meals
        $mealsTotal = $this->extractMealsTotalFromEmail($plainText);
        if ($mealsTotal > 0) {
            $categoriesFound[] = 'Meals';
            $pnlItemsToSave[] = [
                'type' => 'MEALS',
                'service_name' => 'Meals Expenses',
                'hotel_name' => null,
                'amount' => $mealsTotal,
                'details' => ['remarks' => 'Total meals expenses']
            ];
        }
        
        $categoriesString = implode(', ', $categoriesFound);
        $exchangeRate = $this->exchangeRates[$countryCode] ?? 25500;
        $tourRef = $tourNumber ? $tourNumber . 'CNTL' : null;
        
        // ========== CREATE MAIN RECORD ==========
        $record = PnlRecord::create([
            'sno' => $sno,
            'message_id' => $message['id'],
            'from_email' => $fromEmail,
            'from_address' => $fromName,
            'from_name' => $fromName,
            'subject' => $subject,
            'body' => $plainText,
            'body_html' => $htmlBody,
            'received_at' => $receivedAt,
            'vendor_name' => $fromName ?: 'Apple Holidays',
            'invoice_number' => $isNumber,
            'is_number' => $isNumber,
            'amount' => $totalTourCost,
            'profit_loss' => $profitLoss,
            'currency' => 'USD',
            'country_code' => $countryCode,
            'exchange_rate_used' => $exchangeRate,
            'category' => $categoriesString,
            'status' => 'pending',
            'read_status' => $readStatus,
            'has_attachments' => $message['hasAttachments'] ?? false,
            'agent_name' => $agentName,
            'tour_ref' => $tourRef,
            'total_pax' => $totalPax,
            'total_nights' => $totalNights,
        ]);
        
        // ========== SAVE INVOICE ITEM ==========
        if ($totalTourCost > 0) {
            PnlItem::create([
                'pnl_record_id' => $record->id,
                'control_number' => $tourRef,
                'invoice_number' => $isNumber,
                'type' => 'INVOICE',
                'credit_type' => 'Credit',
                'agent_name' => $agentName,
                'hotel_name' => null,
                'service_name' => 'Total Tour Package',
                'country_code' => $countryCode,
                'currency' => 'USD',
                'amount_original' => $totalTourCost,
                'exchange_rate' => 1,
                'amount_converted' => $totalTourCost,
                'item_details' => json_encode(['remarks' => "Pax: {$totalPax}, Nights: {$totalNights}"]),
            ]);
        }
        
        // ========== SAVE PNL ITEMS ==========
        foreach ($pnlItemsToSave as $item) {
            PnlItem::create([
                'pnl_record_id' => $record->id,
                'control_number' => $tourRef,
                'invoice_number' => $isNumber,
                'type' => $item['type'],
                'credit_type' => 'Credit',
                'agent_name' => $agentName,
                'hotel_name' => $item['hotel_name'],
                'service_name' => $item['service_name'],
                'country_code' => $countryCode,
                'currency' => 'USD',
                'amount_original' => $item['amount'],
                'exchange_rate' => 1,
                'amount_converted' => $item['amount'],
                'item_details' => json_encode($item['details']),
            ]);
        }
        
        Log::info("✅ Saved PnL record: {$isNumber}");
        return true;
        
    } catch (\Exception $e) {
        Log::error('Save PnL email failed: ' . $e->getMessage());
        Log::error($e->getTraceAsString());
        return false;
    }
}
    

private function extractHotelsFromEmail($html)
{
    $hotels = [];

    try {

        if (empty($html)) {
            return [];
        }

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        $tables = $dom->getElementsByTagName('table');

        Log::info("Total tables found: " . $tables->length);

        foreach ($tables as $tableIndex => $table) {

            $rows = $table->getElementsByTagName('tr');

            if ($rows->length < 2) {
                continue;
            }

            $headers = [];

            $firstRow = $rows->item(0);

            foreach ($firstRow->childNodes as $cell) {

                if (
                    $cell->nodeType === XML_ELEMENT_NODE &&
                    in_array(strtolower($cell->nodeName), ['th', 'td'])
                ) {
                    $headers[] = trim($cell->textContent);
                }
            }

            $headerText = strtoupper(implode(' ', $headers));

            Log::info("Table {$tableIndex} Headers: " . $headerText);

            if (
                strpos($headerText, 'NAME') === false ||
                strpos($headerText, 'NIGHTS') === false ||
                strpos($headerText, 'TOTAL') === false
            ) {
                continue;
            }

            Log::info("Hotels table detected");

            for ($i = 1; $i < $rows->length; $i++) {

                $row = $rows->item($i);

                $cells = [];

                foreach ($row->childNodes as $cell) {

                    if (
                        $cell->nodeType === XML_ELEMENT_NODE &&
                        in_array(strtolower($cell->nodeName), ['td', 'th'])
                    ) {
                        $cells[] = trim($cell->textContent);
                    }
                }

                if (count($cells) < 3) {
                    continue;
                }

                $hotelName = trim($cells[0]);

                if (
                    empty($hotelName) ||
                    strtoupper($hotelName) === 'TOTAL' ||
                    is_numeric($hotelName)
                ) {
                    continue;
                }

                $nights = 0;
                $amount = 0;

                foreach ($cells as $index => $value) {

                    if (
                        strtoupper($headers[$index] ?? '') === 'NIGHTS'
                    ) {
                        $nights = (int) preg_replace('/[^0-9]/', '', $value);
                    }

                    if (
                        strtoupper($headers[$index] ?? '') === 'TOTAL'
                    ) {
                        $amount = (float) str_replace(
                            ',',
                            '',
                            preg_replace('/[^0-9\.]/', '', $value)
                        );
                    }
                }

                if ($amount <= 0) {
                    continue;
                }

                $hotels[] = [
                    'name' => $hotelName,
                    'amount' => $amount,
                    'nights' => $nights,
                ];

                Log::info(
                    "Hotel Extracted => {$hotelName} | Nights: {$nights} | Amount: {$amount}"
                );
            }

            if (!empty($hotels)) {
                break;
            }
        }

    } catch (\Exception $e) {

        Log::error(
            'Hotel extraction error: ' . $e->getMessage()
        );
    }

    Log::info("Total hotels extracted: " . count($hotels));

    return $hotels;
}
    /**
     * Extract Transport total
     */
private function extractTransportTotalFromEmail($text)
{
    // First check if Transport section even exists
    if (!preg_match('/Transport/i', $text)) return 0;
    
    // Look for Total Transport pattern
    if (preg_match('/Total Transport\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total found: " . $total);
        return $total > 0 ? $total : 0; // ✅ Return 0 if total is 0 or negative
    }
    
    // Alternative pattern
    if (preg_match('/Transport.*?Total[\s\|]*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total found (alt): " . $total);
        return $total > 0 ? $total : 0; // ✅ Return 0 if total is 0 or negative
    }
    
    return 0;
}
    
    /**
     * Extract Other Rates total
     */
/**
 * Extract Other Rates total - ONLY return > 0
 */
private function extractOtherRatesTotalFromEmail($text)
{
    if (!preg_match('/Other Rates/i', $text)) return 0;
    
    if (preg_match('/Other Rates.*?Total\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Other Rates Total: " . $total);
        return $total > 0 ? $total : 0;
    }
    
    return 0;
}
    
    /**
     * Extract Attraction total - only if non-zero
     */
/**
 * Extract Attraction total - ONLY return > 0
 */
private function extractAttractionTotalFromEmail($text)
{
    if (!preg_match('/Attraction/i', $text)) return 0;
    
    if (preg_match('/Attraction.*?Total\s*\|\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Attraction total found: " . $total);
        return $total > 0 ? $total : 0;
    }
    
    if (preg_match('/Attraction.*?Total[\s\|]*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Attraction total found (alt): " . $total);
        return $total > 0 ? $total : 0;
    }
    
    return 0;
}
    
    /**
     * Extract Tour Transfers total - only if non-zero
     */
/**
 * Extract Tour Transfers total - ONLY return > 0
 */
private function extractTourTransfersTotalFromEmail($text)
{
    if (!preg_match('/Tour Transfers/i', $text)) return 0;
    
    // Look for Total at the bottom of Tour Transfers table
    if (preg_match('/Tour Transfers.*?Total\s*\|\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Tour Transfers total found: " . $total);
        return $total > 0 ? $total : 0;
    }
    
    if (preg_match('/Tour Transfers.*?Total\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Tour Transfers total found (alt): " . $total);
        return $total > 0 ? $total : 0;
    }
    
    return 0;
}
    /**
 * Extract Profit/Loss from email
 */
/**
 * Extract Profit/Loss from email
 */
/**
 * Extract Profit/Loss from email
 */
/**
 * Extract Profit/Loss from email
 */
/**
 * Extract Profit/Loss from email
 */
private function extractProfitLossFromEmail($text)
{
    // Debug: Log what we're searching
    Log::info("Searching for Profit/Loss in text...");
    
    // Try multiple patterns - order matters from most specific to least specific
    
    // Pattern 1: Pipe table format | Profit/Loss | 14.28 USD |
    if (preg_match('/Profit\/Loss\s*\|\s*([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (pipe table): " . $profitLoss);
        return $profitLoss;
    }
    
    // Pattern 2: HTML table with Profit/Loss in one cell and value in next cell
    if (preg_match('/Profit\/Loss<\/t[dh]>.*?<t[dh][^>]*>([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (HTML table): " . $profitLoss);
        return $profitLoss;
    }
    
    // Pattern 3: Bold/number format | **Profit/Loss** | **14.28** USD
    if (preg_match('/Profit\/Loss.*?\*\*([\d,]+(?:\.\d+)?)\*\*\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (bold format): " . $profitLoss);
        return $profitLoss;
    }
    
    // Pattern 4: Simple "Profit/Loss 14.28 USD" (spaces)
    if (preg_match('/Profit\/Loss\s+([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (space separated): " . $profitLoss);
        return $profitLoss;
    }
    
    // Pattern 5: "Profit/Loss: 14.28 USD" (with colon)
    if (preg_match('/Profit\/Loss\s*:\s*([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (with colon): " . $profitLoss);
        return $profitLoss;
    }
    
    // Pattern 6: Any number after Profit/Loss within 50 characters
    if (preg_match('/Profit\/Loss.{0,50}?([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (flexible): " . $profitLoss);
        return $profitLoss;
    }
    
    Log::info("❌ No Profit/Loss pattern matched");
    return null;
}
    /**
     * Extract Meals total - only if non-zero
     */
/**
 * Extract Meals total - ONLY return > 0
 */
private function extractMealsTotalFromEmail($text)
{
    if (!preg_match('/Meals/i', $text)) return 0;
    
    if (preg_match('/Meals.*?Total\s*\|\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Meals total found: " . $total);
        return $total > 0 ? $total : 0;
    }
    
    if (preg_match('/Meals.*?Total\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Meals total found (alt): " . $total);
        return $total > 0 ? $total : 0;
    }
    
    return 0;
}

/**
 * Extract transport total for Vietnam/Singapore/Malaysia format
 * Table has 4 columns: EXPENSE, DISTANCE/DAYS, RATE, TOTAL
 * Last row contains "Total Transport" with amount in TOTAL column
 */
private function extractTransportTotalForSouthEastAsia($html)
{
    if (empty($html)) return 0;
    
    // Method 1: Look for "Total Transport" in HTML tables
    if (preg_match('/Total Transport.*?<td[^>]*>.*?<\/td><td[^>]*>(?:[\d.,]+)<\/td><td[^>]*>([\d.,]+)<\/td>/is', $html, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total from HTML table: " . $total);
        return $total;
    }
    
    // Method 2: Look for pipe table format with Total Transport
    // Format: | Total Transport | 174 | 196.00 | 196.00 |
    if (preg_match('/\|\s*Total Transport\s*\|\s*[\d.]*\s*\|\s*([\d.,]+)\s*\|\s*([\d.,]+)\s*\|/i', $html, $match)) {
        // Take the last number (TOTAL column)
        $total = floatval(str_replace(',', '', end($match)));
        Log::info("Transport Total from pipe table: " . $total);
        return $total;
    }   
    
    // Method 3: Look for the Transport section and find the number in TOTAL column
    if (preg_match('/Transport.*?Total Transport.*?\|.*?\|.*?\|.*?([\d.,]+)\s*\|/is', $html, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total from section: " . $total);
        return $total;
    }
    
    return 0;
}

/**
 * Extract Other Rates total for Vietnam/Singapore/Malaysia format
 */
private function extractOtherRatesTotalForSouthEastAsia($html)
{
    if (empty($html)) return 0;
    
    // Look for "Other Rates" table and find the total
    // The total is usually at the bottom of the table
    
    // Method 1: Find the total in the Other Rates section
    if (preg_match('/Other Rates.*?(?:Total|TOTAL)\s*\|\s*([\d.,]+)\s*\|/is', $html, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Other Rates Total found: " . $total);
        return $total;
    }
    
    // Method 2: For HTML tables
    if (preg_match('/Other Rates.*?<t[dh][^>]*>Total<\/t[dh]>\s*<t[dh][^>]*>([\d.,]+)<\/t[dh]>/is', $html, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Other Rates Total from HTML: " . $total);
        return $total;
    }
    
    // Method 3: Get the last number in Other Rates section
    if (preg_match('/Other Rates(.*?)(?:Attraction|Tour Transfers|Meals|$)/is', $html, $sectionMatch)) {
        $section = $sectionMatch[1];
        if (preg_match_all('/([\d.,]+)/', $section, $matches)) {
            if (!empty($matches[1])) {
                $total = floatval(str_replace(',', '', end($matches[1])));
                Log::info("Other Rates Total (last number): " . $total);
                return $total;
            }
        }
    }
    
    return 0;
}

/**
 * Extract individual transport items from Transport section
 * Returns array of transport items with their amounts
 */
private function extractTransportItemsFromEmail($html, $plainText = '')
{
    $transportItems = [];
    
    if (empty($html) && empty($plainText)) {
        return $transportItems;
    }
    
    // Try HTML extraction first
    if (!empty($html)) {
        $transportItems = $this->extractTransportItemsFromHTML($html);
    }
    
    // If no items found, try plain text
    if (empty($transportItems) && !empty($plainText)) {
        $transportItems = $this->extractTransportItemsFromPlainText($plainText);
    }
    
    return $transportItems;
}

/**
 * Extract transport items from HTML tables
 */
private function extractTransportItemsFromHTML($html)
{
    $transportItems = [];
    
    try {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        
        $tables = $dom->getElementsByTagName('table');
        
        foreach ($tables as $table) {
            $rows = $table->getElementsByTagName('tr');
            
            if ($rows->length < 2) continue;
            
            // Check if this is a Transport table
            $headers = [];
            $firstRow = $rows->item(0);
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'])) {
                    $headers[] = trim(strtoupper($cell->textContent));
                }
            }
            
            $headerText = implode(' ', $headers);
            
            // ✅ CRITICAL FIX: Only identify as Transport table if it has EXPENSE column
            // AND it doesn't have hotel-related columns (NAME, SGL, DBL, TPL, CWB, CNB)
            $hasExpense = strpos($headerText, 'EXPENSE') !== false;
            $hasTransport = strpos($headerText, 'TRANSPORT') !== false;
            $hasDistance = strpos($headerText, 'DISTANCE') !== false || strpos($headerText, 'DAYS') !== false;
            $hasHotelColumns = strpos($headerText, 'SGL') !== false || 
                              strpos($headerText, 'DBL') !== false || 
                              strpos($headerText, 'TPL') !== false || 
                              strpos($headerText, 'CWB') !== false || 
                              strpos($headerText, 'CNB') !== false;
            
            // ✅ ONLY process if it's a Transport table (has EXPENSE or TRANSPORT and DISTANCE/DAYS)
            // AND it's NOT a hotel table
            if (!($hasExpense || $hasTransport) || !$hasDistance || $hasHotelColumns) {
                Log::info("Skipping non-transport table: " . $headerText);
                continue;
            }
            
            Log::info("Processing Transport table: " . $headerText);
            
            // Find column indices
            $nameIndex = -1;
            $totalIndex = -1;
            $rateIndex = -1;
            
            foreach ($headers as $index => $header) {
                $upperHeader = strtoupper(trim($header));
                if (strpos($upperHeader, 'EXPENSE') !== false || 
                    strpos($upperHeader, 'NAME') !== false || 
                    strpos($upperHeader, 'PARTICULARS') !== false) {
                    $nameIndex = $index;
                }
                if (strpos($upperHeader, 'TOTAL') !== false) {
                    $totalIndex = $index;
                }
                if (strpos($upperHeader, 'RATE') !== false) {
                    $rateIndex = $index;
                }
            }
            
            if ($nameIndex == -1 || $totalIndex == -1) {
                continue;
            }
            
            // Parse rows (skip header row)
            for ($i = 1; $i < $rows->length; $i++) {
                $row = $rows->item($i);
                $cells = [];
                
                foreach ($row->childNodes as $cell) {
                    if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'])) {
                        $cells[] = trim($cell->textContent);
                    }
                }
                
                if (count($cells) <= max($nameIndex, $totalIndex)) {
                    continue;
                }
                
                $serviceName = trim($cells[$nameIndex]);
                
                // Extract the total amount - ensure it's properly parsed
                $totalValue = trim($cells[$totalIndex]);
                // Remove any non-numeric characters except decimal point
                $totalValue = preg_replace('/[^0-9.]/', '', $totalValue);
                $totalAmount = floatval($totalValue);
                
                // ✅ STRICT CHECK: Skip if amount is 0 or negative
                if ($totalAmount <= 0) {
                    Log::info("Skipping transport item with zero/negative amount: {$serviceName} - {$totalAmount}");
                    continue;
                }
                
                // Skip if service name is empty or is a total/summary row
                if (empty($serviceName) || 
                    strtoupper($serviceName) === 'TOTAL' ||
                    strtoupper($serviceName) === 'TOTAL TRANSPORT' ||
                    strtoupper($serviceName) === 'MEAL TRANSPORT TOTAL' ||
                    preg_match('/^[\d.,]+$/', $serviceName)) {
                    Log::info("Skipping summary row: {$serviceName}");
                    continue;
                }
                
                // Skip if it's a "Meal Transport Total" or summary row
                if (preg_match('/meal transport|total transport/i', $serviceName)) {
                    Log::info("Skipping transport summary: {$serviceName}");
                    continue;
                }
                
                // ✅ EXTRA CHECK: Skip if it looks like a hotel name (has common hotel keywords)
                if (preg_match('/hotel|resort|villa|apartment|inn|lodge|hostel/i', $serviceName)) {
                    Log::info("Skipping hotel item in transport: {$serviceName}");
                    continue;
                }
                
                $transportItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $totalAmount,
                    'details' => ['remarks' => $serviceName]
                ];
                
                Log::info("✓ Transport item extracted (HTML): {$serviceName} - \${$totalAmount}");
            }
            
            // If we found items, break out of table loop
            if (!empty($transportItems)) {
                break;
            }
        }
        
    } catch (\Exception $e) {
        Log::error('Transport items HTML extraction error: ' . $e->getMessage());
    }
    
    return $transportItems;
}

/**
 * Extract transport items from plain text (fallback for Sri Lanka and others)
 */
private function extractTransportItemsFromPlainText($text)
{
    $transportItems = [];
    
    if (empty($text)) {
        return $transportItems;
    }
    
    // Try to find the Transport section
    if (preg_match('/Transport(.*?)(?:Attraction|Tour Transfers|Meals|Other Rates|$)/is', $text, $sectionMatch)) {
        $transportSection = $sectionMatch[1];
        
        $lines = explode("\n", $transportSection);
        foreach ($lines as $line) {
            if (strpos($line, '|') !== false) {
                $parts = explode('|', $line);
                if (count($parts) >= 3) {
                    $cleanParts = array_map('trim', $parts);
                    $serviceName = $cleanParts[1] ?? '';
                    $amount = 0;
                    
                    $lastPart = end($cleanParts);
                    // Remove any non-numeric characters except decimal point
                    $lastPartClean = preg_replace('/[^0-9.]/', '', $lastPart);
                    if (is_numeric($lastPartClean)) {
                        $amount = floatval($lastPartClean);
                    } else {
                        $secondLast = $cleanParts[count($cleanParts) - 2] ?? '';
                        $secondLastClean = preg_replace('/[^0-9.]/', '', $secondLast);
                        if (is_numeric($secondLastClean)) {
                            $amount = floatval($secondLastClean);
                        }
                    }
                    
                    // ✅ STRICT CHECK: Skip if amount is 0 or negative
                    if ($amount <= 0) {
                        Log::info("Skipping transport item with zero amount in plain text: {$serviceName}");
                        continue;
                    }
                    
                    if (!empty($serviceName) && 
                        !preg_match('/total|meal|transport/i', $serviceName)) {
                        $transportItems[] = [
                            'service_name' => $serviceName,
                            'amount' => $amount,
                            'details' => ['remarks' => $serviceName]
                        ];
                        Log::info("✓ Transport item extracted (pipe): {$serviceName} - \${$amount}");
                    }
                }
            }
        }
    }
    
    return $transportItems;
}

/**
 * Extract individual transport items - Main method with country-specific logic
 */
private function extractTransportItemsForCountry($html, $plainText, $countryCode)
{
    $transportItems = [];
    
    // For Vietnam, Singapore, Malaysia - use HTML parsing
    if ($countryCode == 'VN' || $countryCode == 'SG' || $countryCode == 'MY') {
        $transportItems = $this->extractTransportItemsFromHTML($html);
        Log::info("Transport items from HTML (SEA): " . json_encode($transportItems));
        
        // If no items found, try plain text
        if (empty($transportItems)) {
            $transportItems = $this->extractTransportItemsFromPlainText($plainText);
            Log::info("Transport items from plain text (SEA): " . json_encode($transportItems));
        }
    } else {
        // ✅ For Sri Lanka - ONLY use plain text parsing, skip HTML
        $transportItems = $this->extractTransportItemsFromPlainText($plainText);
        Log::info("Transport items from plain text (LK): " . json_encode($transportItems));
        
        // ✅ Don't fall back to HTML for Sri Lanka as it causes confusion
    }
    
    // Filter out items with amount <= 0 (extra safety)
    $transportItems = array_filter($transportItems, function($item) {
        return isset($item['amount']) && $item['amount'] > 0;
    });
    
    Log::info("Final transport items after filtering: " . json_encode($transportItems));
    
    // ✅ FIX: Only add fallback if total > 0
    if (empty($transportItems)) {
        $total = $this->extractTransportTotalFromEmail($plainText);
        Log::info("Transport total from email: " . $total);
        
        // ✅ CRITICAL FIX: Only add if total > 0
        if ($total > 0) {
            $transportItems[] = [
                'service_name' => 'Transport Expenses (Total)',
                'amount' => $total,
                'details' => ['remarks' => 'Total transport expenses']
            ];
            Log::info("⚠️ Using transport total as fallback: \${$total}");
        } else {
            Log::info("ℹ️ No transport items found and total is 0, skipping transport");
        }
    }
    
    return $transportItems;
}
public function fetchAllPnLEmailsInBackground()
{
    try {
        set_time_limit(0); // No time limit
        ini_set('memory_limit', '1024M');
        
        $allMessages = [];
        $nextLink = null;
        $pageCount = 0;
        $maxPages = 50;

        $url = 'https://graph.microsoft.com/v1.0/users/' . env('GRAPH_PNL_USER') . '/messages'
            . '?$top=200'
            . '&$orderby=receivedDateTime desc'
            . '&$select=id,subject,bodyPreview,from,receivedDateTime,isRead,hasAttachments';

        do {
            $response = Http::withToken($this->accessToken)
                ->timeout(300) // 5 minutes per page
                ->get($url);

            if (!$response->ok()) {
                Log::error('Failed to fetch PnL emails: ' . $response->body());
                break;
            }

            $data = $response->json();
            $messages = $data['value'] ?? [];
            $allMessages = array_merge($allMessages, $messages);
            
            $nextLink = $data['@odata.nextLink'] ?? null;
            $pageCount++;
            
            Log::info("Background fetch page {$pageCount}: " . count($messages) . " emails");

            if ($pageCount >= $maxPages) {
                Log::warning("Reached max pages ({$maxPages})");
                break;
            }

            if ($nextLink) {
                usleep(300000); // 0.3 second delay
            }

        } while ($nextLink);

        Log::info("Total emails fetched in background: " . count($allMessages));

        $newCount = 0;
        $sno = PnlRecord::max('sno') ?? 0;

        foreach ($allMessages as $message) {
            $existing = PnlRecord::where('message_id', $message['id'])->first();
            if (!$existing) {
                $sno++;
                $fullMessage = $this->fetchFullMessage($message['id']);
                if ($fullMessage) {
                    $saved = $this->savePnLEmail($fullMessage, $sno);
                    if ($saved) $newCount++;
                }
            }
        }

        Log::info("Background fetch completed: {$newCount} new emails");
        return $newCount;

    } catch (\Exception $e) {
        Log::error('Background fetch error: ' . $e->getMessage());
        return 0;
    }
}

protected function fetchFullMessage($messageId)
{
    try {
        $response = Http::withToken($this->accessToken)
            ->timeout(60)
            ->get('https://graph.microsoft.com/v1.0/users/' . env('GRAPH_PNL_USER') . '/messages/' . $messageId, [
                '$select' => 'id,subject,body,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
            ]);
        
        if ($response->ok()) {
            return $response->json();
        }
    } catch (\Exception $e) {
        Log::error("Failed to fetch full message: " . $e->getMessage());
    }
    return null;
}
}