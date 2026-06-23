<?php

namespace App\Http\Controllers;

use App\Models\IncomingEmail;
use App\Models\GeneratedInvoice;
use App\Services\EmailFetchService;
use App\Services\InvoiceGenerationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use App\Mail\InvoiceMail;
use Illuminate\Support\Facades\Mail;
use App\Services\AgentClassificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File; 


class InvoiceController extends Controller
{
    public function index(Request $request)
    {
       $query = IncomingEmail::with('invoice')
        ->orderBy('received_at', 'desc')  // ✅ NEWEST FIRST for display
        ->latest('received_at');
        
        // Apply filters
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('from_email', 'like', "%{$search}%")
                  ->orWhere('from_name', 'like', "%{$search}%")
                  ->orWhere('agent_name', 'like', "%{$search}%")
                  ->orWhere('guest_name', 'like', "%{$search}%")
                  ->orWhere('tour_ref', 'like', "%{$search}%")
                  ->orWhere('file_handler', 'like', "%{$search}%");
            });
        }
        
        if ($request->filled('credit_type') && $request->credit_type != 'all') {
            $query->where('credit_type', $request->credit_type);
        }
        
        if ($request->filled('read_status') && $request->read_status != 'all') {
            $query->where('read_status', $request->read_status);
        }
        
        if ($request->filled('date_from')) {
            $query->whereDate('received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('received_at', '<=', $request->date_to);
        }
        
        $perPage = $request->get('per_page', 20);
        $emails = $query->paginate($perPage)->withQueryString();
        
        $stats = [
            'total' => IncomingEmail::count(),
            'credit' => IncomingEmail::where('credit_type', 'credit')->count(),
            'non_credit' => IncomingEmail::where('credit_type', 'non_credit')->count(),
            'invoices' => GeneratedInvoice::count(),
        ];
        
        return view('invoices.index', compact('emails', 'stats'));
    }
    
public function credit(Request $request)
{
    $query = GeneratedInvoice::with('email')->latest();
    
    // Apply filters
    if ($request->filled('search')) {
        $search = $request->search;
        $query->where(function($q) use ($search) {
            $q->where('invoice_number', 'like', "%{$search}%")
              ->orWhere('customer_name', 'like', "%{$search}%")
              ->orWhere('tour_ref', 'like', "%{$search}%");
        });
    }
    
    if ($request->filled('date_from')) {
        $query->whereDate('invoice_date', '>=', $request->date_from);
    }
    if ($request->filled('date_to')) {
        $query->whereDate('invoice_date', '<=', $request->date_to);
    }
    
    $invoices = $query->paginate(20)->withQueryString();
    
    return view('invoices.credit', compact('invoices'));
}

public function nonCredit(Request $request)
{
    $query = IncomingEmail::where('credit_type', 'non_credit')->latest('received_at');
    
    if ($request->filled('search')) {
        $search = $request->search;
        $query->where(function($q) use ($search) {
            $q->where('subject', 'like', "%{$search}%")
              ->orWhere('agent_name', 'like', "%{$search}%")
              ->orWhere('guest_name', 'like', "%{$search}%")
              ->orWhere('tour_ref', 'like', "%{$search}%");
        });
    }
    
    if ($request->filled('date_from')) {
        $query->whereDate('received_at', '>=', $request->date_from);
    }
    if ($request->filled('date_to')) {
        $query->whereDate('received_at', '<=', $request->date_to);
    }
    
    $perPage = $request->get('per_page', 20);
    $emails = $query->paginate($perPage)->withQueryString();
    
    $stats = [
        'total' => IncomingEmail::where('credit_type', 'non_credit')->count(),
        'credit' => IncomingEmail::where('credit_type', 'credit')->count(),
        'non_credit' => IncomingEmail::where('credit_type', 'non_credit')->count(),
        'invoices' => GeneratedInvoice::count(),
    ];
    
    return view('invoices.non-credit', compact('emails', 'stats'));
}
    
    public function processNow()
    {
        try {
            $service = new EmailFetchService();
            $count = $service->fetchAllEmails();
            $message = $count > 0 
                ? "✅ Successfully fetched and saved {$count} new email(s)!"
                : '📭 No new emails found in the mailbox.';
            return redirect()->back()->with('success', $message);
        } catch (\Exception $e) {
            \Log::error('Email processing failed: ' . $e->getMessage());
            return redirect()->back()->with('error', '❌ Failed to fetch emails: ' . $e->getMessage());
        }
    }
    
