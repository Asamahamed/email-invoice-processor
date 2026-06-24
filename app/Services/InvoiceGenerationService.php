<?php

namespace App\Services;

use App\Models\GeneratedInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Models\AgentGst;
use App\Mail\InvoiceMail;
use Illuminate\Support\Facades\Mail;
use App\Services\AgentClassificationService;

class InvoiceGenerationService
{
    private $revisionCounters = [];

public function generateFromEmail($email, $classification = null, $revisionNumber = null)
{
    if (!$classification) {
        $agentClassifier = new AgentClassificationService();
        $classification = $agentClassifier->classify(
            $email->body ?? '', 
            $email->from_email ?? '', 
            $email->subject ?? '', 
            $email->agent_name
        );
    }

    $gstNumber = null;
    if ($email->agent_name) {
        $agentName = trim($email->agent_name);
        
        $agentGst = AgentGst::whereRaw('LOWER(agent_name) = LOWER(?)', [$agentName])->first();
        
        if (!$agentGst) {
            $searchName = preg_replace('/\s+(AGENT|TRAVEL|TOURS|PVT|LTD|PRIVATE|LIMITED|LLP|HOLIDAYS|INTERNATIONAL|SOLUTIONS)/i', '', $agentName);
            $searchName = trim($searchName);
            $agentGst = AgentGst::whereRaw('LOWER(agent_name) LIKE ?', ['%' . strtolower($searchName) . '%'])->first();
        }
        
        if ($agentGst) {
            $gstNumber = $agentGst->gst_number;
        }
    }

    $salesPerson = $email->sales_person ?? null;

    // ✅ Get base invoice number
    $baseInvoiceNumber = $email->invoice_number;
    $cleanBase = $this->cleanBaseNumber($baseInvoiceNumber);
    
    // ✅ Check if ANY invoice with this base number exists
    $existingInvoices = GeneratedInvoice::where('original_invoice_number', $cleanBase)
        ->orWhere('invoice_number', 'LIKE', $cleanBase . '%')
        ->orderBy('id', 'asc')
        ->get();
    
    $totalRevisions = $existingInvoices->count();
    
// ✅ Determine the invoice number
if ($totalRevisions == 0) {
    $displayInvoiceNumber = $cleanBase;
    $fileInvoiceNumber = $cleanBase;
    $currentRevision = 0;
    $totalRevisionsCount = 0;
    $isRevision = false;
    $newTotal = 0;
} else {
    $existingForEmail = $existingInvoices->where('email_id', $email->id)->first();
    
    if ($existingForEmail) {
        Log::info("⏭️ Invoice {$cleanBase} already exists for this email. Returning existing.");
        return $existingForEmail;
    }
    
    $isRevision = true;
    $newTotal = $totalRevisions + 1;
    $currentRevision = $newTotal;
    
    // ✅ DISPLAY format: IS48329_R2/R2 (for UI)
    $displayInvoiceNumber = $cleanBase . '_R' . $currentRevision . '/R' . $newTotal;
    
    // ✅ FILE format: IS48329_R2_R2 (for filename - NO slashes)
    $fileInvoiceNumber = $cleanBase . '_R' . $currentRevision . '_R' . $newTotal;
    
    $this->updatePreviousRevisions($cleanBase, $newTotal);
    
    Log::info("📄 Revision detected: New total = {$newTotal}, Current = {$currentRevision}");
    Log::info("📄 Display: {$displayInvoiceNumber}, File: {$fileInvoiceNumber}");
}
    
    // Create directory
    $directory = storage_path('app/public/invoices');
    if (!File::exists($directory)) {
        File::makeDirectory($directory, 0755, true);
    }
    
    // ✅ STEP 1: Get HTML body and plain text
    $htmlBody = $email->body ?? '';
    $plainText = $this->htmlToPlainText($htmlBody);
    
    // ✅ STEP 2: Get the currency and amount from email
    $currency = $email->currency ?? 'USD';
    $originalAmount = $email->total_amount ?? 0;
    
    Log::info("💰 Original Amount: {$originalAmount} {$currency}");
    
    // ✅ STEP 3: Get the correct exchange rate based on currency
    $exchangeService = new ExchangeRateService();
    $exchangeRate = null;
    
    switch ($currency) {
        case 'MYR':
            $exchangeRate = $exchangeService->getMyrToInrRate(); // 22.82 + 1 = 23.82
            Log::info("🇲🇾 Using MYR to INR rate: {$exchangeRate}");
            break;
        case 'SGD':
            $exchangeRate = $exchangeService->getSgdToInrRate(); // 62.00 + 1 = 63.00
            Log::info("🇸🇬 Using SGD to INR rate: {$exchangeRate}");
            break;
        case 'USD':
        default:
            $exchangeRate = $exchangeService->getUsdToInrRate(); // 83.50 + 1 = 84.50
            Log::info("🇺🇸 Using USD to INR rate: {$exchangeRate}");
            break;
    }
    
    $totalGuests = (int)($email->number_of_guests ?? $email->pax_count ?? 1);
    if ($totalGuests < 1) {
        $totalGuests = 1;
    }
    
    // ✅ STEP 4: Calculate correctly
    $hasHandlingFee = $classification['has_handling_fee'] ?? false;
    $invoiceFormat = $classification['invoice_format'] ?? 'apple_holidays';
    
    if (!$hasHandlingFee) {
        // ✅ NO Handling Fee - Direct conversion
        $totalAmountINR = $originalAmount * $exchangeRate;
        $handlingFee = 0;
        $grandTotal = $totalAmountINR;
        $currency = 'INR';
        $calculations = null;
        
        Log::info("✅ No handling fee: {$originalAmount} {$email->currency} × {$exchangeRate} = {$totalAmountINR} INR");
        
    } else {
    // ✅ WITH Handling Fee - CORRECTED CALCULATION
    
    // ✅ Handling fee is ALWAYS 5 in the email's currency
    // For USD: $5 per person
    // For MYR: RM 5 per person
    // For SGD: SGD 5 per person
    $handlingFeePerPersonOriginalCurrency = 5;
    
    Log::info("💵 Handling fee per person in {$currency}: {$handlingFeePerPersonOriginalCurrency}");
    
    // ✅ Per person in original currency
    $perPersonOriginal = $originalAmount / $totalGuests;
    
    // ✅ Unit Fare / Cost Per Person = Per Person - Handling Fee
    $unitFareOriginal = $perPersonOriginal - $handlingFeePerPersonOriginalCurrency;
    
    // ✅ Convert to INR
    $perPersonINR = $perPersonOriginal * $exchangeRate;
    $unitFareINR = $unitFareOriginal * $exchangeRate;
    $totalAmountINR = $originalAmount * $exchangeRate;
    $handlingFeePerPersonINR = $handlingFeePerPersonOriginalCurrency * $exchangeRate;
    $totalHandlingFeeINR = $handlingFeePerPersonINR * $totalGuests;
    
    // ✅ Sub total (Tour cost + Handling fee) - same as original total in INR
    $subTotalINR = $totalAmountINR;
    
    // ✅ GST on handling fee only
    $cgstPercent = $email->cgst_percent ?? 9;
    $sgstPercent = $email->sgst_percent ?? 9;
    $cgst = $totalHandlingFeeINR * ($cgstPercent / 100);
    $sgst = $totalHandlingFeeINR * ($sgstPercent / 100);
    
    // ✅ Grand Total = Tour Cost + GST on Handling Fee
    $grandTotal = $totalAmountINR + $cgst + $sgst;
    $handlingFee = $totalHandlingFeeINR;
    $currency = 'INR';
    
    $calculations = [
        'original_amount' => $originalAmount,
        'original_currency' => $email->currency,
        'total_guests' => $totalGuests,
        'per_person_original' => $perPersonOriginal,
        'per_person_inr' => $perPersonINR,
        'handling_fee_per_person_original' => $handlingFeePerPersonOriginalCurrency,
        'handling_fee_per_person_inr' => $handlingFeePerPersonINR,
        'total_handling_fee_inr' => $totalHandlingFeeINR,
        'unit_fare_original' => $unitFareOriginal,
        'unit_fare_inr' => $unitFareINR,
        'exchange_rate' => $exchangeRate,
        'total_amount_inr' => $totalAmountINR,
        'sub_total_inr' => $subTotalINR,
        'cgst_percent' => $cgstPercent,
        'cgst_amount' => $cgst,
        'sgst_percent' => $sgstPercent,
        'sgst_amount' => $sgst,
        'final_total_inr' => $grandTotal,
        'gst_number' => $gstNumber,
        'sales_person' => $salesPerson,
        'detected_currency' => $email->currency,
    ];
    
    Log::info("✅ With handling fee:");
    Log::info("   Original: {$originalAmount} {$email->currency}");
    Log::info("   Per person: {$perPersonOriginal} {$email->currency}");
    Log::info("   Handling fee per person: {$handlingFeePerPersonOriginalCurrency} {$email->currency}");
    Log::info("   Unit Fare (Per Person - Fee): {$unitFareOriginal} {$email->currency}");
    Log::info("   Exchange rate: {$exchangeRate}");
    Log::info("   Total INR: {$totalAmountINR}");
    Log::info("   Handling fee INR: {$totalHandlingFeeINR}");
    Log::info("   CGST: {$cgst}");
    Log::info("   SGST: {$sgst}");
    Log::info("   Grand Total: {$grandTotal}");
}
    
    // ✅ Create invoice record
    $invoice = GeneratedInvoice::create([
        'email_id' => $email->id,
        'invoice_number' => $displayInvoiceNumber,
        'invoice_date' => now()->format('Y-m-d'),
        'customer_name' => $email->agent_name ?? ($email->guest_name ?? 'Unknown Customer'),
        'guest_name' => $email->guest_name,
        'tour_ref' => $email->tour_ref,
        'total_amount' => $originalAmount,
        'handling_fee' => $handlingFee ?? 0,
        'grand_total' => $grandTotal,
        'currency' => $currency,
        'invoice_type' => $classification['credit_type'],
        'status' => 'draft',
        'file_path' => null,
        'calculations' => $calculations ? json_encode($calculations) : null,
        'revision_number' => $currentRevision,
        'total_revisions' => $newTotal,
        'is_revision' => $isRevision,
        'original_invoice_number' => $cleanBase,
        'gst_number' => $gstNumber,
        'sales_person' => $salesPerson,
    ]);
    
    // Generate PDF based on invoice format
    if ($invoiceFormat == 'apple_holidays') {
        $html = $this->generateAppleHolidaysInvoiceHTML($invoice, $email);
    } else {
        $html = $this->generateSharmilaInvoiceHTML($invoice, $email, $calculations);
    }
    
    $pdf = Pdf::loadHTML($html);
    $filename = "invoices/{$fileInvoiceNumber}.pdf";
    $pdf->save(storage_path("app/public/{$filename}"));
    
    $invoice->file_path = $filename;
    $invoice->save();
    
    Log::info("✅ Created invoice: {$displayInvoiceNumber}");
    
    return $invoice;
}

/**
 * ✅ Get USD to any currency rate
 * Fixed: Required parameters first, optional after
 */
protected function getUsdToCurrencyRate($toCurrency, $fromCurrency = 'USD')
{
    try {
        if ($toCurrency == 'USD') {
            return 1;
        }
        
        // Try to get from cache first
        $cacheKey = strtolower($fromCurrency) . '_to_' . strtolower($toCurrency) . '_rate';
        $cachedRate = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cachedRate) {
            return $cachedRate;
        }
        
        // Fetch from API
        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->get("https://api.exchangerate-api.com/v4/latest/{$fromCurrency}");
        
        if ($response->successful()) {
            $data = $response->json();
            $rate = $data['rates'][$toCurrency] ?? null;
            if ($rate) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $rate, 3600);
                Log::info("✅ USD to {$toCurrency} rate: {$rate}");
                return $rate;
            }
        }
        
        // Fallback rates
        $fallbackRates = [
            'MYR' => 4.42,  // 1 USD = 4.42 MYR
            'SGD' => 1.34,  // 1 SGD = 1.34 USD
            'INR' => 83.50, // 1 USD = 83.50 INR
        ];
        
        if (isset($fallbackRates[$toCurrency])) {
            Log::warning("⚠️ Using fallback USD to {$toCurrency} rate: {$fallbackRates[$toCurrency]}");
            return $fallbackRates[$toCurrency];
        }
        
        return 1;
        
    } catch (\Exception $e) {
        Log::error("Failed to get USD to {$toCurrency} rate: " . $e->getMessage());
        return 1;
    }
}

   protected function updatePreviousRevisions($baseNumber, $newTotal)
{
    $cleanBase = $this->cleanBaseNumber($baseNumber);
    
    $existingInvoices = GeneratedInvoice::where('original_invoice_number', $cleanBase)
        ->orWhere('invoice_number', 'LIKE', $cleanBase . '%')
        ->where('is_revision', true)
        ->get();
    
    Log::info("🔄 Updating " . $existingInvoices->count() . " previous revisions to total: {$newTotal}");
    
    foreach ($existingInvoices as $inv) {
        // Update the total_revisions field
        $inv->total_revisions = $newTotal;
        
        // ✅ Update invoice number with DISPLAY format: IS48329_R2/R3
        if (preg_match('/_R(\d+)\/R\d+$/', $inv->invoice_number, $matches)) {
            $revNum = $matches[1];
            $newDisplayNumber = $cleanBase . '_R' . $revNum . '/R' . $newTotal;
            $inv->invoice_number = $newDisplayNumber;
            
            Log::info("🔄 Updated display: {$newDisplayNumber} (Total: {$newTotal})");
            
            // ✅✅✅ CRITICAL FIX: Rename the file and update file_path
            $oldFilePath = $inv->file_path;
            if ($oldFilePath) {
                // Convert from old format to new file format
                $newFileNumber = $cleanBase . '_R' . $revNum . '_R' . $newTotal;
                $newFilePath = "invoices/{$newFileNumber}.pdf";
                
                $oldFullPath = storage_path("app/public/{$oldFilePath}");
                $newFullPath = storage_path("app/public/{$newFilePath}");
                
                Log::info("🔄 Renaming file: {$oldFullPath} -> {$newFullPath}");
                
                if (File::exists($oldFullPath)) {
                    // ✅ Rename the file
                    File::move($oldFullPath, $newFullPath);
                    $inv->file_path = $newFilePath;
                    Log::info("✅ File renamed successfully: {$newFilePath}");
                } else {
                    // ✅ If file doesn't exist, just update the path
                    Log::warning("⚠️ File not found: {$oldFullPath}, updating path only");
                    $inv->file_path = $newFilePath;
                }
            }
        }
        
        // ✅✅✅ CRITICAL: SAVE the changes!
        $inv->save();
        Log::info("✅ Saved invoice: {$inv->invoice_number} with file_path: {$inv->file_path}");
    }
}


  protected function cleanBaseNumber($number)
    {
        // Remove _R{num}/R{total} or _R{num}_R{total} or R{num} patterns
        $cleaned = preg_replace('/_R\d+\/R\d+$/', '', $number);
        $cleaned = preg_replace('/_R\d+_R\d+$/', '', $cleaned);
        $cleaned = preg_replace('/R\d+$/', '', $cleaned);
        return $cleaned;
    }

