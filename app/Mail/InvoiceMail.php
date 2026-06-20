<?php

namespace App\Mail;

use App\Models\GeneratedInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invoice;
    public $emailType;

    public function __construct(GeneratedInvoice $invoice, $emailType = 'credit')
    {
        $this->invoice = $invoice;
        $this->emailType = $emailType;
    }

    public function envelope(): Envelope
    {
        $subject = $this->emailType == 'revision' 
            ? "REVISED INVOICE - {$this->invoice->invoice_number}" 
            : "INVOICE - {$this->invoice->invoice_number}";
            
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
     */
    protected function getCreditBody()
    {
        return "Dear Team,\n\n" .
               "Greetings from Apple Holidays!\n\n" .
               "Kindly find the attached invoice.\n\n" .
               "WE REQUIRED THE ALL PASSENGERS AADHAR LINKED PAN CARD, FLIGHT TICKETS, VISA & THE PASSPORT COPIES WITH ADDRESS PAGE.\n\n" .
               "Thanks & Regards,\n" .
               "Accounts Team\n" .
               "Apple Holidays";
    }

    /**
     * ✅ NON-CREDIT Agent Email
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
        return "Dear Team,\n\n" .
               "Greetings from Apple Holidays!\n\n" .
               "Kindly find the attached revised invoice.\n\n" .
               "WE REQUIRED THE ALL PASSENGERS AADHAR LINKED PAN CARD, FLIGHT TICKETS, VISA & THE PASSPORT COPIES WITH ADDRESS PAGE.\n\n" .
               "NOTE: (PLEASE IGNORE IF YOU SHARED THE DOCUMENTS)\n\n" .
               "Thanks & Regards,\n" .
               "Accounts Team\n" .
               "Apple Holidays";
    }
}