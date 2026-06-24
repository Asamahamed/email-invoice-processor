<?php

namespace App\Services;

use App\Models\IncomingEmail;
use App\Models\EmailAttachment;
use App\Models\GeneratedInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Mail\InvoiceMail;
use Illuminate\Support\Facades\Mail;

class MicrosoftGraphService
{
    protected $accessToken;
    protected $agentClassifier;
    protected $batchSize = 100;
    protected $maxEmailsToProcess = 5000; // ✅ Increased limit
    
    public function __construct()
    {
        $this->authenticate();
        $this->agentClassifier = new AgentClassificationService();
    }
    // Add these public methods at the end of the class

public function getAccessToken()
{
    return $this->accessToken;
}

public function isAuthenticated()
{
    return !empty($this->accessToken);
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
        $totalFetched = 0;
        
        $baseUrl = 'https://graph.microsoft.com/v1.0/users/' . env('GRAPH_INVOICE_USER') . '/mailfolders/inbox/messages';
        
        Log::info("🚀 Starting to fetch emails from INBOX (OLDEST FIRST)...");
        
        // ✅ Get existing message IDs
        $existingIds = IncomingEmail::pluck('message_id')->toArray();
        $existingIdSet = array_flip($existingIds);
        Log::info("📊 Found " . count($existingIds) . " existing emails in database");
        
        do {
            // ✅ CHANGED: 'asc' instead of 'desc' - OLDEST FIRST
            $url = $nextLink ?? $baseUrl . '?' . http_build_query([
                '$top' => $this->batchSize,
                '$orderby' => 'receivedDateTime asc',  // ← OLDEST FIRST
                '$select' => 'id,subject,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
            ]);
            
            Log::info("📡 Fetching page " . ($pageCount + 1) . " (OLDEST FIRST)");
            
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
            
            Log::info("📥 Page " . ($pageCount + 1) . " has " . count($messages) . " messages");
            
            foreach ($messages as $message) {
                $messageId = $message['id'];
                
                if (isset($existingIdSet[$messageId])) {
                    Log::info("⏭️ Skipping existing email: " . ($message['subject'] ?? 'NO SUBJECT'));
                    continue;
                }
                
                $existingIdSet[$messageId] = true;
                $allMessages[] = $message;
                $totalFetched++;
            }
            
            $nextLink = $data['@odata.nextLink'] ?? null;
            $pageCount++;
            
            Log::info("📊 Page {$pageCount}: Found " . count($allMessages) . " new emails so far");
            
            if ($totalFetched >= $this->maxEmailsToProcess) {
                Log::info("⚠️ Reached safety limit of {$this->maxEmailsToProcess} new emails");
                break;
            }
            
            if (!$nextLink) {
                Log::info("✅ Reached end of mailbox");
                break;
            }
            
            usleep(200000);
            
        } while ($nextLink);
        
        Log::info("📥 TOTAL new emails to process: " . count($allMessages));
        
        $savedCount = 0;
        $failedCount = 0;
        $chunkSize = 20;
        
        foreach (array_chunk($allMessages, $chunkSize) as $chunkIndex => $chunk) {
            Log::info("📦 Processing chunk " . ($chunkIndex + 1) . " of " . ceil(count($allMessages) / $chunkSize));
            
            foreach ($chunk as $index => $message) {
                try {
                    $subject = $message['subject'] ?? 'NO SUBJECT';
                    Log::info("🔄 Processing email " . ($index + 1) . ": " . $subject);
                    
                    $saved = $this->processEmail($message);
                    
                    if ($saved === true) {
                        $savedCount++;
                        Log::info("✅ Successfully processed: " . $subject);
                    } else {
                        $failedCount++;
                        Log::error("❌ Failed to process: " . $subject);
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    Log::error("❌ Exception processing email: " . ($message['subject'] ?? 'Unknown') . " - " . $e->getMessage() . "\n" . $e->getTraceAsString());
                }
            }
            
            usleep(100000);
        }
        
        Log::info("📊 Final Summary: {$savedCount} saved, {$failedCount} failed out of " . count($allMessages) . " total");
        return $savedCount;
        
    } catch (\Exception $e) {
        Log::error('Error fetching emails: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return 0;
    }
}
    
    protected function processEmail($message)
    {
        $messageId = $message['id'];
        $subject = $message['subject'] ?? 'NO SUBJECT';
        
        // ✅ Double-check if already exists (to avoid race conditions)
        if (IncomingEmail::where('message_id', $messageId)->exists()) {
            Log::info("⏭️ Email already exists (double-check): " . $subject);
            return true;
        }
        
        // ✅ Try to fetch full message
        $fullMessage = $this->fetchFullMessage($messageId);
        
        if ($fullMessage) {
            return $this->saveEmail($fullMessage);
        } else {
            Log::warning("⚠️ Could not fetch full message for: " . $subject . ", using preview");
            return $this->saveEmailWithPreview($message);
        }
    }
    
    protected function fetchFullMessage($messageId)
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(60)
                ->get('https://graph.microsoft.com/v1.0/users/' . env('GRAPH_INVOICE_USER') . '/messages/' . $messageId, [
                    '$select' => 'id,subject,body,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
                    '$expand' => 'attachments($top=10)'
                ]);
            
            if ($response->ok()) {
                return $response->json();
            } else {
                Log::warning("Failed to fetch full message {$messageId}: HTTP " . $response->status());
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Failed to fetch full message {$messageId}: " . $e->getMessage());
            return null;
        }
    }
    
protected function saveEmail($message)
{
    try {
        $subject = $message['subject'] ?? 'No Subject';
        
        $htmlBody = $message['body']['content'] ?? $message['bodyPreview'] ?? '';
        $plainText = $this->htmlToPlainText($htmlBody);
        
        $fromEmail = $message['from']['emailAddress']['address'] ?? '';
        $fromName = $message['from']['emailAddress']['name'] ?? '';
        $receivedAt = Carbon::parse($message['receivedDateTime']);
        $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
        
        $isTourConfirmation = stripos($plainText, 'TOUR CONFIRMATION') !== false;
        
        // Extract invoice number, tour ref, etc.
        $invoiceNumber = $this->extractInvoiceNumber($plainText);
        $invoiceNumber = $this->cleanInvoiceNumber($invoiceNumber);
        $tourRef = $this->extractTourReference($plainText);
        $agentReferenceNo = $this->extractAgentReferenceNo($plainText);
        
        if (!$tourRef) $tourRef = "NA";
        if (!$agentReferenceNo) $agentReferenceNo = "NA";
        if (!$invoiceNumber) $invoiceNumber = "NA";
        
        // Extract other fields
        $fileHandler = $this->extractField($plainText, 'File Handler');
        $agentName = $this->extractField($plainText, 'Agent');
        
        if ($agentName) {
            $agentName = preg_replace('/\s*[-–].*$/', '', $agentName);
            $agentName = trim($agentName);
        }
        
        // ✅ EXTRACT SALES PERSON
        $salesPerson = $this->extractField($plainText, 'Sales Person');
        if ($salesPerson) {
            $salesPerson = trim($salesPerson);
            Log::info("✅ Extracted Sales Person: {$salesPerson}");
        }
        
        $passengerNames = $this->extractPassengerNames($plainText);
        $guestName = !empty($passengerNames) ? implode(', ', $passengerNames) : $this->extractField($plainText, 'Guests Name');
        
        $travelDates = $this->extractTravelDates($plainText);
        $travelStart = $travelDates['start'];
        $travelEnd = $travelDates['end'];
        
        // ✅ FIX: ONLY extract Total Tour Cost with better patterns
        $totalAmount = null;
        $currency = 'USD';
        
        Log::info("🔍 Extracting Total Tour Cost from email: " . $subject);
        Log::info("📄 Plain text preview: " . substr($plainText, 0, 500));
        
        // ✅ PATTERN 1: Total Tour Cost with RM (Malaysia) - MUST CHECK FIRST
        // Matches: "Total Tour Cost | RM 3,197.00" or "Total Tour Cost RM 3,197.00"
        if (preg_match('/Total\s+Tour\s+Cost\s*[:\|]?\s*RM\s*([0-9,]+\.?[0-9]*)/i', $plainText, $match)) {
            $totalAmount = floatval(str_replace(',', '', $match[1]));
            $currency = 'MYR';
            Log::info("✅ Extracted Total Tour Cost (MYR): RM {$totalAmount}");
        }
        // ✅ PATTERN 2: Total Tour Cost with MYR
        elseif (preg_match('/Total\s+Tour\s+Cost\s*[:\|]?\s*MYR\s*([0-9,]+\.?[0-9]*)/i', $plainText, $match)) {
            $totalAmount = floatval(str_replace(',', '', $match[1]));
            $currency = 'MYR';
            Log::info("✅ Extracted Total Tour Cost (MYR): MYR {$totalAmount}");
        }
        // ✅ PATTERN 3: Total Tour Cost with S$ (Singapore)
        elseif (preg_match('/Total\s+Tour\s+Cost\s*[:\|]?\s*S\$\s*([0-9,]+\.?[0-9]*)/i', $plainText, $match)) {
            $totalAmount = floatval(str_replace(',', '', $match[1]));
            $currency = 'SGD';
            Log::info("✅ Extracted Total Tour Cost (SGD): S$ {$totalAmount}");
        }
        // ✅ PATTERN 4: Total Tour Cost with SGD
        elseif (preg_match('/Total\s+Tour\s+Cost\s*[:\|]?\s*SGD\s*([0-9,]+\.?[0-9]*)/i', $plainText, $match)) {
            $totalAmount = floatval(str_replace(',', '', $match[1]));
            $currency = 'SGD';
            Log::info("✅ Extracted Total Tour Cost (SGD): SGD {$totalAmount}");
        }
        // ✅ PATTERN 5: Total Tour Cost with $ (USD) - but check if it's Singapore (has S$)
        elseif (preg_match('/Total\s+Tour\s+Cost\s*[:\|]?\s*\$?\s*([0-9,]+\.?[0-9]*)/i', $plainText, $match)) {
            $totalAmount = floatval(str_replace(',', '', $match[1]));
            // Check if this is Singapore (look for S$ or Singapore in text)
            if (stripos($plainText, 'S$') !== false || stripos($plainText, 'SGD') !== false || stripos($plainText, 'Singapore') !== false) {
                $currency = 'SGD';
            } else {
                $currency = 'USD';
            }
            Log::info("✅ Extracted Total Tour Cost: {$currency} {$totalAmount}");
        }
        // ✅ PATTERN 6: Total Tour Cost with currency code (generic)
        elseif (preg_match('/Total\s+Tour\s+Cost\s*[:\|]?\s*([A-Z]{3})?\s*([0-9,]+\.?[0-9]*)/i', $plainText, $match)) {
            $totalAmount = floatval(str_replace(',', '', $match[2]));
            if (isset($match[1]) && !empty($match[1])) {
                $currency = strtoupper($match[1]);
            } else {
                $currency = $this->detectCurrencyFromText($plainText);
            }
            Log::info("✅ Extracted Total Tour Cost (generic): {$currency} {$totalAmount}");
        }
        
        // ✅ If still no amount, try ANY amount with currency symbols
        if (!$totalAmount) {
            // Try RM (Malaysia)
            if (preg_match('/RM\s*([0-9,]+\.?[0-9]*)/', $plainText, $match)) {
                $totalAmount = floatval(str_replace(',', '', $match[1]));
                $currency = 'MYR';
                Log::info("✅ Found RM amount: {$currency} {$totalAmount}");
            }
            // Try S$ (Singapore)
            elseif (preg_match('/S\$\s*([0-9,]+\.?[0-9]*)/', $plainText, $match)) {
                $totalAmount = floatval(str_replace(',', '', $match[1]));
                $currency = 'SGD';
                Log::info("✅ Found S$ amount: {$currency} {$totalAmount}");
            }
            // Try SGD
            elseif (preg_match('/SGD\s*([0-9,]+\.?[0-9]*)/i', $plainText, $match)) {
                $totalAmount = floatval(str_replace(',', '', $match[1]));
                $currency = 'SGD';
                Log::info("✅ Found SGD amount: {$currency} {$totalAmount}");
            }
            // Try USD
            elseif (preg_match('/\$\s*([0-9,]+\.?[0-9]*)/', $plainText, $match)) {
                $totalAmount = floatval(str_replace(',', '', $match[1]));
                $currency = 'USD';
                Log::info("✅ Found USD amount: {$currency} {$totalAmount}");
            }
        }
        
// ✅ EXTRACT NUMBER OF GUESTS - FIXED to count ALL guests (Adults + Children)
$numberOfGuests = null;
$adults = 0;
$cwbCount = 0;
$cnbCount = 0;

Log::info("🔍 Extracting guest count from email");

// ✅ PATTERN 1: "4 Adults | 3 CWB | 0 CNB" (full format)
if (preg_match('/(\d+)\s+Adults?\s*[|\s]*(\d+)\s+CWB\s*[|\s]*(\d+)\s+CNB/i', $plainText, $match)) {
    $adults = intval($match[1]);
    $cwbCount = intval($match[2]);
    $cnbCount = intval($match[3]);
    $numberOfGuests = $adults + $cwbCount + $cnbCount;
    Log::info("✅ Guests: {$adults} Adults + {$cwbCount} CWB + {$cnbCount} CNB = {$numberOfGuests} Total");
}
// ✅ PATTERN 2: "4 Adults | 3 CWB" (no CNB)
elseif (preg_match('/(\d+)\s+Adults?\s*[|\s]*(\d+)\s+CWB/i', $plainText, $match)) {
    $adults = intval($match[1]);
    $cwbCount = intval($match[2]);
    $numberOfGuests = $adults + $cwbCount;
    Log::info("✅ Guests: {$adults} Adults + {$cwbCount} CWB = {$numberOfGuests} Total");
}
// ✅ PATTERN 3: "No. of Guests | 4 Adults | 3 CWB"
elseif (preg_match('/No\.?\s+of\s+Guests?\s*[:\|]\s*(\d+)\s+Adults?\s*[|\s]*(\d+)\s+CWB/i', $plainText, $match)) {
    $adults = intval($match[1]);
    $cwbCount = intval($match[2]);
    $numberOfGuests = $adults + $cwbCount;
    Log::info("✅ Guests (with label): {$adults} Adults + {$cwbCount} CWB = {$numberOfGuests} Total");
}
// ✅ PATTERN 4: Just "No. of Guests: 4" (old format)
elseif (preg_match('/No\.?\s+of\s+Guests?\s*[:\s]*(\d+)/i', $plainText, $match)) {
    $numberOfGuests = intval($match[1]);
    Log::info("✅ Guests (simple): {$numberOfGuests}");
}
// ✅ PATTERN 5: "4 Adults" only
elseif (preg_match('/(\d+)\s+Adults?/i', $plainText, $match)) {
    $adults = intval($match[1]);
    $numberOfGuests = $adults;
    Log::info("✅ Guests (adults only): {$numberOfGuests}");
}

// ✅ PAX Count = Total number of guests (Adults + Children)
$paxCount = $numberOfGuests;
if ($paxCount) {
    Log::info("✅ PAX Count set to: {$paxCount}");
}
        
        $destination = $this->extractDestination($plainText, $subject);
        $classification = $this->agentClassifier->classify($plainText, $fromEmail, $subject, $agentName);
        
        // ✅ Build email data with ALL fields including sales_person
        $emailData = [
            'message_id' => $message['id'],
            'from_email' => $fromEmail ?: 'unknown@example.com',
            'from_name' => $fromName ?: 'Unknown Sender',
            'subject' => $subject ?: 'No Subject',
            'body' => $htmlBody ?: $plainText,
            'body_preview' => substr(($plainText ?: $htmlBody), 0, 500),
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
            'currency' => $currency ?: 'USD',
            'reference_no' => $agentReferenceNo,
            'credit_type' => $classification['credit_type'] ?? null,
            'classification_reason' => $classification['reason'] ?? null,
            'read_status' => $readStatus,
            'processing_status' => 'processed',
            'is_tour_confirmation' => $isTourConfirmation,
            'has_attachments' => $message['hasAttachments'] ?? false,
            'sales_person' => $salesPerson, // ✅ ADDED SALES PERSON
        ];
        
        Log::info("💾 FINAL - Total amount: {$currency} {$totalAmount} for: " . $subject);
        Log::info("💾 Sales Person: " . ($salesPerson ?: 'NULL'));
        
        try {
            $email = IncomingEmail::create($emailData);
            Log::info("💾 Saved email to database: " . $subject . " (ID: " . $email->id . ")");
        } catch (\Illuminate\Database\QueryException $qe) {
            Log::error('❌ Database error: ' . $qe->getMessage() . ' - Subject: ' . $subject);
            Log::error('   Data: ' . json_encode($emailData, JSON_PARTIAL_OUTPUT_ON_ERROR));
            return false;
        }
        
        if ($isTourConfirmation && $tourRef != 'NA' && $invoiceNumber != 'NA') {
            $this->autoGenerateInvoice($email);
        }
        
        if (isset($message['attachments']) && !empty($message['attachments'])) {
            $this->saveAttachments($message['attachments'], $email);
        }
        
        Log::info("✅ Successfully saved email: " . $subject);
        return true;
        
    } catch (\Exception $e) {
        Log::error('❌ Save failed: ' . $e->getMessage() . ' - Subject: ' . ($message['subject'] ?? 'N/A'));
        Log::error('   Trace: ' . $e->getTraceAsString());
        return false;
    }
}
protected function autoGenerateRevisionInvoice($newEmail, $oldEmail)
{
    try {
        // Check if any invoice exists with this tour_ref or invoice_number
        $existingInvoice = GeneratedInvoice::where(function($query) use ($newEmail) {
            $query->where('tour_ref', $newEmail->tour_ref)
                  ->orWhere('original_invoice_number', $newEmail->invoice_number)
                  ->orWhere('invoice_number', 'LIKE', $newEmail->invoice_number . '%');
        })->orderBy('revision_number', 'desc')->first();
        
        if (!$existingInvoice) {
            Log::info("ℹ️ No existing invoice found for revision, creating new invoice");
            $this->autoGenerateInvoice($newEmail);
            return;
        }
        
        // ✅ Calculate next revision number
        $nextRevisionNumber = ($existingInvoice->revision_number ?? 0) + 1;
        $baseNumber = $newEmail->invoice_number;
        
        // ✅ DISPLAY format: VN40113_R2/R2 (with slash for display)
        $displayInvoiceNumber = $baseNumber . '_R' . $nextRevisionNumber . '/R' . $nextRevisionNumber;
        
        // ✅ FILE format: VN40113_R2_R2 (with underscore for filename - NO SLASHES)
        $fileInvoiceNumber = $baseNumber . '_R' . $nextRevisionNumber . '_R' . $nextRevisionNumber;
        
        Log::info("📄 Creating revision invoice: Display: {$displayInvoiceNumber}, File: {$fileInvoiceNumber}");
        
        // ✅ Get classification
        $agentClassifier = new AgentClassificationService();
        $classification = $agentClassifier->classify(
            $newEmail->body ?? '', 
            $newEmail->from_email ?? '', 
            $newEmail->subject ?? '', 
            $newEmail->agent_name
        );
        
        // ✅ Create revision invoice using the FILE format (no slashes)
        $invoiceService = app(InvoiceGenerationService::class);
        $invoice = $invoiceService->generateRevisionFromEmail(
            $newEmail, 
            $classification, 
            $fileInvoiceNumber,  // ← Use fileInvoiceNumber (no slashes)
            $nextRevisionNumber,
            $baseNumber
        );
        
        if ($invoice) {
            // ✅ Update the invoice number to display format (with slashes) for UI
            $invoice->invoice_number = $displayInvoiceNumber;
            $invoice->save();
            
            Log::info("✅ Auto-generated REVISION invoice: " . $invoice->invoice_number);
            Log::info("   Revision: {$nextRevisionNumber} of " . ($invoice->total_revisions ?? $nextRevisionNumber));
        }
        
    } catch (\Exception $e) {
        Log::error('❌ Auto-generate revision invoice failed: ' . $e->getMessage());
    }
}
/**
 * Detect currency from text context
 */
protected function detectCurrencyFromText($text)
{
    // Check for Singapore Dollar
    if (stripos($text, 'S$') !== false || stripos($text, 'SGD') !== false) {
        return 'SGD';
    }
    // Check for Malaysian Ringgit
    if (stripos($text, 'RM') !== false || stripos($text, 'MYR') !== false) {
        return 'MYR';
    }
    // Check for USD
    if (stripos($text, '$') !== false || stripos($text, 'USD') !== false) {
        return 'USD';
    }
    return 'USD';
}
    
