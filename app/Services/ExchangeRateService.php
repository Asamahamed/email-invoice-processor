<?php
// app/Services/ExchangeRateService.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    /**
     * Get USD to INR exchange rate from XE.com or API
     * Returns rate with +1 markup as per your requirement
     */
    public function getUsdToInrRate()
    {
        // Check cache first (cache for 1 hour)
        $cachedRate = Cache::get('usd_to_inr_rate');
        if ($cachedRate) {
            return $cachedRate;
        }
        
        $rate = $this->fetchFromXE();
        
        if (!$rate) {
            // Fallback rates if API fails
            $rate = $this->getFallbackRate();
        }
        
        // Add +1 markup
        $finalRate = $rate + 1;
        
        // Cache for 1 hour
        Cache::put('usd_to_inr_rate', $finalRate, 3600);
        
        return $finalRate;
    }
    
    /**
     * Fetch rate from XE.com (via scraping or free API)
     */
    protected function fetchFromXE()
    {
        try {
            // Try free API (fixer.io or exchangerate-api.com)
            // Option 1: exchangerate-api.com (free, no API key required for basic)
            $response = Http::timeout(10)->get('https://api.exchangerate-api.com/v4/latest/USD');
            
            if ($response->successful()) {
                $data = $response->json();
                $rate = $data['rates']['INR'] ?? null;
                if ($rate) {
                    Log::info("Exchange rate fetched from exchangerate-api: USD 1 = INR {$rate}");
                    return $rate;
                }
            }
            
            // Option 2: Alternative free API
            $response2 = Http::timeout(10)->get('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json');
            
            if ($response2->successful()) {
                $data = $response2->json();
                $rate = $data['usd']['inr'] ?? null;
                if ($rate) {
                    Log::info("Exchange rate fetched from jsdelivr: USD 1 = INR {$rate}");
                    return $rate;
                }
            }
            
            // Option 3: Scrape XE.com (last resort)
            $response3 = Http::timeout(15)->get('https://www.xe.com/currencyconverter/convert/?Amount=1&From=USD&To=INR');
            
            if ($response3->successful()) {
                $html = $response3->body();
                // Look for conversion result pattern
                if (preg_match('/(\d+\.?\d*)\s*INR/', $html, $matches)) {
                    $rate = floatval($matches[1]);
                    Log::info("Exchange rate scraped from XE.com: USD 1 = INR {$rate}");
                    return $rate;
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to fetch exchange rate: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Get fallback rate if API fails
     */
    protected function getFallbackRate()
    {
        // Get current date to check if weekend (rates don't change much)
        $currentRate = 83.50; // Base rate
        
        Log::warning("Using fallback exchange rate: USD 1 = INR {$currentRate}");
        return $currentRate;
    }
    
    /**
     * Get multiple currencies at once
     */
    public function getRates($baseCurrency = 'USD', $targetCurrencies = ['INR', 'SGD', 'EUR'])
    {
        $rates = [];
        
        foreach ($targetCurrencies as $currency) {
            if ($currency === $baseCurrency) {
                $rates[$currency] = 1;
                continue;
            }
            
            $rate = $this->getRate($baseCurrency, $currency);
            if ($rate) {
                $rates[$currency] = $rate;
            }
        }
        
        return $rates;
    }
    
    /**
     * Get rate between two currencies
     */
    public function getRate($from, $to)
    {
        try {
            $response = Http::timeout(10)->get("https://api.exchangerate-api.com/v4/latest/{$from}");
            
            if ($response->successful()) {
                $data = $response->json();
                return $data['rates'][$to] ?? null;
            }
        } catch (\Exception $e) {
            Log::error("Failed to fetch {$from} to {$to} rate: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Manually update cache (for cron job)
     */
    public function refreshCache()
    {
        $rate = $this->fetchFromXE();
        if ($rate) {
            $finalRate = $rate + 1;
            Cache::put('usd_to_inr_rate', $finalRate, 3600);
            Log::info("Exchange rate cache refreshed: USD 1 = INR {$finalRate}");
            return $finalRate;
        }
        return null;
    }
}