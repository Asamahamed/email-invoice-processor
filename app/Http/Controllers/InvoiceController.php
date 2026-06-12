<?php

namespace App\Http\Controllers;

use App\Models\IncomingEmail;
use App\Models\GeneratedInvoice;
use App\Services\EmailFetchService;
use App\Services\InvoiceGenerationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = IncomingEmail::with('invoice')->latest('received_at');
        
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
    
    public function nonCredit(Request $request)
    {
        $query = IncomingEmail::with('invoice')->where('credit_type', 'non_credit')->latest('received_at');
        
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
        
        $emails = $query->paginate(20)->withQueryString();
        
        $stats = [
            'total' => IncomingEmail::where('credit_type', 'non_credit')->count(),
            'credit' => IncomingEmail::where('credit_type', 'credit')->count(),
            'non_credit' => IncomingEmail::where('credit_type', 'non_credit')->count(),
            'invoices' => GeneratedInvoice::count(),
        ];
        
        return view('invoices.non-credit', compact('emails', 'stats'));
    }
    
    public function credit(Request $request)
    {
        $query = GeneratedInvoice::with('email')->latest();
        
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
        
        $stats = [
            'total' => GeneratedInvoice::count(),
            'credit_total' => GeneratedInvoice::where('invoice_type', 'credit')->sum('grand_total'),
            'non_credit_total' => GeneratedInvoice::where('invoice_type', 'non_credit')->sum('grand_total'),
        ];
        
        return view('invoices.credit', compact('invoices', 'stats'));
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
        $path = storage_path("app/public/{$invoice->file_path}");
        
        if (file_exists($path)) {
            return response()->file($path, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $invoice->invoice_number . '.pdf"'
            ]);
        }
        
        return redirect()->back()->with('error', 'Invoice file not found');
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
    $request->validate(['email_id' => 'required|exists:incoming_emails,id']);
    $email = IncomingEmail::findOrFail($request->email_id);
    
    try {
        // REMOVED: Both duplicate checks - Allow multiple invoices from same email
        
        $invoiceService = new InvoiceGenerationService();
        $invoice = $invoiceService->generateFromEmail($email);
        $email->update(['processing_status' => 'invoice_generated']);
        
        return response()->json([
            'success' => true,
            'invoice_id' => $invoice->id,
            'message' => '✅ Invoice generated successfully!'
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Invoice generation failed: ' . $e->getMessage());
        
        if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), '1062')) {
            return response()->json([
                'success' => false,
                'message' => '❌ Database duplicate error. Please check manually.'
            ], 409);
        }
        
        return response()->json([
            'success' => false,
            'message' => '❌ Failed to generate invoice: ' . $e->getMessage()
        ], 500);
    }
}
    
public function regenerateInvoice(Request $request)
{
    $request->validate(['email_id' => 'required|exists:incoming_emails,id']);
    $email = IncomingEmail::findOrFail($request->email_id);
    
    try {
        $existingInvoice = GeneratedInvoice::where('email_id', $email->id)->first();
        
        if (!$existingInvoice) {
            // If no invoice exists, just generate a new one
            $invoiceService = new InvoiceGenerationService();
            $invoice = $invoiceService->generateFromEmail($email);
            $email->update(['processing_status' => 'invoice_generated']);
            
            return response()->json([
                'success' => true,
                'invoice_id' => $invoice->id,
                'message' => '✅ Invoice generated successfully!'
            ]);
        }
        
        // Delete old PDF
        $oldPath = storage_path("app/public/{$existingInvoice->file_path}");
        if (file_exists($oldPath)) {
            unlink($oldPath);
        }
        
        // Regenerate
        $invoiceService = new InvoiceGenerationService();
        $invoice = $invoiceService->regenerateInvoice($email, $existingInvoice);
        $email->update(['processing_status' => 'invoice_generated']);
        
        return response()->json([
            'success' => true,
            'invoice_id' => $invoice->id,
            'message' => '✅ Invoice regenerated successfully!'
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Invoice regeneration failed: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => '❌ Failed to regenerate invoice: ' . $e->getMessage()
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
}