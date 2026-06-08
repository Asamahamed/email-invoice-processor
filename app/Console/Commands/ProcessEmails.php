<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EmailFetchService;

class ProcessEmails extends Command
{
    protected $signature = 'emails:process';
    protected $description = 'Process emails from mailbox';
    
    public function handle()
    {
        $this->info('Processing emails...');
        
        $service = new EmailFetchService();
        $count = $service->fetchUnprocessedEmails();
        
        $this->info("Processed {$count} emails");
    }
}