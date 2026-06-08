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
            $response = Http::withToken($this->accessToken)
                ->get('https://graph.microsoft.com/v1.0/users/' . env('GRAPH_INVOICE_USER') . '/messages', [
                    '$top' => 100,
                    '$orderby' => 'receivedDateTime desc',
                    '$select' => 'id,subject,body,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
                    '$expand' => 'attachments'
                ]);
            
            if (!$response->ok()) {
                Log::error('Failed to fetch emails: ' . $response->body());
                return 0;
            }
            
            $messages = $response->json()['value'] ?? [];
            Log::info("📥 Fetched " . count($messages) . " emails from mailbox");
            
            $newCount = 0;
            
            foreach ($messages as $message) {
                $subject = $message['subject'] ?? 'NO SUBJECT';
                Log::info("Processing: " . $subject);
                
                $existing = IncomingEmail::where('message_id', $message['id'])->first();
                
                if ($existing) {
                    $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
                    if ($existing->read_status !== $readStatus) {
                        $existing->update(['read_status' => $readStatus]);
                        Log::info("Updated read status for: " . $subject);
                    }
                } else {
                    $saved = $this->saveEmail($message);
                    if ($saved) {
                        $newCount++;
                        Log::info("✅ Saved new email: " . $subject);
                    }
                }
            }
            
            Log::info("📊 Summary: {$newCount} new emails saved");
            return $newCount;
            
        } catch (\Exception $e) {
            Log::error('Error fetching emails: ' . $e->getMessage());
            return 0;
        }
    }
    
