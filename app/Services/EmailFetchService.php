<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class EmailFetchService
{
    protected $graphService;
    
    public function __construct()
    {
        $this->graphService = new MicrosoftGraphService();
    }
    
    public function fetchAllEmails()
    {
        try {
            $count = $this->graphService->fetchAllEmails();
            return $count;
        } catch (\Exception $e) {
            Log::error('Error in EmailFetchService: ' . $e->getMessage());
            return 0;
        }
    }
    
    // Keep for backward compatibility
    public function fetchUnprocessedEmails()
    {
        return $this->fetchAllEmails();
    }
}