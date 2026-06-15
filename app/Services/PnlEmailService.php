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
            $response = Http::withToken($this->accessToken)
                ->get('https://graph.microsoft.com/v1.0/users/' . env('GRAPH_PNL_USER') . '/messages', [
                    '$top' => 100,
                    '$orderby' => 'receivedDateTime desc',
                    '$select' => 'id,subject,body,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
                ]);
            
            if (!$response->ok()) {
                Log::error('Failed to fetch PnL emails: ' . $response->body());
                return 0;
            }
            
            $messages = $response->json()['value'] ?? [];
            Log::info("📥 Fetched " . count($messages) . " PnL emails");
            
            $newCount = 0;
            $sno = PnlRecord::max('sno') ?? 0;
            
            foreach ($messages as $message) {
                $existing = PnlRecord::where('message_id', $message['id'])->first();
                
                if (!$existing) {
                    $sno++;
                    $saved = $this->savePnLEmail($message, $sno);
                    if ($saved) {
                        $newCount++;
                        Log::info("✅ Saved new PnL email: " . ($message['subject'] ?? 'No Subject'));
                    }
                }
            }
            
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
            Log::info("HTML contains table: " . (strpos($htmlBody, '<table') !== false ? 'YES' : 'NO'));
Log::info("HTML contains Hotels/Cruises: " . (stripos($htmlBody, 'Hotels/Cruises') !== false ? 'YES' : 'NO'));
Log::info("HTML Preview: " . substr($htmlBody, 0, 1000));
            
            $fromEmail = $message['from']['emailAddress']['address'] ?? '';
            $fromName = $message['from']['emailAddress']['name'] ?? '';
            $receivedAt = Carbon::parse($message['receivedDateTime']);
            $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
            
            // ========== EXTRACT HEADER DATA ==========
            $tourNumber = null;
            if (preg_match('/Tour No:\s*#?(\d+)/i', $plainText, $match)) {
                $tourNumber = $match[1];
                Log::info("Tour Number: " . $tourNumber);
            }
            
            $isNumber = null;
            if (preg_match('/Is Number:\s*([A-Z]{2})\s*(\d+)/i', $plainText, $match)) {
                $isNumber = $match[1] . $match[2];
                Log::info("IS Number: " . $isNumber);
            }
            
            $agentName = 'Unknown';
            if (preg_match('/Agent:\s*([^\n]+?)(?:\s+No\.|\s+Currency|$)/i', $plainText, $match)) {
                $agentName = trim($match[1]);
                Log::info("Agent Name: " . $agentName);
            }
            
            $totalPax = 0;
            if (preg_match('/No\.\s*Adult:\s*(\d+)/i', $plainText, $match)) {
                $totalPax = intval($match[1]);
                Log::info("Total Pax: " . $totalPax);
            } elseif (preg_match('/No\.\s*Pax:\s*(\d+)/i', $plainText, $match)) {
                $totalPax = intval($match[1]);
                Log::info("Total Pax: " . $totalPax);
            }
            
            $totalNights = 0;
            if (preg_match('/No\.\s*Night:\s*(\d+)/i', $plainText, $match)) {
                $totalNights = intval($match[1]);
                Log::info("Total Nights: " . $totalNights);
            }
            
            // Extract Total Tour Cost
           $totalTourCost = 0;
if (preg_match('/Total Tour Cost\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/i', $plainText, $match)) {
    $totalTourCost = floatval(str_replace(',', '', $match[1]));
    Log::info("Total Tour Cost: " . $totalTourCost);
}
 $profitLoss = $this->extractProfitLossFromEmail($htmlBody); // Use HTML, not plain text!
        if ($profitLoss === null) {
            // Fallback to plain text
            $profitLoss = $this->extractProfitLossFromEmail($plainText);
        }
        
        if ($profitLoss !== null) {
            Log::info("Profit/Loss extracted: " . $profitLoss);
        } else {
            Log::info("No Profit/Loss found in email");
        }
            // ========== EXTRACT ONLY CATEGORIES WITH VALUES ==========
            $categoriesFound = [];
            $pnlItemsToSave = [];
            
            // 1. Hotels/Cruises (FIXED)
           // 1. Hotels/Cruises (FIXED)
// 1. Hotels/Cruises (FIXED)
// Extract Profit/Loss

