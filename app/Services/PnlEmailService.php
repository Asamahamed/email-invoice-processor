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
    public function getAccessToken()
{
    return $this->accessToken;
}
public function fetchPnLEmails()
{
    try {
        // STEP 1: Get all existing message IDs from database
        $existingIds = PnlRecord::pluck('message_id')->toArray();
        Log::info("📊 Existing emails in DB: " . count($existingIds));
        
        // STEP 2: Fetch ALL emails with pagination (NO LIMIT)
        $allMessages = [];
        $nextLink = null;
        $pageCount = 0;
        
        $baseUrl = 'https://graph.microsoft.com/v1.0/users/' . env('GRAPH_PNL_USER') . '/messages';
        
        Log::info("📧 Fetching ALL emails with pagination (no limit)...");
        
        do {
            $url = $nextLink ?? $baseUrl . '?' . http_build_query([
                '$top' => 100,  // 100 per page (max allowed)
                '$orderby' => 'receivedDateTime desc',
                '$select' => 'id,subject,body,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
            ]);
            
            $response = Http::withToken($this->accessToken)
                ->timeout(60)
                ->get($url);
            
            if (!$response->ok()) {
                Log::error('Failed to fetch emails: ' . $response->body());
                break;
            }
            
            $data = $response->json();
            $messages = $data['value'] ?? [];
            
            if (empty($messages)) {
                Log::info("No more messages to fetch");
                break;
            }
            
            $allMessages = array_merge($allMessages, $messages);
            
            $nextLink = $data['@odata.nextLink'] ?? null;
            $pageCount++;
            
            Log::info("📥 Page {$pageCount}: " . count($messages) . " emails (Total: " . count($allMessages) . ")");
            
            // ✅ REMOVED the maxPages limit - will fetch ALL pages
            if ($nextLink) {
                usleep(200000); // Small delay between requests
            }
            
        } while ($nextLink);
        
        Log::info("📬 Total emails fetched from API: " . count($allMessages));
        
        // STEP 3: Filter to find NEW emails only
        $newMessages = [];
        foreach ($allMessages as $message) {
            if (!in_array($message['id'], $existingIds)) {
                $newMessages[] = $message;
            }
        }
        
        Log::info("🆕 New emails to process: " . count($newMessages));
        
        // STEP 4: If no new emails, return early
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
 // ========== EXTRACT PAX ==========
$totalPax = 0;
$totalNights = 0;

Log::info("🔍 Searching for PAX in text...");

// Try ALL possible patterns - ORDER MATTERS!
$patterns = [
    // For "No. Pax: 2" format
    '/No\.\s*Pax\s*:\s*(\d+)/i',
    '/No\.\s*Pax:\s*(\d+)/i',
    '/No\.\s*Pax\s*(\d+)/i',
    // For "No. Adult: 2" format
    '/No\.\s*Adult\s*:\s*(\d+)/i',
    '/No\.\s*Adult:\s*(\d+)/i',
    '/No\.\s*Adult\s*(\d+)/i',
    // Fallback patterns
    '/Adult\s*:\s*(\d+)/i',
    '/Adult:\s*(\d+)/i',
    '/Pax\s*:\s*(\d+)/i',
    '/Pax:\s*(\d+)/i',
];

foreach ($patterns as $pattern) {
    if (preg_match($pattern, $plainText, $match)) {
        $totalPax = intval($match[1]);
        Log::info("✅ Extracted PAX: " . $totalPax . " (pattern: " . $pattern . ")");
        break;
    }
}

// If still 0, try searching in HTML directly
if ($totalPax == 0) {
    if (preg_match('/No\.\s*Pax\s*:\s*(\d+)/i', $htmlBody, $match)) {
        $totalPax = intval($match[1]);
        Log::info("✅ Extracted PAX from HTML: " . $totalPax);
    } elseif (preg_match('/No\.\s*Adult\s*:\s*(\d+)/i', $htmlBody, $match)) {
        $totalPax = intval($match[1]);
        Log::info("✅ Extracted PAX from HTML: " . $totalPax);
    }
}

// Extract nights
$nightPatterns = [
    '/No\.\s*Night\s*:\s*(\d+)/i',
    '/No\.\s*Night:\s*(\d+)/i',
    '/No\.\s*Night\s*(\d+)/i',
    '/Night\s*:\s*(\d+)/i',
    '/Night:\s*(\d+)/i',
];

foreach ($nightPatterns as $pattern) {
    if (preg_match($pattern, $plainText, $match)) {
        $totalNights = intval($match[1]);
        Log::info("✅ Extracted NIGHTS: " . $totalNights . " (pattern: " . $pattern . ")");
        break;
    }
}

Log::info("📊 FINAL PAX: " . $totalPax . ", NIGHTS: " . $totalNights);

// Force add a debug line to check
if ($totalPax == 0) {
    Log::warning("⚠️ PAX extraction failed! Please check the email format.");
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
        
        // Calculate transport total
        $transportTotal = 0;
        foreach ($transportItems as $item) {
            $transportTotal += $item['amount'];
        }
        
$otherRateItems = $this->extractOtherRateItems($htmlBody, $plainText);

if (!empty($otherRateItems)) {
    $categoriesFound[] = 'Other Rates';
    foreach ($otherRateItems as $other) {
        $pnlItemsToSave[] = [
            'type' => 'OTHER RATES',
            'service_name' => $other['service_name'],
            'hotel_name' => null,
            'amount' => $other['amount'],
            'details' => $other['details']
        ];
        Log::info("✅ Added other rate item: {$other['service_name']} - \${$other['amount']}");
    }
} else {
    // Fallback: Use total if individual items not found and total > 0
    $otherRatesTotal = 0;
    if ($countryCode == 'VN' || $countryCode == 'SG' || $countryCode == 'MY') {
        $otherRatesTotal = $this->extractOtherRatesTotalForSouthEastAsia($htmlBody);
        if ($otherRatesTotal == 0) {
            $otherRatesTotal = $this->extractOtherRatesTotalFromEmail($plainText);
        }
    } else {
        $otherRatesTotal = $this->extractOtherRatesTotalFromEmail($plainText);
    }
    
    // Only add fallback if there is a positive total AND no individual items were found
    if ($otherRatesTotal > 0) {
        $categoriesFound[] = 'Other Rates';
        $pnlItemsToSave[] = [
            'type' => 'OTHER RATES',
            'service_name' => 'Other Rates (Entrance Tickets, etc.)',
            'hotel_name' => null,
            'amount' => $otherRatesTotal,
            'details' => ['remarks' => 'Other attraction & entrance fees']
        ];
        Log::info("⚠️ Using Other Rates total as fallback: \${$otherRatesTotal}");
    }
}
        // 4. Attraction
      // 4. Attraction - Extract INDIVIDUAL ITEMS
$attractionItems = $this->extractAttractionItems($htmlBody, $plainText);

if (!empty($attractionItems)) {
    $categoriesFound[] = 'Attraction';
    foreach ($attractionItems as $attraction) {
        $pnlItemsToSave[] = [
            'type' => 'ATTRACTION',
            'service_name' => $attraction['service_name'],
            'hotel_name' => null,
            'amount' => $attraction['amount'],
            'details' => $attraction['details']
        ];
        Log::info("✅ Added attraction item: {$attraction['service_name']} - \${$attraction['amount']}");
    }
} else {
    // Fallback: Use total if individual items not found
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
}
        
$tourTransferItems = $this->extractTourTransferItems($htmlBody, $plainText, $totalPax);

if (!empty($tourTransferItems)) {
    $categoriesFound[] = 'Tour Transfers';
    foreach ($tourTransferItems as $transfer) {
        $pnlItemsToSave[] = [
            'type' => 'TOUR TRANSFER',
            'service_name' => $transfer['service_name'],
            'hotel_name' => null,
            'amount' => $transfer['amount'],
            'details' => $transfer['details']
        ];
        Log::info("✅ Added tour transfer item: {$transfer['service_name']} - \${$transfer['amount']}");
    }
} else {
    // Fallback: Use total if individual items not found
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
}
        
      // 6. Meals - Extract INDIVIDUAL ITEMS
$mealItems = $this->extractMealItems($plainText);

if (!empty($mealItems)) {
    $categoriesFound[] = 'Meals';
    foreach ($mealItems as $meal) {
        $pnlItemsToSave[] = [
            'type' => 'MEALS',
            'service_name' => $meal['service_name'],
            'hotel_name' => null,
            'amount' => $meal['amount'],
            'details' => $meal['details']
        ];
        Log::info("✅ Added meal item: {$meal['service_name']} - \${$meal['amount']}");
    }
} else {
    // Fallback: Use total if individual items not found
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
        
        // ✅✅✅ AUTO-UPDATE: Get client_name, start_date, end_date from invoices
        $this->updatePnLItemsWithInvoiceData($record);
        
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
    
    // Alternative pattern - look for Total row with just number
    if (preg_match('/Total\s*:?\s*([\d,]+\.?\d*)\s*USD?/i', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total found (alt): " . $total);
        return $total > 0 ? $total : 0;
    }
    
    // Look for Total row in pipe format: | Total | 227.60 USD |
    if (preg_match('/\|\s*Total\s*\|\s*[\d.]*\s*\|\s*[\d.]*\s*\|\s*([\d,]+\.?\d*)\s*USD?/i', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total found (pipe): " . $total);
        return $total > 0 ? $total : 0;
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
 * Extract individual transport items from plain text.
 * Handles space‑separated data (HTML tables stripped to text).
 * Extracts EACH row with amount > 0.
 */
private function extractTransportItemsFromPlainText($text)
{
    $transportItems = [];
    if (empty($text)) {
        return $transportItems;
    }

    // Find the Transport section
    if (!preg_match('/Transport(.*?)(?:Attraction|Tour Transfers|Meals|Other Rates|$)/is', $text, $sectionMatch)) {
        Log::info("Transport section not found in plain text");
        return $transportItems;
    }

    $section = $sectionMatch[1];
    Log::info("Transport section found for plain text extraction");

    // Split into lines
    $lines = preg_split('/\r\n|\n|\r/', $section);

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }

        // Skip header lines
        if (preg_match('/^(EXPENSE|DISTANCE|DAYS|RATE|TOTAL|NAME|PARTICULARS)/i', $line)) {
            continue;
        }

        // Skip total rows (lines that contain "Total" as a standalone word or in a cell)
        if (preg_match('/^\s*Total\s*$/i', $line) || 
            preg_match('/\|\s*Total\s*\|/i', $line) ||
            preg_match('/\*\*Total\*\*/i', $line) ||
            preg_match('/^Total\s+Transport/i', $line) ||
            preg_match('/Meal Transport Total/i', $line)) {
            continue;
        }

        // Find all numbers (including decimals) in the line
        preg_match_all('/\b\d+\.?\d*\b/', $line, $matches);
        $numbers = $matches[0];
        if (empty($numbers)) {
            continue; // no numbers, skip
        }

        // The total is the last number
        $total = (float) end($numbers);
        if ($total <= 0) {
            continue; // skip zero or negative totals
        }

        // Find the position of the first number
        $firstNumber = reset($numbers);
        $pos = strpos($line, $firstNumber);
        if ($pos === false) {
            continue;
        }

        // Service name is everything before the first number
        $serviceName = trim(substr($line, 0, $pos));
        // Clean up extra spaces and pipe characters
        $serviceName = preg_replace('/\s+/', ' ', $serviceName);
        $serviceName = trim($serviceName, '| ');

        // Skip if service name is empty or is a known header/total
        if (empty($serviceName)) {
            continue;
        }
        if (in_array(strtoupper($serviceName), ['EXPENSE', 'DISTANCE/DAYS', 'RATE', 'TOTAL', 'NAME', 'OTHER COST'])) {
            continue;
        }
        if (preg_match('/^(Total|TOTAL)$/i', $serviceName) || 
            preg_match('/^Total\s+Transport/i', $serviceName) ||
            preg_match('/Meal Transport Total/i', $serviceName)) {
            continue;
        }

        // Avoid duplicates (same service name)
        $exists = false;
        foreach ($transportItems as $item) {
            if ($item['service_name'] === $serviceName) {
                $exists = true;
                break;
            }
        }
        if ($exists) {
            continue;
        }

        $transportItems[] = [
            'service_name' => $serviceName,
            'amount' => $total,
            'details' => ['remarks' => $serviceName]
        ];
        Log::info("✓ Transport item extracted (plain): {$serviceName} - \${$total}");
    }

    // Final fallback: If no items found, use the total row (only if > 0)
    if (empty($transportItems)) {
        if (preg_match('/Total\s*:?\s*([\d,]+\.?\d*)\s*USD?/i', $section, $match)) {
            $total = floatval(str_replace(',', '', $match[1]));
            if ($total > 0) {
                $transportItems[] = [
                    'service_name' => 'Transport Expenses (Total)',
                    'amount' => $total,
                    'details' => ['remarks' => 'Total transport expenses']
                ];
                Log::info("⚠️ Using transport total as fallback: \${$total}");
            }
        }
    }

    Log::info("Total transport items extracted (plain): " . count($transportItems));
    return $transportItems;
}
/**
 * Extract transport items from table text format (for Sri Lanka)
 */
private function extractTransportItemsFromTableText($text)
{
    $transportItems = [];
    
    // Try to find Transport section
    if (preg_match('/Transport(.*?)(?:Attraction|Tour Transfers|Meals|Other Rates|$)/is', $text, $sectionMatch)) {
        $section = $sectionMatch[1];
        
        // Look for patterns like: "Travel    1035    0.3273    338.73"
        // or "| Travel | 1035 | 0.3273 | 338.73 |"
        $lines = explode("\n", $section);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip header lines
            if (preg_match('/EXPENSE|DISTANCE|DAYS|RATE|TOTAL/i', $line)) continue;
            
            // Try to match pattern: Name    Number    Rate    Amount
            // This handles both pipe and space-separated formats
            if (preg_match('/^([A-Za-z\s]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $line, $match)) {
                $serviceName = trim($match[1]);
                $amount = floatval($match[4]);
                
                // Skip if amount is 0 or service name is empty
                if ($amount <= 0 || empty($serviceName)) continue;
                
                // Skip total rows
                if (preg_match('/total|transport/i', $serviceName)) continue;
                
                $transportItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => ['remarks' => $serviceName]
                ];
                
                Log::info("✓ Transport item extracted (table text): {$serviceName} - \${$amount}");
            }
            // Try pattern with pipe: | Name | value | value | amount |
            elseif (preg_match('/\|\s*([A-Za-z\s]+)\s*\|\s*[\d.]*\s*\|\s*[\d.]*\s*\|\s*([\d.]+)\s*\|/', $line, $match)) {
                $serviceName = trim($match[1]);
                $amount = floatval($match[2]);
                
                if ($amount <= 0 || empty($serviceName)) continue;
                if (preg_match('/total|transport/i', $serviceName)) continue;
                
                $transportItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => ['remarks' => $serviceName]
                ];
                
                Log::info("✓ Transport item extracted (pipe): {$serviceName} - \${$amount}");
            }
        }
    }
    
    return $transportItems;
}

private function extractTransportItemsForCountry($html, $plainText, $countryCode)
{
    $transportItems = [];

    // Always try HTML extraction first if HTML is available
    if (!empty($html)) {
        $transportItems = $this->extractTransportItemsFromHTML($html);
        Log::info("Transport items from HTML: " . json_encode($transportItems));
    }

    // If no items found, fallback to plain text parsing
    if (empty($transportItems)) {
        $transportItems = $this->extractTransportItemsFromPlainText($plainText);
        Log::info("Transport items from plain text: " . json_encode($transportItems));
    }

    // Filter out items with amount <= 0 (already done in HTML parser, but safe)
    $transportItems = array_filter($transportItems, function ($item) {
        return isset($item['amount']) && $item['amount'] > 0;
    });

    // If no items with positive amount, skip transport entirely
    if (empty($transportItems)) {
        Log::info("No transport items with positive amount found - skipping transport");
        return [];
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
/**
 * Update PnL items with client info from matching invoices
 */
protected function updatePnLItemsWithInvoiceData($pnlRecord)
{
    try {
        $invoiceNumber = $pnlRecord->invoice_number;
        $tourRef = $pnlRecord->tour_ref;
        
        $matchedEmail = null;
        
        // Try to match by invoice_number
        if ($invoiceNumber && $invoiceNumber !== 'NA' && $invoiceNumber !== 'N/A') {
            $matchedEmail = \App\Models\IncomingEmail::where('invoice_number', $invoiceNumber)
                ->whereNotNull('guest_name')
                ->where('guest_name', '!=', 'NA')
                ->where('guest_name', '!=', '')
                ->first();
        }
        
        // If not found, try by tour_ref
        if (!$matchedEmail && $tourRef && $tourRef !== 'NA' && $tourRef !== 'N/A') {
            $matchedEmail = \App\Models\IncomingEmail::where('tour_ref', $tourRef)
                ->whereNotNull('guest_name')
                ->where('guest_name', '!=', 'NA')
                ->where('guest_name', '!=', '')
                ->first();
        }
        
        if ($matchedEmail) {
            Log::info("✅ Found matching invoice for PnL record {$pnlRecord->id}");
            
            // ✅ ALSO UPDATE PNL RECORD
            $pnlRecord->vendor_name = $matchedEmail->guest_name;
            $pnlRecord->start_date = $matchedEmail->travel_start_date;
            $pnlRecord->end_date = $matchedEmail->travel_end_date;
            $pnlRecord->save();
            
            // Update all PnL items
            $pnlItems = PnlItem::where('pnl_record_id', $pnlRecord->id)->get();
            $updatedCount = 0;
            
            foreach ($pnlItems as $item) {
                $updated = false;
                
                if ($matchedEmail->guest_name && $matchedEmail->guest_name !== 'NA' && $matchedEmail->guest_name !== '') {
                    $item->client_name = $matchedEmail->guest_name;
                    $updated = true;
                }
                
                if ($matchedEmail->travel_start_date) {
                    $item->start_date = $matchedEmail->travel_start_date;
                    if (isset($item->check_in_date)) {
                        $item->check_in_date = $matchedEmail->travel_start_date;
                    }
                    $updated = true;
                }
                
                if ($matchedEmail->travel_end_date) {
                    $item->end_date = $matchedEmail->travel_end_date;
                    if (isset($item->check_out_date)) {
                        $item->check_out_date = $matchedEmail->travel_end_date;
                    }
                    $updated = true;
                }
                
                if ($updated) {
                    $item->save();
                    $updatedCount++;
                }
            }
            
            Log::info("✅ Updated PnL record and {$updatedCount} items with client info");
            return $updatedCount;
        }
        
        return 0;
        
    } catch (\Exception $e) {
        Log::error("Error updating PnL items with invoice data: " . $e->getMessage());
        return 0;
    }
}
/**
 * Extract individual attraction items from email
 */
/**
 * Extract individual attraction items from email
 */
private function extractAttractionItems($html, $plainText)
{
    $attractionItems = [];
    
    // Try to find Attraction section
    if (preg_match('/Attraction(.*?)(?:Tour Transfers|Meals|Other Rates|$)/is', $plainText, $sectionMatch)) {
        $attractionSection = $sectionMatch[1];
        Log::info("Attraction section found");
        Log::info("Attraction section preview: " . substr($attractionSection, 0, 500));
        
        // Look for table rows
        $lines = explode("\n", $attractionSection);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Check if line has pipe format
            if (strpos($line, '|') !== false) {
                $parts = explode('|', $line);
                $cleanParts = array_map('trim', $parts);
                $cleanParts = array_filter($cleanParts, function($part) {
                    return $part !== '';
                });
                $cleanParts = array_values($cleanParts);
                
                // Need at least 4 columns
                if (count($cleanParts) < 4) continue;
                
                // Skip header row
                $firstPart = strtoupper($cleanParts[0] ?? '');
                if (in_array($firstPart, ['#DAY(S)', 'DAY', 'CITY', 'ATTRACTION', 'TOTAL'])) {
                    Log::info("Skipping header row: " . $line);
                    continue;
                }
                
                // Check if this is a total row
                $firstPartLower = strtolower($cleanParts[0] ?? '');
                if (strpos($firstPartLower, 'total') !== false) {
                    Log::info("Skipping total row: " . $line);
                    continue;
                }
                
                // Extract attraction name - usually column 2 (index 2) or column 3 (index 3)
                $attractionName = '';
                $amount = 0;
                
                // Find attraction name - look for column with text that's not a day or city
                foreach ($cleanParts as $index => $part) {
                    $part = trim($part);
                    // Skip if it's a day (Day 2, Day 3, etc.)
                    if (preg_match('/^Day\s*\d+/i', $part)) continue;
                    // Skip if it's a city name (Danang, Hanoi, Langkawi, etc.)
                    if (preg_match('/^[A-Za-z\s]+$/', $part) && strlen($part) < 20 && in_array(trim($part), ['Danang', 'Hanoi', 'Langkawi', 'Singapore', 'Bali', 'Kuala Lumpur', 'Colombo'])) continue;
                    // If it's a long text with spaces, it's likely the attraction name
                    if (strlen($part) > 5 && !preg_match('/^\d+$/', $part)) {
                        $attractionName = $part;
                        break;
                    }
                }
                
                // If no attraction name found, try column 2
                if (empty($attractionName) && isset($cleanParts[2])) {
                    $attractionName = trim($cleanParts[2]);
                }
                
                // If still no attraction name, skip
                if (empty($attractionName)) {
                    Log::info("No attraction name found in line: " . $line);
                    continue;
                }
                
                // Try to find amount - look for number in RATE column or any column with decimal
                foreach ($cleanParts as $part) {
                    $part = trim($part);
                    // Look for pattern like "Adult: 85" or "85.00" or "52.6"
                    if (preg_match('/Adult:\s*([\d.]+)/i', $part, $match)) {
                        $amount = floatval($match[1]);
                        break;
                    }
                    // Look for standalone number with decimal
                    if (preg_match('/\b(\d+\.\d+)\b/', $part, $match)) {
                        $amount = floatval($match[1]);
                        break;
                    }
                }
                
                // If amount not found, try to find in the last column
                if ($amount == 0) {
                    $lastPart = trim(end($cleanParts));
                    if (preg_match('/\b(\d+\.\d+)\b/', $lastPart, $match)) {
                        $amount = floatval($match[1]);
                    }
                }
                
                // Skip if amount is 0 or negative
                if ($amount <= 0) {
                    Log::info("Skipping zero amount attraction: {$attractionName} - Amount: {$amount}");
                    continue;
                }
                
                // Skip if it looks like a total or summary
                if (preg_match('/total|transfer|entrance/i', $attractionName)) {
                    continue;
                }
                
                $attractionItems[] = [
                    'service_name' => $attractionName,
                    'amount' => $amount,
                    'details' => ['remarks' => $attractionName]
                ];
                
                Log::info("✓ Attraction item extracted: {$attractionName} - \${$amount}");
            }
        }
    }
    
    // If no items found, try HTML extraction
    if (empty($attractionItems) && !empty($html)) {
        Log::info("No attraction items from plain text, trying HTML extraction");
        $attractionItems = $this->extractAttractionItemsFromHTML($html);
    }
    
    return $attractionItems;
}

/**
 * Extract attraction items from HTML
 */
/**
 * Extract attraction items from HTML
 */
private function extractAttractionItemsFromHTML($html)
{
    $attractionItems = [];
    
    try {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        
        $tables = $dom->getElementsByTagName('table');
        
        foreach ($tables as $table) {
            $rows = $table->getElementsByTagName('tr');
            if ($rows->length < 2) continue;
            
            // Check if this is an Attraction table
            $headers = [];
            $firstRow = $rows->item(0);
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'])) {
                    $headers[] = trim(strtoupper($cell->textContent));
                }
            }
            
            $headerText = implode(' ', $headers);
            Log::info("Table headers: " . $headerText);
            
            // Check if this is an Attraction table (has ATTRACTION or ENTRANCE)
            if (strpos($headerText, 'ATTRACTION') === false && strpos($headerText, 'ENTRANCE') === false) {
                continue;
            }
            
            Log::info("Processing Attraction table: " . $headerText);
            
            // Find column indices
            $nameIndex = -1;
            $amountIndex = -1;
            
            foreach ($headers as $index => $header) {
                $upperHeader = strtoupper(trim($header));
                if (strpos($upperHeader, 'ATTRACTION') !== false || 
                    strpos($upperHeader, 'NAME') !== false) {
                    $nameIndex = $index;
                }
                if (strpos($upperHeader, 'RATE') !== false || 
                    strpos($upperHeader, 'TOTAL') !== false) {
                    $amountIndex = $index;
                }
            }
            
            if ($nameIndex == -1) {
                Log::info("No name column found, using column 2");
                $nameIndex = 2;
            }
            
            // Parse rows (skip header)
            for ($i = 1; $i < $rows->length; $i++) {
                $row = $rows->item($i);
                $cells = [];
                
                foreach ($row->childNodes as $cell) {
                    if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'])) {
                        $cells[] = trim($cell->textContent);
                    }
                }
                
                if (count($cells) <= $nameIndex) continue;
                
                $attractionName = trim($cells[$nameIndex]);
                
                // Skip if empty or total row
                if (empty($attractionName) || strtoupper($attractionName) === 'TOTAL') {
                    continue;
                }
                
                // Skip if it looks like a day or city
                if (preg_match('/^Day\s*\d+/i', $attractionName)) continue;
                
                $amount = 0;
                
                // Try to find amount in RATE column
                if ($amountIndex != -1 && isset($cells[$amountIndex])) {
                    $amountValue = trim($cells[$amountIndex]);
                    Log::info("Amount cell value: " . $amountValue);
                    
                    // Try to extract amount from "Adult: 85" or "85.00"
                    if (preg_match('/Adult:\s*([\d.]+)/i', $amountValue, $match)) {
                        $amount = floatval($match[1]);
                    } elseif (preg_match('/\b(\d+\.\d+)\b/', $amountValue, $match)) {
                        $amount = floatval($match[1]);
                    }
                }
                
                // If amount not found, try to find in TRANSFER column
                if ($amount == 0) {
                    foreach ($cells as $cell) {
                        // Check for TRANSFER column with amount
                        if (preg_match('/Adult:\s*([\d.]+)/i', $cell, $match)) {
                            $amount = floatval($match[1]);
                            break;
                        }
                        if (preg_match('/\b(\d+\.\d+)\b/', $cell, $match)) {
                            $amount = floatval($match[1]);
                            break;
                        }
                    }
                }
                
                if ($amount <= 0) {
                    Log::info("Skipping zero amount attraction: {$attractionName}");
                    continue;
                }
                
                // Skip if it's not really an attraction name (too short or all caps)
                if (strlen($attractionName) < 3) continue;
                
                $attractionItems[] = [
                    'service_name' => $attractionName,
                    'amount' => $amount,
                    'details' => ['remarks' => $attractionName]
                ];
                
                Log::info("✓ Attraction item extracted (HTML): {$attractionName} - \${$amount}");
            }
            
            if (!empty($attractionItems)) break;
        }
        
    } catch (\Exception $e) {
        Log::error('Attraction items HTML extraction error: ' . $e->getMessage());
    }
    
    return $attractionItems;
}


private function extractTourTransferItems($html, $plainText, $totalPax)
{
    $transferItems = [];
    
    // First try HTML parsing for VN/SG/MY or any HTML content
    if (!empty($html)) {
        $items = $this->extractTourTransferItemsFromHTML($html, $totalPax);
        if (!empty($items)) {
            return $items;
        }
    }
    
    // Fallback to plain text parsing (for LK or if HTML parsing fails)
    return $this->extractTourTransferItemsFromPlainText($plainText, $totalPax);
}
/**
 * Fallback: Extract Tour Transfer items from plain text (pipe format or space-separated)
 */
private function extractTourTransferItemsFromPlainText($plainText, $totalPax)
{
    $transferItems = [];
    
    if (empty($plainText)) {
        return $transferItems;
    }
    
    // Try to find Tour Transfers section
    if (!preg_match('/Tour Transfers(.*?)(?:Attraction|Meals|Other Rates|$)/is', $plainText, $sectionMatch)) {
        Log::info("No Tour Transfers section found in plain text");
        return $transferItems;
    }
    
    $section = $sectionMatch[1];
    Log::info("Tour Transfers section found in plain text");
    
    // Look for rows with pipe format: | Day 2 | Langkawi | (PVT) Half Day Tour | 0 | 0 | 150 | Adult: 75 |
    // Or similar with spaces
    $lines = explode("\n", $section);
    $currentService = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Skip header and total lines
        if (preg_match('/#DAY|CITY|ATTRACTION|ADULT|CHILD|TRANSFER|RATE|Total/i', $line)) {
            continue;
        }
        
        // Try pipe format
        if (strpos($line, '|') !== false) {
            $parts = explode('|', $line);
            $cleanParts = array_map('trim', $parts);
            $cleanParts = array_filter($cleanParts, function($p) { return $p !== ''; });
            $cleanParts = array_values($cleanParts);
            
            // Need at least 5 columns
            if (count($cleanParts) < 5) continue;
            
            // Attempt to identify columns: day, city, attraction, adult entrance, child entrance, transfer, rate
            // Usually day is first, city second, attraction third, then numbers, transfer, rate
            $serviceName = '';
            $transferAmount = 0;
            $adultRate = 0;
            
            // Find attraction name (usually third column)
            if (isset($cleanParts[2]) && !empty($cleanParts[2]) && !is_numeric($cleanParts[2])) {
                $serviceName = trim($cleanParts[2]);
            } elseif (isset($cleanParts[1]) && !empty($cleanParts[1]) && !is_numeric($cleanParts[1])) {
                $serviceName = trim($cleanParts[1]);
            }
            
            // Find transfer and rate
            foreach ($cleanParts as $idx => $val) {
                if (is_numeric($val) && floatval($val) > 0 && $idx > 2) {
                    // Could be transfer or other numeric
                    // Check if this is transfer column (usually before rate)
                    if (isset($cleanParts[$idx+1]) && preg_match('/Adult:/i', $cleanParts[$idx+1])) {
                        $transferAmount = floatval($val);
                        // rate is next
                        if (preg_match('/Adult:\s*([\d.]+)/i', $cleanParts[$idx+1], $match)) {
                            $adultRate = floatval($match[1]);
                        }
                    } elseif (preg_match('/Adult:/i', $val)) {
                        // rate is in this column
                        if (preg_match('/Adult:\s*([\d.]+)/i', $val, $match)) {
                            $adultRate = floatval($match[1]);
                            // transfer might be previous column
                            if ($idx > 0 && is_numeric($cleanParts[$idx-1])) {
                                $transferAmount = floatval($cleanParts[$idx-1]);
                            }
                        }
                    }
                }
            }
            
            // If service name still empty, use day+city
            if (empty($serviceName)) {
                $day = isset($cleanParts[0]) ? trim($cleanParts[0]) : '';
                $city = isset($cleanParts[1]) ? trim($cleanParts[1]) : '';
                if (!empty($day) && !empty($city)) {
                    $serviceName = $day . ' - ' . $city;
                } else {
                    continue;
                }
            }
            
            // Determine amount
            $amount = 0;
            if ($transferAmount > 0) {
                $amount = $transferAmount;
            } elseif ($adultRate > 0 && $totalPax > 0) {
                $amount = $adultRate * $totalPax;
            }
            
            if ($amount <= 0) continue;
            
            // Avoid duplicates
            $exists = false;
            foreach ($transferItems as $item) {
                if ($item['service_name'] === $serviceName) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) continue;
            
            $transferItems[] = [
                'service_name' => $serviceName,
                'amount' => $amount,
                'details' => [
                    'remarks' => $serviceName,
                    'adult_rate' => $adultRate,
                    'transfer_amount' => $transferAmount,
                    'pax' => $totalPax,
                ]
            ];
            Log::info("✅ Added Tour Transfer item from plain text: {$serviceName} - \${$amount}");
        }
        // If no pipe, maybe it's space-separated with columns
        else {
            // Try to match pattern: Day 2 Langkawi (PVT) Half Day Tour 0 0 150 Adult: 75
            if (preg_match('/Day\s*\d+\s+([A-Za-z]+)\s+(.+?)\s+(\d+)\s+(\d+)\s+(\d+)\s+Adult:\s*([\d.]+)/i', $line, $matches)) {
                $city = trim($matches[1]);
                $attraction = trim($matches[2]);
                $transferAmount = floatval($matches[5]);
                $adultRate = floatval($matches[6]);
                $serviceName = $attraction ?: $city;
                
                $amount = 0;
                if ($transferAmount > 0) {
                    $amount = $transferAmount;
                } elseif ($adultRate > 0 && $totalPax > 0) {
                    $amount = $adultRate * $totalPax;
                }
                if ($amount <= 0) continue;
                
                $exists = false;
                foreach ($transferItems as $item) {
                    if ($item['service_name'] === $serviceName) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists) continue;
                
                $transferItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $serviceName,
                        'adult_rate' => $adultRate,
                        'transfer_amount' => $transferAmount,
                        'pax' => $totalPax,
                    ]
                ];
                Log::info("✅ Added Tour Transfer item from space-separated: {$serviceName} - \${$amount}");
            }
        }
    }
    
    // If still no items, try to get total from "Total" row
    if (empty($transferItems)) {
        if (preg_match('/Total.*?(\d+\.?\d*)\s*USD/i', $section, $match)) {
            $total = floatval($match[1]);
            if ($total > 0) {
                $transferItems[] = [
                    'service_name' => 'Tour Transfers Total',
                    'amount' => $total,
                    'details' => ['remarks' => 'Total tour transfers']
                ];
                Log::info("⚠️ Using total tour transfer amount as fallback: \${$total}");
            }
        }
    }
    
    return $transferItems;
}
/**
 * Extract transfers from Attraction section (if they are mixed in)
 */
