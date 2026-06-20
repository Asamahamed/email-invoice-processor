<?php

namespace App\Console\Commands;

use App\Services\MicrosoftGraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchEmailsCommand extends Command
{
    protected $signature = 'emails:fetch';
    protected $description = 'Fetch emails from Microsoft Graph and save to database';

    public function handle()
    {
        $this->info('🚀 Starting email fetch...');
        
        try {
            $service = new MicrosoftGraphService();
            $count = $service->fetchAllEmails();
            
            $this->info("✅ Fetched and saved {$count} new emails");
            Log::info("✅ Command: Fetched {$count} new emails");
            
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed to fetch emails: ' . $e->getMessage());
            Log::error('❌ Command failed: ' . $e->getMessage());
            return 1;
        }
    }
}