protected function saveEmail($message)
{
    try {
        $subject = $message['subject'] ?? 'No Subject';
        
        // Get raw HTML body
        $htmlBody = $message['body']['content'] ?? $message['bodyPreview'] ?? '';
        
        // Convert HTML to plain text while preserving line breaks
        $plainText = $this->htmlToPlainText($htmlBody);
        
        // Log the full text for debugging (first 2000 chars)
        Log::info("Email body preview: " . substr($plainText, 0, 2000));
        
        $fromEmail = $message['from']['emailAddress']['address'] ?? '';
        $fromName = $message['from']['emailAddress']['name'] ?? '';
        $receivedAt = Carbon::parse($message['receivedDateTime']);
        $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
        
        // Check if this is a Tour Confirmation email
        $isTourConfirmation = stripos($plainText, 'TOUR CONFIRMATION') !== false;
        
        // Extract Tour Ref - THIS IS THE INVOICE NUMBER
      $tourRef = $this->extractTourReference($plainText);

// NEW code - add these:
// Extract Invoice Number (VN19610, IS48162 etc.)
// ========== EXTRACT ALL REFERENCE NUMBERS ==========
// 1. Extract Invoice Number (VN19610, IS48162, etc.)
$invoiceNumber = $this->extractInvoiceNumber($plainText);

// 2. Extract Tour Ref (ends with CNTL - like 462414CNTL)
$tourRef = $this->extractTourReference($plainText);

// 3. Extract Agent Reference No (MMT Booking ID, etc. - like NL2203305926788)
$agentReferenceNo = $this->extractAgentReferenceNo($plainText);

// 4. If tourRef not found, set to "NA"
if (!$tourRef) {
    $tourRef = "NA";
}

// 5. If no agent reference found, set to "NA" (NOT using tourRef)
if (!$agentReferenceNo) {
    $agentReferenceNo = "NA";
}

// 6. If no invoice number found, set to "NA"
if (!$invoiceNumber) {
    $invoiceNumber = "NA";
}

Log::info("Final Extracted - Invoice: {$invoiceNumber}, Tour Ref: {$tourRef}, Agent Ref: {$agentReferenceNo}");
        
        // Extract File Handler - This should be in TOUR CONFIRMATION section
        $fileHandler = $this->extractField($plainText, 'File Handler');
        
        // Extract Agent Name - This should be in TOUR CONFIRMATION section
        $agentName = $this->extractField($plainText, 'Agent');
        if ($agentName) {
            // Clean up agent name - remove anything after dash
            $agentName = preg_replace('/\s*[-–].*$/', '', $agentName);
            $agentName = trim($agentName);
            Log::info("Cleaned Agent Name: {$agentName}");
        }
        
        // Extract Guest Name
      // Extract passenger names
$passengerNames = $this->extractPassengerNames($plainText);
$guestName = !empty($passengerNames) ? implode(', ', $passengerNames) : $this->extractField($plainText, 'Guests Name');
        
        // ========== IMPROVED TRAVEL DATE EXTRACTION ==========
        $travelStart = null;
        $travelEnd = null;
        
        // Try to extract from "Arrival Date" field
        if (preg_match('/Arrival Date[:\s]*([0-9\/\-]+)/i', $plainText, $match)) {
            try {
                $travelStart = Carbon::parse(trim($match[1]))->format('Y-m-d');
                Log::info("Extracted Arrival Date: {$travelStart}");
            } catch (\Exception $e) {}
        }
        
        // Try to extract from "Travel Date:" or "Tour Date:" field
        if (!$travelStart) {
            if (preg_match('/Travel Date[:\s]*([0-9\/\-]+)/i', $plainText, $match)) {
                try {
                    $travelStart = Carbon::parse(trim($match[1]))->format('Y-m-d');
                    Log::info("Extracted Travel Date: {$travelStart}");
                } catch (\Exception $e) {}
            }
        }
        
        // Try to extract from date range like "2026-6-10" or "Jun 10, 2026"
        if (!$travelStart) {
            if (preg_match('/(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/', $plainText, $match)) {
                try {
                    $travelStart = Carbon::parse(trim($match[1]))->format('Y-m-d');
                    Log::info("Extracted Date from pattern: {$travelStart}");
                } catch (\Exception $e) {}
            }
        }
        
        // Extract Travel End Date
        // Look for date range like "Jun 10, 2026 - Jun 16, 2026" or "2026-6-10 to 2026-6-16"
        if (preg_match('/(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})\s*[-–to]+\s*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/i', $plainText, $match)) {
            try {
                $travelEnd = Carbon::parse(trim($match[2]))->format('Y-m-d');
                if (!$travelStart) {
                    $travelStart = Carbon::parse(trim($match[1]))->format('Y-m-d');
                }
                Log::info("Extracted Date Range: {$match[1]} to {$match[2]}");
            } catch (\Exception $e) {}
        }
        
        // Look for end date from the TOUR CONFIRMATION section
        if (!$travelEnd && $travelStart) {
            // Try to find end date from the itinerary
            $endDatePatterns = [
                '/Bentota\s+([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})/i',
                '/Drop off[:\s]+(\d{1,2}\/\d{1,2}\/\d{4})/i',
                '/End Date[:\s]+(\d{1,2}\/\d{1,2}\/\d{4})/i',
            ];
            
            foreach ($endDatePatterns as $pattern) {
                if (preg_match($pattern, $plainText, $match)) {
                    try {
                        if (isset($match[3])) {
                            $travelEnd = Carbon::parse("{$match[2]} {$match[1]} {$match[3]}")->format('Y-m-d');
                        } else {
                            $travelEnd = Carbon::parse(trim($match[1]))->format('Y-m-d');
                        }
                        Log::info("Extracted End Date from pattern: {$travelEnd}");
                        break;
                    } catch (\Exception $e) {}
                }
            }
        }
        
        Log::info("Travel Dates - Start: {$travelStart}, End: {$travelEnd}");
        // ========== END OF TRAVEL DATE EXTRACTION ==========
        
        // Extract Total Amount
        $totalAmount = null;
        $currency = 'USD';
        
        // Look for Total Tour Cost
        if (preg_match('/Total Tour Cost[:\s]*([A-Z]{3})?\s*\$?\s*([0-9,]+\.?[0-9]*)/i', $plainText, $match)) {
            $totalAmount = floatval(str_replace(',', '', $match[2]));
            if (isset($match[1]) && !empty($match[1])) {
                $currency = strtoupper($match[1]);
            }
            Log::info("Found Total Tour Cost: {$currency} {$totalAmount}");
        }
        // Look for dollar amount
        elseif (preg_match('/\$\s*([0-9,]+\.?[0-9]*)/', $plainText, $match)) {
            $totalAmount = floatval(str_replace(',', '', $match[1]));
            Log::info("Found USD amount: {$totalAmount}");
        }
        
        // Extract number of guests
      // In saveEmail method, replace the number_of_guests extraction with:

// Extract number of guests - handle "2 Adults" format
$numberOfGuests = null;
if (preg_match('/No\. of Guests?[:\s]*(\d+)\s*Adults?/i', $plainText, $match)) {
    $numberOfGuests = intval($match[1]);
    Log::info("Extracted Number of Guests: {$numberOfGuests}");
}
// Alternative pattern
elseif (preg_match('/No\. of Guests?[:\s]*(\d+)/i', $plainText, $match)) {
    $numberOfGuests = intval($match[1]);
    Log::info("Extracted Number of Guests: {$numberOfGuests}");
}

// Extract pax count from "No. of Adult" or "Adults"
$paxCount = null;
if (preg_match('/No\. of Adult[:\s]*(\d+)/i', $plainText, $match)) {
    $paxCount = intval($match[1]);
    Log::info("Extracted Pax Count from Adult: {$paxCount}");
}
elseif (preg_match('/(\d+)\s*Adults?/i', $plainText, $match)) {
    $paxCount = intval($match[1]);
    Log::info("Extracted Pax Count from Adults: {$paxCount}");
}
        
        // Extract destination
        $destination = $this->extractDestination($plainText, $subject);
        
        // Classification
        $classification = $this->agentClassifier->classify($plainText, $fromEmail, $subject, $agentName);
        
        Log::info("Extracted Data", [
            'subject' => $subject,
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
        
        // Save to database - Add travel_end_date field if exists in migration
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
            'travel_end_date' => $travelEnd, // Add this if column exists
            'number_of_guests' => $numberOfGuests,
            'pax_count' => $paxCount, // Add this if column exists
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
    
/**
 * Extract field value from plain text - FIXED VERSION
 * Handles format: "Field Name: Value" or "Field Name\nValue"
 */
/**
 * Extract field value from plain text - IMPROVED for TOUR CONFIRMATION
 */
/**
 * Extract field value from plain text - FIXED for your email format
 */
protected function extractField($text, $fieldName)
{
    // First, try to find the TOUR CONFIRMATION section
    $tourConfirmationSection = '';
    if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
        $tourConfirmationSection = $sectionMatch[1];
        Log::info("Found TOUR CONFIRMATION section for {$fieldName}");
    }
    
    // Search in TOUR CONFIRMATION section first if available
    $searchText = !empty($tourConfirmationSection) ? $tourConfirmationSection : $text;
    
    // Clean the text - remove special Unicode characters
    $searchText = preg_replace('/[^\x20-\x7E\x0A\x0D]/u', ' ', $searchText);
    
    // Pattern 1: Field Name followed by newline then value (most common)
    // Example: "File Handler\nSajid" or "File Handler \n Sajid"
    $pattern1 = '/' . preg_quote($fieldName, '/') . '\s*\n\s*([^\n]+)/i';
    if (preg_match($pattern1, $searchText, $match)) {
        $value = trim($match[1]);
        $value = preg_replace('/\s+/', ' ', $value);
        if (!empty($value) && strlen($value) < 200) {
            Log::info("✓ Extracted {$fieldName} (pattern1): {$value}");
            return $value;
        }
    }
    
    // Pattern 2: Field Name followed by spaces then value (same line)
    // Example: "File Handler Sajid" or "File Handler: Sajid"
    $pattern2 = '/' . preg_quote($fieldName, '/') . '\s*:?\s*([^\n]+)/i';
    if (preg_match($pattern2, $searchText, $match)) {
        $value = trim($match[1]);
        $value = preg_replace('/\s+/', ' ', $value);
        // Skip if the value looks like another field name
        if (!empty($value) && strlen($value) < 200 && !preg_match('/^(Tour Ref|Flight|Agent|Guests Name|IS Number)/i', $value)) {
            Log::info("✓ Extracted {$fieldName} (pattern2): {$value}");
            return $value;
        }
    }
    
    // Pattern 3: Look for exact line with field name
    $lines = explode("\n", $searchText);
    foreach ($lines as $i => $line) {
        if (preg_match('/' . preg_quote($fieldName, '/') . '/i', $line)) {
            // Check next line for value
            if (isset($lines[$i + 1])) {
                $value = trim($lines[$i + 1]);
                // Skip if the next line looks like another field or is empty
                if (!empty($value) && !preg_match('/^(Emergency contact|Customer Support|Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan)/i', $value)) {
                    Log::info("✓ Extracted {$fieldName} (pattern3 - next line): {$value}");
                    return $value;
                }
            }
            // Also check same line after removing the field name
            $value = preg_replace('/' . preg_quote($fieldName, '/') . '\s*/i', '', $line);
            $value = trim($value);
            if (!empty($value) && strlen($value) < 200) {
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
protected function extractInvoiceNumber($text)
{
    // First, get the TOUR CONFIRMATION section
    $tourSection = '';
    if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
        $tourSection = $sectionMatch[1];
    }
    $searchText = !empty($tourSection) ? $tourSection : $text;
    
    // Pattern 1: IS Number field (from 30 Sundays email)
    if (preg_match('/IS\s+Number\s*[:\s]*([A-Z]{2,3}\d+)/i', $searchText, $match)) {
        $value = strtoupper(trim($match[1]));
        Log::info("✓ Extracted Invoice Number from IS Number: {$value}");
        return $value;
    }
    
    // Pattern 2: Confirmation Number field (from Make My Trip email)
    if (preg_match('/Confirmation\s+Number\s*[:\s]*([A-Z]{2,3}\d+)/i', $searchText, $match)) {
        $value = strtoupper(trim($match[1]));
        Log::info("✓ Extracted Invoice Number from Confirmation Number: {$value}");
        return $value;
    }
    
    // Pattern 3: Invoice No. field
    if (preg_match('/Invoice\s+No\.?\s*[:\s]*([A-Z]{2,3}\d+)/i', $searchText, $match)) {
        $value = strtoupper(trim($match[1]));
        Log::info("✓ Extracted Invoice Number from Invoice No: {$value}");
        return $value;
    }
    
    // Pattern 4: Country code patterns
    $patterns = [
        '/\b(VN\d{5,})\b/i',
        '/\b(IS\d{5,})\b/i',
        '/\b(SG\d{5,})\b/i',
        '/\b(MY\d{5,})\b/i',
        '/\b(TH\d{5,})\b/i',
        '/\b(ID\d{5,})\b/i',
    ];
    
    foreach ($patterns as $pattern) {
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

}