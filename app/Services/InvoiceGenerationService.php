<?php

namespace App\Services;

use App\Models\GeneratedInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

class InvoiceGenerationService
{
    public function generateFromEmail($email, $classification = null)
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
        
        // Generate invoice number
        $invoiceNumber = $email->invoice_number;
        if (!$invoiceNumber || $invoiceNumber === 'NA') {
            $lastInvoice = GeneratedInvoice::orderBy('id', 'desc')->first();
            $lastNumber = $lastInvoice ? intval(substr($lastInvoice->invoice_number, 2)) : 48150;
            $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);
            $invoiceNumber = 'IS' . $newNumber;
        }
        
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
        $handlingFeePerPersonUSD = 5; // $5 handling fee PER PERSON
        
        // Determine if handling fee should be applied from classification
        $hasHandlingFee = $classification['has_handling_fee'] ?? false;
        $invoiceFormat = $classification['invoice_format'] ?? 'apple_holidays';
        $currency = $classification['currency'] ?? 'USD';
        
        if (!$hasHandlingFee) {
            // CREDIT USD - Apple Holidays: NO handling fee
            $handlingFee = 0;
            $grandTotal = $totalUSD;
            $currency = 'USD';
            $calculations = null;
        } else {
            // CREDIT INR OR NON-CREDIT - Sharmila: WITH handling fee calculations
            
            // Step 1: Per person USD
            $perPersonUSD = $totalUSD / $totalGuests;
            
            // Step 2: Subtract handling fee from per person USD
            $netPerPersonUSD = $perPersonUSD - $handlingFeePerPersonUSD;
            
            // Step 3: Convert net per person to INR
            $netPerPersonINR = $netPerPersonUSD * $exchangeRate;
            
            // Step 4: Total Tour Cost INR (net amount after handling fee deduction)
            $totalTourCostINR = $netPerPersonINR * $totalGuests;
            
            // Step 5: Handling Fee per person in INR
            $handlingFeePerPersonINR = $handlingFeePerPersonUSD * $exchangeRate;
            
            // Step 6: Total Handling Fee INR
            $totalHandlingFeeINR = $handlingFeePerPersonINR * $totalGuests;
            
            // Step 7: Sub Total (Tour Cost + Handling Fee)
            $subTotalINR = $totalTourCostINR + $totalHandlingFeeINR;
            
            // Step 8: GST calculation - ONLY on handling fee portion
            $cgstPercent = $email->cgst_percent ?? 9;
            $sgstPercent = $email->sgst_percent ?? 9;
            
            $cgst = $totalHandlingFeeINR * ($cgstPercent / 100);
            $sgst = $totalHandlingFeeINR * ($sgstPercent / 100);
            
            // Step 9: Grand Total
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
                'final_total_inr' => $finalGrandTotal
            ];
        }
        
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
     * Get settlement date based on invoice type
     * USD: Current date (invoice generation date)
     * INR: 15 days before travel start date
     */
   /**
 * Get settlement date based on invoice type
 * USD: Current date (invoice generation date)
 * INR: 15 days before travel start date
 */
protected function getSettlementDate($travelStartDate, $isUSD = true)
{
    if ($isUSD) {
        return date('d/m/Y');
    } else {
        if ($travelStartDate) {
            try {
                // Try different date formats
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
                    $settlementDate->modify('-15 days');
                    
                    $today = new \DateTime();
                    if ($settlementDate < $today) {
                        return $today->format('d/m/Y');
                    }
                    return $settlementDate->format('d/m/Y');
                }
            } catch (\Exception $e) {
                Log::error("Date parsing error in getSettlementDate: " . $e->getMessage());
            }
        }
        return date('d/m/Y');
    }
}
    
    /**
     * Get formatted travel dates for remark
     */
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
     * CREDIT USD - APPLE HOLIDAYS Format (USD) - NO HANDLING FEE
     */
   /**
 * CREDIT USD - APPLE HOLIDAYS Format (USD) - NO HANDLING FEE
 */
