<?php

namespace App\Http\Controllers;

use App\Models\GeneratedInvoice;
use App\Models\IncomingEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportController extends Controller
{
    public function index()
    {
        return view('reports.index');
    }

    public function monthWise(Request $request)
    {
        $month = $request->month ?? date('m');
        $year = $request->year ?? date('Y');
        
        // Get all months for filter dropdown
        $months = GeneratedInvoice::select(
            DB::raw('YEAR(invoice_date) as year'),
            DB::raw('MONTH(invoice_date) as month'),
            DB::raw('COUNT(*) as count')
        )
        ->groupBy('year', 'month')
        ->orderBy('year', 'desc')
        ->orderBy('month', 'desc')
        ->get();
        
        // ========== FIX: Get ONLY LATEST revisions ==========
        // Get all invoices for the month
        $allInvoices = GeneratedInvoice::with('email')
            ->whereYear('invoice_date', $year)
            ->whereMonth('invoice_date', $month)
            ->orderBy('invoice_date', 'asc')
            ->get();
        
        // Filter to keep only the latest revision for each original invoice
        $latestInvoices = $this->getLatestRevisions($allInvoices);
        
        // Prepare report data
        $reportData = [];
        foreach ($latestInvoices as $invoice) {
            $reportData[] = [
                'month' => date('M-y', strtotime($invoice->invoice_date)),
                'date' => date('d/m/Y', strtotime($invoice->invoice_date)),
                'invoice_number' => $invoice->invoice_number,
                'tour_ref' => $invoice->tour_ref,
                'agent_name' => $invoice->customer_name,
                'guest_name' => $invoice->guest_name,
                'amount' => $invoice->grand_total,
                'currency' => $invoice->currency,
                'file_handler' => $invoice->email->file_handler ?? 'NA',
                'tour_start_date' => $invoice->email->travel_start_date ? date('d/m/Y', strtotime($invoice->email->travel_start_date)) : 'NA',
                'travel_date' => $this->getTravelDates($invoice->email),
                'sales_person' => $invoice->sales_person ?? 'NA',
                'gst_no' => $invoice->gst_number ?? 'NA',
                'revision_number' => $invoice->revision_number ?? 0,
                'is_revision' => $invoice->is_revision ?? false,
            ];
        }
        
        // Summary statistics
        $summary = [
            'total_invoices' => $latestInvoices->count(),
            'total_amount' => $latestInvoices->sum('grand_total'),
            'currency' => $latestInvoices->first() ? $latestInvoices->first()->currency : 'USD',
            'month_name' => date('F Y', strtotime("$year-$month-01")),
            'year' => $year,
            'month' => $month,
        ];
        
        return view('reports.month-wise', compact('reportData', 'summary', 'months', 'month', 'year'));
    }

    public function dateWise(Request $request)
    {
        $startDate = $request->start_date ?? date('Y-m-01');
        $endDate = $request->end_date ?? date('Y-m-t');
        
        // Get all invoices within date range
        $allInvoices = GeneratedInvoice::with('email')
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->orderBy('invoice_date', 'asc')
            ->get();
        
        // Filter to keep only the latest revision for each original invoice
        $latestInvoices = $this->getLatestRevisions($allInvoices);
        
        $reportData = [];
        foreach ($latestInvoices as $invoice) {
            $reportData[] = [
                'month' => date('M-y', strtotime($invoice->invoice_date)),
                'date' => date('d/m/Y', strtotime($invoice->invoice_date)),
                'invoice_number' => $invoice->invoice_number,
                'tour_ref' => $invoice->tour_ref,
                'agent_name' => $invoice->customer_name,
                'guest_name' => $invoice->guest_name,
                'amount' => $invoice->grand_total,
                'currency' => $invoice->currency,
                'file_handler' => $invoice->email->file_handler ?? 'NA',
                'tour_start_date' => $invoice->email->travel_start_date ? date('d/m/Y', strtotime($invoice->email->travel_start_date)) : 'NA',
                'travel_date' => $this->getTravelDates($invoice->email),
                'sales_person' => $invoice->sales_person ?? 'NA',
                'gst_no' => $invoice->gst_number ?? 'NA',
                'revision_number' => $invoice->revision_number ?? 0,
                'is_revision' => $invoice->is_revision ?? false,
            ];
        }
        
        $summary = [
            'total_invoices' => $latestInvoices->count(),
            'total_amount' => $latestInvoices->sum('grand_total'),
            'currency' => $latestInvoices->first() ? $latestInvoices->first()->currency : 'USD',
            'start_date' => date('d/m/Y', strtotime($startDate)),
            'end_date' => date('d/m/Y', strtotime($endDate)),
        ];
        
        return view('reports.date-wise', compact('reportData', 'summary', 'startDate', 'endDate'));
    }

    /**
     * Get only the latest revision for each invoice
     * Groups by original_invoice_number and keeps the one with highest revision_number
     */
    protected function getLatestRevisions($invoices)
    {
        $grouped = [];
        
        foreach ($invoices as $invoice) {
            // Determine the key for grouping
            // If it has original_invoice_number, use that
            // Otherwise use invoice_number without revision suffix
            if ($invoice->original_invoice_number) {
                $key = $invoice->original_invoice_number;
            } else {
                // Remove revision suffix (R1, R2, etc.)
                $key = preg_replace('/R\d+$/i', '', $invoice->invoice_number);
            }
            
            // If this key doesn't exist yet, or this invoice has higher revision number
            if (!isset($grouped[$key]) || $invoice->revision_number > $grouped[$key]->revision_number) {
                $grouped[$key] = $invoice;
            }
        }
        
        return collect(array_values($grouped));
    }

    public function exportMonthWise(Request $request)
    {
        $month = $request->month ?? date('m');
        $year = $request->year ?? date('Y');
        
        $allInvoices = GeneratedInvoice::with('email')
            ->whereYear('invoice_date', $year)
            ->whereMonth('invoice_date', $month)
            ->orderBy('invoice_date', 'asc')
            ->get();
        
        $latestInvoices = $this->getLatestRevisions($allInvoices);
        
        return $this->exportExcel($latestInvoices, 'Month_Wise_Report_' . date('M_Y', strtotime("$year-$month-01")));
    }

    public function exportDateWise(Request $request)
    {
        $startDate = $request->start_date ?? date('Y-m-01');
        $endDate = $request->end_date ?? date('Y-m-t');
        
        $allInvoices = GeneratedInvoice::with('email')
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->orderBy('invoice_date', 'asc')
            ->get();
        
        $latestInvoices = $this->getLatestRevisions($allInvoices);
        
        return $this->exportExcel($latestInvoices, 'Date_Wise_Report_' . date('d_m_Y', strtotime($startDate)) . '_to_' . date('d_m_Y', strtotime($endDate)));
    }

    protected function exportExcel($invoices, $filename)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Headers
        $headers = [
            'Month', 'Date', 'Invoice #', 'CNTL', 'Agent Name', 'Guest Name',
            'Amount', 'Currency', 'File Handler', 'Tour Start Date',
            'Travel Date', 'Sales Person', 'GST No', 'Revision'
        ];
        
        // Set headers
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $col++;
        }
        
        // Data rows
        $row = 2;
        foreach ($invoices as $invoice) {
            $col = 'A';
            $sheet->setCellValue($col++ . $row, date('M-y', strtotime($invoice->invoice_date)));
            $sheet->setCellValue($col++ . $row, date('d/m/Y', strtotime($invoice->invoice_date)));
            $sheet->setCellValue($col++ . $row, $invoice->invoice_number);
            $sheet->setCellValue($col++ . $row, $invoice->tour_ref);
            $sheet->setCellValue($col++ . $row, $invoice->customer_name);
            $sheet->setCellValue($col++ . $row, $invoice->guest_name);
            $sheet->setCellValue($col++ . $row, $invoice->grand_total);
            $sheet->setCellValue($col++ . $row, $invoice->currency);
            $sheet->setCellValue($col++ . $row, $invoice->email->file_handler ?? 'NA');
            $sheet->setCellValue($col++ . $row, $invoice->email->travel_start_date ? date('d/m/Y', strtotime($invoice->email->travel_start_date)) : 'NA');
            $sheet->setCellValue($col++ . $row, $this->getTravelDates($invoice->email));
            $sheet->setCellValue($col++ . $row, $invoice->sales_person ?? 'NA');
            $sheet->setCellValue($col++ . $row, $invoice->gst_number ?? 'NA');
            $sheet->setCellValue($col++ . $row, $invoice->is_revision ? 'R' . $invoice->revision_number : 'Original');
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'report_');
        $writer->save($tempFile);
        
        return response()->download($tempFile, $filename . '.xlsx')->deleteFileAfterSend(true);
    }

    protected function getTravelDates($email)
    {
        if (!$email) return 'NA';
        
        $start = $email->travel_start_date ? date('d/m/Y', strtotime($email->travel_start_date)) : '';
        $end = $email->travel_end_date ? date('d/m/Y', strtotime($email->travel_end_date)) : '';
        
        if ($start && $end) {
            return $start . ' - ' . $end;
        } elseif ($start) {
            return $start;
        }
        return 'NA';
    }
}