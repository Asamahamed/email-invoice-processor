<?php

namespace App\Services;

use App\Models\GeneratedInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class InvoiceGenerationService
{
    private $revisionCounters = [];

   public function generateFromEmail($email, $classification = null, $revisionNumber = null, $gstNumber = null, $salesPerson = null)
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
        
        // Generate invoice number with revision support
        $baseInvoiceNumber = $email->invoice_number;
        $invoiceNumber = $this->getInvoiceNumberWithRevision($baseInvoiceNumber, $revisionNumber);
        
        // Create directory
        $directory = storage_path('app/public/invoices');
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
        
        // Get dynamic values from email
        $totalUSD = $email->total_amount ?? 0;
        
        // Get number of guests from database
        $totalGuests = (int)($email->number_of_guests ?? $email->pax_count ?? 1);
        if ($totalGuests < 1) {
            $totalGuests = 1;
        }
        
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
        
        // Check if invoice with this number already exists (for revision tracking)
        $existingInvoice = GeneratedInvoice::where('invoice_number', $invoiceNumber)->first();
        $isRevision = ($revisionNumber !== null) || ($existingInvoice && $existingInvoice->revision_number > 0);
        
        // Create invoice record
        $invoice = GeneratedInvoice::create([
            'email_id' => $email->id,
            'invoice_number' => $invoiceNumber,
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
            'revision_number' => $revisionNumber ?? ($existingInvoice ? $existingInvoice->revision_number + 1 : 0),
            'original_invoice_number' => $baseInvoiceNumber,
            'is_revision' => $isRevision,
              'gst_number' => $gstNumber,          // ✅ Add this
    'sales_person' => $salesPerson, 
        ]);
        
        // Generate PDF based on invoice format
        if ($invoiceFormat == 'apple_holidays') {
            $html = $this->generateAppleHolidaysInvoiceHTML($invoice, $email);
        } else {
            $html = $this->generateSharmilaInvoiceHTML($invoice, $email, $calculations);
        }
        
        $pdf = Pdf::loadHTML($html);
        $filename = "invoices/{$invoice->invoice_number}.pdf";
        $pdf->save(storage_path("app/public/{$filename}"));
        
        $invoice->file_path = $filename;
        $invoice->save();
        
        return $invoice;
    }
    
    /**
     * Generate invoice number with revision suffix (e.g., IS43595R1, IS43595R2, IS43595R3)
     */
    /**
 * Generate invoice number with revision suffix (e.g., IS43595R1, IS43595R2, IS43595R3)
 * Now handles spaces in original invoice number
 */