    protected function saveEmailWithPreview($message)
    {
        try {
            $subject = $message['subject'] ?? 'No Subject';
            $plainText = $message['bodyPreview'] ?? '';
            
            $fromEmail = $message['from']['emailAddress']['address'] ?? '';
            $fromName = $message['from']['emailAddress']['name'] ?? '';
            $receivedAt = Carbon::parse($message['receivedDateTime']);
            $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
            
            $isTourConfirmation = stripos($plainText, 'TOUR CONFIRMATION') !== false;
            
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
                'body' => $plainText,
                'body_preview' => substr($plainText, 0, 500),
                'received_at' => $receivedAt,
                'agent_name' => $agentName,
                'tour_ref' => $tourRef,
                'invoice_number' => $invoiceNumber,
                'file_handler' => $fileHandler,
                'credit_type' => $classification['credit_type'] ?? null,
                'classification_reason' => $classification['reason'] ?? null,
                'read_status' => $readStatus,
                'processing_status' => 'processed',
                'is_tour_confirmation' => $isTourConfirmation,
                'has_attachments' => $message['hasAttachments'] ?? false,
            ];
            
            IncomingEmail::create($emailData);
            Log::info("✅ Saved email with preview: " . $subject);
            return true;
            
        } catch (\Exception $e) {
            Log::error('❌ Save preview failed: ' . $e->getMessage() . ' - Subject: ' . ($message['subject'] ?? 'N/A'));
            return false;
        }
    }
    /**
 * ✅ EMERGENCY FIX: Save email with minimal data (fallback when full save fails)
 */
