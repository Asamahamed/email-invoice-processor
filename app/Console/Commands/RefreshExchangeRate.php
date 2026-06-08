<?php
// app/Console/Commands/RefreshExchangeRate.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ExchangeRateService;

class RefreshExchangeRate extends Command
{
    protected $signature = 'exchange:refresh';
    protected $description = 'Refresh exchange rate cache';

    public function handle(ExchangeRateService $service)
    {
        $rate = $service->refreshCache();
        
        if ($rate) {
            $this->info("Exchange rate refreshed: USD 1 = INR {$rate}");
        } else {
            $this->error("Failed to refresh exchange rate");
        }
    }
}