protected function getInvoiceNumberWithRevision($baseNumber, $revisionNumber = null)
{
    // Clean the base number
    $cleanBase = preg_replace('/R\d+$/i', '', $baseNumber);
    
    if ($revisionNumber !== null && $revisionNumber > 0) {
        return $cleanBase . 'R' . $revisionNumber;  // IS43595R1, IS43595R2, etc.
    }
    
    // Check existing revisions
    $existingRevisions = GeneratedInvoice::where('original_invoice_number', $cleanBase)
        ->orWhere('invoice_number', 'LIKE', $cleanBase . 'R%')
        ->count();
    
    if ($existingRevisions > 0) {
        $newRevisionNumber = $existingRevisions + 1;
        return $cleanBase . 'R' . $newRevisionNumber;
    }
    
    return $cleanBase;  // First invoice: IS43595
}
    

/**
 * Get settlement date based on invoice type
 * 
 * For Apple Holidays (Credit USD):
 * - Travel 1st-15th → Settlement 16th of same month
 * - Travel 16th-31st → Settlement 1st of next month
 * 
 * For Sharmila (Non-Credit INR):
 * - 15 days before travel start date
 */
protected function getSettlementDate($travelStartDate, $isUSD = true)
{
    // ✅ For Sharmila (Non-Credit INR) - 15 days before travel
    if (!$isUSD) {
        if (!$travelStartDate) {
            $defaultDate = new \DateTime();
            $defaultDate->modify('+15 days');
            return $defaultDate->format('d/m/Y');
        }
        
        try {
            $travelDate = $this->parseDate($travelStartDate);
            if ($travelDate) {
                // 15 days before travel
                $settlementDate = clone $travelDate;
                $settlementDate->modify('-15 days');
                return $settlementDate->format('d/m/Y');
            }
        } catch (\Exception $e) {
            Log::error("Date parsing error for Sharmila: " . $e->getMessage());
        }
        
        $fallbackDate = new \DateTime();
        $fallbackDate->modify('+15 days');
        return $fallbackDate->format('d/m/Y');
    }
    
    // ✅ For Apple Holidays (Credit USD) - Based on travel date
    if (!$travelStartDate) {
        $defaultDate = new \DateTime();
        $defaultDate->modify('+10 days');
        return $defaultDate->format('d/m/Y');
    }
    
    try {
        $travelDate = $this->parseDate($travelStartDate);
        
        if ($travelDate) {
            $day = (int)$travelDate->format('d');
            $month = (int)$travelDate->format('m');
            $year = (int)$travelDate->format('Y');
            
            // ✅ Rule: 1-15 → 16th same month, 16-31 → 1st next month
            if ($day >= 1 && $day <= 15) {
                $settlementDate = new \DateTime("{$year}-{$month}-16");
                Log::info("📅 Apple Holidays Settlement: Travel on {$day}/{$month} → 16/{$month}");
            } else {
                $nextMonth = $month + 1;
                $nextYear = $year;
                if ($nextMonth > 12) {
                    $nextMonth = 1;
                    $nextYear = $year + 1;
                }
                $settlementDate = new \DateTime("{$nextYear}-{$nextMonth}-01");
                Log::info("📅 Apple Holidays Settlement: Travel on {$day}/{$month} → 01/{$nextMonth}");
            }
            
            return $settlementDate->format('d/m/Y');
        }
        
    } catch (\Exception $e) {
        Log::error("Date parsing error for Apple Holidays: " . $e->getMessage());
    }
    
    // Fallback for Apple Holidays
    $fallbackDate = new \DateTime();
    $fallbackDate->modify('+10 days');
    return $fallbackDate->format('d/m/Y');
}