public function viewInvoice($id)
{
    $invoice = GeneratedInvoice::findOrFail($id);
    
    // ✅ Check if file_path exists
    if (!$invoice->file_path) {
        return redirect()->back()->with('error', 'Invoice file path not found for: ' . $invoice->invoice_number);
    }
    
    $path = storage_path("app/public/{$invoice->file_path}");
    
    // ✅ Log the path for debugging
    Log::info("Looking for invoice file: {$path}");
    
    if (file_exists($path)) {
        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $invoice->invoice_number . '.pdf"'
        ]);
    }
    
    // ✅ If file doesn't exist, try to regenerate it
    Log::warning("Invoice file not found: {$path}, attempting to regenerate");
    
    // Try to find the email and regenerate
    $email = \App\Models\IncomingEmail::find($invoice->email_id);
    if ($email) {
        try {
            // Regenerate the invoice PDF
            $invoiceService = new InvoiceGenerationService();
            $classification = (new AgentClassificationService())->classify(
                $email->body ?? '', 
                $email->from_email ?? '', 
                $email->subject ?? '', 
                $email->agent_name
            );
            
            // Generate PDF
            $html = $invoiceService->generateAppleHolidaysInvoiceHTML($invoice, $email);
            $pdf = Pdf::loadHTML($html);
            $filename = "invoices/{$invoice->invoice_number}.pdf";
            
            // Create directory if needed
            $directory = storage_path('app/public/invoices');
            if (!File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }
            
            $pdf->save(storage_path("app/public/{$filename}"));
            $invoice->file_path = $filename;
            $invoice->save();
            
            Log::info("✅ Regenerated invoice: {$filename}");
            
            // Now serve the file
            return response()->file(storage_path("app/public/{$filename}"), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $invoice->invoice_number . '.pdf"'
            ]);
            
        } catch (\Exception $e) {
            Log::error("Failed to regenerate invoice: " . $e->getMessage());
            return redirect()->back()->with('error', 'Invoice file not found and could not be regenerated.');
        }
    }
    
    return redirect()->back()->with('error', 'Invoice file not found for: ' . $invoice->invoice_number);
}
    
    public function downloadInvoice($id)
    {
        $invoice = GeneratedInvoice::findOrFail($id);
        $path = storage_path("app/public/{$invoice->file_path}");
        
        if (file_exists($path)) {
            return response()->download($path, "{$invoice->invoice_number}.pdf");
        }
        
        return redirect()->back()->with('error', 'Invoice file not found');
    }
    
 public function generateAndViewInvoice(Request $request)
    {
        $request->validate([
            'email_id' => 'required|exists:incoming_emails,id',
        ]);

        $email = IncomingEmail::findOrFail($request->email_id);
        
        try {
            // Check if any invoice exists with this invoice_number or tour_ref
            $existingInvoice = GeneratedInvoice::where(function($query) use ($email) {
                $query->where('invoice_number', 'LIKE', $email->invoice_number . '%')
                      ->orWhere('tour_ref', $email->tour_ref)
                      ->orWhere('original_invoice_number', $email->invoice_number);
            })->orderBy('revision_number', 'desc')->first();
            
            $invoiceService = new InvoiceGenerationService();
            
            if ($existingInvoice) {
                // Get next revision number
                $nextRevisionNumber = $existingInvoice->revision_number + 1;
                $baseNumber = $email->invoice_number;
                $newInvoiceNumber = $baseNumber . 'R' . $nextRevisionNumber;
                
                // Get classification
                $agentClassifier = new \App\Services\AgentClassificationService();
                $classification = $agentClassifier->classify(
                    $email->body ?? '', 
                    $email->from_email ?? '', 
                    $email->subject ?? '', 
                    $email->agent_name
                );
                
                // Create NEW revision invoice
                $invoice = $invoiceService->generateRevisionFromEmail(
                    $email, 
                    $classification, 
                    $newInvoiceNumber, 
                    $nextRevisionNumber,
                    $baseNumber
                );
                $message = '✅ Revision R' . $nextRevisionNumber . ' created!';
            } else {
                // Create new invoice
                $invoice = $invoiceService->generateFromEmail($email);
                $message = '✅ Invoice generated successfully!';
            }
            
            // ✅✅✅ SEND EMAIL - PLACE THIS HERE ✅✅✅
            if ($invoice) {
                try {
                    // Determine email type
                    $emailType = 'credit';
                    if ($invoice->is_revision) {
                        $emailType = 'revision';
                    } elseif ($invoice->invoice_type == 'non_credit') {
                        $emailType = 'non_credit';
                    }
                    
                    // Send email with attachment
                   
                Mail::to('kevinraj@aahaas.com')
                    ->cc('raja.lakshmi@aahaas.com')
                    ->send(new InvoiceMail($invoice, $emailType));
                
                Log::info("📧 Invoice email sent for: " . $invoice->invoice_number);
                
                } catch (\Exception $e) {
                    Log::error('❌ Email send failed: ' . $e->getMessage());
                }
            }
            // ✅✅✅ END OF EMAIL SEND ✅✅✅
            
            $email->update(['processing_status' => 'invoice_generated']);
            
            return response()->json([
                'success' => true,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'revision_number' => $invoice->revision_number,
                'message' => $message
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Invoice generation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '❌ Failed to generate invoice: ' . $e->getMessage()
            ], 500);
        }
    }

   public function getInvoiceDetails(Request $request)
{
    $request->validate([
        'email_id' => 'required|exists:incoming_emails,id'
    ]);
    
    $invoice = GeneratedInvoice::where('email_id', $request->email_id)->first();
    
    if (!$invoice) {
        return response()->json([
            'success' => false,
            'message' => 'No invoice found for this email'
        ]);
    }
    
    return response()->json([
        'success' => true,
        'gst_number' => $invoice->gst_number,
        'sales_person' => $invoice->sales_person
    ]);
} 
public function regenerateInvoice(Request $request)
{
    $request->validate([
        'email_id' => 'required|exists:incoming_emails,id',
    ]);
    
    $email = IncomingEmail::findOrFail($request->email_id);
    
    try {
        // Find the latest invoice with this invoice_number or tour_ref
        $latestInvoice = GeneratedInvoice::where(function($query) use ($email) {
            $query->where('invoice_number', 'LIKE', $email->invoice_number . '%')
                  ->orWhere('tour_ref', $email->tour_ref)
                  ->orWhere('original_invoice_number', $email->invoice_number);
        })->orderBy('revision_number', 'desc')->first();
        
        // Calculate next revision number
        $nextRevisionNumber = $latestInvoice ? ($latestInvoice->revision_number + 1) : 1;
        
        // Generate new invoice number with revision
        $baseNumber = $email->invoice_number;
        $newInvoiceNumber = $baseNumber . 'R' . $nextRevisionNumber;
        
        // Get classification
        $agentClassifier = new \App\Services\AgentClassificationService();
        $classification = $agentClassifier->classify(
            $email->body ?? '', 
            $email->from_email ?? '', 
            $email->subject ?? '', 
            $email->agent_name
        );
        
        // Create NEW invoice record (NOT update existing)
        $invoiceService = new InvoiceGenerationService();
        $invoice = $invoiceService->generateRevisionFromEmail(
            $email, 
            $classification, 
            $newInvoiceNumber, 
            $nextRevisionNumber,
            $baseNumber
        );
        
        $email->update(['processing_status' => 'invoice_generated']);
        
        return response()->json([
            'success' => true,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'revision_number' => $invoice->revision_number,
            'message' => '✅ Revision R' . $nextRevisionNumber . ' created!'
        ]);
    } catch (\Exception $e) {
        \Log::error('Invoice processing failed: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => '❌ Failed: ' . $e->getMessage()
        ], 500);
    }
}
    
    public function viewEmail(Request $request)
    {
        $email = IncomingEmail::find($request->id);
        if (!$email) {
            return response()->json(['success' => false, 'message' => 'Email not found']);
        }
        
        return response()->json([
            'success' => true,
            'email' => [
                'id' => $email->id,
                'from_name' => $email->from_name,
                'from_email' => $email->from_email,
                'subject' => $email->subject,
                'body' => $email->body,
                'received_at' => $email->received_at->format('d/m/Y H:i:s'),
                'agent_name' => $email->agent_name,
                'tour_ref' => $email->tour_ref,
            ]
        ]);
    }

    // Add this method to InvoiceController.php

