<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PnlEmailService;
class SyncPnlEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-pnl-emails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
   public function handle()
{
    $service = new PnlEmailService();
    $count = $service->fetchPnLEmails();

    $this->info("Fetched {$count} emails");
}
}
