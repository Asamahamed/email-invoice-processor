<?php

namespace App\Services;

use App\Models\IncomingEmail;
use App\Models\EmailAttachment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MicrosoftGraphService
{
    protected $accessToken;
    protected $agentClassifier;
    
    public function __construct()
    {
        $this->authenticate();
        $this->agentClassifier = new AgentClassificationService();
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
                Log::info('Microsoft Graph authenticated successfully');
                return true;
            } else {
                Log::error('Microsoft Graph auth failed: ' . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Microsoft Graph auth error: ' . $e->getMessage());
            return false;
        }
    }
    
public function fetchAllEmails()
{
    try {
        set_time_limit(600);
        
        $allMessages = [];
        $nextLink = null;
        $pageCount = 0;
        $maxPages = 10;

        // ✅ Use the Inbox folder explicitly
        $baseUrl = 'https://graph.microsoft.com/v1.0/users/' . env('GRAPH_INVOICE_USER') . '/mailfolders/inbox/messages';
        
        Log::info("🚀 Starting to fetch emails from INBOX...");
        
        do {
            $url = $nextLink ?? $baseUrl . '?' . http_build_query([
                '$top' => 50,
                '$orderby' => 'receivedDateTime desc',
                '$select' => 'id,subject,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
            ]);
            
            Log::info("📡 Requesting URL: " . substr($url, 0, 200) . "...");
            
            $response = Http::withToken($this->accessToken)
                ->timeout(180)
                ->get($url);
            
            if (!$response->ok()) {
                Log::error('Failed to fetch emails page: ' . $response->body());
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
            
            Log::info("📥 Page {$pageCount}: " . count($messages) . " emails (Total so far: " . count($allMessages) . ")");
            
            if ($pageCount >= $maxPages) {
                Log::warning("Reached maximum page limit ({$maxPages} pages)");
                break;
            }
            
            if (!$nextLink) {
                Log::info("✅ No more pages to fetch");
                break;
            }
            
            usleep(200000);
            
        } while ($nextLink);
        
        Log::info("📥 TOTAL emails fetched from INBOX: " . count($allMessages));
        
        $newCount = 0;
        $processedCount = 0;
        $totalMessages = count($allMessages);
        
        foreach ($allMessages as $message) {
            $processedCount++;
            $subject = $message['subject'] ?? 'NO SUBJECT';
            
            if ($processedCount % 50 == 0) {
                Log::info("⏳ Processing {$processedCount}/{$totalMessages} emails...");
            }
            
            $existing = IncomingEmail::where('message_id', $message['id'])->first();
            
            if ($existing) {
                $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
                if ($existing->read_status !== $readStatus) {
                    $existing->update(['read_status' => $readStatus]);
                    Log::info("📬 Updated read status for: " . $subject);
                }
            } else {
                $fullMessage = $this->fetchFullMessage($message['id']);
                if ($fullMessage) {
                    $saved = $this->saveEmail($fullMessage);
                    if ($saved) {
                        $newCount++;
                        Log::info("✅ Saved new email: " . $subject);
                    }
                } else {
                    $saved = $this->saveEmailWithPreview($message);
                    if ($saved) {
                        $newCount++;
                        Log::info("✅ Saved new email (preview): " . $subject);
                    }
                }
            }
        }
        
        Log::info("📊 Summary: {$newCount} new emails saved out of {$totalMessages} total emails");
        return $newCount;
        
    } catch (\Exception $e) {
        Log::error('Error fetching emails: ' . $e->getMessage());
        return 0;
    }
}
    
    /**
     * Fetch full message with body and attachments
     */
    protected function fetchFullMessage($messageId)
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(60)
                ->get('https://graph.microsoft.com/v1.0/users/' . env('GRAPH_INVOICE_USER') . '/messages/' . $messageId, [
                    '$select' => 'id,subject,body,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
                    '$expand' => 'attachments($top=5)' // Limit attachments
                ]);
            
            if ($response->ok()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::error("Failed to fetch full message {$messageId}: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Save email with preview only (fallback)
     */
    protected function saveEmailWithPreview($message)
    {
        try {
            $subject = $message['subject'] ?? 'No Subject';
            $htmlBody = $message['bodyPreview'] ?? '';
            $plainText = $htmlBody;
            
            $fromEmail = $message['from']['emailAddress']['address'] ?? '';
            $fromName = $message['from']['emailAddress']['name'] ?? '';
            $receivedAt = Carbon::parse($message['receivedDateTime']);
            $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
            
            $isTourConfirmation = stripos($plainText, 'TOUR CONFIRMATION') !== false;
            
            // Extract invoice number from preview
            $invoiceNumber = $this->extractInvoiceNumber($plainText);
            $invoiceNumber = $this->cleanInvoiceNumber($invoiceNumber);
            $tourRef = $this->extractTourReference($plainText);
            
            if (!$tourRef) $tourRef = "NA";
            if (!$invoiceNumber) $invoiceNumber = "NA";
            
            $fileHandler = $this->extractField($plainText, 'File Handler');
            $agentName = $this->extractField($plainText, 'Agent');
            
            if ($agentName) {
                $agentName = preg_replace('/\s*[-–].*$/', '', $agentName);
                $agentName = trim($agentName);
            }
            
            $classification = $this->agentClassifier->classify($plainText, $fromEmail, $subject, $agentName);
            
            $emailData = [
                'message_id' => $message['id'],
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'subject' => $subject,
                'body' => $htmlBody,
                'body_preview' => substr($plainText, 0, 500),
                'received_at' => $receivedAt,
                'agent_name' => $agentName,
                'tour_ref' => $tourRef,
                'invoice_number' => $invoiceNumber,
                'file_handler' => $fileHandler,
                'credit_type' => $classification['credit_type'],
                'classification_reason' => $classification['reason'],
                'read_status' => $readStatus,
                'processing_status' => 'processed',
                'is_tour_confirmation' => $isTourConfirmation,
                'has_attachments' => $message['hasAttachments'] ?? false,
            ];
            
            IncomingEmail::create($emailData);
            return true;
            
        } catch (\Exception $e) {
            Log::error('Save preview failed: ' . $e->getMessage());
            return false;
        }
    }
    
protected function saveEmail($message)
{
    try {
        $subject = $message['subject'] ?? 'No Subject';
        
        // Get raw HTML body
        $htmlBody = $message['body']['content'] ?? $message['bodyPreview'] ?? '';
        
        // Convert HTML to plain text
        $plainText = $this->htmlToPlainText($htmlBody);
        
        Log::info("Email body preview: " . substr($plainText, 0, 2000));
        
        $fromEmail = $message['from']['emailAddress']['address'] ?? '';
        $fromName = $message['from']['emailAddress']['name'] ?? '';
        $receivedAt = Carbon::parse($message['receivedDateTime']);
        $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
        
        // Check if this is a Tour Confirmation email
        $isTourConfirmation = stripos($plainText, 'TOUR CONFIRMATION') !== false;
        
        // ========== EXTRACT ALL REFERENCE NUMBERS ==========
        // 1. Extract Invoice Number FIRST (VN19610, IS48162, etc.)
        $invoiceNumber = $this->extractInvoiceNumber($plainText);
        
        // 2. Clean the invoice number (remove spaces)
        $invoiceNumber = $this->cleanInvoiceNumber($invoiceNumber);
        
        // 3. Extract Tour Ref (ends with CNTL - like 462414CNTL)
        $tourRef = $this->extractTourReference($plainText);
        
        // 4. Extract Agent Reference No (MMT Booking ID, etc.)
        $agentReferenceNo = $this->extractAgentReferenceNo($plainText);
        
        // 5. Set default values
        if (!$tourRef) {
            $tourRef = "NA";
        }
        if (!$agentReferenceNo) {
            $agentReferenceNo = "NA";
        }
        if (!$invoiceNumber) {
            $invoiceNumber = "NA";
        }
        
        Log::info("Final Extracted - Invoice: {$invoiceNumber}, Tour Ref: {$tourRef}, Agent Ref: {$agentReferenceNo}");
        
        // Extract other fields
        $fileHandler = $this->extractField($plainText, 'File Handler');
        $agentName = $this->extractField($plainText, 'Agent');
        
        if ($agentName) {
            $agentName = preg_replace('/\s*[-–].*$/', '', $agentName);
            $agentName = trim($agentName);
            Log::info("Cleaned Agent Name: {$agentName}");
        }
        
        // Extract passenger names
        $passengerNames = $this->extractPassengerNames($plainText);
        $guestName = !empty($passengerNames) ? implode(', ', $passengerNames) : $this->extractField($plainText, 'Guests Name');
        
        // Extract travel dates
        $travelDates = $this->extractTravelDates($plainText);
        $travelStart = $travelDates['start'];
        $travelEnd = $travelDates['end'];
        
        // Extract Total Amount
        $totalAmount = null;
        $currency = 'USD';
        
        if (preg_match('/Total Tour Cost[:\s]*([A-Z]{3})?\s*\$?\s*([0-9,]+\.?[0-9]*)/i', $plainText, $match)) {
            $totalAmount = floatval(str_replace(',', '', $match[2]));
            if (isset($match[1]) && !empty($match[1])) {
                $currency = strtoupper($match[1]);
            }
            Log::info("Found Total Tour Cost: {$currency} {$totalAmount}");
        } elseif (preg_match('/\$\s*([0-9,]+\.?[0-9]*)/', $plainText, $match)) {
            $totalAmount = floatval(str_replace(',', '', $match[1]));
            Log::info("Found USD amount: {$totalAmount}");
        }
        
        // Extract number of guests
        $numberOfGuests = null;
        if (preg_match('/No\. of Guests?[:\s]*(\d+)\s*Adults?/i', $plainText, $match)) {
            $numberOfGuests = intval($match[1]);
            Log::info("Extracted Number of Guests: {$numberOfGuests}");
        } elseif (preg_match('/No\. of Guests?[:\s]*(\d+)/i', $plainText, $match)) {
            $numberOfGuests = intval($match[1]);
            Log::info("Extracted Number of Guests: {$numberOfGuests}");
        }
        
        // Extract pax count
        $paxCount = null;
        if (preg_match('/No\. of Adult[:\s]*(\d+)/i', $plainText, $match)) {
            $paxCount = intval($match[1]);
            Log::info("Extracted Pax Count from Adult: {$paxCount}");
        } elseif (preg_match('/(\d+)\s*Adults?/i', $plainText, $match)) {
            $paxCount = intval($match[1]);
            Log::info("Extracted Pax Count from Adults: {$paxCount}");
        }
        
        // Extract destination
        $destination = $this->extractDestination($plainText, $subject);
        
        // Classification
        $classification = $this->agentClassifier->classify($plainText, $fromEmail, $subject, $agentName);
        
        Log::info("Extracted Data", [
            'subject' => $subject,
            'invoice_number' => $invoiceNumber,
            'tour_ref' => $tourRef,
            'file_handler' => $fileHandler,
            'agent_name' => $agentName,
            'guest_name' => $guestName,
            'travel_start' => $travelStart,
            'travel_end' => $travelEnd,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'pax_count' => $paxCount
        ]);
        
        // Save to database
        $emailData = [
            'message_id' => $message['id'],
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'subject' => $subject,
            'body' => $htmlBody,
            'body_preview' => substr($plainText, 0, 500),
            'received_at' => $receivedAt,
            'agent_name' => $agentName,
            'guest_name' => $guestName,
            'tour_ref' => $tourRef,
            'invoice_number' => $invoiceNumber,  
            'file_handler' => $fileHandler,
            'travel_start_date' => $travelStart,
            'travel_end_date' => $travelEnd,
            'number_of_guests' => $numberOfGuests,
            'pax_count' => $paxCount,
            'destination' => $destination,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'reference_no' => $agentReferenceNo,
            'credit_type' => $classification['credit_type'],
            'classification_reason' => $classification['reason'],
            'read_status' => $readStatus,
            'processing_status' => 'processed',
            'is_tour_confirmation' => $isTourConfirmation,
            'has_attachments' => $message['hasAttachments'] ?? false,
        ];
        
        $email = IncomingEmail::create($emailData);
        
        // Save attachments if any
        if (isset($message['attachments']) && !empty($message['attachments'])) {
            $this->saveAttachments($message['attachments'], $email);
        }
        
        return true;
        
    } catch (\Exception $e) {
        Log::error('Save failed: ' . $e->getMessage() . ' - Subject: ' . ($message['subject'] ?? 'N/A'));
        return false;
    }
}
    /**
 * Clean text by removing special characters and normalizing spaces
 */
/**
 * Comprehensive travel date extraction for all email formats
 */
protected function extractTravelDates($text)
{
    $travelStart = null;
    $travelEnd = null;
    
    // First, try to extract from TOUR CONFIRMATION section
    $tourSection = '';
    if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
        $tourSection = $sectionMatch[1];
    }
    $searchText = !empty($tourSection) ? $tourSection : $text;
    
    // Log what we're searching
    Log::info("Searching for travel dates in text length: " . strlen($searchText));
    
    // ========== FORMAT A: Arrival Date + Departure Date (Pick Your Trial style) ==========
    // Example: "Arrival Date    | July 15, 2026" and "Departure Date    | July 18, 2026"
    if (!$travelStart) {
        if (preg_match('/Arrival\s*Date[:\s|]*([A-Za-z]+\s+\d{1,2},?\s*\d{4}|\d{4}[-\/]\d{1,2}[-\/]\d{1,2}|\d{1,2}\s*[-–]\s*[A-Za-z]+)/i', $searchText, $match)) {
            try {
                $dateStr = trim($match[1]);
                // Handle "13 - Sep" format
                if (preg_match('/(\d{1,2})\s*[-–]\s*([A-Za-z]+)/i', $dateStr, $dateMatch)) {
                    $dateStr = "{$dateMatch[2]} {$dateMatch[1]}, " . date('Y');
                }
                $travelStart = Carbon::parse($dateStr)->format('Y-m-d');
                Log::info("Format A - Arrival Date: {$travelStart}");
            } catch (\Exception $e) {
                Log::error("Failed to parse Arrival Date: {$dateStr} - " . $e->getMessage());
            }
        }
    }
    
    if (!$travelEnd) {
        if (preg_match('/Departure\s*Date[:\s|]*([A-Za-z]+\s+\d{1,2},?\s*\d{4}|\d{4}[-\/]\d{1,2}[-\/]\d{1,2}|\d{1,2}\s*[-–]\s*[A-Za-z]+)/i', $searchText, $match)) {
            try {
                $dateStr = trim($match[1]);
                // Handle "20 - Sep" format
                if (preg_match('/(\d{1,2})\s*[-–]\s*([A-Za-z]+)/i', $dateStr, $dateMatch)) {
                    $dateStr = "{$dateMatch[2]} {$dateMatch[1]}, " . date('Y');
                }
                $travelEnd = Carbon::parse($dateStr)->format('Y-m-d');
                Log::info("Format A - Departure Date: {$travelEnd}");
            } catch (\Exception $e) {
                Log::error("Failed to parse Departure Date: {$dateStr} - " . $e->getMessage());
            }
        }
    }
    
    // ========== FORMAT B: Early check-in Date + Departure Date (table with two rows) ==========
    // Example: 
    // | Early check-in Date | Arrival Date | Departure Date |
    // | 12 Jun, 2026        |              |                |
    // | 19 Jun, 2026        |              |                |
    if (!$travelStart || !$travelEnd) {
        // Find the Early check-in/Departure table
        if (preg_match('/Early check-in Date.*?Departure Date.*?(\d{1,2})\s+([A-Za-z]+)[,\s]*(\d{4}).*?(\d{1,2})\s+([A-Za-z]+)[,\s]*(\d{4})/is', $searchText, $match)) {
            try {
                $travelStart = Carbon::parse("{$match[2]} {$match[1]}, {$match[3]}")->format('Y-m-d');
                $travelEnd = Carbon::parse("{$match[5]} {$match[4]}, {$match[6]}")->format('Y-m-d');
                Log::info("Format B - Early check-in table: {$travelStart} to {$travelEnd}");
            } catch (\Exception $e) {}
        }
        // Alternative: Two separate date patterns in sequence
        elseif (preg_match_all('/(\d{1,2})\s+([A-Za-z]+)[,\s]*(\d{4})/i', $searchText, $matches, PREG_SET_ORDER)) {
            if (count($matches) >= 2) {
                try {
                    $travelStart = Carbon::parse("{$matches[0][2]} {$matches[0][1]}, {$matches[0][3]}")->format('Y-m-d');
                    $travelEnd = Carbon::parse("{$matches[1][2]} {$matches[1][1]}, {$matches[1][3]}")->format('Y-m-d');
                    Log::info("Format B - Two date pattern: {$travelStart} to {$travelEnd}");
                } catch (\Exception $e) {}
            }
        }
    }
    
    // ========== FORMAT C: Travel Date field with range ==========
    // Example: "Travel Date: 2026-6-10" and then "2026-6-16"
    if (!$travelStart || !$travelEnd) {
        if (preg_match('/Travel Date[:\s]*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})\s*[-–to]+\s*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/i', $searchText, $match)) {
            try {
                $travelStart = Carbon::parse(trim($match[1]))->format('Y-m-d');
                $travelEnd = Carbon::parse(trim($match[2]))->format('Y-m-d');
                Log::info("Format C - Travel Date range: {$travelStart} to {$travelEnd}");
            } catch (\Exception $e) {}
        }
        // Travel Date on one line, next line has end date
        elseif (preg_match('/Travel Date[:\s]*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/i', $searchText, $match)) {
            try {
                $travelStart = Carbon::parse(trim($match[1]))->format('Y-m-d');
                // Look for date after this position
                $pos = strpos($searchText, $match[0]) + strlen($match[0]);
                if (preg_match('/(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/', substr($searchText, $pos), $endMatch)) {
                    $travelEnd = Carbon::parse(trim($endMatch[1]))->format('Y-m-d');
                    Log::info("Format C - Travel Date with next line: {$travelStart} to {$travelEnd}");
                }
            } catch (\Exception $e) {}
        }
    }
    
    // ========== FORMAT D: Itinerary with check-in/check-out dates ==========
    // Example: "Check In: July 15, 2026" and "Check Out: July 18, 2026"
    if (!$travelStart || !$travelEnd) {
        if (preg_match('/Check\s*In[:\s]*([A-Za-z]+\s+\d{1,2},?\s*\d{4})/i', $searchText, $match)) {
            try {
                $travelStart = Carbon::parse(trim($match[1]))->format('Y-m-d');
                Log::info("Format D - Check In: {$travelStart}");
            } catch (\Exception $e) {}
        }
        if (preg_match('/Check\s*Out[:\s]*([A-Za-z]+\s+\d{1,2},?\s*\d{4})/i', $searchText, $match)) {
            try {
                $travelEnd = Carbon::parse(trim($match[1]))->format('Y-m-d');
                Log::info("Format D - Check Out: {$travelEnd}");
            } catch (\Exception $e) {}
        }
    }
    
    // ========== FORMAT E: Date range in itinerary (Jun 21, 2026 - Jun 22, 2026) ==========
    if (!$travelStart || !$travelEnd) {
        $dateRanges = [];
        
        // Pattern: Month Day, Year - Month Day, Year
        if (preg_match_all('/([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})\s*[-–]+\s*([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})/i', $searchText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                try {
                    $start = Carbon::parse("{$match[1]} {$match[2]}, {$match[3]}")->format('Y-m-d');
                    $end = Carbon::parse("{$match[4]} {$match[5]}, {$match[6]}")->format('Y-m-d');
                    $dateRanges[] = ['start' => $start, 'end' => $end];
                } catch (\Exception $e) {}
            }
        }
        
        // Pattern: 2026-6-21 - 2026-6-22
        if (preg_match_all('/(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})\s*[-–]+\s*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/i', $searchText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                try {
                    $start = Carbon::parse(trim($match[1]))->format('Y-m-d');
                    $end = Carbon::parse(trim($match[2]))->format('Y-m-d');
                    $dateRanges[] = ['start' => $start, 'end' => $end];
                } catch (\Exception $e) {}
            }
        }
        
        if (!empty($dateRanges)) {
            $starts = array_column($dateRanges, 'start');
            $ends = array_column($dateRanges, 'end');
            $travelStart = min($starts);
            $travelEnd = max($ends);
            Log::info("Format E - Combined itinerary dates: {$travelStart} to {$travelEnd}");
        }
    }
    
    // ========== FORMAT F: Arrival Date + Nights calculation ==========
    if (!$travelStart && preg_match('/Arrival Date[:\s]*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2}|\d{1,2}\s*[-–]\s*\w+)/i', $searchText, $match)) {
        try {
            $arrivalDateStr = trim($match[1]);
            if (preg_match('/(\d{1,2})\s*[-–]\s*(\w+)/i', $arrivalDateStr, $dateMatch)) {
                $arrivalDateStr = "{$dateMatch[2]} {$dateMatch[1]}, " . date('Y');
            }
            $travelStart = Carbon::parse($arrivalDateStr)->format('Y-m-d');
            Log::info("Format F - Arrival Date: {$travelStart}");
        } catch (\Exception $e) {}
    }
    
    // Extract nights if available
    $nights = null;
    if (preg_match('/Nights?\s*[:\s]*(\d+)/i', $searchText, $match)) {
        $nights = intval($match[1]);
        Log::info("Found nights: {$nights}");
    }
    
    // Calculate end date from nights if we have start but no end
    if ($travelStart && !$travelEnd && $nights) {
        try {
            $travelEnd = Carbon::parse($travelStart)->addDays($nights)->format('Y-m-d');
            Log::info("Calculated end date from nights: {$travelEnd}");
        } catch (\Exception $e) {}
    }
    
    // ========== FALLBACK: Simple date extraction ==========
    if (!$travelStart) {
        if (preg_match('/\b(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})\b/', $searchText, $match)) {
            try {
                $travelStart = Carbon::parse(trim($match[1]))->format('Y-m-d');
                Log::info("Fallback - Simple start date: {$travelStart}");
            } catch (\Exception $e) {}
        }
    }
    
    Log::info("FINAL EXTRACTED - Start: {$travelStart}, End: {$travelEnd}");
    
    return ['start' => $travelStart, 'end' => $travelEnd];
}
protected function cleanText($text)
{
    // Remove special Unicode characters (document icons, emojis, etc.)
    $text = preg_replace('/[^\x20-\x7E\x0A\x0D]/u', ' ', $text);
    
    // Normalize line endings
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    
    // Remove extra spaces
    $text = preg_replace('/[ \t]+/', ' ', $text);
    
    // Remove empty lines
    $lines = explode("\n", $text);
    $lines = array_filter($lines, function($line) {
        return trim($line) !== '';
    });
    
    return implode("\n", $lines);
}
    /**
     * Convert HTML to plain text while preserving line breaks
     */
protected function htmlToPlainText($html)
{
    // First, decode HTML entities
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Replace common block elements with newlines
    $html = preg_replace('/<\/(div|p|tr|li|h[1-6])>/i', "\n", $html);
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $html = preg_replace('/<\/(td|th)>/i', ' ', $html);
    $html = str_replace('</td>', ' ', $html);
    $html = str_replace('</tr>', "\n", $html);
    
    // Remove all HTML tags
    $text = strip_tags($html);
    
    // Remove special Unicode characters (document icon, etc.)
    $text = preg_replace('/[\x{2190}-\x{21FF}]/u', '', $text);
    $text = preg_replace('/[\x{1F300}-\x{1F6FF}]/u', '', $text);
    
    // Remove non-printable characters but keep newlines
    $text = preg_replace('/[^\x20-\x7E\x0A\x0D]/u', ' ', $text);
    
    // Normalize line endings
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    
    // Collapse multiple spaces
    $text = preg_replace('/[ \t]+/', ' ', $text);
    
    // Remove lines that are just separators
    $lines = explode("\n", $text);
    $lines = array_map('trim', $lines);
    $lines = array_filter($lines, function($line) {
        return $line !== '' && !preg_match('/^[_\-\s]+$/', $line) && strlen($line) > 2;
    });
    
    $result = implode("\n", $lines);
    
    // Log for debugging
    Log::info("Cleaned text preview: " . substr($result, 0, 1000));
    
    return $result;
}
    

protected function extractField($text, $fieldName)
{
    // First, try to find the TOUR CONFIRMATION section
    $tourConfirmationSection = '';
    if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
        $tourConfirmationSection = $sectionMatch[1];
        Log::info("Found TOUR CONFIRMATION section for {$fieldName}");
    }
    
    $searchText = !empty($tourConfirmationSection) ? $tourConfirmationSection : $text;
    
    // Clean the text - remove special Unicode characters
    $searchText = preg_replace('/[^\x20-\x7E\x0A\x0D]/u', ' ', $searchText);
    
    // ========== NEW: Match lines that start with the field name ==========
    // Example: "Agent Al Mousim Travel & Tours, Co. Ltd" or "Agent: Al Mousim ..."
    // Use multiline flag to match start of line
    $pattern = '/^' . preg_quote($fieldName, '/') . '\s*:?\s*(.+)$/im';
    if (preg_match($pattern, $searchText, $match)) {
        $value = trim($match[1]);
        // Skip if the value looks like another field name (to avoid mis-matching)
        if (!empty($value) && strlen($value) < 200 && !preg_match('/^(Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan|Chauffeur)/i', $value)) {
            Log::info("✓ Extracted {$fieldName} (line start): {$value}");
            return $value;
        }
    }
    
    // ========== Existing Pattern 1: Field Name followed by newline then value ==========
    $pattern1 = '/' . preg_quote($fieldName, '/') . '\s*\n\s*([^\n]+)/i';
    if (preg_match($pattern1, $searchText, $match)) {
        $value = trim($match[1]);
        $value = preg_replace('/\s+/', ' ', $value);
        if (!empty($value) && strlen($value) < 200 && !preg_match('/^(Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan)/i', $value)) {
            Log::info("✓ Extracted {$fieldName} (pattern1): {$value}");
            return $value;
        }
    }
    
    // ========== Existing Pattern 2: Field Name followed by spaces then value (same line) ==========
    $pattern2 = '/' . preg_quote($fieldName, '/') . '\s*:?\s*([^\n]+)/i';
    if (preg_match($pattern2, $searchText, $match)) {
        $value = trim($match[1]);
        $value = preg_replace('/\s+/', ' ', $value);
        if (!empty($value) && strlen($value) < 200 && !preg_match('/^(Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan)/i', $value)) {
            Log::info("✓ Extracted {$fieldName} (pattern2): {$value}");
            return $value;
        }
    }
    
    // ========== Existing Pattern 3: Check next line after the field name ==========
    $lines = explode("\n", $searchText);
    foreach ($lines as $i => $line) {
        if (preg_match('/' . preg_quote($fieldName, '/') . '/i', $line)) {
            // Check next line for value
            if (isset($lines[$i + 1])) {
                $value = trim($lines[$i + 1]);
                if (!empty($value) && !preg_match('/^(Emergency contact|Customer Support|Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan)/i', $value)) {
                    Log::info("✓ Extracted {$fieldName} (pattern3 - next line): {$value}");
                    return $value;
                }
            }
            // Also check same line after removing the field name
            $value = preg_replace('/' . preg_quote($fieldName, '/') . '\s*/i', '', $line);
            $value = trim($value);
            if (!empty($value) && strlen($value) < 200 && !preg_match('/^(Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan)/i', $value)) {
                Log::info("✓ Extracted {$fieldName} (pattern3 - same line): {$value}");
                return $value;
            }
        }
    }
    
    Log::info("✗ Could not extract {$fieldName} from text");
    return null;
}
    /**
     * Extract Tour Ref specifically - this is the invoice number
     * Format: "Tour Ref\n462445CNTL" or "Tour Ref 462445CNTL"
     */
protected function extractTourRef($text)
{
    $patterns = [
        '/Tour\s*Ref\s*[:\s]*([A-Z0-9]+(?:[A-Z]+)?)/i',
        '/Reference\s*No\s*[:#]?\s*([A-Z0-9]+(?:[A-Z]+)?)/i',
        '/Invoice\s*No\s*[:#]?\s*([A-Z0-9]+(?:[A-Z]+)?)/i',
        '/Booking\s*ID\s*[:#]?\s*([A-Z0-9]+(?:[A-Z]+)?)/i',
        '/Confirmation\s*No\s*[:#]?\s*([A-Z0-9]+(?:[A-Z]+)?)/i',
        '/\b(VN[0-9A-Z]+)\b/i',
        '/\b(NL[0-9A-Z]+)\b/i',
        '/\b(ORN[0-9A-Z]+)\b/i',
        '/\b([0-9]{3,}[A-Z]{2,})\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $match)) {

            $value = trim($match[1]);

            // remove unwanted spaces/non breaking spaces
            $value = preg_replace('/\s+/u', '', $value);

            Log::info("Extracted Invoice/Tour Ref: {$value}");

            return $value;
        }
    }

    return null;
}
    
    /**
     * Extract destination from body or subject
     */
    protected function extractDestination($body, $subject)
    {
        $destinations = ['Vietnam', 'Mauritius', 'Sri Lanka', 'Thailand', 'Singapore', 'Malaysia', 'Bali', 'Indonesia'];
        foreach ($destinations as $dest) {
            if (stripos($body, $dest) !== false || stripos($subject, $dest) !== false) {
                return $dest;
            }
        }
        return null;
    }
    
    /**
     * Save attachments
     */
    protected function saveAttachments($attachments, $email)
    {
        foreach ($attachments as $attachment) {
            $filename = $attachment['name'] ?? 'attachment';
            
            if (isset($attachment['contentBytes'])) {
                $content = base64_decode($attachment['contentBytes']);
            } elseif (isset($attachment['contentUrl'])) {
                $content = Http::withToken($this->accessToken)->get($attachment['contentUrl'])->body();
            } else {
                continue;
            }
            
            $path = "email_attachments/{$email->id}/{$filename}";
            
            if (!empty($content)) {
                Storage::disk('public')->put($path, $content);
                
                EmailAttachment::create([
                    'email_id' => $email->id,
                    'filename' => $filename,
                    'file_path' => $path,
                    'mime_type' => $attachment['contentType'] ?? 'application/octet-stream',
                    'file_size' => strlen($content)
                ]);
            }
        }
    }

    /**
 * Extract Invoice Number from email
 * Looks for patterns like: VN19610, IS48162, SG12345, MY12345, TH12345
 * Checks fields: IS Number, Confirmation Number, Invoice No.
 */
/**
 * Extract Agent Reference Number (MMT Booking ID, etc.)
 * Examples: NL2203305926788, ORN123456, etc.
 * This is different from Tour Ref which ends with CNTL
 */
protected function extractAgentReferenceNo($text)
{
    $tourSection = '';
    if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
        $tourSection = $sectionMatch[1];
    }
    $searchText = !empty($tourSection) ? $tourSection : $text;
    
    // Pattern 1: MMT - Booking ID (NL format)
    // "MMT - Booking ID: NL2203305926788" or "Booking ID: NL2203305926788"
    if (preg_match('/Booking\s+ID\s*[:\s]*(NL\d+)/i', $searchText, $match)) {
        $value = trim($match[1]);
        Log::info("✓ Extracted Agent Reference (Booking ID): {$value}");
        return $value;
    }
    
    // Pattern 2: Reference No field (generic)
    if (preg_match('/Reference\s+No\.?\s*[:\s]*([A-Z0-9]+(?:CNTL)?)/i', $searchText, $match)) {
        $value = trim($match[1]);
        // Skip if it ends with CNTL (that's tour ref, not agent ref)
        if (!preg_match('/CNTL$/i', $value)) {
            Log::info("✓ Extracted Agent Reference (Reference No): {$value}");
            return $value;
        }
    }
    
    // Pattern 3: ORN format (ORN2203305926788)
    if (preg_match('/\b(ORN\d+)\b/i', $searchText, $match)) {
        $value = trim($match[1]);
        Log::info("✓ Extracted Agent Reference (ORN): {$value}");
        return $value;
    }
    
    // Pattern 4: Look for NL numbers (but not if it's the only thing)
    if (preg_match('/\b(NL\d{10,})\b/i', $searchText, $match)) {
        $value = trim($match[1]);
        Log::info("✓ Extracted Agent Reference (NL format): {$value}");
        return $value;
    }
    
    Log::info("✗ No Agent Reference Number found");
    return null;
}