private function extractTransfersFromAttraction($plainText, $noAdult = 0)
{
    $transferItems = [];
    
    if ($noAdult == 0) {
        if (preg_match('/No\.\s*Adult\s*:\s*(\d+)/i', $plainText, $match)) {
            $noAdult = intval($match[1]);
        }
    }
    
    if (preg_match('/Attraction(.*?)(?:Tour Transfers|Meals|Other Rates|$)/is', $plainText, $sectionMatch)) {
        $attractionSection = $sectionMatch[1];
        Log::info("Checking Attraction section for transfers...");
        
        // Look for lines with Adult: X pattern
        $lines = explode("\n", $attractionSection);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (preg_match('/Adult:\s*([\d.]+)/i', $line, $match)) {
                $adultRate = floatval($match[1]);
                if ($adultRate <= 0) continue;
                
                // Try to find service name
                $serviceName = '';
                
                if (strpos($line, '|') !== false) {
                    $parts = explode('|', $line);
                    $cleanParts = array_map('trim', $parts);
                    $cleanParts = array_filter($cleanParts, function($part) {
                        return $part !== '';
                    });
                    $cleanParts = array_values($cleanParts);
                    
                    foreach ($cleanParts as $index => $part) {
                        if (stripos($part, 'Transfer') !== false || stripos($part, 'SIC') !== false || stripos($part, 'PVT') !== false) {
                            $serviceName = trim($part);
                            break;
                        }
                    }
                    
                    if (empty($serviceName) && isset($cleanParts[2]) && !empty($cleanParts[2]) && strlen($cleanParts[2]) > 3) {
                        $serviceName = trim($cleanParts[2]);
                    }
                }
                
                if (empty($serviceName)) {
                    if (preg_match('/Day\s*\d+\s+[A-Za-z\s]+\s+(.+?)\s*Adult:/i', $line, $nameMatch)) {
                        $serviceName = trim($nameMatch[1]);
                    }
                }
                
                if (!empty($serviceName)) {
                    $serviceName = str_replace('|', '', $serviceName);
                    $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                    $serviceName = trim($serviceName);
                    
                    $cityNames = ['Danang', 'Hanoi', 'Langkawi', 'Phuquoc', 'Singapore', 'Bali', 'Kuala Lumpur', 'Colombo', 'Phu Quoc', 'Hoi An', 'City Center', 'Da Nang'];
                    if (in_array($serviceName, $cityNames)) continue;
                    if (strlen($serviceName) < 3) continue;
                }
                
                if (empty($serviceName)) continue;
                
                $amount = $adultRate * $noAdult;
                if ($amount <= 0) continue;
                
                $transferItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $serviceName,
                        'adult_rate' => $adultRate,
                        'pax' => $noAdult
                    ]
                ];
                
                Log::info("✓ Transfer extracted from Attraction section: {$serviceName} - \${$amount} (Adult: {$adultRate} x {$noAdult} pax)");
            }
        }
    }
    
    return $transferItems;
}

