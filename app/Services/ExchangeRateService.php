<?php
// app/Services/ExchangeRateService.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    /**
     * Get USD to INR exchange rate
     */
    public function getUsdToInrRate()
    {
        $cachedRate = Cache::get('usd_to_inr_rate');
        if ($cachedRate) {
            return $cachedRate;
        }
        
        $rate = $this->fetchRate('USD', 'INR');
        
        if (!$rate) {
            $rate = $this->getFallbackRate('USD', 'INR');
        }
        
        // Add +1 markup
        $finalRate = $rate + 1;
        
        Cache::put('usd_to_inr_rate', $finalRate, 3600);
        
        return $finalRate;
    }

    /**
     * ✅ Get SGD to INR exchange rate
     */
    public function getSgdToInrRate()
    {
        $cachedRate = Cache::get('sgd_to_inr_rate');
        if ($cachedRate) {
            return $cachedRate;
        }
        
        $rate = $this->fetchRate('SGD', 'INR');
        
        if (!$rate) {
            $rate = $this->getFallbackRate('SGD', 'INR');
        }
        
        // Add +1 markup
        $finalRate = $rate + 1;
        
        Cache::put('sgd_to_inr_rate', $finalRate, 3600);
        
        return $finalRate;
    }

    /**
     * ✅ Get MYR (Malaysian Ringgit) to INR exchange rate
     */
    public function getMyrToInrRate()
    {
        $cachedRate = Cache::get('myr_to_inr_rate');
        if ($cachedRate) {
            return $cachedRate;
        }
        
        $rate = $this->fetchRate('MYR', 'INR');
        
        if (!$rate) {
            $rate = $this->getFallbackRate('MYR', 'INR');
        }
        
        // Add +1 markup
        $finalRate = $rate + 1;
        
        Cache::put('myr_to_inr_rate', $finalRate, 3600);
        
        Log::info("✅ MYR to INR rate: {$finalRate} (Base: {$rate} + 1)");
        
        return $finalRate;
    }

    /**
     * ✅ Get exchange rate between any two currencies
     */
    public function getRate($from, $to)
    {
        $cacheKey = strtolower($from) . '_to_' . strtolower($to) . '_rate';
        $cachedRate = Cache::get($cacheKey);
        if ($cachedRate) {
            return $cachedRate;
        }
        
        $rate = $this->fetchRate($from, $to);
        
        if (!$rate) {
            $rate = $this->getFallbackRate($from, $to);
        }
        
        Cache::put($cacheKey, $rate, 3600);
        
        return $rate;
    }

    /**
     * Fetch rate from API
     */
    protected function fetchRate($from, $to)
    {
        try {
            // Using exchangerate-api.com (free, no API key required)
            $response = Http::timeout(10)->get("https://api.exchangerate-api.com/v4/latest/{$from}");
            
            if ($response->successful()) {
                $data = $response->json();
                $rate = $data['rates'][$to] ?? null;
                if ($rate) {
                    Log::info("Exchange rate fetched: 1 {$from} = {$rate} {$to}");
                    return $rate;
                }
            }
            
            // Fallback to another free API
            $response2 = Http::timeout(10)->get("https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/" . strtolower($from) . ".json");
            
            if ($response2->successful()) {
                $data = $response2->json();
                $rate = $data[strtolower($from)][strtolower($to)] ?? null;
                if ($rate) {
                    Log::info("Exchange rate fetched (jsdelivr): 1 {$from} = {$rate} {$to}");
                    return $rate;
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to fetch exchange rate for {$from} to {$to}: " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Fallback rates
     */
    protected function getFallbackRate($from, $to)
    {
        // Common exchange rates (approximate) - ✅ UPDATED with correct rates
        $rates = [
            'USD_INR' => 83.50,    // 1 USD = 83.50 INR
            'SGD_INR' => 62.00,    // 1 SGD = 62.00 INR
            'MYR_INR' => 22.82,    // 1 MYR = 22.82 INR (from your XE.com screenshot)
            'EUR_INR' => 90.00,
            'GBP_INR' => 105.00,
        ];
        
        $key = $from . '_' . $to;
        
        // Check if we have a fallback rate
        if (isset($rates[$key])) {
            Log::warning("Using fallback exchange rate: 1 {$from} = {$rates[$key]} {$to}");
            return $rates[$key];
        }
        
        // Try reverse rate
        $reverseKey = $to . '_' . $from;
        if (isset($rates[$reverseKey])) {
            $rate = 1 / $rates[$reverseKey];
            Log::warning("Using fallback exchange rate: 1 {$from} = {$rate} {$to}");
            return $rate;
        }
        
        // Default fallback for SGD to INR
        if ($from === 'SGD' && $to === 'INR') {
            return 62.00;
        }
        
        // Default fallback for MYR to INR - ✅ CORRECTED
        if ($from === 'MYR' && $to === 'INR') {
            return 22.82;  // From your XE.com screenshot
        }
        
        return 83.50; // Default USD to INR
    }

    /**
     * Convert amount with markup
     */
    public function convertWithMarkup($amount, $fromCurrency, $toCurrency = 'INR', $markup = 1)
    {
        $rate = $this->getRate($fromCurrency, $toCurrency);
        $convertedAmount = $amount * $rate;
        
        // Add markup
        $finalAmount = $convertedAmount + ($convertedAmount * ($markup / 100));
        
        Log::info("Converted: {$amount} {$fromCurrency} = {$finalAmount} {$toCurrency} (Rate: {$rate}, Markup: {$markup}%)");
        
        return $finalAmount;
    }

    /**
     * Refresh all cached rates
     */
    public function refreshAllRates()
    {
        $this->refreshRate('USD', 'INR');
        $this->refreshRate('SGD', 'INR');
        $this->refreshRate('MYR', 'INR');
        
        Log::info("All exchange rates refreshed");
    }

    protected function refreshRate($from, $to)
    {
        $rate = $this->fetchRate($from, $to);
        if ($rate) {
            $cacheKey = strtolower($from) . '_to_' . strtolower($to) . '_rate';
            Cache::put($cacheKey, $rate, 3600);
            Log::info("Refreshed {$from} to {$to} rate: {$rate}");
        }
    }
}   