/**
 * Extract Tour Reference (ends with CNTL)
 * Example: 462414CNTL
 */
protected function extractTourReference($text)
{
    $tourSection = '';
    if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
        $tourSection = $sectionMatch[1];
    }
    $searchText = !empty($tourSection) ? $tourSection : $text;
    
    // Pattern 1: Tour Ref field (specifically looking for CNTL ending)
    if (preg_match('/Tour\s+Ref\s*[:\s]*([A-Z0-9]+CNTL)/i', $searchText, $match)) {
        $value = trim($match[1]);
        Log::info("✓ Extracted Tour Ref (CNTL): {$value}");
        return $value;
    }
    
    // Pattern 2: Tour Ref field without CNTL but likely is CNTL
    if (preg_match('/Tour\s+Ref\s*[:\s]*([A-Z0-9]{6,})/i', $searchText, $match)) {
        $value = trim($match[1]);
        Log::info("✓ Extracted Tour Ref: {$value}");
        return $value;
    }
    
    // Pattern 3: Look for CNTL pattern anywhere
    if (preg_match('/\b(\d{6,}CNTL)\b/i', $searchText, $match)) {
        $value = trim($match[1]);
        Log::info("✓ Extracted CNTL pattern: {$value}");
        return $value;
    }
    
    Log::info("✗ No Tour Ref found - will set to NA");
    return null;  // Return null, let caller handle "NA"
}

