<?php
// app/Jobs/FetchPnLEmailsJob.php

namespace App\Jobs;

use App\Services\PnlEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchPnLEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 3;

    public function __construct()
    {
        //
    }

    public function handle(PnlEmailService $service)
    {
        try {
            Log::info('Starting PnL email fetch job');
            
            $count = $service->fetchNewEmailsOnly();
            
            Log::info("PnL email fetch job completed. Fetched: {$count} emails");
            
        } catch (\Exception $e) {
            Log::error('PnL email fetch job failed: ' . $e->getMessage());
            throw $e;
        }
    }
}