protected function generateAppleHolidaysInvoiceHTML($invoice, $email)
{
    $agentAddress = $this->getAgentAddress($invoice->customer_name);
    $voucherNo = $email->tour_ref ?? 'NL' . rand(1000000000, 9999999999);
    
    $travelDates = $this->getTravelDates($email);
    $settlementDate = $this->getSettlementDate($email->travel_start_date, true);
    
    $staffName = $email->file_handler ?? 'Kevin';
    $fileHandler = $email->file_handler ?? 'Esther';
    $totalAmount = $invoice->total_amount;
    
    // Get number of guests for per-person calculation
    $totalGuests = (int)($email->number_of_guests ?? $email->pax_count ?? 1);
    if ($totalGuests < 1) {
        $totalGuests = 1;
    }
    
    // Calculate per person amount
    $perPersonAmount = $totalAmount / $totalGuests;
    
    $logoPath = public_path('images/credit_img.png');
    $logoBase64 = '';
    if (file_exists($logoPath)) {
        $logoData = base64_encode(file_get_contents($logoPath));
        $logoBase64 = 'data:image/png;base64,' . $logoData;
    }
    
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>INVOICE - ' . $invoice->invoice_number . '</title>
        <style>
            @page { 
                margin: 15px; 
                size: A4;
            }
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
            .logo {
                text-align: center;
                margin-bottom: 10px;
            }
            .logo img {
                max-width: 180px;
                height: auto;
            }
            .header-address {
                text-align: center;
                margin-bottom: 15px;
                font-size: 7pt;
                line-height: 1.3;
            }
            .invoice-title {
                text-align: right;
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
            .amount {
                text-align: right;
            }
            .total-section {
                margin-top: 10px;
                margin-bottom: 10px;
            }
            .total-table {
                width: 100%;
                border-collapse: collapse;
            }
            .total-table td {
                padding: 4px 8px;
            }
            .total-table .label-cell {
                text-align: left;
                width: 50%;
            }
            .total-table .amount-cell {
                text-align: right;
                width: 50%;
            }
            .settlement-text {
                margin: 10px 0;
                font-size: 8pt;
                font-weight: bold;
            }
            .payment-details {
                background: #f5f5f5;
                padding: 10px;
                margin: 15px 0;
                font-size: 7pt;
                line-height: 1.4;
            }
            .payment-details strong {
                font-size: 8pt;
            }
            .footer {
                margin-top: 15px;
                font-size: 6pt;
                text-align: center;
                color: #666;
                border-top: 1px solid #ddd;
                padding-top: 6px;
            }
            .staff {
                margin: 8px 0;
                font-size: 8pt;
            }
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
            <!-- Logo -->
            ' . ($logoBase64 ? '<div class="logo"><img src="' . $logoBase64 . '" alt="Apple Holidays Logo"></div>' : '<div class="logo" style="font-size: 18pt; font-weight: bold; color: #1a237e;">Apple Holidays</div>') . '
            
            <!-- Header Address -->
            <div class="header-address">
                #2207 - #2208, One Galle Face Tower, 1A Center Road, Colombo 02, Sri Lanka<br>
                Tel: +94-11-7423700 Fax: +94-11-7423707 email: accounts@appleholidaysds.com
            </div>
            
            <!-- To Section -->
            <div class="to-section">
                <strong>To:</strong> ' . nl2br(htmlspecialchars($agentAddress)) . '
            </div>
            
            <!-- Invoice Title -->
            <div class="invoice-title">
                <h1>INVOICE</h1>
            </div>
            
            <!-- Address placeholder -->
            <div class="to-section">
                <strong>Address:</strong>
            </div>
            
            <!-- Invoice Details -->
            <table class="invoice-details">
                <tr>
                    <td class="label">Invoice No.:</td>
                    <td><strong>' . $invoice->invoice_number . '</strong></td>
                    <td class="label">Date:</td>
                    <td>' . date('d/m/Y', strtotime($invoice->invoice_date)) . '</td>
                </tr>
                <tr>
                    <td class="label">Ref ID:</td>
                    <td>' . htmlspecialchars($email->tour_ref ?? '-') . '</td>
                    <td class="label">Agent ID:</td>
                    <td>' . htmlspecialchars($email->reference_no ?? '-') . '</td>
                </tr>
                <tr>
                    <td class="label">File Handler:</td>
                    <td>' . strtoupper($fileHandler) . '</td>
                    <td class="label">Guest Name:</td>
                    <td>' . htmlspecialchars($email->guest_name ?? '-') . '</td>
                </tr>
            </table>
            
            <!-- Items Table -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Particulars</th>
                        <th>UNIT FARE</th>
                        <th>DISC %</th>
                        <th>QTY.</th>
                        <th class="amount">AMOUNT</th>
                    </tr>
                </thead>
                <tbody>
                    ' . ($totalGuests > 1 ? '
                    <tr>
                        <td>Cost Per Person</td>
                        <td>$' . number_format($perPersonAmount, 2) . '</td>
                        <td>0</td>
                        <td>' . $totalGuests . '</td>
                        <td class="amount">$' . number_format($totalAmount, 2) . '</td>
                    </tr>
                    <tr>
                        <td>Total Tour cost</td>
                        <td>$' . number_format($totalAmount, 2) . '</td>
                        <td>0</td>
                        <td>1</td>
                        <td class="amount">$' . number_format($totalAmount, 2) . '</td>
                    </tr>
                    ' : '
                    <tr>
                        <td>Total Tour cost</td>
                        <td>$' . number_format($totalAmount, 2) . '</td>
                        <td>0</td>
                        <td>1</td>
                        <td class="amount">$' . number_format($totalAmount, 2) . '</td>
                    </tr>
                    ') . '
                </tbody>
            </table>
            
            <!-- Total Section - Two column layout like your PDF -->
          <div class="total-section">
    <table class="total-table" style="width:300px; margin-left:auto;">
        <tr>
            <td class="label-cell"><strong>SUB TOTAL</strong></td>
            <td class="amount-cell">$' . number_format($totalAmount, 2) . '</td>
        </tr>
        <tr>
            <td class="label-cell"><strong>BANK CHARGES</strong></td>
            <td class="amount-cell">$0.00</td>
        </tr>
        <tr>
            <td class="label-cell"><strong>TOTAL</strong></td>
            <td class="amount-cell">$' . number_format($totalAmount, 2) . '</td>
        </tr>
        <tr>
            <td class="label-cell"><strong>AMOUNT RECEIVED</strong></td>
            <td class="amount-cell">$0.00</td>
        </tr>
        <tr>
            <td class="label-cell"><strong>BALANCE DUE</strong></td>
            <td class="amount-cell">$' . number_format($totalAmount, 2) . '</td>
        </tr>
    </table>
</div>
             <div class="remark">
                <strong>Travel Date:</strong> ' . ($travelDates ?: 'No travel dates specified') . '
            </div>
            <!-- Settlement Text -->
            <div class="settlement-text">
                Please settle the invoice on or before ' . $settlementDate . '
            </div>
            
            <!-- Account Details -->
            <div class="payment-details">
                <strong>ACCOUNT IN SRI LANKA</strong><br>
                ACCOUNT NAME: APPLE HOLIDAYS DESTINATION SERVICES (PVT) LTD<br>
                BANK: COMMERCIAL BANK<br>
                BRANCH: PETTAH<br>
                ACCOUNT NO: 1000136027<br>
                SWIFT CODE: CCEYLKLX<br>
                Bank Address: Commercial Bank, Peoples Park Shopping Complex, No 180/1/31, Colombo 11, Sri Lanka
            </div>
            
            <!-- Printed By -->
            <div class="staff">
            Auto Generated  <br>
            </div>
            
            <!-- Travel Dates Remark -->
           
            
            <!-- Footer -->
            <div class="footer">
                Cheques should be drawn in favour of APPLE HOLIDAYS and crossed A/C Payee Only<br>
                This is a computer generated document no signature is required
            </div>
        </div>
    </body>
    </html>';
}
    
    /**
     * CREDIT INR & NON-CREDIT - SHARMILA Format (INR) - WITH HANDLING FEE PER PERSON
     */
   /**
 * CREDIT INR & NON-CREDIT - SHARMILA Format (INR) - EXACT MATCH TO YOUR PDF
 */
protected function generateSharmilaInvoiceHTML($invoice, $email, $calculations)
{
    $travelDates = $this->getTravelDates($email);
    $settlementDate = $this->getSettlementDate($email->travel_start_date, false);
    
    $staffName = $email->file_handler ?? 'Kevin';
    $salesId = $email->file_handler ?? 'Shahinsha';
    $fileHandler = $email->file_handler ?? 'AKASH KUMAR';
    
    $totalGuests = $calculations['total_guests'] ?? 1;
    $exchangeRate = $calculations['exchange_rate'];
    $xeRate = $exchangeRate - 1; // Xe rate before adding +1
    
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
    
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>INVOICE - ' . $invoice->invoice_number . '</title>
        <style>
            @page { 
                margin: 12px; 
                size: A4;
            }
            body {
                font-family: "DejaVu Sans", Arial, sans-serif;
                margin: 0;
                padding: 0;
                background: #fff;
                font-size: 9pt;
            }
            .invoice-container {
                max-width: 100%;
                margin: 0 auto;
                background: white;
            }
            .header {
                text-align: center;
                margin-bottom: 10px;
                padding-bottom: 8px;
                border-bottom: 1px solid #ddd;
            }
            .company-name {
                font-size: 14pt;
                font-weight: bold;
                color: #1a237e;
                margin-bottom: 3px;
            }
            .company-address {
                font-size: 7pt;
                color: #333;
                line-height: 1.3;
            }
            .company-details {
                font-size: 7pt;
                color: #333;
                margin-top: 3px;
            }
            .invoice-title {
                text-align: right;
                margin: 10px 0 8px 0;
            }
            .invoice-title span {
                font-size: 14pt;
                font-weight: bold;
                text-decoration: underline;
            }
            .to-section {
                margin: 8px 0;
                line-height: 1.3;
            }
            .to-section strong {
                font-weight: bold;
            }
            .info-table {
                width: 100%;
                border-collapse: collapse;
                margin: 8px 0;
                font-size: 8pt;
            }
            .info-table td {
                padding: 3px 5px;
                vertical-align: top;
            }
            .info-label {
                font-weight: bold;
                width: 90px;
            }
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
                font-weight: bold;
            }
            .items-table td {
                padding: 6px 5px;
                border: 1px solid #ddd;
            }
            .amount {
                text-align: right;
            }
            .total-section {
                margin-top: 8px;
                margin-bottom: 8px;
            }
            .total-table {
                width: 100%;
                border-collapse: collapse;
            }
            .total-table td {
                padding: 3px 8px;
                font-size: 8pt;
            }
            .total-table .label-cell {
                text-align: left;
            }
            .total-table .amount-cell {
                text-align: right;
            }
            .settlement-text {
                margin: 10px 0;
                font-size: 9pt;
                font-weight: bold;
            }
            .payment-details {
                background: #f5f5f5;
                padding: 8px 10px;
                margin: 10px 0;
                font-size: 7pt;
                line-height: 1.4;
            }
            .payment-details strong {
                font-size: 8pt;
            }
            .footer {
                margin-top: 10px;
                font-size: 6pt;
                text-align: center;
                color: #666;
                border-top: 1px solid #ddd;
                padding-top: 6px;
            }
            .printed-by {
                margin: 6px 0;
                font-size: 8pt;
            }
            .remark {
                margin: 8px 0;
                padding: 6px 8px;
                background: #fff3cd;
                border-left: 3px solid #ffc107;
                font-size: 8pt;
                line-height: 1.3;
            }
            .warning-text {
                font-size: 6.5pt;
                color: #856404;
                background-color: #fff3cd;
                padding: 6px;
                margin-top: 8px;
                border-radius: 3px;
                line-height: 1.3;
            }
            .warning-text strong {
                font-size: 7pt;
            }
        </style>
    </head>
    <body>
        <div class="invoice-container">
            <!-- Header -->
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
            
            <!-- To Section -->
            <div class="to-section">
                <strong>To:</strong> ' . htmlspecialchars($invoice->customer_name) . '<br>
                <strong>Address:</strong>
            </div>
            
            <!-- Invoice Title -->
            <div class="invoice-title">
                <span>INVOICE (Original)</span>
            </div>
            
            <!-- Invoice Details Table -->
            <table class="info-table">
                <tr>
                    <td class="info-label">Invoice No.:</td>
                    <td><strong>' . $invoice->invoice_number . '</strong></td>
                    <td class="info-label">Ref ID.:</td>
                    <td>' . htmlspecialchars($email->tour_ref ?? '-') . '</td>
                </tr>
                <tr>
                    <td class="info-label">File Handler:</td>
                    <td>' . strtoupper($fileHandler) . '</td>
                    <td class="info-label">Sales Person:</td>
                    <td>' . strtoupper($salesId) . '</td>
                </tr>
                <tr>
                    <td class="info-label">Date:</td>
                    <td>' . date('d/m/Y', strtotime($invoice->invoice_date)) . '</td>
                    <td class="info-label">Agent ID:</td>
                    <td>' . htmlspecialchars($email->reference_no ?? '-') . '</td>
                 </tr>
                <tr>
                    <td class="info-label">GST NO.:</td>
                    <td>33AAECT8475B1ZD</td>
                    <td class="info-label">Guest Name:</td>
                    <td>' . htmlspecialchars($email->guest_name ?? '-') . '</td>
                 </tr>
            </table>
            
            <!-- Items Table -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Particulars</th>
                        <th>Unit Fare</th>
                        <th>Discount</th>
                        <th>Qty</th>
                        <th class="amount">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Cost Per Person</td>
                        <td>INR ' . number_format($netPerPersonINR, 2) . '</td>
                        <td>0</td>
                        <td>' . $totalGuests . '</td>
                        <td class="amount">INR ' . number_format($totalTourCost, 2) . '</td>
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
            
            <!-- Total Section -->
            <div class="total-section">
                <table class="total-table">
                    <tr><td class="label-cell">Sub Total :</td><td class="amount-cell">INR ' . number_format($subTotal, 2) . '</td></tr>
                    <tr><td class="label-cell">CGST of 9.00% :</td><td class="amount-cell">INR ' . number_format($cgstAmount, 2) . '</td></tr>
                    <tr><td class="label-cell">SGST of 9.00% :</td><td class="amount-cell">INR ' . number_format($sgstAmount, 2) . '</td></tr>
                    <tr style="background-color: #f0f0f0; font-weight: bold;">
                        <td class="label-cell">Total :</td><td class="amount-cell">INR ' . number_format($grandTotal, 2) . '</td>
                    </tr>
                    <tr><td class="label-cell">Amount Received :</td><td class="amount-cell">INR ' . number_format($amountReceived, 2) . '</td></tr>
                    <tr style="font-weight: bold;">
                        <td class="label-cell">Balance :</td><td class="amount-cell">INR ' . number_format($balanceDue, 2) . '</td>
                    </tr>
                </table>
            </div>
              <div class="remark">
                <strong>Travel Date:</strong> ' . ($travelDates ?: 'No travel dates specified') . '
            </div>
            <!-- Settlement Text -->
            <div class="settlement-text">
                Please settle the invoice on or before ' . $settlementDate . '
            </div>
            
            <!-- Account Details -->
            <div class="payment-details">
                <strong>ACCOUNT DETAILS</strong><br>
                ACCOUNT NAME: SHARMILA TOURS AND TRAVELS<br>
                ACCOUNT NO: 056205002744<br>
                BANK: ICICI BANK LTD<br>
                BRANCH: TEPPAKULAM, MADURAI BRANCH<br>
                IFSC CODE: ICIC0000562<br>
                Bank Address: NO 199, DARSHINI TOWER, VAIGAI COLONY, ANNA NAGAR, 625020
            </div>
            
            <!-- Printed By and Exchange Rate -->
<div class="printed-by">
   Auto Generated <br>
    Xe: ' . number_format($exchangeRate - 1, 2) . ' (+1) = ' . $exchangeRate . '
</div>
            
            <!-- Travel Dates Remark -->
          
            
            <!-- Important Notes -->
            <div class="warning-text">
                <strong>Important Notes:</strong><br>
                • <strong>Cash Deposit Instructions:</strong> Please do not deposit the full amount in a single transaction into our account. Instead, kindly make part payments at regular intervals to avoid attracting Tax Collected at Source (TCS), which is applicable on cash deposits exceeding ₹49,000.<br>
                • <strong>QR Code Payment Delivery:</strong> While making payments via QR code, please avoid using credit cards. If a credit card is used, Transaction Disbursement Rate (TDR) charges will be applicable.
            </div>
            
            <!-- Footer -->
            <div class="footer">
                This is a computer generated document - no signature required
            </div>
        </div>
    </body>
    </html>';
}
    
    protected function getAgentAddress($agentName)
{
    $addresses = [
        'MAKE MY TRIP' => "MAKE MY TRIP INDIA PVT LTD\n19th floor, Tower A, B & C Epitome Building No. 5\nDLF Cyber City, Phase - III\nGurgaon 122 002, India",
        'TRIP FACTORY' => "Trip Factory\nYour Address Here\nCity, State - PIN\nCountry",
        'PICK YOUR TRAIL' => "Pick Your Trail\nYour Address Here\nCity, State - PIN\nCountry",
        '30 SUNDAYS' => "30 Sundays\nYour Address Here\nCity, State - PIN\nCountry",
        'I TRIP' => "I TRIP\nYour Address Here\nCity, State - PIN\nCountry",
        'NEXUS DMC' => "Nexus DMC\nYour Address Here\nCity, State - PIN\nCountry",
        'RIYA' => "RIYA HOLIDAYS PVT LTD\nG 2 Leela Business Park, Andheri - Kurla Road\nAndheri East, Mumbai - 400 059\nIndia",
    ];
    
    $upperName = strtoupper($agentName);
    foreach ($addresses as $key => $address) {
        if (strpos($upperName, $key) !== false) {
            return $address;
        }
    }
    
    // Default - return agent name with line breaks (split by spaces or common patterns)
    // If no match, try to format the agent name properly
    if (strlen($agentName) > 30) {
        // Split long agent names into multiple lines
        return wordwrap($agentName, 40, "\n", true);
    }
    
    return $agentName;
}
    /**
 * Get dynamic exchange rate from XE.com
 */