/**
 * Extract Invoice Number from email
 * Looks for patterns like: VN19610, IS48162, SG12345, MY12345
 */
/**
 * Extract Invoice Number from email
 * Looks for patterns like: VN19610, IS48162, SG12345, MY12345
 * Now handles spaces: IS 48363 -> IS48363
 */
protected function extractInvoiceNumber($text)
{
    // First, get the TOUR CONFIRMATION section
    $tourSection = '';
    if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
        $tourSection = $sectionMatch[1];
    }
    $searchText = !empty($tourSection) ? $tourSection : $text;
    
    // Pattern 1: IS Number field (from 30 Sundays email)
    // Handle both "IS Number: IS 48363" and "IS Number: IS48363"
    if (preg_match('/IS\s+Number\s*[:\s]*([A-Z]{2,3})\s+(\d+)/i', $searchText, $match)) {
        // Found "IS 48363" with space - remove space
        $value = strtoupper(trim($match[1] . $match[2]));
        Log::info("✓ Extracted Invoice Number from IS Number (with space removed): {$value}");
        return $value;
    }
    
    // Same pattern but without space (IS48363)
    if (preg_match('/IS\s+Number\s*[:\s]*([A-Z]{2,3}\d+)/i', $searchText, $match)) {
        $value = strtoupper(trim($match[1]));
        Log::info("✓ Extracted Invoice Number from IS Number: {$value}");
        return $value;
    }
    
    // Pattern 2: Confirmation Number field (from Make My Trip email)
    if (preg_match('/Confirmation\s+Number\s*[:\s]*([A-Z]{2,3})\s+(\d+)/i', $searchText, $match)) {
        $value = strtoupper(trim($match[1] . $match[2]));
        Log::info("✓ Extracted Invoice Number from Confirmation Number (space removed): {$value}");
        return $value;
    }
    
    if (preg_match('/Confirmation\s+Number\s*[:\s]*([A-Z]{2,3}\d+)/i', $searchText, $match)) {
        $value = strtoupper(trim($match[1]));
        Log::info("✓ Extracted Invoice Number from Confirmation Number: {$value}");
        return $value;
    }
    
    // Pattern 3: Invoice No. field
    if (preg_match('/Invoice\s+No\.?\s*[:\s]*([A-Z]{2,3})\s+(\d+)/i', $searchText, $match)) {
        $value = strtoupper(trim($match[1] . $match[2]));
        Log::info("✓ Extracted Invoice Number from Invoice No (space removed): {$value}");
        return $value;
    }
    
    if (preg_match('/Invoice\s+No\.?\s*[:\s]*([A-Z]{2,3}\d+)/i', $searchText, $match)) {
        $value = strtoupper(trim($match[1]));
        Log::info("✓ Extracted Invoice Number from Invoice No: {$value}");
        return $value;
    }
    
    // Pattern 4: Direct patterns with space
    // "VN 56758" -> "VN56758"
    $patterns_with_space = [
        '/\b(VN)\s+(\d{5,})\b/i',
        '/\b(IS)\s+(\d{5,})\b/i',
        '/\b(SG)\s+(\d{5,})\b/i',
        '/\b(MY)\s+(\d{5,})\b/i',
        '/\b(TH)\s+(\d{5,})\b/i',
        '/\b(ID)\s+(\d{5,})\b/i',
    ];
    
    foreach ($patterns_with_space as $pattern) {
        if (preg_match($pattern, $searchText, $match)) {
            $value = strtoupper(trim($match[1] . $match[2]));  // Remove space
            Log::info("✓ Extracted Invoice Number from pattern with space: {$value}");
            return $value;
        }
    }
    
    // Pattern 5: Without space - VN56758, IS48363
    $patterns_no_space = [
        '/\b(VN\d{5,})\b/i',
        '/\b(IS\d{5,})\b/i',
        '/\b(SG\d{5,})\b/i',
        '/\b(MY\d{5,})\b/i',
        '/\b(TH\d{5,})\b/i',
        '/\b(ID\d{5,})\b/i',
    ];
    
    foreach ($patterns_no_space as $pattern) {
        if (preg_match($pattern, $searchText, $match)) {
            $value = strtoupper(trim($match[1]));
            Log::info("✓ Extracted Invoice Number from pattern: {$value}");
            return $value;
        }
    }
    
    Log::info("✗ No Invoice Number found");
    return null;
}
/**
 * Extract passenger/guest names from email
 */
