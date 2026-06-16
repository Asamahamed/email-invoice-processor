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
        
        // Get invoices for selected month/year
        $invoices = GeneratedInvoice::with('email')
            ->whereYear('invoice_date', $year)
            ->whereMonth('invoice_date', $month)
            ->orderBy('invoice_date', 'asc')
            ->get();
        
        // Prepare report data
        $reportData = [];
        foreach ($invoices as $invoice) {
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
            ];
        }
        
        // Summary statistics
        $summary = [
            'total_invoices' => $invoices->count(),
            'total_amount' => $invoices->sum('grand_total'),
            'currency' => $invoices->first() ? $invoices->first()->currency : 'USD',
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
        
        $invoices = GeneratedInvoice::with('email')
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->orderBy('invoice_date', 'asc')
            ->get();
        
        $reportData = [];
        foreach ($invoices as $invoice) {
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
            ];
        }
        
        $summary = [
            'total_invoices' => $invoices->count(),
            'total_amount' => $invoices->sum('grand_total'),
            'currency' => $invoices->first() ? $invoices->first()->currency : 'USD',
            'start_date' => date('d/m/Y', strtotime($startDate)),
            'end_date' => date('d/m/Y', strtotime($endDate)),
        ];
        
        return view('reports.date-wise', compact('reportData', 'summary', 'startDate', 'endDate'));
    }

    public function exportMonthWise(Request $request)
    {
        $month = $request->month ?? date('m');
        $year = $request->year ?? date('Y');
        
        $invoices = GeneratedInvoice::with('email')
            ->whereYear('invoice_date', $year)
            ->whereMonth('invoice_date', $month)
            ->orderBy('invoice_date', 'asc')
            ->get();
        
        return $this->exportExcel($invoices, 'Month_Wise_Report_' . date('M_Y', strtotime("$year-$month-01")));
    }

    public function exportDateWise(Request $request)
    {
        $startDate = $request->start_date ?? date('Y-m-01');
        $endDate = $request->end_date ?? date('Y-m-t');
        
        $invoices = GeneratedInvoice::with('email')
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->orderBy('invoice_date', 'asc')
            ->get();
        
        return $this->exportExcel($invoices, 'Date_Wise_Report_' . date('d_m_Y', strtotime($startDate)) . '_to_' . date('d_m_Y', strtotime($endDate)));
    }

    protected function exportExcel($invoices, $filename)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Headers
        $headers = [
            'Month', 'Date', 'Invoice #', 'CNTL', 'Agent Name', 'Guest Name',
            'Amount', 'Currency', 'File Handler', 'Tour Start Date',
            'Travel Date', 'Sales Person', 'GST No'
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
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', 'M') as $col) {
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