protected function saveEmailMinimal($message)
{
    try {
        $subject = $message['subject'] ?? 'No Subject';
        $htmlBody = $message['body']['content'] ?? $message['bodyPreview'] ?? '';
        $plainText = $this->htmlToPlainText($htmlBody);
        
        $fromEmail = $message['from']['emailAddress']['address'] ?? 'unknown@example.com';
        $fromName = $message['from']['emailAddress']['name'] ?? 'Unknown Sender';
        $receivedAt = Carbon::parse($message['receivedDateTime']);
        $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
        
        // Try to extract tour ref and invoice number if possible
        $invoiceNumber = $this->extractInvoiceNumber($plainText);
        $invoiceNumber = $this->cleanInvoiceNumber($invoiceNumber);
        $tourRef = $this->extractTourReference($plainText);
        
        if (!$tourRef) $tourRef = "NA";
        if (!$invoiceNumber) $invoiceNumber = "NA";
        
        // ✅ MINIMAL DATA - only essential fields
        $emailData = [
            'message_id' => $message['id'],
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'subject' => $subject,
            'body' => $htmlBody ?: $plainText,
            'body_preview' => substr(($plainText ?: $htmlBody), 0, 500),
            'received_at' => $receivedAt,
            'tour_ref' => $tourRef,
            'invoice_number' => $invoiceNumber,
            'read_status' => $readStatus,
            'processing_status' => 'processed',
            'is_tour_confirmation' => stripos($plainText, 'TOUR CONFIRMATION') !== false,
            'has_attachments' => $message['hasAttachments'] ?? false,
            // Set nullable fields to null
            'agent_name' => null,
            'guest_name' => null,
            'file_handler' => null,
            'travel_start_date' => null,
            'travel_end_date' => null,
            'number_of_guests' => null,
            'pax_count' => null,
            'destination' => null,
            'total_amount' => null,
            'currency' => 'USD',
            'reference_no' => null,
            'credit_type' => null,
            'classification_reason' => null,
        ];
        
        $email = IncomingEmail::create($emailData);
        Log::info("💾 Saved email with MINIMAL data: " . $subject . " (ID: " . $email->id . ")");
        return true;
        
    } catch (\Exception $e) {
        Log::error('❌ Minimal save failed: ' . $e->getMessage() . ' - Subject: ' . ($message['subject'] ?? 'N/A'));
        Log::error('   Trace: ' . $e->getTraceAsString());
        return false;
    }
}
protected function autoGenerateInvoice($email)
{
    try {
        if (GeneratedInvoice::where('email_id', $email->id)->exists()) {
            Log::info("⏭️ Invoice already exists for email: " . $email->subject);
            return;
        }
        
        if ($email->tour_ref == 'NA' || $email->invoice_number == 'NA') {
            Log::info("⏭️ Skipping auto-generate - missing tour_ref or invoice_number for: " . $email->subject);
            return;
        }
        
        Log::info("🏷️ Generating invoice for: " . $email->subject);
        
        $invoiceService = app(InvoiceGenerationService::class);
        $invoice = $invoiceService->generateFromEmail($email);
        
        if ($invoice) {
            Log::info("✅ Auto-generated invoice: " . $invoice->invoice_number);
            
            // ✅ Send email
            $this->sendInvoiceEmail($invoice);
            Log::info("📧 Email sent for invoice: " . $invoice->invoice_number);
            
        } else {
            Log::warning("⚠️ Invoice generation returned null for email: " . $email->subject);
        }
        
    } catch (\Exception $e) {
        Log::error('❌ Auto-generate invoice failed: ' . $e->getMessage());
    }
}
/**
 * ✅ Send Invoice Email - Added to MicrosoftGraphService
 */