/**
 * Helper method to parse date in multiple formats
 */
private function parseDate($dateStr)
{
    if (strpos($dateStr, '-') !== false) {
        return new \DateTime($dateStr);
    } elseif (strpos($dateStr, '/') !== false) {
        $date = \DateTime::createFromFormat('d/m/Y', $dateStr);
        if (!$date) {
            $date = \DateTime::createFromFormat('m/d/Y', $dateStr);
        }
        return $date;
    }
    return null;
}
/**
 * Convert HTML to plain text while preserving line breaks
 */
protected function htmlToPlainText($html)
{
    // Decode HTML entities
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
    
    Log::info("Cleaned text preview: " . substr($result, 0, 1000));
    
    return $result;
}
    
    /**
     * Get formatted travel dates for remark
     */
    protected function getTravelDates($email)
    {
        $start = null;
        $end = null;
        
        if ($email->travel_start_date) {
            try {
                $start = date('d/m/Y', strtotime($email->travel_start_date));
            } catch (\Exception $e) {
                $start = $email->travel_start_date;
            }
        }
        
        if ($email->travel_end_date) {
            try {
                $end = date('d/m/Y', strtotime($email->travel_end_date));
            } catch (\Exception $e) {
                $end = $email->travel_end_date;
            }
        }
        
        if ($start && $end) {
            return "{$start} - {$end}";
        } elseif ($start) {
            return $start;
        }
        
        return '';
    }
    
    /**
     * Get formatted address for "To:" section (WITHOUT duplicating as "Address:")
     */
    protected function getFormattedToAddress($agentName)
    {
        $addresses = [
            'MAKE MY TRIP' => "MAKE MY TRIP INDIA PVT LTD\n19th floor, Tower A, B & C Epitome Building No. 5\nDLF Cyber City, Phase - III\nGurgaon 122 002, India",
            'TRIP FACTORY' => "Trip Factory",
            'PICK YOUR TRAIL' => "Pick Your Trail",
            '30 SUNDAYS' => "30 Sundays",
            'I TRIP' => "I TRIP",
            'NEXUS DMC' => "Nexus DMC",
            'RIYA' => "RIYA HOLIDAYS PVT LTD\nG 2 Leesa Business Park, Andheri - Kurla Road\nAndheri East, Mumbai - 400 059\nIndia",
        ];
        
        $upperName = strtoupper($agentName);
        foreach ($addresses as $key => $address) {
            if (strpos($upperName, $key) !== false) {
                return $address;
            }
        }
        
        return $agentName;
    }
    
    /**
     * CREDIT USD - APPLE HOLIDAYS Format (USD) - FIXED ADDRESS DISPLAY
     */
    public function generateAppleHolidaysInvoiceHTML($invoice, $email)
    {
        $agentAddress = $this->getFormattedToAddress($invoice->customer_name);
        $voucherNo = $email->tour_ref ?? 'NL' . rand(1000000000, 9999999999);
        
        $travelDates = $this->getTravelDates($email);
        $settlementDate = $this->getSettlementDate($email->travel_start_date, true);
        
        $staffName = $email->file_handler ?? 'Kevin';
        $fileHandler = $email->file_handler ?? 'Esther';
        $totalAmount = $invoice->total_amount;
        
        $totalGuests = (int)($email->number_of_guests ?? $email->pax_count ?? 1);
        if ($totalGuests < 1) {
            $totalGuests = 1;
        }
        
        $perPersonAmount = $totalAmount / $totalGuests;
        
        $logoPath = public_path('images/credit_img.png');
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $logoData = base64_encode(file_get_contents($logoPath));
            $logoBase64 = 'data:image/png;base64,' . $logoData;
        }
        
        // Show revision note if this is a revised invoice
        $revisionNote = '';
        if ($invoice->is_revision && $invoice->revision_number > 0) {
            $revisionNote = '<div class="revision-note" style="background-color: #fff3cd; padding: 5px 10px; margin-bottom: 10px; border-left: 4px solid #ffc107; font-size: 8pt;">
                <strong>⚠️ REVISED INVOICE - Revision ' . $invoice->revision_number . '</strong><br>
                This is a revised invoice. Please disregard any previous invoices for this booking.
            </div>';
        }
        
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>INVOICE - ' . $invoice->invoice_number . '</title>
            <style>
                @page { margin: 15px; size: A4; }
                body {
                    font-family: "DejaVu Sans", Arial, sans-serif;
                    margin: 0;
                    padding: 0;
                    background: #fff;
                    font-size: 9pt;
                }
                .invoice-container {
                    max-width: 750px;
                    margin: 0 auto;
                    background: white;
                }
                .logo { text-align: center; margin-bottom: 10px; }
                .logo img { max-width: 300px; height: auto; }
                .header-address {
                    text-align: center;
                    margin-bottom: 15px;
                    font-size: 7pt;
                    line-height: 1.3;
                }
                .invoice-title {
                    text-align: center;
                    margin: 10px 0;
                }
                .invoice-title h1 {
                    margin: 0;
                    font-size: 16pt;
                    font-weight: bold;
                }
                .to-section {
                    margin: 10px 0;
                }
                .to-section br {
                    display: block;
                    margin: 2px 0;
                }
                .to-section strong {
                    font-weight: bold;
                }
                .invoice-details {
                    width: 100%;
                    margin: 10px 0;
                    border-collapse: collapse;
                }
                .invoice-details td {
                    padding: 3px 5px;
                    vertical-align: top;
                }
                .invoice-details .label {
                    font-weight: bold;
                    width: 90px;
                }
                .items-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 15px 0;
                }
                .items-table th {
                    background-color: #1a237e;
                    color: white;
                    padding: 6px;
                    text-align: left;
                    border: 1px solid #1a237e;
                    font-size: 8pt;
                }
                .items-table td {
                    padding: 6px;
                    border: 1px solid #ddd;
                    font-size: 8pt;
                }
                .amount { text-align: right; }
                .total-section { margin-top: 10px; margin-bottom: 10px; }
                .total-table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .total-table td {
                    padding: 4px 8px;
                }
                .total-table .label-cell { text-align: left; width: 50%; }
                .total-table .amount-cell { text-align: right; width: 50%; }
                .settlement-text { margin: 10px 0; font-size: 8pt; font-weight: bold; }
                .payment-details {
                    background: #f5f5f5;
                    padding: 10px;
                    margin: 15px 0;
                    font-size: 7pt;
                    line-height: 1.4;
                }
                .payment-details strong { font-size: 8pt; }
                .footer {
                    margin-top: 15px;
                    font-size: 6pt;
                    text-align: center;
                    color: #666;
                    border-top: 1px solid #ddd;
                    padding-top: 6px;
                }
                .staff { margin: 8px 0; font-size: 8pt; }
                .remark {
                    margin: 8px 0;
                    padding: 6px;
                    background: #fff3cd;
                    border-left: 3px solid #ffc107;
                    font-size: 8pt;
                }
            </style>
        </head>
        <body>
            <div class="invoice-container">
                ' . ($logoBase64 ? '<div class="logo"><img src="' . $logoBase64 . '" alt="Apple Holidays Logo"></div>' : '<div class="logo" style="font-size: 18pt; font-weight: bold; color: #1a237e;">Apple Holidays</div>') . '
                
                <div class="header-address">
                    #2207 - #2208, One Galle Face Tower, 1A Center Road, Colombo 02, Sri Lanka<br>
                    Tel: +94-11-2353400 email: accounts@aahaas.com
                </div>
                
                ' . $revisionNote . '
                
                <!-- To Section with address ONLY ONCE -->
                <div class="to-section">
                    <strong>To:</strong> ' . nl2br(htmlspecialchars($agentAddress)) . '
                </div>
                
                <div class="invoice-title">
                    <h1 >INVOICE</h1>
                </div>
                
                <!-- NO separate Address: section here - removed duplicate -->
                
              <table class="invoice-details">
    <tr><td class="label">Invoice No.:</td><td><strong>' . $invoice->invoice_number . '</strong></td>
        <td class="label">Date:</td><td>' . date('d/m/Y', strtotime($invoice->invoice_date)) . '</td>
    </tr>
    <tr><td class="label">Ref ID:</td><td>' . htmlspecialchars($email->tour_ref ?? '-') . '</td>
        <td class="label">Agent ID:</td><td>' . htmlspecialchars($email->reference_no ?? '-') . '</td>
    </tr>
    <tr><td class="label">File Handler:</td><td>' . strtoupper($fileHandler) . '</td>
        <td class="label">Guest Name:</td><td>' . htmlspecialchars($email->guest_name ?? '-') . '</td>
    </tr>
    <!-- NEW ROWS -->
    <tr><td class="label">GST No.:</td><td>' . ($invoice->gst_number ?: 'NA') . '</td>
        <td class="label">Sales Person:</td><td>' . ($invoice->sales_person ?: 'NA') . '</td>
    </tr>
