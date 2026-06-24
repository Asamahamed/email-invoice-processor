<?php

namespace App\Mail;

use App\Models\GeneratedInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invoice;
    public $emailType;
    public $displayCurrency;

    public function __construct(GeneratedInvoice $invoice, $emailType = 'credit')
    {
        $this->invoice = $invoice;
        $this->emailType = $emailType;
        
        // ✅ FIX: Determine display currency based on invoice currency
        // If invoice currency is USD, show USD
        // If invoice currency is INR, show INR
        // This handles both Credit USD and Credit INR agents
        if ($invoice->currency == 'USD') {
            $this->displayCurrency = 'USD';
        } elseif ($invoice->currency == 'INR') {
            $this->displayCurrency = 'INR';
        } else {
            // Fallback based on email type
            if ($emailType == 'credit') {
                $this->displayCurrency = 'USD';
            } else {
                $this->displayCurrency = 'INR';
            }
        }
        
        Log::info("📧 InvoiceMail - Invoice: {$invoice->invoice_number}, Currency: {$invoice->currency}, Display: {$this->displayCurrency}, Type: {$emailType}");
    }

public function envelope(): Envelope
{
    $currencyLabel = ($this->displayCurrency == 'USD') ? 'USD' : 'INR';
    $subject = $this->emailType == 'revision' 
        ? "REVISED INVOICE - {$this->invoice->invoice_number} ({$currencyLabel})" 
        : "INVOICE - {$this->invoice->invoice_number} ({$currencyLabel})";
        
    $agentName = $this->invoice->customer_name ?? 'Agent';
    
    return new Envelope(
        subject: "{$subject} ({$agentName})",
        to: ['kevinraj@aahaas.com'],
        cc: ['raja.lakshmi@aahaas.com'],
    );
}

    public function content(): Content
    {
        $body = $this->getEmailBody();
        
        return new Content(
            view: 'emails.invoice',
            with: [
                'body' => $body,
                'invoice' => $this->invoice,
                'displayCurrency' => $this->displayCurrency,
            ],
        );
    }

    public function attachments(): array
    {
        $pdfPath = storage_path("app/public/{$this->invoice->file_path}");
        
        if (file_exists($pdfPath)) {
            return [
                Attachment::fromPath($pdfPath)
                    ->as("{$this->invoice->invoice_number}.pdf")
                    ->withMime('application/pdf'),
            ];
        }
        
        return [];
    }

    protected function getEmailBody()
    {
        if ($this->emailType == 'revision') {
            return $this->getRevisionBody();
        } elseif ($this->emailType == 'credit') {
            return $this->getCreditBody();
        } else {
            return $this->getNonCreditBody();
        }
    }

    /**
     * ✅ CREDIT Agent Email
     * For both USD and INR credit agents
     */
/**
 * ✅ CREDIT Agent Email
 * For both USD and INR credit agents
 */
protected function getCreditBody()
{
    $body = "Dear Team,\n\n" .
            "Greetings from Apple Holidays!\n\n" .
            "Kindly find the attached invoice.\n\n";
    
    // ✅ Add currency-specific content based on display currency
    if ($this->displayCurrency == 'USD') {
        // USD Credit Agent - Original USD content
        $body .= "WE REQUIRED THE ALL PASSENGERS AADHAR LINKED PAN CARD, FLIGHT TICKETS, VISA & THE PASSPORT COPIES WITH ADDRESS PAGE.\n\n";
    } else {
        // INR Credit Agent - INR content with payment instructions
        $body .= "WE REQUIRED THE ALL PASSENGERS AADHAR LINKED PAN CARD, FLIGHT TICKETS, VISA & THE PASSPORT COPIES WITH ADDRESS PAGE.\n\n" .
                 "⚠️ IMPORTANT: Payment should be made in INR only, not in USD.\n" .
                 "Payment should be made based on the invoice exchange rate as per XE.com + ₹1.\n\n" .
                 "For INR PAYMENT WE REQUIRED THE ALL PASSENGERS AADHAR LINKED PAN CARD, FLIGHT TICKETS, VISA & THE PASSPORT COPIES WITH ADDRESS PAGE.\n\n" .
                 "Important Notes:\n" .
                 "Cash Deposit Instructions:\n" .
                 "Please do not deposit the full amount in a single transaction into our account. Instead, kindly make part payments at regular intervals to avoid attracting Tax Collected at Source (TCS), which is applicable on cash deposits exceeding ₹49,000.\n\n" .
                 "QR Code Payment Advisory:\n" .
                 "While making payments via QR code, please avoid using credit cards. If a credit card is used, Transaction Discount Rate (TDR) charges will be applicable.\n\n" .
                 "Xe: Currency Exchange Rates and International Money Transfers\n" .
                 "Get the best currency exchange rates for international money transfers to 200 countries in 100 foreign currencies. Send and receive money with best forex rates.\n\n";
    }
    
    $body .= "Thanks & Regards,\n" .
             "Accounts Team\n" .
             "Apple Holidays";
    
    return $body;
}

    /**
     * ✅ NON-CREDIT Agent Email (INR)
     */
    protected function getNonCreditBody()
    {
        return "Dear Team,\n\n" .
               "Greetings from Apple Holidays!\n\n" .
               "Kindly make the full payment on or before 24 hours without any fail.\n\n" .
               "Please find the attached invoice for your reference.\n\n" .
               "Payment should be made based on the invoice exchange rate as per XE.com + ₹1.\n\n" .
               "For INR PAYMENT WE REQUIRED THE ALL PASSENGERS AADHAR LINKED PAN CARD, FLIGHT TICKETS, VISA & THE PASSPORT COPIES WITH ADDRESS PAGE.\n\n" .
               "Important Notes:\n" .
               "Cash Deposit Instructions:\n" .
               "Please do not deposit the full amount in a single transaction into our account. Instead, kindly make part payments at regular intervals to avoid attracting Tax Collected at Source (TCS), which is applicable on cash deposits exceeding ₹49,000.\n\n" .
               "QR Code Payment Advisory:\n" .
               "While making payments via QR code, please avoid using credit cards. If a credit card is used, Transaction Discount Rate (TDR) charges will be applicable.\n\n" .
               "Xe: Currency Exchange Rates and International Money Transfers\n" .
               "Get the best currency exchange rates for international money transfers to 200 countries in 100 foreign currencies. Send and receive money with best forex rates.\n\n" .
               "Thanks & Regards,\n" .
               "Accounts Team\n" .
               "Apple Holidays";
    }

    /**
     * ✅ REVISED Invoice Email
     */
    protected function getRevisionBody()
    {
        $body = "Dear Team,\n\n" .
                "Greetings from Apple Holidays!\n\n" .
                "Kindly find the attached revised invoice.\n\n";
        
        // ✅ Add payment instructions based on currency
        if ($this->displayCurrency == 'USD') {
            $body .= "WE REQUIRED THE ALL PASSENGERS AADHAR LINKED PAN CARD, FLIGHT TICKETS, VISA & THE PASSPORT COPIES WITH ADDRESS PAGE.\n\n";
        } else {
            $body .= "Kindly make the full payment on or before 24 hours without any fail.\n\n" .
                     "Payment should be made based on the invoice exchange rate as per XE.com + ₹1.\n\n";
        }
        
        $body .= "NOTE: (PLEASE IGNORE IF YOU SHARED THE DOCUMENTS)\n\n" .
                 "Thanks & Regards,\n" .
                 "Accounts Team\n" .
                 "Apple Holidays";
        
        return $body;
    }
}