<?php
// app/Services/PnlEmailService.php

namespace App\Services;

use App\Models\PnlRecord;
use App\Models\PnlItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PnlEmailService
{
    protected $accessToken;
    
    // Country code mapping
    protected $countryCodes = [
        'SG' => ['currency' => 'SGD', 'rate_key' => 'SGD'],
        'MY' => ['currency' => 'MYR', 'rate_key' => 'MYR'],
        'VN' => ['currency' => 'VND', 'rate_key' => 'VND'],
        'IS' => ['currency' => 'LKR', 'rate_key' => 'LKR'],
        'SL' => ['currency' => 'LKR', 'rate_key' => 'LKR'],
        'LK' => ['currency' => 'LKR', 'rate_key' => 'LKR'],
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
            
            $fromEmail = $message['from']['emailAddress']['address'] ?? '';
            $fromName = $message['from']['emailAddress']['name'] ?? '';
            $receivedAt = Carbon::parse($message['receivedDateTime']);
            $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
            
            // Extract data from email
            $isNumber = $this->extractIsNumber($plainText);
            $countryCode = $this->extractCountryCode($isNumber, $plainText);
            $exchangeRate = $this->getExchangeRate($countryCode);
            
            // Parse PnL items from email
            $pnlItems = $this->parsePnLItems($plainText, $countryCode, $exchangeRate);
            
            // Calculate total amount from items
            $totalAmount = collect($pnlItems)->sum('amount_converted');
            
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
                'vendor_name' => $this->extractVendorName($fromEmail, $fromName, $plainText),
                'invoice_number' => $this->extractInvoiceNumber($plainText),
                'is_number' => $isNumber,
                'amount' => $totalAmount,
                'currency' => $this->getCurrencyFromCode($countryCode),
                'country_code' => $countryCode,
                'exchange_rate_used' => $exchangeRate,
                'category' => 'Multi', // Multiple items
                'status' => 'pending',
                'read_status' => $readStatus,
                'has_attachments' => $message['hasAttachments'] ?? false,
                'extracted_data' => json_encode(['items_count' => count($pnlItems)]),
            ]);
            
            // Save individual items
            foreach ($pnlItems as $item) {
                PnlItem::create([
                    'pnl_record_id' => $record->id,
                    'control_number' => $item['control_number'] ?? null,
                    'invoice_number' => $item['invoice_number'] ?? null,
                    'start_date' => $item['start_date'] ?? null,
                    'end_date' => $item['end_date'] ?? null,
                    'type' => $item['type'],
                    'credit_type' => $item['credit_type'] ?? null,
                    'agent_name' => $item['agent_name'] ?? null,
                    'client_name' => $item['client_name'] ?? null,
                    'check_in_date' => $item['check_in_date'] ?? null,
                    'check_out_date' => $item['check_out_date'] ?? null,
                    'hotel_name' => $item['hotel_name'] ?? null,
                    'transport_name' => $item['transport_name'] ?? null,
                    'service_name' => $item['service_name'] ?? null,
                    'country_code' => $countryCode,
                    'currency' => $item['currency'] ?? 'USD',
                    'amount_original' => $item['amount_original'],
                    'exchange_rate' => $exchangeRate,
                    'amount_converted' => $item['amount_converted'],
                    'item_details' => json_encode($item['details'] ?? []),
                ]);
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Save PnL email failed: ' . $e->getMessage());
            return false;
        }
    }
    
    protected function parsePnLItems($text, $countryCode, $exchangeRate)
    {
        $items = [];
        
        // Parse Hotel section
        if (preg_match_all('/Hotel:\s*([^\n]+)/i', $text, $hotelMatches)) {
            foreach ($hotelMatches[1] as $hotel) {
                $items[] = [
                    'type' => 'Hotel',
                    'hotel_name' => trim($hotel),
                    'amount_original' => $this->extractAmountFromLine($text, $hotel),
                    'amount_converted' => 0,
                    'currency' => $this->getCurrencyFromCode($countryCode),
                ];
            }
        }
        
        // Parse Transport section
        if (preg_match_all('/Transport:\s*([^\n]+)/i', $text, $transportMatches)) {
            foreach ($transportMatches[1] as $transport) {
                $items[] = [
                    'type' => 'Transport',
                    'transport_name' => trim($transport),
                    'amount_original' => $this->extractAmountFromLine($text, $transport),
                    'amount_converted' => 0,
                    'currency' => $this->getCurrencyFromCode($countryCode),
                ];
            }
        }
        
        // Parse Attractions/Tickets
        if (preg_match_all('/Attraction:\s*([^\n]+)/i', $text, $attractionMatches)) {
            foreach ($attractionMatches[1] as $attraction) {
                $items[] = [
                    'type' => 'Ticket',
                    'service_name' => trim($attraction),
                    'amount_original' => $this->extractAmountFromLine($text, $attraction),
                    'amount_converted' => 0,
                    'currency' => $this->getCurrencyFromCode($countryCode),
                ];
            }
        }
        
        // If no specific items found, create one from total
        if (empty($items)) {
            $totalAmount = $this->extractTotalAmount($text);
            if ($totalAmount > 0) {
                $items[] = [
                    'type' => 'Other',
                    'service_name' => 'General Expense',
                    'amount_original' => $totalAmount,
                    'amount_converted' => $totalAmount * $exchangeRate,
                    'currency' => $this->getCurrencyFromCode($countryCode),
                ];
            }
        }
        
        // Calculate converted amounts
        foreach ($items as &$item) {
            $item['amount_converted'] = $item['amount_original'] * $exchangeRate;
        }
        
        return $items;
    }
    
    protected function extractIsNumber($text)
    {
        if (preg_match('/Is Number:\s*([A-Z0-9\s]+)/i', $text, $match)) {
            return trim($match[1]);
        }
        if (preg_match('/IS\s*#?\s*[:]?\s*([A-Z0-9]+)/i', $text, $match)) {
            return trim($match[1]);
        }
        return null;
    }
    
    protected function extractCountryCode($isNumber, $text)
    {
        if ($isNumber) {
            if (preg_match('/^([A-Z]{2})/', $isNumber, $match)) {
                $code = strtoupper($match[1]);
                if (isset($this->countryCodes[$code])) {
                    return $code;
                }
            }
        }
        
        // Check for country mentions
        $countries = ['Singapore' => 'SG', 'Malaysia' => 'MY', 'Vietnam' => 'VN', 'Sri Lanka' => 'LK', 'Thailand' => 'TH'];
        foreach ($countries as $country => $code) {
            if (stripos($text, $country) !== false) {
                return $code;
            }
        }
        
        // Check for currency mentions
        if (stripos($text, 'SGD') !== false) return 'SG';
        if (stripos($text, 'MYR') !== false) return 'MY';
        if (stripos($text, 'VND') !== false) return 'VN';
        if (stripos($text, 'LKR') !== false) return 'LK';
        
        return 'SG'; // Default
    }
    
    protected function getExchangeRate($countryCode)
    {
        $rates = [
            'SG' => 1,      // SGD to SGD
            'MY' => 0.30,   // MYR to SGD (example)
            'VN' => 0.00006, // VND to SGD
            'LK' => 0.004,   // LKR to SGD
        ];
        
        return $rates[$countryCode] ?? 1;
    }
    
    protected function getCurrencyFromCode($countryCode)
    {
        $currencies = [
            'SG' => 'SGD',
            'MY' => 'MYR',
            'VN' => 'VND',
            'LK' => 'LKR',
            'TH' => 'THB',
        ];
        
        return $currencies[$countryCode] ?? 'USD';
    }
    
    protected function extractTotalAmount($text)
    {
        if (preg_match('/Total Tour Cost\s*[:]?\s*([0-9,]+\.?[0-9]*)/i', $text, $match)) {
            return floatval(str_replace(',', '', $match[1]));
        }
        if (preg_match('/Total\s*[:]?\s*\$?\s*([0-9,]+\.?[0-9]*)/i', $text, $match)) {
            return floatval(str_replace(',', '', $match[1]));
        }
        return 0;
    }
    
    protected function extractAmountFromLine($text, $keyword)
    {
        // Find amount near the keyword
        $pattern = '/' . preg_quote($keyword, '/') . '.*?([0-9,]+\.?[0-9]*)/is';
        if (preg_match($pattern, $text, $match)) {
            return floatval(str_replace(',', '', $match[1]));
        }
        return 0;
    }
    
    protected function extractVendorName($fromEmail, $fromName, $text)
    {
        if ($fromName && !empty($fromName)) {
            return $fromName;
        }
        if (preg_match('/Vendor:\s*([^\n]+)/i', $text, $match)) {
            return trim($match[1]);
        }
        return 'Unknown Vendor';
    }
    
    protected function extractInvoiceNumber($text)
    {
        $patterns = [
            '/Invoice\s*#?\s*[:]?\s*([A-Z0-9\-]+)/i',
            '/PNL:\s*#?([0-9]+)/i',
            '/Tour No:\s*#?([0-9]+)/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return $match[1];
            }
        }
        return null;
    }
}