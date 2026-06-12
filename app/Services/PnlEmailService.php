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
    
    // Add exchange rates here
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
            Log::info("Email preview: " . substr($plainText, 0, 500));
            
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
            if (preg_match('/No\.\s*Pax:\s*(\d+)/i', $plainText, $match)) {
                $totalPax = intval($match[1]);
            }
            
            $totalNights = 0;
            if (preg_match('/No\.\s*Night:\s*(\d+)/i', $plainText, $match)) {
                $totalNights = intval($match[1]);
            }
            
            // FIX: Extract Total Tour Cost - handle commas in numbers (e.g., "1,135.96")
            $totalTourCost = 0;
            $patterns = [
                '/Total Tour Cost\s+([\d,]+(?:\.\d+)?)/i',
                '/Total Tour Cost\s*:?\s*([\d,]+(?:\.\d+)?)/i',
                '/Total Tour Cost\s*=\s*([\d,]+(?:\.\d+)?)/i',
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $plainText, $match)) {
                    // Remove commas from the number
                    $amount = str_replace(',', '', $match[1]);
                    $totalTourCost = floatval($amount);
                    if ($totalTourCost > 0) {
                        Log::info("Total Tour Cost found: " . $totalTourCost);
                        break;
                    }
                }
            }
            
            // Extract Other Rates total (Attraction)
            $attractionTotal = 0;
            if (preg_match('/Other Rates.*?Total\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $plainText, $match)) {
                $amount = str_replace(',', '', $match[1]);
                $attractionTotal = floatval($amount);
                Log::info("Other Rates Total: " . $attractionTotal);
            }
            
            // Extract Transport total
            $transportTotal = 0;
            if (preg_match('/Transport.*?Total\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $plainText, $match)) {
                $amount = str_replace(',', '', $match[1]);
                $transportTotal = floatval($amount);
                Log::info("Transport Total: " . $transportTotal);
            }
            
            // Extract Tour Transfers total
            $tourTransfersTotal = 0;
            if (preg_match('/Tour Transfers.*?Total\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $plainText, $match)) {
                $amount = str_replace(',', '', $match[1]);
                $tourTransfersTotal = floatval($amount);
                Log::info("Tour Transfers Total: " . $tourTransfersTotal);
            }
            
            // Extract Meals total
            $mealsTotal = 0;
            if (preg_match('/Meals.*?Total\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $plainText, $match)) {
                $amount = str_replace(',', '', $match[1]);
                $mealsTotal = floatval($amount);
                Log::info("Meals Total: " . $mealsTotal);
            }
            
            // Country and Currency
            $countryCode = 'SG';
            $currency = 'SGD';
            if ($isNumber && strpos($isNumber, 'IS') === 0) {
                $countryCode = 'LK';
                $currency = 'LKR';
            } elseif ($isNumber && strpos($isNumber, 'VN') === 0) {
                $countryCode = 'VN';
                $currency = 'VND';
            } elseif ($isNumber && strpos($isNumber, 'MY') === 0) {
                $countryCode = 'MY';
                $currency = 'MYR';
            }
            
            // ========== CREATE PNL ITEMS ==========
            $pnlItems = [];
            
            // Add INVOICE item
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
            
            // Add TOUR TRANSFER item
            if ($tourTransfersTotal > 0) {
                $pnlItems[] = [
                    'type' => 'TOUR TRANSFER',
                    'service_name' => 'Tour Transfer Expenses',
                    'amount_original' => $tourTransfersTotal,
                    'amount_converted' => $tourTransfersTotal,
                    'currency' => 'USD',
                    'hotel_name' => null,
                ];
            }
            
            // Add ATTRACTION item (from Other Rates)
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
            
            // Add MEALS item
            if ($mealsTotal > 0) {
                $pnlItems[] = [
                    'type' => 'MEALS',
                    'service_name' => 'Meals Expenses',
                    'amount_original' => $mealsTotal,
                    'amount_converted' => $mealsTotal,
                    'currency' => 'USD',
                    'hotel_name' => null,
                ];
            }
            
            Log::info("Extracted Data Summary", [
                'tour_number' => $tourNumber,
                'is_number' => $isNumber,
                'agent_name' => $agentName,
                'total_tour_cost' => $totalTourCost,
                'transport_total' => $transportTotal,
                'tour_transfers_total' => $tourTransfersTotal,
                'attraction_total' => $attractionTotal,
                'meals_total' => $mealsTotal,
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
                'currency' => 'USD',
                'country_code' => $countryCode,
                'exchange_rate_used' => $this->exchangeRates[$countryCode] ?? 1,
                'category' => 'Multi',
                'status' => 'pending',
                'read_status' => $readStatus,
                'has_attachments' => $message['hasAttachments'] ?? false,
                'extracted_data' => json_encode([
                    'tour_number' => $tourNumber,
                    'transport_total' => $transportTotal,
                    'tour_transfers_total' => $tourTransfersTotal,
                    'attraction_total' => $attractionTotal,
                    'meals_total' => $mealsTotal,
                    'items_count' => count($pnlItems)
                ]),
                 'agent_name' => $agentName,
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
            Log::error($e->getTraceAsString());
            return false;
        }
    }
}