/**
 * Extract Tour Transfer items from HTML tables
 */
private function extractTourTransferItemsFromHTML($html, $totalPax)
{
    $transferItems = [];
    
    try {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        
        $tables = $dom->getElementsByTagName('table');
        
        foreach ($tables as $table) {
            $rows = $table->getElementsByTagName('tr');
            if ($rows->length < 2) continue;
            
            // Check headers for Tour Transfers table
            $headers = [];
            $firstRow = $rows->item(0);
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'])) {
                    $headers[] = trim(strtoupper($cell->textContent));
                }
            }
            
            // Look for specific columns: #DAY(S), CITY, ATTRACTION, ADULT ENTRANCE, CHILD ENTRANCE, TRANSFER, RATE
            $hasDay = false;
            $hasTransfer = false;
            $hasRate = false;
            foreach ($headers as $h) {
                if (strpos($h, 'DAY') !== false) $hasDay = true;
                if (strpos($h, 'TRANSFER') !== false) $hasTransfer = true;
                if (strpos($h, 'RATE') !== false) $hasRate = true;
            }
            
            if (!$hasDay || !$hasTransfer || !$hasRate) {
                continue; // not a tour transfer table
            }
            
            Log::info("Found Tour Transfers table with headers: " . implode(', ', $headers));
            
            // Find column indices
            $dayIndex = -1;
            $cityIndex = -1;
            $attractionIndex = -1;
            $adultEntranceIndex = -1;
            $childEntranceIndex = -1;
            $transferIndex = -1;
            $rateIndex = -1;
            
            foreach ($headers as $i => $h) {
                $upper = strtoupper(trim($h));
                if (strpos($upper, 'DAY') !== false) $dayIndex = $i;
                if (strpos($upper, 'CITY') !== false) $cityIndex = $i;
                if (strpos($upper, 'ATTRACTION') !== false) $attractionIndex = $i;
                if (strpos($upper, 'ADULT ENTRANCE') !== false) $adultEntranceIndex = $i;
                if (strpos($upper, 'CHILD ENTRANCE') !== false) $childEntranceIndex = $i;
                if (strpos($upper, 'TRANSFER') !== false) $transferIndex = $i;
                if (strpos($upper, 'RATE') !== false) $rateIndex = $i;
            }
            
            // Process data rows
            for ($i = 1; $i < $rows->length; $i++) {
                $row = $rows->item($i);
                $cells = [];
                foreach ($row->childNodes as $cell) {
                    if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'])) {
                        $cells[] = trim($cell->textContent);
                    }
                }
                
                // Skip if not enough columns
                if (count($cells) <= max($transferIndex, $rateIndex)) {
                    continue;
                }
                
                // Get service name (attraction) if available, else city + day
                $serviceName = '';
                if ($attractionIndex != -1 && !empty($cells[$attractionIndex])) {
                    $serviceName = trim($cells[$attractionIndex]);
                } elseif ($cityIndex != -1 && !empty($cells[$cityIndex])) {
                    $serviceName = trim($cells[$cityIndex]);
                } else {
                    $serviceName = 'Tour Transfer';
                }
                
                // Skip if service name is empty or looks like total
                if (empty($serviceName) || strtoupper($serviceName) === 'TOTAL') {
                    continue;
                }
                
                // Get transfer amount (if any)
                $transferValue = trim($cells[$transferIndex] ?? '');
                $transferAmount = floatval(preg_replace('/[^0-9.]/', '', $transferValue));
                
                // Get rate column (e.g., "Adult: 75")
                $rateValue = trim($cells[$rateIndex] ?? '');
                $adultRate = 0;
                if (preg_match('/Adult:\s*([\d.]+)/i', $rateValue, $match)) {
                    $adultRate = floatval($match[1]);
                }
                
                // Determine total amount:
                // If transferAmount > 0, use that (it might be total for all pax)
                // Else if adultRate > 0, multiply by total pax
                $amount = 0;
                if ($transferAmount > 0) {
                    $amount = $transferAmount;
                } elseif ($adultRate > 0 && $totalPax > 0) {
                    $amount = $adultRate * $totalPax;
                }
                
                // Skip if amount <= 0
                if ($amount <= 0) {
                    continue;
                }
                
                // Avoid duplicates (same service name)
                $exists = false;
                foreach ($transferItems as $item) {
                    if ($item['service_name'] === $serviceName) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists) continue;
                
                $transferItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $serviceName,
                        'adult_rate' => $adultRate,
                        'transfer_amount' => $transferAmount,
                        'pax' => $totalPax,
                    ]
                ];
                
                Log::info("✅ Added Tour Transfer item from HTML: {$serviceName} - \${$amount} (Adult rate: {$adultRate}, Transfer: {$transferAmount}, Pax: {$totalPax})");
            }
            
            if (!empty($transferItems)) {
                break;
            }
        }
        
    } catch (\Exception $e) {
        Log::error('Error extracting Tour Transfers from HTML: ' . $e->getMessage());
    }
    
    return $transferItems;
}
/**
 * Extract individual Other Rates items
 */