protected function getExchangeRate()
{
    try {
        $exchangeService = new \App\Services\ExchangeRateService();
        $rate = $exchangeService->getUsdToInrRate();
        
        // Log the rate being used
        \Log::info("Using exchange rate: USD 1 = INR {$rate}");
        
        return $rate;
    } catch (\Exception $e) {
        \Log::error("Failed to get exchange rate: " . $e->getMessage());
        // Fallback to default
        return 97;
    }
}
/**
 * Regenerate existing invoice with updated data
 */
public function regenerateInvoice($email, $existingInvoice)
{
    // Get classification
    $agentClassifier = new AgentClassificationService();
    $classification = $agentClassifier->classify(
        $email->body ?? '', 
        $email->from_email ?? '', 
        $email->subject ?? '', 
        $email->agent_name
    );
    
    // Generate invoice number (keep same if exists)
    $invoiceNumber = $existingInvoice->invoice_number;
    
    // Create directory
    $directory = storage_path('app/public/invoices');
    if (!File::exists($directory)) {
        File::makeDirectory($directory, 0755, true);
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
            'final_total_inr' => $finalGrandTotal
        ];
    }
    
    // Update existing invoice record
    $existingInvoice->update([
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
        'updated_at' => now(),
    ]);
    
    // Generate PDF based on invoice format
    if ($invoiceFormat == 'apple_holidays') {
        $html = $this->generateAppleHolidaysInvoiceHTML($existingInvoice, $email);
    } else {
        $html = $this->generateSharmilaInvoiceHTML($existingInvoice, $email, $calculations);
    }
    
    $pdf = Pdf::loadHTML($html);
    $filename = "invoices/{$existingInvoice->invoice_number}.pdf";
    $pdf->save(storage_path("app/public/{$filename}"));
    
    $existingInvoice->file_path = $filename;
    $existingInvoice->save();
    
    return $existingInvoice;
}
}