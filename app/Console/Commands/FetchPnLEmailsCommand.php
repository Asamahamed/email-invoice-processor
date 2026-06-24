<?php

namespace App\Console\Commands;

use App\Services\PnlEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchPnLEmailsCommand extends Command
{
    protected $signature = 'pnl:fetch';
    protected $description = 'Fetch PnL emails from Microsoft Graph';

    public function handle()
    {
        $this->info('🔄 Fetching PnL emails...');
        
        try {
            $service = new PnlEmailService();
            $count = $service->fetchPnLEmails();
            
            $this->info("✅ Fetched {$count} new PnL emails");
            Log::info("PnL fetch completed: {$count} new emails");
            
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed: ' . $e->getMessage());
            Log::error('PnL fetch failed: ' . $e->getMessage());
            return 1;
        }
    }
}