/**
 * Extract individual meal items from email
 */
private function extractMealItems($plainText)
{
    $mealItems = [];
    
    // Get No. Adult
    $noAdult = 0;
    if (preg_match('/No\.\s*Adult:\s*(\d+)/i', $plainText, $match)) {
        $noAdult = intval($match[1]);
    }
    
    // Try to find Meals section
    if (preg_match('/Meals(.*?)(?:Transport|Other Rates|Attraction|Tour Transfers|$)/is', $plainText, $sectionMatch)) {
        $mealSection = $sectionMatch[1];
        Log::info("Meals section found");
        Log::info("Meals section preview: " . substr($mealSection, 0, 500));
        
        // Look for meal rows with pipe format
        $lines = explode("\n", $mealSection);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (strpos($line, '|') !== false) {
                $parts = explode('|', $line);
                $cleanParts = array_map('trim', $parts);
                $cleanParts = array_filter($cleanParts, function($part) {
                    return $part !== '';
                });
                $cleanParts = array_values($cleanParts);
                
                if (count($cleanParts) < 2) continue;
                
                // Skip header
                $firstPart = strtoupper($cleanParts[0] ?? '');
                if (in_array($firstPart, ['DAY', 'TOTAL'])) continue;
                
                // Check if this is a total row
                if (strpos(strtolower($cleanParts[0] ?? ''), 'total') !== false) {
                    continue;
                }
                
                // Get day name
                $day = trim($cleanParts[0] ?? '');
                if (empty($day) || !preg_match('/Day/i', $day)) continue;
                
                // Try to find total amount in the last column
                $amount = 0;
                $lastPart = trim(end($cleanParts));
                if (preg_match('/\b(\d+\.\d+)\b/', $lastPart, $match)) {
                    $amount = floatval($match[1]);
                }
                
                // Also check other columns for meal amounts
                $breakfast = 0;
                $lunch = 0;
                $dinner = 0;
                
                foreach ($cleanParts as $part) {
                    if (preg_match('/BREAK.*?(\d+\.?\d*)/i', $part, $match)) {
                        $breakfast = floatval($match[1]);
                    }
                    if (preg_match('/LUNCH.*?(\d+\.?\d*)/i', $part, $match)) {
                        $lunch = floatval($match[1]);
                    }
                    if (preg_match('/DINNER.*?(\d+\.?\d*)/i', $part, $match)) {
                        $dinner = floatval($match[1]);
                    }
                }
                
                // If no total found, calculate from meal components
                if ($amount == 0 && ($breakfast > 0 || $lunch > 0 || $dinner > 0)) {
                    // Multiply by No. Adult
                    $amount = ($breakfast + $lunch + $dinner) * $noAdult;
                }
                
                if ($amount <= 0) continue;
                
                // Create separate items for each meal type if they have amounts
                if ($breakfast > 0) {
                    $mealItems[] = [
                        'service_name' => "{$day} - Breakfast",
                        'amount' => $breakfast * $noAdult,
                        'details' => ['remarks' => "{$day} - Breakfast", 'pax' => $noAdult]
                    ];
                }
                if ($lunch > 0) {
                    $mealItems[] = [
                        'service_name' => "{$day} - Lunch",
                        'amount' => $lunch * $noAdult,
                        'details' => ['remarks' => "{$day} - Lunch", 'pax' => $noAdult]
                    ];
                }
                if ($dinner > 0) {
                    $mealItems[] = [
                        'service_name' => "{$day} - Dinner",
                        'amount' => $dinner * $noAdult,
                        'details' => ['remarks' => "{$day} - Dinner", 'pax' => $noAdult]
                    ];
                }
            }
        }
    }
    
    return $mealItems;
}

