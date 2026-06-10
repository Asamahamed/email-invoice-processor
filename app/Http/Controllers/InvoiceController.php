<?php
// app/Http/Controllers/InvoiceController.php

namespace App\Http\Controllers;

use App\Models\IncomingEmail;
use App\Models\GeneratedInvoice;
use App\Services\EmailFetchService;
use App\Services\InvoiceGenerationService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = IncomingEmail::query();
        
        // Search functionality
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
        
        // Filter by credit type
        if ($request->filled('credit_type') && $request->credit_type != 'all') {
            $query->where('credit_type', $request->credit_type);
        }
        
        // Filter by read status
        if ($request->filled('read_status') && $request->read_status != 'all') {
            $query->where('read_status', $request->read_status);
        }
        
        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('received_at', '<=', $request->date_to);
        }
        
        // Sort
        $sortField = $request->get('sort', 'received_at');
        $sortDirection = $request->get('direction', 'desc');
        
        // Validate sort field to prevent SQL injection
        $allowedSortFields = ['id', 'received_at', 'total_amount', 'agent_name'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'received_at';
        }
        
        $query->orderBy($sortField, $sortDirection);
        
        // Pagination
        $perPage = $request->get('per_page', 20);
        $allowedPerPage = [10, 20, 50, 100];
        if (!in_array($perPage, $allowedPerPage)) {
            $perPage = 20;
        }
        
        $emails = $query->paginate($perPage)->withQueryString();
        
        // Get statistics
        $stats = [
            'total' => IncomingEmail::count(),
            'credit' => IncomingEmail::where('credit_type', 'credit')->count(),
            'non_credit' => IncomingEmail::where('credit_type', 'non_credit')->count(),
            'invoices' => GeneratedInvoice::count(),
        ];
        
        return view('invoices.index', compact('emails', 'stats'));
    }
    
    // NON-CREDIT: Show emails with search/filter
    public function nonCredit(Request $request)
    {
        $query = IncomingEmail::where('credit_type', 'non_credit');
        
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
        
        $emails = $query->orderBy('received_at', 'desc')->paginate(20)->withQueryString();
        
        $stats = [
            'total' => $query->count(),
            'credit' => IncomingEmail::where('credit_type', 'credit')->count(),
            'non_credit' => IncomingEmail::where('credit_type', 'non_credit')->count(),
            'invoices' => GeneratedInvoice::count(),
        ];
        
        return view('invoices.non-credit', compact('emails', 'stats'));
    }
    
    // CREDIT: Show generated invoices with search/filter
    public function credit(Request $request)
    {
        $query = GeneratedInvoice::with('email');
        
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
        
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        
        $allowedSortFields = ['id', 'invoice_number', 'invoice_date', 'grand_total', 'created_at'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'created_at';
        }
        
        $query->orderBy($sortField, $sortDirection);
        
        $perPage = $request->get('per_page', 20);
        $invoices = $query->paginate($perPage)->withQueryString();
        
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
            
            if ($count > 0) {
                return redirect()->back()->with('success', "✅ Successfully fetched and saved {$count} new email(s)!");
            } else {
                return redirect()->back()->with('info', '📭 No new emails found in the mailbox.');
            }
            
        } catch (\Exception $e) {
            \Log::error('Email processing failed: ' . $e->getMessage());
            return redirect()->back()->with('error', '❌ Failed to fetch emails: ' . $e->getMessage());
        }
    }
    
    public function downloadInvoice($id)
    {
        $invoice = GeneratedInvoice::findOrFail($id);
        
        $paths = [
            storage_path("app/public/{$invoice->file_path}"),
            storage_path("app/{$invoice->file_path}"),
            storage_path("app/public/invoices/{$invoice->invoice_number}.pdf")
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return response()->download($path, "{$invoice->invoice_number}.pdf");
            }
        }
        
        return redirect()->back()->with('error', 'Invoice file not found');
    }
    
    public function generateInvoice(Request $request)
    {
        $request->validate([
            'email_id' => 'required|exists:incoming_emails,id'
        ]);
        
        $email = IncomingEmail::findOrFail($request->email_id);
        
        try {
            $invoiceService = new InvoiceGenerationService();
            $invoice = $invoiceService->generateFromEmail($email);
            
            $email->processing_status = 'invoice_generated';
            $email->save();
            
            return redirect()->back()->with('success', "✅ Invoice {$invoice->invoice_number} generated successfully!");
            
        } catch (\Exception $e) {
            \Log::error('Invoice generation failed: ' . $e->getMessage());
            return redirect()->back()->with('error', '❌ Failed to generate invoice: ' . $e->getMessage());
        }
    }
    
    public function bulkGenerate(Request $request)
    {
        $request->validate([
            'email_ids' => 'required|array',
            'email_ids.*' => 'exists:incoming_emails,id'
        ]);
        
        $generated = 0;
        $failed = 0;
        
        foreach ($request->email_ids as $emailId) {
            $email = IncomingEmail::find($emailId);
            if ($email && $email->credit_type == 'non_credit') {
                try {
                    $invoiceService = new InvoiceGenerationService();
                    $invoice = $invoiceService->generateFromEmail($email);
                    $email->processing_status = 'invoice_generated';
                    $email->save();
                    $generated++;
                } catch (\Exception $e) {
                    $failed++;
                }
            } else {
                $failed++;
            }
        }
        
        return redirect()->back()->with('success', "✅ Generated {$generated} invoices. Failed: {$failed}");
    }

    /**
 * View full email content
 */
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
            'received_at' => $email->received_at ? $email->received_at->format('d/m/Y H:i:s') : null,
            'agent_name' => $email->agent_name,
            'tour_ref' => $email->tour_ref,
        ]
    ]);
}
}