</table>
              <table class="items-table">
    <thead><tr><th>Description</th><th>UNIT FARE</th><th>DISCount %</th><th>Quantity</th><th class="amount">AMOUNT</th></tr></thead>
    <tbody>
        <!-- Cost Per Person row - shows all columns -->
        <tr>
            <td>Cost Per Person</td>
            <td>$' . number_format($perPersonAmount, 2) . '</td>
            <td>0</td>
            <td>' . $totalGuests . '</td>
            <td class="amount">$' . number_format($totalAmount, 2) . '</td>
        </tr>
        <!-- Total Tour cost row - ONLY Description and AMOUNT -->
        <tr>
            <td><strong>Total Tour cost</strong></td>
            <td></td>
            <td></td>
            <td></td>
            <td class="amount"><strong>$' . number_format($totalAmount, 2) . '</strong></td>
        </tr>
    </tbody>
</table>
                
                <div class="total-section">
                    <table class="total-table" style="width:300px; margin-left:auto;">
                        <tr><td class="label-cell"><strong>SUB TOTAL</strong></td><td class="amount-cell">$' . number_format($totalAmount, 2) . '</td></tr>
                        <tr><td class="label-cell"><strong>BANK CHARGES</strong></td><td class="amount-cell">$0.00</td></tr>
                        <tr><td class="label-cell"><strong>TOTAL</strong></td><td class="amount-cell">$' . number_format($totalAmount, 2) . '</td></tr>
                        <tr><td class="label-cell"><strong>AMOUNT RECEIVED</strong></td><td class="amount-cell">$0.00</td></tr>
                        <tr><td class="label-cell"><strong>BALANCE DUE</strong></td><td class="amount-cell">$' . number_format($totalAmount, 2) . '</td></tr>
                    </table>
                </div>
                
                <div class="remark">
                    <strong>Travel Date:</strong> ' . ($travelDates ?: 'No travel dates specified') . '
                </div>
                
                <div class="settlement-text">
                    Please settle the invoice on or before ' . $settlementDate . '
                </div>
                
                <div class="payment-details">
                    <strong>ACCOUNT IN SRI LANKA</strong><br>
                    ACCOUNT NAME: APPLE HOLIDAYS DESTINATION SERVICES (PVT) LTD<br>
                    BANK: COMMERCIAL BANK<br>
                    BRANCH: PETTAH<br>
                    ACCOUNT NO: 1000136027<br>
                    SWIFT CODE: CCEYLKLX<br>
                    Bank Address: Commercial Bank, Peoples Park Shopping Complex, No 180/1/31, Colombo 11, Sri Lanka
                </div>
                
                <div class="staff">Auto Generated<br></div>
                
                <div class="footer">
                  
                    This is a computer generated document no signature is required
                </div>
            </div>
        </body>
        </html>';
    }