/**
 * Extract individual Other Rates items from the email
 * Handles format: | **Sun World Ba Na Hills: Cable car ticket only** | adult : 6 | 37 | 222.00 |
 */
/**
 * Extract individual Other Rates items from email HTML
 * Handles HTML tables with headers: PAX, RATE, TOTAL
 */
private function extractOtherRateItems($html, $plainText = '')
{
    $otherRateItems = [];
    
    // If HTML is empty, fallback to plain text parsing
    if (empty($html)) {
        return $this->extractOtherRateItemsFromPlainText($plainText);
    }
    
    try {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        
        $tables = $dom->getElementsByTagName('table');
        
        foreach ($tables as $table) {
            $rows = $table->getElementsByTagName('tr');
            if ($rows->length < 2) continue;
            
            // Check headers to identify the Other Rates table
            $headers = [];
            $firstRow = $rows->item(0);
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'])) {
                    $headers[] = trim(strtoupper($cell->textContent));
                }
            }
            
            // Look for headers: PAX, RATE, TOTAL (case-insensitive)
            $hasPax = false;
            $hasRate = false;
            $hasTotal = false;
            foreach ($headers as $h) {
                if (strpos($h, 'PAX') !== false) $hasPax = true;
                if (strpos($h, 'RATE') !== false) $hasRate = true;
                if (strpos($h, 'TOTAL') !== false) $hasTotal = true;
            }
            
            // If not an Other Rates table, skip
            if (!$hasPax || !$hasRate || !$hasTotal) {
                continue;
            }
            
            Log::info("Found Other Rates table with headers: " . implode(', ', $headers));
            
            // Find column indices for PAX, RATE, TOTAL
            $paxIndex = -1;
            $rateIndex = -1;
            $totalIndex = -1;
            foreach ($headers as $i => $h) {
                if (strpos($h, 'PAX') !== false) $paxIndex = $i;
                if (strpos($h, 'RATE') !== false) $rateIndex = $i;
                if (strpos($h, 'TOTAL') !== false) $totalIndex = $i;
            }
            
            // Process data rows (skip header row)
            for ($i = 1; $i < $rows->length; $i++) {
                $row = $rows->item($i);
                $cells = [];
                foreach ($row->childNodes as $cell) {
                    if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'])) {
                        $cells[] = trim($cell->textContent);
                    }
                }
                
                // Skip if not enough columns
                if (count($cells) <= max($paxIndex, $rateIndex, $totalIndex)) {
                    continue;
                }
                
                // Get service name (usually the first cell, but sometimes the table has an empty first column)
                // In your example, the first cell is empty, so the service name is in column 1 (index 1)
                $serviceName = '';
                $col0 = trim($cells[0] ?? '');
                $col1 = trim($cells[1] ?? '');
                $col2 = trim($cells[2] ?? '');
                
                // Determine which column holds the service name
                // If col0 is empty or is "Total", use col1
                if (empty($col0) || strtoupper($col0) === 'TOTAL') {
                    $serviceName = $col1;
                } else {
                    // Otherwise, assume col0 is the name (or maybe col1)
                    $serviceName = $col0;
                }
                
                // Skip if service name is empty or is a total row
                if (empty($serviceName) || strtoupper($serviceName) === 'TOTAL') {
                    continue;
                }
                
                // Get PAX info from the PAX column
                $paxInfo = $cells[$paxIndex] ?? '';
                $paxCount = 0;
                $paxType = 'adult';
                if (preg_match('/adult\s*:\s*(\d+)/i', $paxInfo, $match)) {
                    $paxCount = intval($match[1]);
                    $paxType = 'adult';
                } elseif (preg_match('/cnb\s*:\s*(\d+)/i', $paxInfo, $match)) {
                    $paxCount = intval($match[1]);
                    $paxType = 'cnb';
                }
                
                // Get rate and total
                $rateValue = trim($cells[$rateIndex] ?? '');
                $rate = floatval(preg_replace('/[^0-9.]/', '', $rateValue));
                
                $totalValue = trim($cells[$totalIndex] ?? '');
                $amount = floatval(preg_replace('/[^0-9.]/', '', $totalValue));
                
                // Skip if amount <= 0 or (child row with 0 pax)
                if ($amount <= 0) continue;
                if ($paxType === 'cnb' && $paxCount == 0) continue;
                
                // Avoid duplicates (same service name)
                $exists = false;
                foreach ($otherRateItems as $item) {
                    if ($item['service_name'] === $serviceName) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists) continue;
                
                $otherRateItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $serviceName,
                        'pax' => $paxCount,
                        'rate' => $rate,
                        'pax_type' => $paxType,
                    ]
                ];
                
                Log::info("✅ Added Other Rate item from HTML: {$serviceName} - \${$amount} (Pax: {$paxCount}, Rate: {$rate})");
            }
            
            // If we found items, break out of table loop
            if (!empty($otherRateItems)) {
                break;
            }
        }
        
    } catch (\Exception $e) {
        Log::error('Error extracting Other Rates from HTML: ' . $e->getMessage());
    }
    
    // If no items found via HTML, fallback to plain text parsing
    if (empty($otherRateItems) && !empty($plainText)) {
        Log::info("No Other Rate items from HTML, trying plain text fallback");
        return $this->extractOtherRateItemsFromPlainText($plainText);
    }
    
    return $otherRateItems;
}

