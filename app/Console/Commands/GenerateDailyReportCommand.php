<?php

namespace App\Console\Commands;

use App\Models\GeneratedInvoice;
use App\Models\IncomingEmail;
use App\Services\MicrosoftGraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use GuzzleHttp\Client as GuzzleClient;


class GenerateDailyReportCommand extends Command
{
    protected $signature = 'report:daily {--date=} {--upload}';
    protected $description = 'Generate daily invoice report and upload to OneDrive';

    public function handle()
    {
        $date = $this->option('date') ?? date('Y-m-d');
        $upload = $this->option('upload') ?? true;
        
        $this->info("📊 Generating daily report for: {$date}");
        
        try {
            $filePath = $this->generateReport($date);
            
            if (!$filePath) {
                $this->error('❌ Failed to generate report');
                return 1;
            }
            
            $this->info("✅ Report generated: {$filePath}");
            
            if ($upload) {
                $this->uploadToOneDrive($filePath, $date);
            }
            
            Log::info("✅ Daily report generated for {$date}: {$filePath}");
            return 0;
            
        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            Log::error('Daily report failed: ' . $e->getMessage());
            return 1;
        }
    }

    // ✅ Helper to sanitize strings
    protected function sanitizeString($string)
    {
        if (is_null($string)) {
            return '';
        }
        
        if (!mb_check_encoding($string, 'UTF-8')) {
            $string = mb_convert_encoding($string, 'UTF-8', 'auto');
        }
        
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);
        return trim($string);
    }

    protected function generateReport($date)
    {
        $invoices = GeneratedInvoice::with('email')
            ->whereDate('created_at', $date)
            ->orderBy('created_at', 'asc')
            ->get();
        
        if ($invoices->isEmpty()) {
            $this->info("📭 No invoices found for {$date}");
            return $this->generateEmptyReport($date);
        }
        
        $latestInvoices = $this->getLatestRevisions($invoices);
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // ✅ ADDED: Agent ID column after Agent Name
        $headers = [
            'S.No', 
            'Invoice #', 
            'Tour Ref', 
            'Agent Name', 
            'Agent ID',      // ← NEW COLUMN
            'Guest Name',
            'Amount', 
            'Currency', 
            'File Handler', 
            'Tour Start Date',
            'Travel Date', 
            'Sales Person', 
            'GST No', 
            'Revision', 
            'Created At'
        ];
        
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $this->sanitizeString($header));
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $col++;
        }
        
        $row = 2;
        $sno = 1;
        $totalAmount = 0;
        
        foreach ($latestInvoices as $invoice) {
            $col = 'A';
            $sheet->setCellValue($col++ . $row, $sno++);
            $sheet->setCellValue($col++ . $row, $this->sanitizeString($invoice->invoice_number));
            $sheet->setCellValue($col++ . $row, $this->sanitizeString($invoice->tour_ref));
            $sheet->setCellValue($col++ . $row, $this->sanitizeString($invoice->customer_name));
            
            // ✅ ADDED: Agent ID from email reference_no
            $agentId = $invoice->email->reference_no ?? 'NA';
            $sheet->setCellValue($col++ . $row, $this->sanitizeString($agentId));
            
            $sheet->setCellValue($col++ . $row, $this->sanitizeString($invoice->guest_name));
            $sheet->setCellValue($col++ . $row, $invoice->grand_total);
            $sheet->setCellValue($col++ . $row, $this->sanitizeString($invoice->currency));
            $sheet->setCellValue($col++ . $row, $this->sanitizeString($invoice->email->file_handler ?? 'NA'));
            $sheet->setCellValue($col++ . $row, $invoice->email->travel_start_date ? date('d/m/Y', strtotime($invoice->email->travel_start_date)) : 'NA');
            $sheet->setCellValue($col++ . $row, $this->sanitizeString($this->getTravelDates($invoice->email)));
            $sheet->setCellValue($col++ . $row, $this->sanitizeString($invoice->sales_person ?? 'NA'));
            $sheet->setCellValue($col++ . $row, $this->sanitizeString($invoice->gst_number ?? 'NA'));
            $sheet->setCellValue($col++ . $row, $invoice->is_revision ? 'R' . $invoice->revision_number : 'Original');
            $sheet->setCellValue($col++ . $row, $invoice->created_at->format('d/m/Y H:i'));
            
            $totalAmount += $invoice->grand_total;
            $row++;
        }
        
        $row++;
        $sheet->setCellValue('A' . $row, 'TOTAL');
        // ✅ FIX: Column for Total is now 'G' (because we added Agent ID column)
        $sheet->setCellValue('G' . $row, $totalAmount);
        $sheet->getStyle('A' . $row . ':O' . $row)->getFont()->setBold(true); // Updated to O (15 columns)
        
        // ✅ UPDATE: Auto-size all columns (A to O)
        foreach (range('A', 'O') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $filename = 'daily_invoice_report_' . date('Y-m-d', strtotime($date)) . '.xlsx';
        $directory = storage_path('app/public/reports/daily');
        
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $filePath = $directory . '/' . $filename;
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($filePath);
        
        return $filePath;
    }

    protected function generateEmptyReport($date)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $sheet->setCellValue('A1', 'No invoices generated on ' . date('d/m/Y', strtotime($date)));
        $sheet->getStyle('A1')->getFont()->setBold(true);
        
        $filename = 'daily_invoice_report_' . date('Y-m-d', strtotime($date)) . '.xlsx';
        $directory = storage_path('app/public/reports/daily');
        
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $filePath = $directory . '/' . $filename;
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
        
        return $filePath;
    }

    protected function getLatestRevisions($invoices)
    {
        $grouped = [];
        
        foreach ($invoices as $invoice) {
            $key = $this->getInvoiceBaseNumber($invoice);
            
            if (!isset($grouped[$key]) || $invoice->revision_number > $grouped[$key]->revision_number) {
                $grouped[$key] = $invoice;
            }
        }
        
        return collect(array_values($grouped));
    }

    protected function getInvoiceBaseNumber($invoice)
    {
        if ($invoice->original_invoice_number) {
            return $invoice->original_invoice_number;
        }
        
        $base = $invoice->invoice_number;
        $base = preg_replace('/_R\d+\/R\d+$/', '', $base);
        $base = preg_replace('/R\d+$/', '', $base);
        $base = preg_replace('/_R\d+_R\d+$/', '', $base);
        
        return $base;
    }

    protected function getTravelDates($email)
    {
        if (!$email) return 'NA';
        
        $start = $email->travel_start_date ? date('d/m/Y', strtotime($email->travel_start_date)) : '';
        $end = $email->travel_end_date ? date('d/m/Y', strtotime($email->travel_end_date)) : '';
        
        if ($start && $end && $start !== $end) {
            return $start . ' - ' . $end;
        }
        return $start ?: 'NA';
    }

    protected function uploadToOneDrive($filePath, $date)
    {
        try {
            $filename = basename($filePath);
            $folderPath = env('ONEDRIVE_FOLDER_PATH', '/Invoice Report/');
            $userEmail = env('ONEDRIVE_USER', env('GRAPH_INVOICE_USER'));
            
            $this->info("📤 Uploading to OneDrive: {$folderPath}{$filename}");
            $this->info("📧 Using account: {$userEmail}");
            
            if (!file_exists($filePath) || !is_readable($filePath)) {
                $this->error("❌ File not found or not readable: {$filePath}");
                return;
            }
            
            $content = file_get_contents($filePath);
            
            if (empty($content)) {
                $this->error("❌ File is empty: {$filePath}");
                return;
            }
            
            $this->info("📄 File size: " . number_format(strlen($content)) . " bytes");
            
            $graphService = new MicrosoftGraphService();
            
            // Get token via reflection
            $reflection = new \ReflectionProperty($graphService, 'accessToken');
            $reflection->setAccessible(true);
            $token = $reflection->getValue($graphService);
            
            if (empty($token)) {
                $this->error("❌ No access token available.");
                Log::error("OneDrive upload failed: No access token");
                return;
            }
            
            $encodedFolder = str_replace(' ', '%20', $folderPath);
            $uploadUrl = "https://graph.microsoft.com/v1.0/users/{$userEmail}/drive/root:{$encodedFolder}{$filename}:/content";
            
            $this->info("📡 Uploading to: " . str_replace($userEmail, '***', $uploadUrl));
            
            // ✅ Fix: Use Guzzle directly with proper binary body
            $client = new \GuzzleHttp\Client([
                'timeout' => 120,
                'verify' => false,
            ]);
            
            $response = $client->put($uploadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ],
                'body' => $content,
            ]);
            
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->info("✅ Uploaded to OneDrive: {$folderPath}{$filename}");
                Log::info("Uploaded daily report to OneDrive: {$folderPath}{$filename}");
                
                $responseBody = $response->getBody()->getContents();
                $fileData = json_decode($responseBody, true);
                if (isset($fileData['webUrl'])) {
                    $this->info("🔗 File URL: " . $fileData['webUrl']);
                }
            } else {
                $this->error("❌ Upload failed: Status " . $response->getStatusCode());
                $this->error("❌ Response: " . $response->getBody()->getContents());
                Log::error("OneDrive upload failed: " . $response->getBody()->getContents());
                
                // Try alternative endpoint
                $this->info("🔄 Trying alternative endpoint...");
                $this->uploadToOneDriveAlternative($filePath, $date);
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Upload error: " . $e->getMessage());
            Log::error("OneDrive upload error: " . $e->getMessage());
        }
    }

    protected function uploadToOneDriveAlternative($filePath, $date)
    {
        try {
            $filename = basename($filePath);
            $folderPath = env('ONEDRIVE_FOLDER_PATH', '/Invoice Report/');
            
            $graphService = new MicrosoftGraphService();
            $reflection = new \ReflectionProperty($graphService, 'accessToken');
            $reflection->setAccessible(true);
            $token = $reflection->getValue($graphService);
            
            if (empty($token)) {
                $this->error("❌ No token for alternative upload");
                return;
            }
            
            $content = file_get_contents($filePath);
            $encodedFolder = str_replace(' ', '%20', $folderPath);
            $uploadUrl = "https://graph.microsoft.com/v1.0/me/drive/root:{$encodedFolder}{$filename}:/content";
            
            // ✅ Use Guzzle directly
            $client = new \GuzzleHttp\Client([
                'timeout' => 120,
                'verify' => false,
            ]);
            
            $response = $client->put($uploadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ],
                'body' => $content,
            ]);
            
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->info("✅ Uploaded to OneDrive (alternative): {$folderPath}{$filename}");
                Log::info("Uploaded daily report to OneDrive (alt): {$folderPath}{$filename}");
            } else {
                $this->error("❌ Alternative upload failed: " . $response->getBody()->getContents());
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Alternative upload error: " . $e->getMessage());
        }
    }
}