public function generateSharmilaInvoiceHTML($invoice, $email, $calculations)
{
    $travelDates = $this->getTravelDates($email);
    $settlementDate = $this->getSettlementDate($email->travel_start_date, false);
    
    $salesId = $email->sales_id ?? 'NA';
    $fileHandler = $email->file_handler ?? 'NA';
    
    // ✅ Define all variables with defaults
    $totalGuests = 1;
    $exchangeRate = 0;
    $costPerPersonINR = 0;
    $handlingFeePerPersonINR = 0;
    $totalTourCost = 0;
    $totalHandlingFee = 0;
    $subTotal = 0;
    $cgstAmount = 0;
    $sgstAmount = 0;
    $grandTotal = 0;
    $amountReceived = 0;
    $balanceDue = 0;
    
    // ✅ FIX: Use correct array keys from calculations
    if ($calculations) {
        $totalGuests = $calculations['total_guests'] ?? 1;
        $exchangeRate = $calculations['exchange_rate'] ?? 0;
        
        // ✅ CORRECT KEYS:
        // Cost Per Person = Unit Fare (after deducting handling fee)
        $costPerPersonINR = $calculations['unit_fare_inr'] ?? 0;  // ← FIXED: Use unit_fare_inr
        $handlingFeePerPersonINR = $calculations['handling_fee_per_person_inr'] ?? 0;
        $totalTourCost = $calculations['total_amount_inr'] ?? 0;
        $totalHandlingFee = $calculations['total_handling_fee_inr'] ?? 0;
        $subTotal = $calculations['sub_total_inr'] ?? 0;
        $cgstAmount = $calculations['cgst_amount'] ?? 0;
        $sgstAmount = $calculations['sgst_amount'] ?? 0;
        $grandTotal = $calculations['final_total_inr'] ?? 0;
    } else {
        // ✅ Fallback values when no handling fee
        $totalGuests = (int)($email->number_of_guests ?? $email->pax_count ?? 1);
        if ($totalGuests < 1) $totalGuests = 1;
        $grandTotal = $invoice->grand_total ?? 0;
    }
    
    $amountReceived = 0;
    $balanceDue = $grandTotal;
    
    $agentName = $email->agent_name ?? $invoice->customer_name ?? 'Unknown Customer';
    $customerAddress = $this->getFormattedToAddress($agentName);
    
    // Revision note for Sharmila invoice
    $revisionNote = '';
    if ($invoice->is_revision && $invoice->revision_number > 0) {
        $revisionNote = '<div class="revision-note" style="background-color: #fff3cd; padding: 5px 10px; margin-bottom: 10px; border-left: 4px solid #ffc107; font-size: 8pt;">
            <strong>⚠️ REVISED INVOICE - Revision ' . $invoice->revision_number . '</strong><br>
            This is a revised invoice. Please disregard any previous invoices for this booking.
        </div>';
    }
    
    // ✅ If no handling fee, show simple invoice
    if (!$calculations) {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>INVOICE - ' . $invoice->invoice_number . '</title>
            <style>
                @page { margin: 12px; size: A4; }
                body {
                    font-family: "DejaVu Sans", Arial, sans-serif;
                    margin: 0;
                    padding: 0;
                    background: #fff;
                    font-size: 9pt;
                }
                .invoice-container { max-width: 100%; margin: 0 auto; background: white; }
                .header {
                    text-align: center;
                    margin-bottom: 10px;
                    padding-bottom: 8px;
                    border-bottom: 1px solid #ddd;
                }
                .company-name { font-size: 14pt; font-weight: bold; color: #1a237e; margin-bottom: 3px; }
                .company-address { font-size: 7pt; color: #333; line-height: 1.3; }
                .company-details { font-size: 7pt; color: #333; margin-top: 3px; }
                .invoice-title { text-align: center; margin: 10px 0 8px 0; }
                .invoice-title span { font-size: 14pt; font-weight: bold; text-decoration: underline; }
                .to-section { margin: 8px 0; line-height: 1.3; }
                .to-section strong { font-weight: bold; }
                .info-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 8px 0;
                    font-size: 8pt;
                }
                .info-table td { padding: 3px 5px; vertical-align: top; }
                .info-label { font-weight: bold; width: 90px; }
                .items-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 10px 0;
                    font-size: 8pt;
                }
                .items-table th {
                    background-color: #1a237e;
                    color: white;
                    padding: 6px 5px;
                    text-align: left;
                    border: 1px solid #1a237e;
                }
                .items-table td { padding: 6px 5px; border: 1px solid #ddd; }
                .amount { text-align: right; }
                .total-section { margin-top: 8px; margin-bottom: 8px; }
                .total-table { width: 100%; border-collapse: collapse; }
                .total-table td { padding: 3px 8px; font-size: 8pt; }
                .total-table .label-cell { text-align: left; }
                .total-table .amount-cell { text-align: right; }
                .settlement-text { margin: 10px 0; font-size: 9pt; font-weight: bold; }
                .payment-details {
                    background: #f5f5f5;
                    padding: 8px 10px;
                    margin: 10px 0;
                    font-size: 7pt;
                    line-height: 1.4;
                }
                .payment-details strong { font-size: 8pt; }
                .footer {
                    margin-top: 10px;
                    font-size: 6pt;
                    text-align: center;
                    color: #666;
                    border-top: 1px solid #ddd;
                    padding-top: 6px;
                }
                .printed-by { margin: 6px 0; font-size: 8pt; }
                .remark {
                    margin: 8px 0;
                    padding: 6px 8px;
                    background: #fff3cd;
                    border-left: 3px solid #ffc107;
                    font-size: 8pt;
                }
            </style>
        </head>
        <body>
            <div class="invoice-container">
                <div class="header">
                    <div class="company-name">SHARMILA TOURS AND TRAVELS</div>
                    <div class="company-address">
                        Shop No : 1st Floor, 10, Venkatraman Road, Kamala Second Street, Chinna Chokkikulam, Madurai - 625002
                    </div>
                    <div class="company-details">
                        Tel : +91 95852 29262 | Email : accounts@aahaas.com<br>
                        Services Tax : ADVF4429D | GSTIN : 33ADVFS4429D1ZV
                    </div>
                </div>
                
                ' . $revisionNote . '
                
                <div class="to-section">
                    <strong>To:</strong> ' . nl2br(htmlspecialchars($customerAddress)) . '
                </div>
                
                <div class="invoice-title">
                    <span>INVOICE</span>
                </div>
                
               <table class="info-table">
                    <tr><td class="info-label">Invoice No.:</td><td><strong>' . $invoice->invoice_number . '</strong></td>
                        <td class="info-label">Ref ID.:</td><td>' . htmlspecialchars($email->tour_ref ?? '-') . '</td>
                    </tr>
                    <tr><td class="info-label">File Handler:</td><td>' . strtoupper($fileHandler) . '</td>
                        <td class="info-label">Sales Person:</td><td>' . strtoupper($invoice->sales_person ?? $salesId) . '</td>
                    </tr>
                    <tr><td class="info-label">Date:</td><td>' . date('d/m/Y', strtotime($invoice->invoice_date)) . '</td>
                        <td class="info-label">Agent ID:</td><td>' . htmlspecialchars($email->reference_no ?? '-') . '</td>
                    </tr>
                    <tr><td class="info-label">GST NO.:</td><td>' . ($invoice->gst_number ?: 'NA') . '</td>
                        <td class="info-label">Guest Name:</td><td>' . htmlspecialchars($email->guest_name ?? '-') . '</td>
                    </tr>
                </table>
                
                <table class="items-table">
                    <thead><tr><th>Description</th><th>Unit Fare</th><th>Discount</th><th>Quantity</th><th class="amount">Amount</th></tr></thead>
                    <tbody>
                        <tr><td>Total Tour Cost</td><td></td><td>0</td><td>' . $totalGuests . '</td><td class="amount">INR ' . number_format($grandTotal, 2) . '</td></tr>
                    </tbody>
                </table>
                
                <div class="total-section">
                    <table class="total-table">
                        <tr style="background-color: #f0f0f0; font-weight: bold;"><td class="label-cell">Total :</td><td class="amount-cell">INR ' . number_format($grandTotal, 2) . '</td></tr>
                        <tr><td class="label-cell">Amount Received :</td><td class="amount-cell">INR ' . number_format($amountReceived, 2) . '</td></tr>
                        <tr style="font-weight: bold;"><td class="label-cell">Balance :</td><td class="amount-cell">INR ' . number_format($balanceDue, 2) . '</td></tr>
                    </table>
                </div>
                
                <div class="remark">
                    <strong>Travel Date:</strong> ' . ($travelDates ?: 'No travel dates specified') . '
                </div>
                
                <div class="settlement-text">
                    Please settle the invoice on or before ' . $settlementDate . '
                </div>
                
                <div class="payment-details">
                    <strong>ACCOUNT DETAILS</strong><br>
                    ACCOUNT NAME: SHARMILA TOURS AND TRAVELS<br>
                    ACCOUNT NO: 056205002744<br>
                    BANK: ICICI BANK LTD<br>
                    BRANCH: TEPPAKULAM, MADURAI BRANCH<br>
                    IFSC CODE: ICIC0000562<br>
                    Bank Address: NO 199, DARSHINI TOWER, VAIGAI COLONY, ANNA NAGAR, 625020
                </div>
                
                <div class="printed-by">Auto Generated</div>
                
               <div style="background: #fff3cd; padding: 6px 10px; margin: 8px 0; border-left: 4px solid #ffc107; font-size: 7pt; line-height: 1.4;">
                    <strong>Note:</strong> Cash deposit should not exceed ₹49,000 per transaction.
                </div>
                
                <div class="footer">
                    This is a computer generated document - no signature required
                </div>
            </div>
        </body>
        </html>';
    }
    
    // ✅ Full version with handling fee - USING CORRECT KEYS
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>INVOICE - ' . $invoice->invoice_number . '</title>
        <style>
            @page { margin: 12px; size: A4; }
            body {
                font-family: "DejaVu Sans", Arial, sans-serif;
                margin: 0;
                padding: 0;
                background: #fff;
                font-size: 9pt;
            }
            .invoice-container { max-width: 100%; margin: 0 auto; background: white; }
            .header {
                text-align: center;
                margin-bottom: 10px;
                padding-bottom: 8px;
                border-bottom: 1px solid #ddd;
            }
            .company-name { font-size: 14pt; font-weight: bold; color: #1a237e; margin-bottom: 3px; }
            .company-address { font-size: 7pt; color: #333; line-height: 1.3; }
            .company-details { font-size: 7pt; color: #333; margin-top: 3px; }
            .invoice-title { text-align: center; margin: 10px 0 8px 0; }
            .invoice-title span { font-size: 14pt; font-weight: bold; text-decoration: underline; }
            .to-section { margin: 8px 0; line-height: 1.3; }
            .to-section strong { font-weight: bold; }
            .info-table {
                width: 100%;
                border-collapse: collapse;
                margin: 8px 0;
                font-size: 8pt;
            }
            .info-table td { padding: 3px 5px; vertical-align: top; }
            .info-label { font-weight: bold; width: 90px; }
            .items-table {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0;
                font-size: 8pt;
            }
            .items-table th {
                background-color: #1a237e;
                color: white;
                padding: 6px 5px;
                text-align: left;
                border: 1px solid #1a237e;
            }
            .items-table td { padding: 6px 5px; border: 1px solid #ddd; }
            .amount { text-align: right; }
            .total-section { margin-top: 8px; margin-bottom: 8px; }
            .total-table { width: 100%; border-collapse: collapse; }
            .total-table td { padding: 3px 8px; font-size: 8pt; }
            .total-table .label-cell { text-align: left; }
            .total-table .amount-cell { text-align: right; }
            .settlement-text { margin: 10px 0; font-size: 9pt; font-weight: bold; }
            .payment-details {
                background: #f5f5f5;
                padding: 8px 10px;
                margin: 10px 0;
                font-size: 7pt;
                line-height: 1.4;
            }
            .payment-details strong { font-size: 8pt; }
            .footer {
                margin-top: 10px;
                font-size: 6pt;
                text-align: center;
                color: #666;
                border-top: 1px solid #ddd;
                padding-top: 6px;
            }
            .printed-by { margin: 6px 0; font-size: 8pt; }
            .remark {
                margin: 8px 0;
                padding: 6px 8px;
                background: #fff3cd;
                border-left: 3px solid #ffc107;
                font-size: 8pt;
            }
        </style>
    </head>
    <body>
        <div class="invoice-container">
            <div class="header">
                <div class="company-name">SHARMILA TOURS AND TRAVELS</div>
                <div class="company-address">
                    Shop No : 1st Floor, 10, Venkatraman Road, Kamala Second Street, Chinna Chokkikulam, Madurai - 625002
                </div>
                <div class="company-details">
                    Tel : +91 95852 29262 | Email : accounts@aahaas.com<br>
                    Services Tax : ADVF4429D | GSTIN : 33ADVFS4429D1ZV
                </div>
            </div>
            
            ' . $revisionNote . '
            
            <div class="to-section">
                <strong>To:</strong> ' . nl2br(htmlspecialchars($customerAddress)) . '
            </div>
            
            <div class="invoice-title">
                <span>INVOICE</span>
            </div>
            
           <table class="info-table">
                <tr><td class="info-label">Invoice No.:</td><td><strong>' . $invoice->invoice_number . '</strong></td>
                    <td class="info-label">Ref ID.:</td><td>' . htmlspecialchars($email->tour_ref ?? '-') . '</td>
                </tr>
                <tr><td class="info-label">File Handler:</td><td>' . strtoupper($fileHandler) . '</td>
                    <td class="info-label">Sales Person:</td><td>' . strtoupper($invoice->sales_person ?? $salesId) . '</td>
                </tr>
                <tr><td class="info-label">Date:</td><td>' . date('d/m/Y', strtotime($invoice->invoice_date)) . '</td>
                    <td class="info-label">Agent ID:</td><td>' . htmlspecialchars($email->reference_no ?? '-') . '</td>
                </tr>
                <tr><td class="info-label">GST NO.:</td><td>' . ($invoice->gst_number ?: 'NA') . '</td>
                    <td class="info-label">Guest Name:</td><td>' . htmlspecialchars($email->guest_name ?? '-') . '</td>
                </tr>
            </table>
            
            <table class="items-table">
                <thead><tr><th>Description</th><th>Unit Fare</th><th>Discount</th><th>Quantity</th><th class="amount">Amount</th></tr></thead>
                <tbody>
                    <!-- ✅ FIXED: Cost Per Person uses unit_fare_inr (after deducting handling fee) -->
                    <!-- Unit Fare = $346.11 - $5 = $341.11 -->
                    <!-- Amount = Unit Fare × Quantity = $341.11 × 2 = $682.22 -->
                    <tr>
                        <td>Cost Per Person</td>
                        <td>INR ' . number_format($costPerPersonINR, 2) . '</td>
                        <td>0</td>
                        <td>' . $totalGuests . '</td>
                        <td class="amount">INR ' . number_format($costPerPersonINR * $totalGuests, 2) . '</td>
                    </tr>
                    <tr>
                        <td>Handling Fee</td>
                        <td>INR ' . number_format($handlingFeePerPersonINR, 2) . '</td>
                        <td>0</td>
                        <td>' . $totalGuests . '</td>
                        <td class="amount">INR ' . number_format($totalHandlingFee, 2) . '</td>
                    </tr>
                </tbody>
            </table>
            
            <div class="total-section">
                <table class="total-table">
                    <tr><td class="label-cell">Sub Total :</td><td class="amount-cell">INR ' . number_format($subTotal, 2) . '</td></tr>
                    <tr><td class="label-cell">CGST 9.00% :</td><td class="amount-cell">INR ' . number_format($cgstAmount, 2) . '</td></tr>
                    <tr><td class="label-cell">SGST 9.00% :</td><td class="amount-cell">INR ' . number_format($sgstAmount, 2) . '</td></tr>
                    <tr style="background-color: #f0f0f0; font-weight: bold;"><td class="label-cell">Total :</td><td class="amount-cell">INR ' . number_format($grandTotal, 2) . '</td></tr>
                    <tr><td class="label-cell">Amount Received :</td><td class="amount-cell">INR ' . number_format($amountReceived, 2) . '</td></tr>
                    <tr style="font-weight: bold;"><td class="label-cell">Balance :</td><td class="amount-cell">INR ' . number_format($balanceDue, 2) . '</td></tr>
                </table>
            </div>
            
            <div class="remark">
                <strong>Travel Date:</strong> ' . ($travelDates ?: 'No travel dates specified') . '
            </div>
            
            <div class="settlement-text">
                Please settle the invoice on or before ' . $settlementDate . '
            </div>
            
            <div class="payment-details">
                <strong>ACCOUNT DETAILS</strong><br>
                ACCOUNT NAME: SHARMILA TOURS AND TRAVELS<br>
                ACCOUNT NO: 056205002744<br>
                BANK: ICICI BANK LTD<br>
                BRANCH: TEPPAKULAM, MADURAI BRANCH<br>
                IFSC CODE: ICIC0000562<br>
                Bank Address: NO 199, DARSHINI TOWER, VAIGAI COLONY, ANNA NAGAR, 625020
            </div>
            
            <div class="printed-by">
                Auto Generated<br>
                Xe: ' . number_format($exchangeRate - 1, 2) . ' (+1) = ' . $exchangeRate . '
            </div>
            
           <div style="background: #fff3cd; padding: 6px 10px; margin: 8px 0; border-left: 4px solid #ffc107; font-size: 7pt; line-height: 1.4;">
                <strong>Note:</strong> Cash deposit should not exceed ₹49,000 per transaction.
            </div>
            
            <div class="footer">
                This is a computer generated document - no signature required
            </div>
        </div>
    </body>
    </html>';
}
    protected function getAgentAddress($agentName)
    {
        return $this->getFormattedToAddress($agentName);
    }
    
    protected function getExchangeRate()
    {
        try {
            $exchangeService = new \App\Services\ExchangeRateService();
            $rate = $exchangeService->getUsdToInrRate();
            \Log::info("Using exchange rate: USD 1 = INR {$rate}");
            return $rate;
        } catch (\Exception $e) {
            \Log::error("Failed to get exchange rate: " . $e->getMessage());
            return 97;
        }
    }
    
    /**
     * Regenerate existing invoice with updated data and revision number
     */
    public function regenerateInvoice($email, $existingInvoice, $revisionNumber = null, $gstNumber = null, $salesPerson = null)
    {
        // Get classification
        $agentClassifier = new AgentClassificationService();
        $classification = $agentClassifier->classify(
            $email->body ?? '', 
            $email->from_email ?? '', 
            $email->subject ?? '', 
            $email->agent_name
        );
        
        // ✅ Determine base number
        $baseNumber = $existingInvoice->original_invoice_number ?? $email->invoice_number;
        $cleanBase = $this->cleanBaseNumber($baseNumber);
        
        // ✅ Get all existing invoices for this base
        $existingInvoices = GeneratedInvoice::where('original_invoice_number', $cleanBase)
            ->orWhere('invoice_number', 'LIKE', $cleanBase . '%')
            ->orderBy('id', 'asc')
            ->get();
        
        $totalCount = $existingInvoices->count();
        $newTotal = $totalCount + 1;
        $newRevisionNumber = $newTotal;
        
        // ✅ DISPLAY format: IS48329_R2/R2
        $displayInvoiceNumber = $cleanBase . '_R' . $newRevisionNumber . '/R' . $newTotal;
        
        // ✅ FILE format: IS48329_R2_R2
        $fileInvoiceNumber = $cleanBase . '_R' . $newRevisionNumber . '_R' . $newTotal;
        
        Log::info("🔄 Generating revision: Display: {$displayInvoiceNumber}, File: {$fileInvoiceNumber}");
        
        // ✅ UPDATE all previous invoices with new total
        $this->updatePreviousRevisions($cleanBase, $newTotal);
        
        // Get dynamic values from email
        $totalUSD = $email->total_amount ?? 0;
        $totalGuests = (int)($email->number_of_guests ?? $email->pax_count ?? 1);
        if ($totalGuests < 1) $totalGuests = 1;
        
        $exchangeRate = $email->exchange_rate ?? $this->getExchangeRate();
        $handlingFeePerPersonUSD = 5;
        $hasHandlingFee = $classification['has_handling_fee'] ?? false;
        $invoiceFormat = $classification['invoice_format'] ?? 'apple_holidays';
        $currency = $classification['currency'] ?? 'USD';
        
        if (!$hasHandlingFee) {
            $handlingFee = 0;
            $grandTotal = $totalUSD;
            $currency = 'USD';
            $calculations = null;
        } else {
            $perPersonUSD = $totalUSD / $totalGuests;
            $netPerPersonUSD = $perPersonUSD - $handlingFeePerPersonUSD;
            $netPerPersonINR = $netPerPersonUSD * $exchangeRate;
            $totalTourCostINR = $netPerPersonINR * $totalGuests;
            $handlingFeePerPersonINR = $handlingFeePerPersonUSD * $exchangeRate;
            $totalHandlingFeeINR = $handlingFeePerPersonINR * $totalGuests;
            $subTotalINR = $totalTourCostINR + $totalHandlingFeeINR;
            
            $cgstPercent = $email->cgst_percent ?? 9;
            $sgstPercent = $email->sgst_percent ?? 9;
            $cgst = $totalHandlingFeeINR * ($cgstPercent / 100);
            $sgst = $totalHandlingFeeINR * ($sgstPercent / 100);
            $finalGrandTotal = $subTotalINR + $cgst + $sgst;
            
            $handlingFee = $totalHandlingFeeINR;
            $grandTotal = $finalGrandTotal;
            $currency = 'INR';
            
            $calculations = [
                'original_usd' => $totalUSD,
                'total_guests' => $totalGuests,
                'per_person_usd' => $perPersonUSD,
                'handling_fee_per_person_usd' => $handlingFeePerPersonUSD,
                'net_per_person_usd' => $netPerPersonUSD,
                'exchange_rate' => $exchangeRate,
                'net_per_person_inr' => $netPerPersonINR,
                'handling_fee_per_person_inr' => $handlingFeePerPersonINR,
                'total_tour_cost_inr' => $totalTourCostINR,
                'total_handling_fee_inr' => $totalHandlingFeeINR,
                'sub_total_inr' => $subTotalINR,
                'cgst_percent' => $cgstPercent,
                'cgst_amount' => $cgst,
                'sgst_percent' => $sgstPercent,
                'sgst_amount' => $sgst,
                'final_total_inr' => $finalGrandTotal,
                'gst_number' => $gstNumber,
                'sales_person' => $salesPerson,
            ];
        }
        
        // ✅ Auto-fetch GST if not provided
        if (!$gstNumber && $email->agent_name) {
            $agentGst = AgentGst::where('agent_name', 'LIKE', '%' . $email->agent_name . '%')->first();
            if ($agentGst) {
                $gstNumber = $agentGst->gst_number;
            }
        }
        
        $salesPerson = $salesPerson ?? $email->sales_person ?? null;
        
        // ✅ CREATE NEW INVOICE RECORD with DISPLAY format
        $newInvoice = GeneratedInvoice::create([
            'email_id' => $email->id,
            'invoice_number' => $displayInvoiceNumber,  // ← IS48329_R2/R2
            'invoice_date' => now()->format('Y-m-d'),
            'customer_name' => $email->agent_name ?? ($email->guest_name ?? 'Unknown Customer'),
            'guest_name' => $email->guest_name,
            'tour_ref' => $email->tour_ref,
            'total_amount' => $totalUSD,
            'handling_fee' => $handlingFee,
            'grand_total' => $grandTotal,
            'currency' => $currency,
            'invoice_type' => $classification['credit_type'],
            'status' => 'draft',
            'file_path' => null,
            'calculations' => $calculations ? json_encode($calculations) : null,
            'revision_number' => $newRevisionNumber,
            'total_revisions' => $newTotal,
            'is_revision' => true,
            'original_invoice_number' => $cleanBase,
            'gst_number' => $gstNumber,
            'sales_person' => $salesPerson,
        ]);
        
        // ✅ Generate PDF with FILE format (underscore)
        if ($invoiceFormat == 'apple_holidays') {
            $html = $this->generateAppleHolidaysInvoiceHTML($newInvoice, $email);
        } else {
            $html = $this->generateSharmilaInvoiceHTML($newInvoice, $email, $calculations);
        }
        
        $pdf = Pdf::loadHTML($html);
        $filename = "invoices/{$fileInvoiceNumber}.pdf";
        $pdf->save(storage_path("app/public/{$filename}"));
        
        $newInvoice->file_path = $filename;
        $newInvoice->save();
        
        Log::info("✅ Created revision invoice: {$displayInvoiceNumber}");
          $this->sendInvoiceEmail($newInvoice);
        return $newInvoice;
    }

    /**
 * Generate a NEW revision invoice (creates new record, doesn't update existing)
 */
  public function generateRevisionFromEmail($email, $classification, $newInvoiceNumber, $revisionNumber, $originalBaseNumber)
    {
        // ✅ First, update previous revisions with new total
        $cleanBase = $this->cleanBaseNumber($originalBaseNumber);
        
        // Get all existing invoices for this base
        $existingInvoices = GeneratedInvoice::where('original_invoice_number', $cleanBase)
            ->orWhere('invoice_number', 'LIKE', $cleanBase . '%')
            ->orderBy('id', 'asc')
            ->get();
        
        $totalCount = $existingInvoices->count();
        $newTotal = $totalCount + 1;
        
        // ✅ Update previous revisions
        $this->updatePreviousRevisions($cleanBase, $newTotal);
        
        // Create directory
        $directory = storage_path('app/public/invoices');
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
        
        // Get dynamic values from email
        $totalUSD = $email->total_amount ?? 0;
        $totalGuests = (int)($email->number_of_guests ?? $email->pax_count ?? 1);
        if ($totalGuests < 1) {
            $totalGuests = 1;
        }
        
        $exchangeRate = $email->exchange_rate ?? $this->getExchangeRate();
        $handlingFeePerPersonUSD = 5;
        
        $hasHandlingFee = $classification['has_handling_fee'] ?? false;
        $invoiceFormat = $classification['invoice_format'] ?? 'apple_holidays';
        $currency = $classification['currency'] ?? 'USD';
        
        // Auto-fetch GST from agent
        $gstNumber = null;
        if ($email->agent_name) {
            $agentGst = AgentGst::where('agent_name', 'LIKE', '%' . $email->agent_name . '%')->first();
            if ($agentGst) {
                $gstNumber = $agentGst->gst_number;
            }
        }
        
        $salesPerson = $email->sales_person ?? null;
        
        if (!$hasHandlingFee) {
            $handlingFee = 0;
            $grandTotal = $totalUSD;
            $currency = 'USD';
            $calculations = null;
        } else {
            $perPersonUSD = $totalUSD / $totalGuests;
            $netPerPersonUSD = $perPersonUSD - $handlingFeePerPersonUSD;
            $netPerPersonINR = $netPerPersonUSD * $exchangeRate;
            $totalTourCostINR = $netPerPersonINR * $totalGuests;
            $handlingFeePerPersonINR = $handlingFeePerPersonUSD * $exchangeRate;
            $totalHandlingFeeINR = $handlingFeePerPersonINR * $totalGuests;
            $subTotalINR = $totalTourCostINR + $totalHandlingFeeINR;
            
            $cgstPercent = $email->cgst_percent ?? 9;
            $sgstPercent = $email->sgst_percent ?? 9;
            $cgst = $totalHandlingFeeINR * ($cgstPercent / 100);
            $sgst = $totalHandlingFeeINR * ($sgstPercent / 100);
            $finalGrandTotal = $subTotalINR + $cgst + $sgst;
            
            $handlingFee = $totalHandlingFeeINR;
            $grandTotal = $finalGrandTotal;
            $currency = 'INR';
            
            $calculations = [
                'original_usd' => $totalUSD,
                'total_guests' => $totalGuests,
                'per_person_usd' => $perPersonUSD,
                'handling_fee_per_person_usd' => $handlingFeePerPersonUSD,
                'net_per_person_usd' => $netPerPersonUSD,
                'exchange_rate' => $exchangeRate,
                'net_per_person_inr' => $netPerPersonINR,
                'handling_fee_per_person_inr' => $handlingFeePerPersonINR,
                'total_tour_cost_inr' => $totalTourCostINR,
                'total_handling_fee_inr' => $totalHandlingFeeINR,
                'sub_total_inr' => $subTotalINR,
                'cgst_percent' => $cgstPercent,
                'cgst_amount' => $cgst,
                'sgst_percent' => $sgstPercent,
                'sgst_amount' => $sgst,
                'final_total_inr' => $finalGrandTotal,
                'gst_number' => $gstNumber,
                'sales_person' => $salesPerson,
            ];
        }
        
        // ✅ Create NEW invoice record with updated total
        $invoice = GeneratedInvoice::create([
            'email_id' => $email->id,
            'invoice_number' => $newInvoiceNumber,
            'invoice_date' => now()->format('Y-m-d'),
            'customer_name' => $email->agent_name ?? ($email->guest_name ?? 'Unknown Customer'),
            'guest_name' => $email->guest_name,
            'tour_ref' => $email->tour_ref,
            'total_amount' => $totalUSD,
            'handling_fee' => $handlingFee,
            'grand_total' => $grandTotal,
            'currency' => $currency,
            'invoice_type' => $classification['credit_type'],
            'status' => 'draft',
            'file_path' => null,
            'calculations' => $calculations ? json_encode($calculations) : null,
            'revision_number' => $revisionNumber,
            'total_revisions' => $newTotal,  // ✅ Updated total
            'is_revision' => true,
            'original_invoice_number' => $cleanBase,
            'gst_number' => $gstNumber,
            'sales_person' => $salesPerson,
        ]);
        
        // Generate PDF
        if ($invoiceFormat == 'apple_holidays') {
            $html = $this->generateAppleHolidaysInvoiceHTML($invoice, $email);
        } else {
            $html = $this->generateSharmilaInvoiceHTML($invoice, $email, $calculations);
        }
        
       $pdf = Pdf::loadHTML($html);
    $filename = "invoices/{$newInvoiceNumber}.pdf";  // ← No slashes
    $pdf->save(storage_path("app/public/{$filename}"));
        
        $invoice->file_path = $filename;
        $invoice->save();
        
        Log::info("✅ Created revision invoice: {$newInvoiceNumber} (Revision {$revisionNumber} of {$newTotal})");
        $this->sendInvoiceEmail($invoice);
        return $invoice;
    }

protected function autoGenerateInvoice($email)
{
    try {
        if (GeneratedInvoice::where('email_id', $email->id)->exists()) {
            return;
        }
        
        if ($email->tour_ref == 'NA' || $email->invoice_number == 'NA') {
            return;
        }
        
        $invoiceService = app(InvoiceGenerationService::class);
        $invoice = $invoiceService->generateFromEmail($email);
        
        if ($invoice) {
            Log::info("✅ Auto-generated invoice: " . $invoice->invoice_number);
            
            // ✅✅✅ ADD THIS LINE ✅✅✅
            $this->sendInvoiceEmail($invoice);
            
        }
        
    } catch (\Exception $e) {
        Log::error('❌ Auto-generate invoice failed: ' . $e->getMessage());
    }
}

/**
 * ✅ Send Invoice Email
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
        
        Mail::to('kevinraj@aahaas.com')
            ->cc('raja.lakshmi@aahaas.com')
            ->send(new InvoiceMail($invoice, $emailType));
        
        Log::info("📧 Invoice email sent for: " . $invoice->invoice_number);
        
    } catch (\Exception $e) {
        Log::error('❌ Failed to send invoice email: ' . $e->getMessage());
    }
}

/**
 * ✅ Detect currency from email body
 */
protected function detectCurrency($plainText)
{
    // Check for Singapore Dollar
    if (preg_match('/S\$\s*([0-9,]+\.?[0-9]*)/', $plainText, $match) || 
        stripos($plainText, 'SGD') !== false || 
        stripos($plainText, 'Singapore') !== false) {
        return 'SGD';
    }
    
    // Check for Malaysian Ringgit
    if (preg_match('/RM\s*([0-9,]+\.?[0-9]*)/', $plainText, $match) || 
        stripos($plainText, 'MYR') !== false || 
        stripos($plainText, 'Ringgit') !== false) {
        return 'MYR';
    }
    
    // Check for USD
    if (preg_match('/\$\s*([0-9,]+\.?[0-9]*)/', $plainText, $match) || 
        stripos($plainText, 'USD') !== false) {
        return 'USD';
    }
    
    // Check for INR
    if (stripos($plainText, 'INR') !== false || 
        stripos($plainText, '₹') !== false) {
        return 'INR';
    }
    
    return 'USD'; // Default
}

/**
 * ✅ Convert amount to INR based on currency
 */
protected function convertToINR($amount, $currency)
{
    if ($currency == 'INR') {
        return $amount;
    }
    
    $exchangeService = new ExchangeRateService();
    
    switch ($currency) {
        case 'SGD':
            $rate = $exchangeService->getSgdToInrRate();  // SGD → INR with +1
            Log::info("🔄 Converting SGD to INR: {$amount} SGD × {$rate} = " . ($amount * $rate));
            break;
        case 'MYR':
            $rate = $exchangeService->getMyrToInrRate();  // MYR → INR with +1
            Log::info("🔄 Converting MYR to INR: {$amount} MYR × {$rate} = " . ($amount * $rate));
            break;
        case 'USD':
        default:
            $rate = $exchangeService->getUsdToInrRate();  // USD → INR with +1
            Log::info("🔄 Converting USD to INR: {$amount} USD × {$rate} = " . ($amount * $rate));
            break;
    }
    
    return $amount * $rate;
}
}