public function generateRevisedInvoice(Request $request)
{
    $request->validate([
        'email_id' => 'required|exists:incoming_emails,id',
        'revision_number' => 'nullable|integer|min:1'
    ]);
    
    $email = IncomingEmail::findOrFail($request->email_id);
    
    try {
        $existingInvoice = GeneratedInvoice::where('email_id', $email->id)->first();
        $revisionNumber = $request->revision_number;
        
        $invoiceService = new InvoiceGenerationService();
        
        if ($existingInvoice) {
            // Regenerate with revision
            $invoice = $invoiceService->regenerateInvoice($email, $existingInvoice, $revisionNumber);
            $message = '✅ Invoice regenerated as REVISION ' . ($invoice->revision_number) . ' (' . $invoice->invoice_number . ')';
        } else {
            // Generate new invoice
            $invoice = $invoiceService->generateFromEmail($email, null, $revisionNumber);
            $message = '✅ Invoice generated successfully!';
        }
        
        $email->update(['processing_status' => 'invoice_generated']);
        
        return response()->json([
            'success' => true,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'revision_number' => $invoice->revision_number,
            'message' => $message
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Invoice generation failed: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => '❌ Failed to generate invoice: ' . $e->getMessage()
        ], 500);
    }
}

public function generateAndView(Request $request)
{
    $request->validate([
        'email_id' => 'required|exists:emails,id',
        'gst_number' => 'nullable|string|max:100',
        'sales_person' => 'nullable|string|max:255',
    ]);

    $email = Email::findOrFail($request->email_id);
    $service = new InvoiceGenerationService();
    $invoice = $service->generateFromEmail(
        $email,
        null,
        null,
        $request->gst_number,
        $request->sales_person
    );

    return response()->json(['success' => true, 'invoice_id' => $invoice->id]);
}

