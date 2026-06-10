<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
    protected $service;
    protected $spreadsheetId;

    public function __construct()
    {
        $this->initialize();
    }

    protected function initialize()
    {
        try {
            $client = new Client();
            $client->setApplicationName('PnL Sheet Updater');
            $client->setScopes([Sheets::SPREADSHEETS]);
            
            // Path to your service account JSON file
            $credentialsPath = storage_path('app/google/service-account-credentials.json');
            
            if (!file_exists($credentialsPath)) {
                throw new \Exception('Google credentials file not found at: ' . $credentialsPath);
            }
            
            $client->setAuthConfig($credentialsPath);
            $client->setAccessType('offline');

            $this->service = new Sheets($client);
            $this->spreadsheetId = env('GOOGLE_SHEETS_ID');
            
            if (empty($this->spreadsheetId)) {
                throw new \Exception('GOOGLE_SHEETS_ID not set in .env file');
            }
            
            Log::info('Google Sheets service initialized successfully');
        } catch (\Exception $e) {
            Log::error('Google Sheets initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Append rows to Google Sheet
     */
    public function appendRows($rows, $sheetName = 'Sheet1')
    {
        try {
            $body = new Sheets\ValueRange([
                'values' => $rows
            ]);

            $params = [
                'valueInputOption' => 'USER_ENTERED'
            ];

            $result = $this->service->spreadsheets_values->append(
                $this->spreadsheetId,
                $sheetName,
                $body,
                $params
            );

            Log::info('Appended ' . count($rows) . ' rows to Google Sheet');
            return $result->getUpdates()->getUpdatedRows();
        } catch (\Exception $e) {
            Log::error('Failed to append to Google Sheet: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Add headers if sheet is empty
     */
    public function ensureHeaders($headers, $sheetName = 'Sheet1')
    {
        try {
            $range = $sheetName . '!A1';
            $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $range);
            $existingData = $response->getValues();
            
            if (empty($existingData)) {
                $body = new Sheets\ValueRange([
                    'values' => [$headers]
                ]);
                
                $params = [
                    'valueInputOption' => 'USER_ENTERED'
                ];
                
                $this->service->spreadsheets_values->update(
                    $this->spreadsheetId,
                    $range,
                    $body,
                    $params
                );
                Log::info('Added headers to Google Sheet');
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            Log::error('Failed to ensure headers: ' . $e->getMessage());
            return false;
        }
    }
}