if (preg_match('/Hotels\/Cruises/i', $plainText)) {
    Log::info("=" . str_repeat("=", 50));
    Log::info("HOTELS/CRUISES SECTION DETECTED");
    Log::info("=" . str_repeat("=", 50));
    
    // Log the raw section for debugging
    if (preg_match('/Hotels\/Cruises(.*?)(?:Attraction|Tour Transfers|Transport|Meals|Other Rates|Close|$)/is', $plainText, $debugSection)) {
        $rawSection = $debugSection[1];
        Log::info("Raw Hotels section (first 1500 chars):");
        Log::info(substr($rawSection, 0, 1500));
        Log::info("--- End of section preview ---");
        
        // Count pipes to see table structure
        $pipeCount = substr_count($rawSection, '|');
        Log::info("Pipe count in section: {$pipeCount}");
        
        // Count lines with pipes
        $linesWithPipes = 0;
        $lines = explode("\n", $rawSection);
        foreach ($lines as $line) {
            if (strpos($line, '|') !== false) {
                $linesWithPipes++;
            }
        }
        Log::info("Lines containing pipes: {$linesWithPipes}");
    }
    
  $hotels = $this->extractHotelsFromEmail($htmlBody);
    
    Log::info("Hotels extraction result count: " . count($hotels));
    
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
            Log::info("Added hotel to PnL items: {$hotel['name']} - \${$hotel['amount']}");
        }
    } else {
        Log::warning("⚠️ Hotels/Cruises section found but NO hotels extracted!");
        Log::warning("Please check if the table format matches the expected pattern.");
    }
}
            
            // 2. Transport
            $transportTotal = $this->extractTransportTotalFromEmail($plainText);
            if ($transportTotal > 0) {
                $categoriesFound[] = 'Transport';
                $pnlItemsToSave[] = [
                    'type' => 'TRANSPORT',
                    'service_name' => 'Transport Expenses',
                    'hotel_name' => null,
                    'amount' => $transportTotal,
                    'details' => ['remarks' => 'Total transport expenses']
                ];
                Log::info("Transport Total: \${$transportTotal}");
            }
            
            // 3. Other Rates
            $otherRatesTotal = $this->extractOtherRatesTotalFromEmail($plainText);
            if ($otherRatesTotal > 0) {
                $categoriesFound[] = 'Other Rates';
                $pnlItemsToSave[] = [
                    'type' => 'OTHER RATES',
                    'service_name' => 'Other Rates (Entrance Tickets, etc.)',
                    'hotel_name' => null,
                    'amount' => $otherRatesTotal,
                    'details' => ['remarks' => 'Other attraction & entrance fees']
                ];
                Log::info("Other Rates Total: \${$otherRatesTotal}");
            }
            
            // 4. Attraction - ONLY if total > 0
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
                Log::info("Attraction Total: \${$attractionTotal}");
            }
            
            // 5. Tour Transfers - ONLY if total > 0
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
                Log::info("Tour Transfers Total: \${$tourTransfersTotal}");
            }
            
            // 6. Meals - ONLY if total > 0
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
                Log::info("Meals Total: \${$mealsTotal}");
            }
            
            $categoriesString = implode(', ', $categoriesFound);
            Log::info("Categories with values: " . $categoriesString);
            
            // Country and Currency
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
            
            $exchangeRate = $this->exchangeRates[$countryCode] ?? 25500;
            $tourRef = $tourNumber ? $tourNumber . 'CNTL' : null;
            Log::info('Profit Loss Value: ' . $profitLoss);
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
            
            Log::info("Created PnlRecord with ID: {$record->id}, Categories: {$categoriesString}");
            
            // ========== SAVE INVOICE ITEM ==========
            $itemsSaved = 0;
            
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
                $itemsSaved++;
                Log::info("✓ Saved INVOICE item: \${$totalTourCost}");
            }
            
            // ========== SAVE ONLY PNL ITEMS THAT HAVE VALUES ==========
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
                $itemsSaved++;
                Log::info("✓ Saved {$item['type']} item: {$item['service_name']} - \${$item['amount']}");
            }
            
            Log::info("✅ Saved PnL record: {$isNumber} with {$itemsSaved} items");
            Log::info("📂 Categories in record: {$categoriesString}");
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Save PnL email failed: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Extract hotels from Hotels/Cruises section – handles markdown table format with pipes
     */
   /**
 * Extract hotels from Hotels/Cruises section – robust markdown table parser
 */


/**
 * Extract hotels from Hotels/Cruises section - Handles 2-row hotel entries
 */
/**
 * Extract hotels from Hotels/Cruises section - Handles BOTH 1-row and 2-row formats
 */
/**
 * Extract hotels from Hotels/Cruises section - Flexible column detection
 */
/**
 * Extract hotels from Hotels/Cruises section - Handles pipe tables AND space-separated tables
 */
/**
 * Extract hotels from Hotels/Cruises section - Handles HTML tables AND text tables
 */
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
        return $total > 0 ? $total : 0;
    }
    
    // Alternative pattern
    if (preg_match('/Transport.*?Total[\s\|]*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total found (alt): " . $total);
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
}