protected function sendInvoiceEmail($invoice)
{
    try {
        // Determine email type
        $emailType = 'credit';
        
        if ($invoice->is_revision) {
            $emailType = 'revision';
        } elseif ($invoice->invoice_type == 'non_credit') {
            $emailType = 'non_credit';
        }
        
        // Send email
        Mail::to('kevinraj@aahaas.com')
            ->cc('raja.lakshmi@aahaas.com')
            ->send(new InvoiceMail($invoice, $emailType));
        
        Log::info("📧 Invoice email sent for: " . $invoice->invoice_number);
        
    } catch (\Exception $e) {
        Log::error('❌ Failed to send invoice email: ' . $e->getMessage());
    }
}
public function debugFetchEmails()
{
    try {
        set_time_limit(600);
        
        $allMessages = [];
        $nextLink = null;
        $pageCount = 0;
        $totalFetched = 0;
        
        $baseUrl = 'https://graph.microsoft.com/v1.0/users/' . env('GRAPH_INVOICE_USER') . '/mailfolders/inbox/messages';
        
        Log::info("🚀 DEBUG: Starting to fetch emails from INBOX...");
        
        // ✅ Get existing message IDs as a SET for faster lookup
        $existingIds = IncomingEmail::pluck('message_id')->toArray();
        $existingIdSet = array_flip($existingIds); // Flip for O(1) lookup
        Log::info("📊 DEBUG: Found " . count($existingIds) . " existing emails in database");
        
        $failedEmails = [];
        $successEmails = [];
        $skippedEmails = [];
        
        do {
            $url = $nextLink ?? $baseUrl . '?' . http_build_query([
                '$top' => $this->batchSize,
                '$orderby' => 'receivedDateTime desc',
                '$select' => 'id,subject,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
            ]);
            
            Log::info("📡 DEBUG: Fetching page " . ($pageCount + 1));
            
            $response = Http::withToken($this->accessToken)
                ->timeout(180)
                ->get($url);
            
            if (!$response->ok()) {
                Log::error('DEBUG: Failed to fetch emails page: ' . $response->body());
                break;
            }
            
            $data = $response->json();
            $messages = $data['value'] ?? [];
            
            if (empty($messages)) {
                Log::info("DEBUG: No more messages to fetch");
                break;
            }
            
            Log::info("📥 DEBUG: Page " . ($pageCount + 1) . " has " . count($messages) . " messages");
            
            foreach ($messages as $message) {
                $messageId = $message['id'];
                $subject = $message['subject'] ?? 'NO SUBJECT';
                
                // ✅ FIXED: Use isset() for faster lookup
                if (isset($existingIdSet[$messageId])) {
                    $skippedEmails[] = $subject;
                    Log::info("⏭️ DEBUG: Skipping existing email: " . $subject);
                    continue;
                }
                
                // ✅ Add to existing set to avoid duplicates within this batch
                $existingIdSet[$messageId] = true;
                
                try {
                    $fullMessage = $this->fetchFullMessage($messageId);
                    
                    if ($fullMessage) {
                        // ✅ Try full save first
                        $result = $this->saveEmail($fullMessage);
                        if ($result === true) {
                            $successEmails[] = $subject;
                            Log::info("✅ DEBUG: Saved (full): " . $subject);
                            continue;
                        }
                        
                        // ✅ If full save fails, try minimal save
                        Log::warning("⚠️ Full save failed, trying minimal save: " . $subject);
                        $result = $this->saveEmailMinimal($fullMessage);
                        if ($result === true) {
                            $successEmails[] = $subject . ' (minimal)';
                            Log::info("✅ DEBUG: Saved (minimal): " . $subject);
                            continue;
                        }
                    } else {
                        // Try preview
                        $result = $this->saveEmailWithPreview($message);
                        if ($result === true) {
                            $successEmails[] = $subject . ' (preview)';
                            Log::info("✅ DEBUG: Saved with preview: " . $subject);
                            continue;
                        }
                        
                        // If preview fails, try minimal
                        $result = $this->saveEmailMinimal($message);
                        if ($result === true) {
                            $successEmails[] = $subject . ' (minimal from preview)';
                            Log::info("✅ DEBUG: Saved (minimal from preview): " . $subject);
                            continue;
                        }
                    }
                    
                    // If all attempts fail
                    $failedEmails[] = [
                        'subject' => $subject,
                        'reason' => 'All save attempts failed'
                    ];
                    Log::error("❌ DEBUG: All save attempts failed for: " . $subject);
                    
                } catch (\Exception $e) {
                    $failedEmails[] = [
                        'subject' => $subject,
                        'reason' => $e->getMessage()
                    ];
                    Log::error("❌ DEBUG: Exception: " . $subject . " - " . $e->getMessage());
                }
            }
            
            $nextLink = $data['@odata.nextLink'] ?? null;
            $pageCount++;
            
            if (!$nextLink) {
                Log::info("✅ DEBUG: Reached end of mailbox");
                break;
            }
            
            usleep(200000);
            
        } while ($nextLink);
        
        Log::info("📊 DEBUG SUMMARY:");
        Log::info("   Total successful: " . count($successEmails));
        Log::info("   Total skipped (existing): " . count($skippedEmails));
        Log::info("   Total failed: " . count($failedEmails));
        
        if (!empty($failedEmails)) {
            Log::info("❌ FAILED EMAILS DETAILS:");
            foreach ($failedEmails as $index => $failed) {
                Log::info("   " . ($index + 1) . ". " . $failed['subject'] . " - Reason: " . $failed['reason']);
            }
        }
        
        return [
            'success' => count($successEmails),
            'skipped' => count($skippedEmails),
            'failed' => count($failedEmails),
            'failed_details' => $failedEmails
        ];
        
    } catch (\Exception $e) {
        Log::error('DEBUG Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return ['error' => $e->getMessage()];
    }
}
/**
 * ✅ DEBUG: Test saving one email and show exact database error
 */
public function debugSaveOne($messageId)
{
    try {
        echo "🔍 Testing email: " . $messageId . "\n";
        
        // Check if already exists
        $exists = IncomingEmail::where('message_id', $messageId)->exists();
        if ($exists) {
            echo "⚠️ Email already exists in database!\n";
            $existing = IncomingEmail::where('message_id', $messageId)->first();
            echo "   Existing ID: " . $existing->id . "\n";
            echo "   Subject: " . $existing->subject . "\n";
            return;
        }
        
        $fullMessage = $this->fetchFullMessage($messageId);
        if (!$fullMessage) {
            echo "❌ Could not fetch message\n";
            return;
        }
        
        echo "✅ Subject: " . ($fullMessage['subject'] ?? 'No Subject') . "\n";
        echo "📝 Attempting to save...\n";
        
        // Try minimal data
        $emailData = [
            'message_id' => $fullMessage['id'],
            'from_email' => $fullMessage['from']['emailAddress']['address'] ?? 'unknown@example.com',
            'from_name' => $fullMessage['from']['emailAddress']['name'] ?? 'Unknown',
            'subject' => $fullMessage['subject'] ?? 'No Subject',
            'body' => $fullMessage['body']['content'] ?? $fullMessage['bodyPreview'] ?? '',
            'body_preview' => substr(($fullMessage['bodyPreview'] ?? ''), 0, 500),
            'received_at' => Carbon::parse($fullMessage['receivedDateTime']),
            'tour_ref' => 'NA',
            'invoice_number' => 'NA',
            'read_status' => isset($fullMessage['isRead']) ? ($fullMessage['isRead'] ? 'read' : 'unread') : 'unread',
            'processing_status' => 'processed',
            'is_tour_confirmation' => false,
            'has_attachments' => $fullMessage['hasAttachments'] ?? false,
        ];
        
        // ✅ Log what we're saving
        Log::info("DEBUG SAVE - Data: " . json_encode($emailData, JSON_PARTIAL_OUTPUT_ON_ERROR));
        
        $email = IncomingEmail::create($emailData);
        echo "✅ SUCCESS! Saved with ID: " . $email->id . "\n";
        
    } catch (\Illuminate\Database\QueryException $qe) {
        echo "❌ DATABASE ERROR: " . $qe->getMessage() . "\n";
        echo "   SQL: " . $qe->getSql() . "\n";
        echo "   Bindings: " . json_encode($qe->getBindings()) . "\n";
    } catch (\Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
        echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}
    /**
     * Convert HTML to plain text while preserving line breaks
     */
    protected function htmlToPlainText($html)
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        $html = preg_replace('/<\/(div|p|tr|li|h[1-6])>/i', "\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/(td|th)>/i', ' ', $html);
        $html = str_replace('</td>', ' ', $html);
        $html = str_replace('</tr>', "\n", $html);
        
        $text = strip_tags($html);
        
        $text = preg_replace('/[\x{2190}-\x{21FF}]/u', '', $text);
        $text = preg_replace('/[\x{1F300}-\x{1F6FF}]/u', '', $text);
        
        $text = preg_replace('/[^\x20-\x7E\x0A\x0D]/u', ' ', $text);
        
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        $text = preg_replace('/[ \t]+/', ' ', $text);
        
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, function($line) {
            return $line !== '' && !preg_match('/^[_\-\s]+$/', $line) && strlen($line) > 2;
        });
        
        $result = implode("\n", $lines);
        
        Log::info("Cleaned text preview: " . substr($result, 0, 1000));
        
        return $result;
    }
    
    /**
     * COMPREHENSIVE Extract Travel Dates - THIS IS THE FULL VERSION FROM YOUR OLD CODE
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
        
        Log::info("Searching for travel dates in text length: " . strlen($searchText));
        
        // ========== FORMAT A: Arrival Date + Departure Date ==========
        if (!$travelStart) {
            if (preg_match('/Arrival\s*Date[:\s|]*([A-Za-z]+\s+\d{1,2},?\s*\d{4}|\d{4}[-\/]\d{1,2}[-\/]\d{1,2}|\d{1,2}\s*[-–]\s*[A-Za-z]+)/i', $searchText, $match)) {
                try {
                    $dateStr = trim($match[1]);
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
        
        // ========== FORMAT B: Early check-in Table ==========
        if (!$travelStart || !$travelEnd) {
            if (preg_match('/Early check-in Date.*?Departure Date.*?(\d{1,2})\s+([A-Za-z]+)[,\s]*(\d{4}).*?(\d{1,2})\s+([A-Za-z]+)[,\s]*(\d{4})/is', $searchText, $match)) {
                try {
                    $travelStart = Carbon::parse("{$match[2]} {$match[1]}, {$match[3]}")->format('Y-m-d');
                    $travelEnd = Carbon::parse("{$match[5]} {$match[4]}, {$match[6]}")->format('Y-m-d');
                    Log::info("Format B - Early check-in table: {$travelStart} to {$travelEnd}");
                } catch (\Exception $e) {}
            }
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
        if (!$travelStart || !$travelEnd) {
            if (preg_match('/Travel Date[:\s]*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})\s*[-–to]+\s*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/i', $searchText, $match)) {
                try {
                    $travelStart = Carbon::parse(trim($match[1]))->format('Y-m-d');
                    $travelEnd = Carbon::parse(trim($match[2]))->format('Y-m-d');
                    Log::info("Format C - Travel Date range: {$travelStart} to {$travelEnd}");
                } catch (\Exception $e) {}
            }
            elseif (preg_match('/Travel Date[:\s]*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/i', $searchText, $match)) {
                try {
                    $travelStart = Carbon::parse(trim($match[1]))->format('Y-m-d');
                    $pos = strpos($searchText, $match[0]) + strlen($match[0]);
                    if (preg_match('/(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/', substr($searchText, $pos), $endMatch)) {
                        $travelEnd = Carbon::parse(trim($endMatch[1]))->format('Y-m-d');
                        Log::info("Format C - Travel Date with next line: {$travelStart} to {$travelEnd}");
                    }
                } catch (\Exception $e) {}
            }
        }
        
        // ========== FORMAT D: Check In/Check Out ==========
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
        
        // ========== FORMAT E: Date range in itinerary ==========
        if (!$travelStart || !$travelEnd) {
            $dateRanges = [];
            
            if (preg_match_all('/([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})\s*[-–]+\s*([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})/i', $searchText, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    try {
                        $start = Carbon::parse("{$match[1]} {$match[2]}, {$match[3]}")->format('Y-m-d');
                        $end = Carbon::parse("{$match[4]} {$match[5]}, {$match[6]}")->format('Y-m-d');
                        $dateRanges[] = ['start' => $start, 'end' => $end];
                    } catch (\Exception $e) {}
                }
            }
            
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
        
        // ========== FORMAT F: Arrival Date + Nights ==========
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
        $text = preg_replace('/[^\x20-\x7E\x0A\x0D]/u', ' ', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        
        $lines = explode("\n", $text);
        $lines = array_filter($lines, function($line) {
            return trim($line) !== '';
        });
        
        return implode("\n", $lines);
    }
    
protected function extractField($text, $fieldName)
{
    $tourConfirmationSection = '';
    if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
        $tourConfirmationSection = $sectionMatch[1];
        Log::info("Found TOUR CONFIRMATION section for {$fieldName}");
    }
    
    $searchText = !empty($tourConfirmationSection) ? $tourConfirmationSection : $text;
    $searchText = preg_replace('/[^\x20-\x7E\x0A\x0D]/u', ' ', $searchText);
    
    // ✅ PATTERN A: Match lines that start with the field name (BEST FOR TABLE FORMAT)
    // Example: "| Agent    | 30 SUNDAYS    |"
    $pattern = '/[|]\s*' . preg_quote($fieldName, '/') . '\s*[|]\s*([^|]+?)\s*[|]/im';
    if (preg_match($pattern, $searchText, $match)) {
        $value = trim($match[1]);
        if (!empty($value) && strlen($value) < 200 && 
            !preg_match('/^(Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan|Chauffeur|Emergency|Customer Support|Sales Person)/i', $value)) {
            Log::info("✓ Extracted {$fieldName} (table format): {$value}");
            return $value;
        }
    }
    
    // ✅ PATTERN B: Match lines that start with the field name (original working code)
    $pattern2 = '/^' . preg_quote($fieldName, '/') . '\s*:?\s*(.+)$/im';
    if (preg_match($pattern2, $searchText, $match)) {
        $value = trim($match[1]);
        if (!empty($value) && strlen($value) < 200 && 
            !preg_match('/^(Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan|Chauffeur)/i', $value)) {
            Log::info("✓ Extracted {$fieldName} (line start): {$value}");
            return $value;
        }
    }
    
    // ✅ PATTERN C: Field Name followed by newline then value
    $pattern3 = '/' . preg_quote($fieldName, '/') . '\s*\n\s*([^\n]+)/i';
    if (preg_match($pattern3, $searchText, $match)) {
        $value = trim($match[1]);
        $value = preg_replace('/\s+/', ' ', $value);
        if (!empty($value) && strlen($value) < 200 && 
            !preg_match('/^(Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan)/i', $value)) {
            Log::info("✓ Extracted {$fieldName} (newline): {$value}");
            return $value;
        }
    }
    
    // ✅ PATTERN D: Field Name followed by spaces then value
    $pattern4 = '/' . preg_quote($fieldName, '/') . '\s*:?\s*([^\n]+)/i';
    if (preg_match($pattern4, $searchText, $match)) {
        $value = trim($match[1]);
        $value = preg_replace('/\s+/', ' ', $value);
        if (!empty($value) && strlen($value) < 200 && 
            !preg_match('/^(Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan)/i', $value)) {
            Log::info("✓ Extracted {$fieldName} (spaces): {$value}");
            return $value;
        }
    }
    
    // ✅ PATTERN E: Check next line after the field name
    $lines = explode("\n", $searchText);
    foreach ($lines as $i => $line) {
        if (preg_match('/' . preg_quote($fieldName, '/') . '/i', $line)) {
            if (isset($lines[$i + 1])) {
                $value = trim($lines[$i + 1]);
                if (!empty($value) && 
                    !preg_match('/^(Emergency contact|Customer Support|Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan)/i', $value)) {
                    Log::info("✓ Extracted {$fieldName} (next line): {$value}");
                    return $value;
                }
            }
            $value = preg_replace('/' . preg_quote($fieldName, '/') . '\s*/i', '', $line);
            $value = trim($value);
            if (!empty($value) && strlen($value) < 200 && 
                !preg_match('/^(Tour Ref|Flight|Agent|Guests Name|IS Number|No\. of Guests|Meal Plan)/i', $value)) {
                Log::info("✓ Extracted {$fieldName} (same line): {$value}");
                return $value;
            }
        }
    }
    
    Log::info("✗ Could not extract {$fieldName} from text");
    return null;
}
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
                $value = preg_replace('/\s+/u', '', $value);
                Log::info("Extracted Invoice/Tour Ref: {$value}");
                return $value;
            }
        }

        return null;
    }
    
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
    
    protected function saveAttachments($attachments, $email)
    {
        foreach ($attachments as $attachment) {
            try {
                $filename = $attachment['name'] ?? 'attachment_' . uniqid();
                $content = null;
                
                if (isset($attachment['contentBytes'])) {
                    $content = base64_decode($attachment['contentBytes']);
                } elseif (isset($attachment['contentUrl'])) {
                    $response = Http::withToken($this->accessToken)
                        ->timeout(30)
                        ->get($attachment['contentUrl']);
                    
                    if ($response->ok()) {
                        $content = $response->body();
                    }
                } else {
                    continue;
                }
                
                if (!empty($content)) {
                    $path = "email_attachments/{$email->id}/" . $filename;
                    Storage::disk('public')->put($path, $content);
                    
                    EmailAttachment::create([
                        'email_id' => $email->id,
                        'filename' => $filename,
                        'file_path' => $path,
                        'mime_type' => $attachment['contentType'] ?? 'application/octet-stream',
                        'file_size' => strlen($content)
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning("Failed to save attachment: " . ($filename ?? 'unknown') . " - " . $e->getMessage());
            }
        }
    }
    
    protected function extractAgentReferenceNo($text)
    {
        $tourSection = '';
        if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
            $tourSection = $sectionMatch[1];
        }
        $searchText = !empty($tourSection) ? $tourSection : $text;
        
        if (preg_match('/Booking\s+ID\s*[:\s]*(NL\d+)/i', $searchText, $match)) {
            $value = trim($match[1]);
            Log::info("✓ Extracted Agent Reference (Booking ID): {$value}");
            return $value;
        }
        
        if (preg_match('/Reference\s+No\.?\s*[:\s]*([A-Z0-9]+(?:CNTL)?)/i', $searchText, $match)) {
            $value = trim($match[1]);
            if (!preg_match('/CNTL$/i', $value)) {
                Log::info("✓ Extracted Agent Reference (Reference No): {$value}");
                return $value;
            }
        }
        
        if (preg_match('/\b(ORN\d+)\b/i', $searchText, $match)) {
            $value = trim($match[1]);
            Log::info("✓ Extracted Agent Reference (ORN): {$value}");
            return $value;
        }
        
        if (preg_match('/\b(NL\d{10,})\b/i', $searchText, $match)) {
            $value = trim($match[1]);
            Log::info("✓ Extracted Agent Reference (NL format): {$value}");
            return $value;
        }
        
        Log::info("✗ No Agent Reference Number found");
        return null;
    }
    
    protected function extractTourReference($text)
    {
        $tourSection = '';
        if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
            $tourSection = $sectionMatch[1];
        }
        $searchText = !empty($tourSection) ? $tourSection : $text;
        
        if (preg_match('/Tour\s+Ref\s*[:\s]*([A-Z0-9]+CNTL)/i', $searchText, $match)) {
            $value = trim($match[1]);
            Log::info("✓ Extracted Tour Ref (CNTL): {$value}");
            return $value;
        }
        
        if (preg_match('/Tour\s+Ref\s*[:\s]*([A-Z0-9]{6,})/i', $searchText, $match)) {
            $value = trim($match[1]);
            Log::info("✓ Extracted Tour Ref: {$value}");
            return $value;
        }
        
        if (preg_match('/\b(\d{6,}CNTL)\b/i', $searchText, $match)) {
            $value = trim($match[1]);
            Log::info("✓ Extracted CNTL pattern: {$value}");
            return $value;
        }
        
        Log::info("✗ No Tour Ref found - will set to NA");
        return null;
    }
    
    protected function extractInvoiceNumber($text)
    {
        $tourSection = '';
        if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
            $tourSection = $sectionMatch[1];
        }
        $searchText = !empty($tourSection) ? $tourSection : $text;
        
        // IS Number field with space
        if (preg_match('/IS\s+Number\s*[:\s]*([A-Z]{2,3})\s+(\d+)/i', $searchText, $match)) {
            $value = strtoupper(trim($match[1] . $match[2]));
            Log::info("✓ Extracted Invoice Number from IS Number: {$value}");
            return $value;
        }
        
        if (preg_match('/IS\s+Number\s*[:\s]*([A-Z]{2,3}\d+)/i', $searchText, $match)) {
            $value = strtoupper(trim($match[1]));
            Log::info("✓ Extracted Invoice Number from IS Number: {$value}");
            return $value;
        }
        
        // Confirmation Number
        if (preg_match('/Confirmation\s+Number\s*[:\s]*([A-Z]{2,3})\s+(\d+)/i', $searchText, $match)) {
            $value = strtoupper(trim($match[1] . $match[2]));
            Log::info("✓ Extracted Invoice Number from Confirmation Number: {$value}");
            return $value;
        }
        
        if (preg_match('/Confirmation\s+Number\s*[:\s]*([A-Z]{2,3}\d+)/i', $searchText, $match)) {
            $value = strtoupper(trim($match[1]));
            Log::info("✓ Extracted Invoice Number from Confirmation Number: {$value}");
            return $value;
        }
        
        // Invoice No
        if (preg_match('/Invoice\s+No\.?\s*[:\s]*([A-Z]{2,3})\s+(\d+)/i', $searchText, $match)) {
            $value = strtoupper(trim($match[1] . $match[2]));
            Log::info("✓ Extracted Invoice Number from Invoice No: {$value}");
            return $value;
        }
        
        if (preg_match('/Invoice\s+No\.?\s*[:\s]*([A-Z]{2,3}\d+)/i', $searchText, $match)) {
            $value = strtoupper(trim($match[1]));
            Log::info("✓ Extracted Invoice Number from Invoice No: {$value}");
            return $value;
        }
        
        // Patterns with space
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
                $value = strtoupper(trim($match[1] . $match[2]));
                Log::info("✓ Extracted Invoice Number from pattern with space: {$value}");
                return $value;
            }
        }
        
        // Patterns without space
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
    
    protected function extractPassengerNames($text)
    {
        $passengers = [];
        
        $tourSection = '';
        if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
            $tourSection = $sectionMatch[1];
        }
        $searchText = !empty($tourSection) ? $tourSection : $text;
        
        // Passenger Details section
        if (preg_match('/Passenger Details(.*?)(?:City|Hotel|Total Tour Cost|$)/is', $searchText, $sectionMatch)) {
            $passengerSection = $sectionMatch[1];
            Log::info("Found Passenger Details section");
            
            if (preg_match_all('/([A-Za-z\s]+)\s+(Adult|Child)\s+(\d+)/i', $passengerSection, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $name = trim(preg_replace('/\s+/', ' ', $match[1]));
                    if (!empty($name) && strlen($name) > 2 && !in_array($name, $passengers)) {
                        $passengers[] = $name;
                    }
                }
            }
            
            if (preg_match('/Lead Passenger Name[:\s]*([^\n]+)/i', $passengerSection, $match)) {
                $leadName = trim($match[1]);
                if (!empty($leadName) && !in_array($leadName, $passengers)) {
                    array_unshift($passengers, $leadName);
                }
            }
        }
        
        // Guests Name format
        if (empty($passengers)) {
            if (preg_match('/Guests Name\s*&?\s*Contact\s*details\s*[:\s]*([^\n]+)/i', $searchText, $match)) {
                $guestLine = trim($match[1]);
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
            
            if (empty($passengers)) {
                $guestName = $this->extractField($searchText, 'Guests Name');
                if ($guestName && $guestName != 'NA') {
                    $guestName = preg_replace('/\s*\(?\+?\d+[\d\s\-]+\)?/', '', $guestName);
                    $guestName = trim($guestName);
                    if (!empty($guestName)) {
                        $passengers[] = $guestName;
                    }
                }
            }
        }
        
        if (empty($passengers)) {
            if (preg_match('/Guest\s+Name[:\s]*([^\n]+)/i', $searchText, $match)) {
                $name = trim($match[1]);
                if (!empty($name) && $name != 'NA') {
                    $passengers[] = $name;
                }
            }
        }
        
        $passengers = array_filter(array_unique($passengers));
        $passengers = array_map(function($name) {
            $name = preg_replace('/\s*\(?\+?\d+[\d\s\-\(\)]+\)?/', '', $name);
            $name = preg_replace('/\s+/', ' ', $name);
            return trim($name);
        }, $passengers);
        
        $result = !empty($passengers) ? implode(', ', $passengers) : null;
        Log::info("Extracted Passengers: " . ($result ?: 'None'));
        
        return $result ? [$result] : [];
    }
    
    protected function cleanInvoiceNumber($invoiceNumber)
    {
        if (!$invoiceNumber) {
            return null;
        }
        
        $cleaned = str_replace(' ', '', $invoiceNumber);
        $cleaned = strtoupper($cleaned);
        $cleaned = preg_replace('/[^A-Z0-9]/', '', $cleaned);
        
        Log::info("Cleaned Invoice Number: '{$invoiceNumber}' -> '{$cleaned}'");
        
        return $cleaned;
    }
    
    protected function getTourSection($text)
    {
        if (preg_match('/TOUR CONFIRMATION(.*?)(?:With appreciation|From:|$)/is', $text, $sectionMatch)) {
            return $sectionMatch[1];
        }
        return $text;
    }
}