/**
 * Extract passenger/guest names from email
 * Handles both formats:
 * 1. "Guests Name & Contact details\nMr. VINAY KUMAR (+91 9079697559)"
 * 2. "Passenger Details" table with Lead Passenger Name and Name/Type/Age
 */
protected function extractPassengerNames($text)
{
    $passengers = [];
    
    // Try to get TOUR CONFIRMATION section
    $tourSection = '';
    if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
        $tourSection = $sectionMatch[1];
    }
    $searchText = !empty($tourSection) ? $tourSection : $text;
    
    // ========== METHOD 1: Passenger Details section ==========
    if (preg_match('/Passenger Details(.*?)(?:City|Hotel|Total Tour Cost|$)/is', $searchText, $sectionMatch)) {
        $passengerSection = $sectionMatch[1];
        Log::info("Found Passenger Details section");
        
        // Pattern for Name, Type, Age (like "Savinay Singh Adult 43")
        if (preg_match_all('/([A-Za-z\s]+)\s+(Adult|Child)\s+(\d+)/i', $passengerSection, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = trim(preg_replace('/\s+/', ' ', $match[1]));
                if (!empty($name) && strlen($name) > 2 && !in_array($name, $passengers)) {
                    $passengers[] = $name;
                }
            }
        }
        
        // Alternative: Lead Passenger Name
        if (preg_match('/Lead Passenger Name[:\s]*([^\n]+)/i', $passengerSection, $match)) {
            $leadName = trim($match[1]);
            if (!empty($leadName) && !in_array($leadName, $passengers)) {
                array_unshift($passengers, $leadName);
            }
        }
    }
    
    // ========== METHOD 2: Guests Name & Contact details format ==========
    if (empty($passengers)) {
        // Pattern: "Guests Name & Contact details\nMr. VINAY KUMAR (+91 9079697559)"
        if (preg_match('/Guests Name\s*&?\s*Contact\s*details\s*[:\s]*([^\n]+)/i', $searchText, $match)) {
            $guestLine = trim($match[1]);
            // Extract name before phone number
            if (preg_match('/([A-Za-z\.\s]+)(?:\+|\(?\d)/', $guestLine, $nameMatch)) {
                $name = trim($nameMatch[1]);
                $name = preg_replace('/\s+/', ' ', $name);
                if (!empty($name) && strlen($name) > 2) {
                    $passengers[] = $name;
                }
            } else {
                $passengers[] = $guestLine;
            }
        }
        
        // Simple Guests Name field
        if (empty($passengers)) {
            $guestName = $this->extractField($searchText, 'Guests Name');
            if ($guestName && $guestName != 'NA') {
                // Clean up - remove phone numbers
                $guestName = preg_replace('/\s*\(?\+?\d+[\d\s\-]+\)?/', '', $guestName);
                $guestName = trim($guestName);
                if (!empty($guestName)) {
                    $passengers[] = $guestName;
                }
            }
        }
    }
    
    // ========== METHOD 3: Guest Name from any field ==========
    if (empty($passengers)) {
        if (preg_match('/Guest\s+Name[:\s]*([^\n]+)/i', $searchText, $match)) {
            $name = trim($match[1]);
            if (!empty($name) && $name != 'NA') {
                $passengers[] = $name;
            }
        }
    }
    
    // Clean up and format passenger names
    $passengers = array_filter(array_unique($passengers));
    $passengers = array_map(function($name) {
        // Remove any remaining phone numbers
        $name = preg_replace('/\s*\(?\+?\d+[\d\s\-\(\)]+\)?/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }, $passengers);
    
    $result = !empty($passengers) ? implode(', ', $passengers) : null;
    Log::info("Extracted Passengers: " . ($result ?: 'None'));
    
    return $result ? [$result] : [];
}
/**
 * Clean invoice number - remove spaces and normalize
 */
protected function cleanInvoiceNumber($invoiceNumber)
{
    if (!$invoiceNumber) {
        return null;
    }
    
    // Remove all spaces
    $cleaned = str_replace(' ', '', $invoiceNumber);
    
    // Convert to uppercase
    $cleaned = strtoupper($cleaned);
    
    // Remove any non-alphanumeric characters except letters and numbers
    $cleaned = preg_replace('/[^A-Z0-9]/', '', $cleaned);
    
    Log::info("Cleaned Invoice Number: '{$invoiceNumber}' -> '{$cleaned}'");
    
    return $cleaned;
}
}