protected function getInvoiceNumberWithRevision($baseNumber, $revisionNumber = null)
{
    if (!$baseNumber) {
        $baseNumber = 'INV' . date('Ymd') . rand(1000, 9999);
    }
    
    // REMOVE ANY SPACES FIRST!
    $baseNumber = str_replace(' ', '', $baseNumber);
    
    // Remove any existing revision suffix (e.g., IS43595R1 -> IS43595)
    $cleanBase = preg_replace('/R\d+$/i', '', $baseNumber);
    
    if ($revisionNumber !== null && $revisionNumber > 0) {
        return $cleanBase . 'R' . $revisionNumber;
    }
    
    // Check if this base number already has invoices
    $existingRevisions = GeneratedInvoice::where('original_invoice_number', $cleanBase)
        ->orWhere('invoice_number', 'LIKE', $cleanBase . 'R%')
        ->count();
    
    if ($existingRevisions > 0) {
        $newRevisionNumber = $existingRevisions + 1;
        return $cleanBase . 'R' . $newRevisionNumber;
    }
    
    return $cleanBase;
}
    
    /**
     * Get settlement date based on invoice type
     */
   protected function getSettlementDate($travelStartDate, $isUSD = true)
{
    $daysBeforeTravel = $isUSD ? 10 : 15;
    
    if (!$travelStartDate) {
        $defaultDate = new \DateTime();
        $defaultDate->modify('+' . $daysBeforeTravel . ' days');
        return $defaultDate->format('d/m/Y');
    }
    
    try {
        $travelDate = null;
        if (strpos($travelStartDate, '-') !== false) {
            $travelDate = new \DateTime($travelStartDate);
        } elseif (strpos($travelStartDate, '/') !== false) {
            $travelDate = \DateTime::createFromFormat('d/m/Y', $travelStartDate);
            if (!$travelDate) {
                $travelDate = \DateTime::createFromFormat('m/d/Y', $travelStartDate);
            }
        }
        
        if ($travelDate) {
            $settlementDate = clone $travelDate;
            $settlementDate->modify('-' . $daysBeforeTravel . ' days');
            
            $today = new \DateTime();
            // Only replace with today if settlement date is more than 30 days past? Or never?
            // Option: Never replace, always use calculated date
            return $settlementDate->format('d/m/Y');
        }
    } catch (\Exception $e) {
        Log::error("Date parsing error: " . $e->getMessage());
    }
    
    $fallbackDate = new \DateTime();
    $fallbackDate->modify('+' . $daysBeforeTravel . ' days');
    return $fallbackDate->format('d/m/Y');
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
            'TRIP FACTORY' => "Trip Factory\nYour Address Here\nCity, State - PIN\nCountry",
            'PICK YOUR TRAIL' => "Pick Your Trail\nYour Address Here\nCity, State - PIN\nCountry",
            '30 SUNDAYS' => "30 Sundays\nYour Address Here\nCity, State - PIN\nCountry",
            'I TRIP' => "I TRIP\nYour Address Here\nCity, State - PIN\nCountry",
            'NEXUS DMC' => "Nexus DMC\nYour Address Here\nCity, State - PIN\nCountry",
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
    protected function generateAppleHolidaysInvoiceHTML($invoice, $email)
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
                .logo img { max-width: 180px; height: auto; }
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
                    Tel: +94-11-7423700 Fax: +94-11-7423707 email: accounts@appleholidaysds.com
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
                    <thead><tr><th>Particulars</th><th>UNIT FARE</th><th>DISC %</th><th>QTY.</th><th class="amount">AMOUNT</th></tr></thead>
                    <tbody>
                        ' . ($totalGuests > 1 ? '
                        <tr><td>Cost Per Person</td><td>$' . number_format($perPersonAmount, 2) . '</td><td>0</td><td>' . $totalGuests . '</td><td class="amount">$' . number_format($totalAmount, 2) . '</td></tr>
                        <tr><td>Total Tour cost</td><td>$' . number_format($totalAmount, 2) . '</td><td>0</td><td>1</td><td class="amount">$' . number_format($totalAmount, 2) . '</td></tr>
                        ' : '
                        <tr><td>Total Tour cost</td><td>$' . number_format($totalAmount, 2) . '</td><td>0</td><td>1</td><td class="amount">$' . number_format($totalAmount, 2) . '</td></tr>
                        ') . '
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
                    Cheques should be drawn in favour of APPLE HOLIDAYS and crossed A/C Payee Only<br>
                    This is a computer generated document no signature is required
                </div>
            </div>
        </body>
        </html>';
    }
    
    /**
     * CREDIT INR & NON-CREDIT - SHARMILA Format - FIXED ADDRESS DISPLAY
     */
    protected function generateSharmilaInvoiceHTML($invoice, $email, $calculations)
    {
        $travelDates = $this->getTravelDates($email);
        $settlementDate = $this->getSettlementDate($email->travel_start_date, false);
        
        $salesId = $email->sales_id ?? 'NA';
        $fileHandler = $email->file_handler ?? 'NA';
        
        $totalGuests = $calculations['total_guests'] ?? 1;
        $exchangeRate = $calculations['exchange_rate'];
        
        $netPerPersonINR = $calculations['net_per_person_inr'];
        $handlingFeePerPersonINR = $calculations['handling_fee_per_person_inr'];
        $totalTourCost = $calculations['total_tour_cost_inr'];
        $totalHandlingFee = $calculations['total_handling_fee_inr'];
        $subTotal = $calculations['sub_total_inr'];
        $cgstAmount = $calculations['cgst_amount'];
        $sgstAmount = $calculations['sgst_amount'];
        $grandTotal = $calculations['final_total_inr'];
        $amountReceived = 0;
        $balanceDue = $grandTotal;
        
        $customerAddress = $this->getFormattedToAddress($invoice->customer_name);
        
        // Revision note for Sharmila invoice
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
                .warning-text {
                    font-size: 6.5pt;
                    color: #856404;
                    background-color: #fff3cd;
                    padding: 6px;
                    margin-top: 8px;
                    border-radius: 3px;
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
                        Tel : +91 0452 405 8375 / 4054704 | Email : Chennai@Sharmilatravels.com<br>
                        Services Tax ( Registration NO .) : ADVF4429D | GSTIN : 33ADVFS4429D1ZV
                    </div>
                </div>
                
                ' . $revisionNote . '
                
                <!-- To Section with address (only once, no separate Address label) -->
                <div class="to-section">
                    <strong>To:</strong> ' . nl2br(htmlspecialchars($customerAddress)) . '
                </div>
                
                <div class="invoice-title">
                    <span>INVOICE (Original)</span>
                </div>
                
                <!-- NO separate Address: section here -->
                
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
                    <thead><tr><th>Particulars</th><th>Unit Fare</th><th>Discount</th><th>Qty</th><th class="amount">Amount</th></tr></thead>
                    <tbody>
                        <tr><td>Cost Per Person</td><td>INR ' . number_format($netPerPersonINR, 2) . '</td><td>0</td><td>' . $totalGuests . '</td><td class="amount">INR ' . number_format($totalTourCost, 2) . '</td></tr>
                        <tr><td>Handling Fee</td><td>INR ' . number_format($handlingFeePerPersonINR, 2) . '</td><td>0</td><td>' . $totalGuests . '</td><td class="amount">INR ' . number_format($totalHandlingFee, 2) . '</td></tr>
                    </tbody>
                </table>
                
                <div class="total-section">
                    <table class="total-table">
                        <tr><td class="label-cell">Sub Total :</td><td class="amount-cell">INR ' . number_format($subTotal, 2) . '</td></tr>
                        <tr><td class="label-cell">CGST of 9.00% :</td><td class="amount-cell">INR ' . number_format($cgstAmount, 2) . '</td></tr>
                        <tr><td class="label-cell">SGST of 9.00% :</td><td class="amount-cell">INR ' . number_format($sgstAmount, 2) . '</td></tr>
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
                
                <div class="warning-text">
                    <strong>Important Notes:</strong><br>
                    • <strong>Cash Deposit Instructions:</strong> Please do not deposit the full amount in a single transaction into our account. Instead, kindly make part payments at regular intervals to avoid attracting Tax Collected at Source (TCS), which is applicable on cash deposits exceeding ₹49,000.<br>
                    • <strong>QR Code Payment Delivery:</strong> While making payments via QR code, please avoid using credit cards. If a credit card is used, Transaction Disbursement Rate (TDR) charges will be applicable.
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
        
        // Calculate new revision number
        if ($revisionNumber === null) {
            $revisionNumber = ($existingInvoice->revision_number ?? 0) + 1;
        }
        
        // Generate new invoice number with revision (e.g., IS43595R3)
        $baseNumber = $existingInvoice->original_invoice_number ?? $email->invoice_number;
        $newInvoiceNumber = $baseNumber . 'R' . $revisionNumber;
        
        // Delete old PDF
        $oldPath = storage_path("app/public/{$existingInvoice->file_path}");
        if (file_exists($oldPath)) {
            unlink($oldPath);
        }
        
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
        
        // Update existing invoice record with new revision
        $existingInvoice->update([
            'invoice_number' => $newInvoiceNumber,
            'customer_name' => $email->agent_name ?? ($email->guest_name ?? 'Unknown Customer'),
            'guest_name' => $email->guest_name,
            'tour_ref' => $email->tour_ref,
            'total_amount' => $totalUSD,
            'handling_fee' => $handlingFee,
            'grand_total' => $grandTotal,
            'currency' => $currency,
            'invoice_type' => $classification['credit_type'],
            'status' => 'draft',
            'calculations' => $calculations ? json_encode($calculations) : null,
            'revision_number' => $revisionNumber,
            'is_revision' => true,
            'updated_at' => now(),
             'gst_number' => $gstNumber ?? $existingInvoice->gst_number,
    'sales_person' => $salesPerson ?? $existingInvoice->sales_person,
        ]);
        
        // Generate PDF based on invoice format
        if ($invoiceFormat == 'apple_holidays') {
            $html = $this->generateAppleHolidaysInvoiceHTML($existingInvoice, $email);
        } else {
            $html = $this->generateSharmilaInvoiceHTML($existingInvoice, $email, $calculations);
        }
        
        $pdf = Pdf::loadHTML($html);
        $filename = "invoices/{$newInvoiceNumber}.pdf";
        $pdf->save(storage_path("app/public/{$filename}"));
        
        $existingInvoice->file_path = $filename;
        $existingInvoice->save();
        
        return $existingInvoice;
    }
}