public function checkInvoiceExists(Request $request)
{
    $request->validate([
        'email_id' => 'required|exists:incoming_emails,id'
    ]);
    
    $email = IncomingEmail::findOrFail($request->email_id);
    
    // Check by invoice_number OR tour_ref
    $invoice = GeneratedInvoice::where(function($query) use ($email) {
        $query->where('invoice_number', 'LIKE', $email->invoice_number . '%')
              ->orWhere('tour_ref', $email->tour_ref)
              ->orWhere('original_invoice_number', $email->invoice_number);
    })->first();
    
    return response()->json([
        'exists' => $invoice !== null,
        'invoice_id' => $invoice ? $invoice->id : null,
        'revision_number' => $invoice ? $invoice->revision_number : 0,
        'invoice_number' => $invoice ? $invoice->invoice_number : null,
        'tour_ref' => $invoice ? $invoice->tour_ref : null
    ]);
}
public function checkInvoiceByNumber(Request $request)
{
    $request->validate([
        'invoice_number' => 'required|string',
        'tour_ref' => 'nullable|string'
    ]);
    
    $invoiceNumber = $request->invoice_number;
    $tourRef = $request->tour_ref;
    
    // Check by invoice_number OR tour_ref
    $invoice = GeneratedInvoice::where(function($query) use ($invoiceNumber, $tourRef) {
        $query->where('invoice_number', 'LIKE', $invoiceNumber . '%')
              ->orWhere('original_invoice_number', $invoiceNumber);
        
        if ($tourRef) {
            $query->orWhere('tour_ref', $tourRef);
        }
    })->first();
    
    return response()->json([
        'exists' => $invoice !== null,
        'invoice_id' => $invoice ? $invoice->id : null,
        'revision_number' => $invoice ? $invoice->revision_number : 0,
        'invoice_number' => $invoice ? $invoice->invoice_number : null
    ]);
}
public function getEmailInvoiceNumber(Request $request)
{
    $request->validate([
        'email_id' => 'required|exists:incoming_emails,id'
    ]);
    
    $email = IncomingEmail::findOrFail($request->email_id);
    
    return response()->json([
        'success' => true,
        'invoice_number' => $email->invoice_number,
        'tour_ref' => $email->tour_ref
    ]);
}
}