/**
 * Fallback: Extract Other Rates from plain text (pipe format)
 */
private function extractOtherRateItemsFromPlainText($plainText)
{
    $otherRateItems = [];
    
    // Primary pattern: bold service names
    $pattern = '/\|\s*\*\*([^*]+?)\*\*\s*\|\s*(?:adult|cnb)\s*:\s*(\d+)\s*\|\s*([\d.]+)\s*\|\s*([\d,]+\.\d+)\s*\|/i';
    if (preg_match_all($pattern, $plainText, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $serviceName = trim($match[1]);
            $paxCount = intval($match[2]);
            $rate = floatval($match[3]);
            $amount = floatval(str_replace(',', '', $match[4]));
            if ($amount <= 0 || empty($serviceName)) continue;
            if (stripos($match[0], 'cnb') !== false && $paxCount == 0) continue;
            
            // Avoid duplicates
            $exists = false;
            foreach ($otherRateItems as $item) {
                if ($item['service_name'] === $serviceName) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) continue;
            
            $otherRateItems[] = [
                'service_name' => $serviceName,
                'amount' => $amount,
                'details' => [
                    'remarks' => $serviceName,
                    'pax' => $paxCount,
                    'rate' => $rate,
                ]
            ];
        }
        if (!empty($otherRateItems)) return $otherRateItems;
    }
    
    // Fallback: without bold
    $fallbackPattern = '/\|\s*([^|]+?)\s*\|\s*(?:adult|cnb)\s*:\s*(\d+)\s*\|\s*([\d.]+)\s*\|\s*([\d,]+\.\d+)\s*\|/i';
    if (preg_match_all($fallbackPattern, $plainText, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $serviceName = trim($match[1]);
            if (in_array(strtoupper($serviceName), ['PAX', 'RATE', 'TOTAL', ''])) continue;
            $paxCount = intval($match[2]);
            $rate = floatval($match[3]);
            $amount = floatval(str_replace(',', '', $match[4]));
            if ($amount <= 0 || empty($serviceName)) continue;
            if (stripos($match[0], 'cnb') !== false && $paxCount == 0) continue;
            
            $exists = false;
            foreach ($otherRateItems as $item) {
                if ($item['service_name'] === $serviceName) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) continue;
            
            $otherRateItems[] = [
                'service_name' => $serviceName,
                'amount' => $amount,
                'details' => [
                    'remarks' => $serviceName,
                    'pax' => $paxCount,
                    'rate' => $rate,
                ]
            ];
        }
    }
    
    return $otherRateItems;
}

