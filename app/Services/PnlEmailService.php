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
            
            // Better text extraction - remove HTML tags and clean
            $plainText = strip_tags($htmlBody);
            // Remove excessive whitespace and line breaks
            $plainText = preg_replace('/\s+/', ' ', $plainText);
            // Also try to get the raw text from the body if available
            if (empty($plainText) && isset($message['body']['content'])) {
                $plainText = $message['body']['content'];
            }
            
            Log::info("Processing email: " . $subject);
            Log::info("Email preview: " . substr($plainText, 0, 500));
            
            $fromEmail = $message['from']['emailAddress']['address'] ?? '';
            $fromName = $message['from']['emailAddress']['name'] ?? '';
            $receivedAt = Carbon::parse($message['receivedDateTime']);
            $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
            
            // ========== EXTRACT HEADER DATA ==========
            
            // 1. Tour Number
            $tourNumber = null;
            if (preg_match('/Tour No:\s*#?(\d+)/i', $plainText, $match)) {
                $tourNumber = $match[1];
                Log::info("Tour Number: " . $tourNumber);
            }
            
            // 2. IS Number (Invoice Number) - "Is Number: VN 5583"
            $isNumber = null;
            if (preg_match('/Is Number:\s*([A-Z]{2})\s*(\d+)/i', $plainText, $match)) {
                $isNumber = $match[1] . $match[2];
                Log::info("IS Number: " . $isNumber);
            }
            
            // 3. Agent Name
            $agentName = 'Unknown';
            if (preg_match('/Agent:\s*([^\n]+?)(?:\s+No\.|\s+Currency|$)/i', $plainText, $match)) {
                $agentName = trim($match[1]);
                Log::info("Agent Name: " . $agentName);
            }
            
            // 4. Pax and Nights
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
            
            // 5. Total Tour Cost - CRITICAL FIX: matches "Total Tour Cost348.01 USD" (no space)
            $totalTourCost = 0;
            // Pattern for "Total Tour Cost348.01 USD" (no space)
            if (preg_match('/Total Tour Cost(\d+(?:\.\d+)?)/i', $plainText, $match)) {
                $totalTourCost = floatval($match[1]);
                Log::info("Total Tour Cost (no space): " . $totalTourCost);
            }
            // Pattern for "Total Tour Cost 348.01 USD" (with space)
            elseif (preg_match('/Total Tour Cost\s+(\d+(?:\.\d+)?)/i', $plainText, $match)) {
                $totalTourCost = floatval($match[1]);
                Log::info("Total Tour Cost (with space): " . $totalTourCost);
            }
            // Pattern with colon
            elseif (preg_match('/Total Tour Cost:\s*(\d+(?:\.\d+)?)/i', $plainText, $match)) {
                $totalTourCost = floatval($match[1]);
                Log::info("Total Tour Cost (colon): " . $totalTourCost);
            }
            
            // 6. Transport Total - Look for Transport section
            $transportTotal = 0;
            if (preg_match('/Transport.*?Total\s+Transport\s+(\d+(?:\.\d+)?)/is', $plainText, $match)) {
                $transportTotal = floatval($match[1]);
                Log::info("Transport Total: " . $transportTotal);
            }
            
            // 7. Attraction Total - Look for Attraction or Other Rates section
            $attractionTotal = 0;
            if (preg_match('/Attraction.*?Total\s+(\d+(?:\.\d+)?)/is', $plainText, $match)) {
                $attractionTotal = floatval($match[1]);
                Log::info("Attraction Total: " . $attractionTotal);
            }
            if ($attractionTotal == 0 && preg_match('/Other Rates.*?Total\s+(\d+(?:\.\d+)?)/is', $plainText, $match)) {
                $attractionTotal = floatval($match[1]);
                Log::info("Other Rates Total: " . $attractionTotal);
            }
            
            // 8. Country and Currency
            $countryCode = 'VN'; // Default for this email
            $currency = 'VND';
            if ($isNumber && strpos($isNumber, 'VN') === 0) {
                $countryCode = 'VN';
                $currency = 'VND';
            } elseif ($isNumber && strpos($isNumber, 'SG') === 0) {
                $countryCode = 'SG';
                $currency = 'SGD';
            } elseif ($isNumber && strpos($isNumber, 'MY') === 0) {
                $countryCode = 'MY';
                $currency = 'MYR';
            } elseif ($isNumber && strpos($isNumber, 'IS') === 0) {
                $countryCode = 'LK';
                $currency = 'LKR';
            }
            
            // ========== CREATE PNL ITEMS ==========
            $pnlItems = [];
            
            // Add INVOICE item (Total Tour Cost)
            if ($totalTourCost > 0) {
                $pnlItems[] = [
                    'type' => 'INVOICE',
                    'service_name' => 'Total Tour Package',
                    'amount_original' => $totalTourCost,
                    'amount_converted' => $totalTourCost,
                    'currency' => 'USD',
                    'hotel_name' => null,
                ];
            }
            
            // Add HOTEL items
            if (preg_match_all('/Hotel:\s*([^\n]+?)\s+(\d+(?:\.\d+)?)/i', $plainText, $hotelMatches, PREG_SET_ORDER)) {
                foreach ($hotelMatches as $hotelMatch) {
                    $hotelName = trim($hotelMatch[1]);
                    $amount = floatval($hotelMatch[2]);
                    if ($amount > 0) {
                        $pnlItems[] = [
                            'type' => 'HOTEL',
                            'service_name' => $hotelName,
                            'amount_original' => $amount,
                            'amount_converted' => $amount,
                            'currency' => 'USD',
                            'hotel_name' => $hotelName,
                        ];
                        Log::info("Found Hotel: {$hotelName} - \${$amount}");
                    }
                }
            }
            
            // Add TRANSPORT item
            if ($transportTotal > 0) {
                $pnlItems[] = [
                    'type' => 'TRANSPORT',
                    'service_name' => 'Transport Expenses',
                    'amount_original' => $transportTotal,
                    'amount_converted' => $transportTotal,
                    'currency' => 'USD',
                    'hotel_name' => null,
                ];
            }
            
            // Add ATTRACTION item
            if ($attractionTotal > 0) {
                $pnlItems[] = [
                    'type' => 'ATTRACTION',
                    'service_name' => 'Attractions & Entrance Fees',
                    'amount_original' => $attractionTotal,
                    'amount_converted' => $attractionTotal,
                    'currency' => 'USD',
                    'hotel_name' => null,
                ];
            }
            
            // Log extracted data
            Log::info("Extracted Data Summary", [
                'tour_number' => $tourNumber,
                'is_number' => $isNumber,
                'agent_name' => $agentName,
                'total_tour_cost' => $totalTourCost,
                'transport_total' => $transportTotal,
                'attraction_total' => $attractionTotal,
                'country_code' => $countryCode,
                'items_count' => count($pnlItems)
            ]);
            
            // Create main record
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
                'currency' => $currency,
                'country_code' => $countryCode,
                'exchange_rate_used' => 1,
                'category' => 'Multi',
                'status' => 'pending',
                'read_status' => $readStatus,
                'has_attachments' => $message['hasAttachments'] ?? false,
                'extracted_data' => json_encode([
                    'tour_number' => $tourNumber,
                    'transport_total' => $transportTotal,
                    'attraction_total' => $attractionTotal,
                    'items_count' => count($pnlItems)
                ]),
            ]);
            
            // Save individual items
            foreach ($pnlItems as $item) {
                PnlItem::create([
                    'pnl_record_id' => $record->id,
                    'control_number' => $tourNumber ? $tourNumber . 'CNTL' : null,
                    'invoice_number' => $isNumber,
                    'type' => $item['type'],
                    'credit_type' => 'Credit',
                    'agent_name' => $agentName,
                    'hotel_name' => $item['hotel_name'] ?? null,
                    'service_name' => $item['service_name'] ?? null,
                    'country_code' => $countryCode,
                    'currency' => $item['currency'],
                    'amount_original' => $item['amount_original'],
                    'exchange_rate' => 1,
                    'amount_converted' => $item['amount_converted'],
                    'item_details' => json_encode(['remarks' => '']),
                ]);
            }
            
            Log::info("✅ Saved PnL record: {$isNumber} with " . count($pnlItems) . " items, Amount: {$totalTourCost}");
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Save PnL email failed: ' . $e->getMessage());
            return false;
        }
    }
}