/**
 * Alternative method to extract Other Rate items using pattern matching
 */
private function extractOtherRateItemsByPattern($plainText)
{
    $otherRateItems = [];
    
    // Try to find the Other Rates section
    if (preg_match('/Other Rates(.*?)(?:Attraction|Tour Transfers|Meals|$)/is', $plainText, $sectionMatch)) {
        $otherSection = $sectionMatch[1];
        
        // Look for patterns like: "| Service Name | adult : 6 | 37 | 222.00 |"
        // This pattern matches lines that start with |, have text, then adult : number, then rate, then amount
        if (preg_match_all('/\|\s*([^|]+?)\s*\|\s*adult\s*:\s*(\d+)\s*\|\s*(\d+\.?\d*)\s*\|\s*([\d,]+\.\d+)\s*\|/i', $otherSection, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $serviceName = trim($match[1]);
                $paxCount = intval($match[2]);
                $rate = floatval($match[3]);
                $amount = floatval(str_replace(',', '', $match[4]));
                
                // Skip if amount is 0 or negative
                if ($amount <= 0) continue;
                
                // Skip if service name is empty or too short
                if (empty($serviceName) || strlen($serviceName) < 3) continue;
                
                // Skip if it's a child row (we only want adult rows)
                // Child rows are handled separately, we only process adult rows here
                
                $otherRateItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $serviceName,
                        'pax' => $paxCount,
                        'rate' => $rate
                    ]
                ];
                
                Log::info("✓ Other Rate item extracted (pattern): {$serviceName} - \${$amount} (Pax: {$paxCount}, Rate: {$rate})");
            }
        }
    }
    
    return $otherRateItems;
}
}