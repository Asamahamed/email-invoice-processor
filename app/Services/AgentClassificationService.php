<?php

namespace App\Services;
use Illuminate\Support\Facades\Log;

class AgentClassificationService
{
    /**
     * CREDIT AGENTS - USD Calculation (NO Handling Fee)
     * These agents get Apple Holidays format invoice in USD
     */
    protected $creditAgents = [
        // MAKE MY TRIP
        'MAKE MY TRIP INDIA PVT LTD',
        'MAKE MY TRIP',
        'MAKEMYTRIP',

        // RIYA
        'RIYA HOLIDAYS PVT LTD',
        'RIYA',
        'RIYA HOLIDAYS',

        // I TRIP
        'I TRIP',
        'ITRIP',

        // NEXUS
        'NEXUS DMC',
        'NEXUS',

        // TRIP FACTORY
        'TRIP FACTORY',
        'TRIPFACTORY',
        'TRIP FACTORY PVT LTD',
    ];

    /**
     * CREDIT AGENTS WITH INR CALCULATION (WITH Handling Fee)
     * These agents get Sharmila format invoice in INR with handling fee
     */
    protected $creditInrAgents = [
        // 30 SUNDAYS
        '30 SUNDAYS',
        '30SUNDAYS',
        'THIRTY SUNDAYS',

        // PICK YOUR TRAIL (including misspellings)
        'PICK YOUR TRAIL',
        'PICK YOUR TRIAL',  // Common misspelling
        'PICKYOURTRAIL',
        'PICK YOUR TRAIL - CURATED COUPLE HOLIDAYS',
        'PICK UR TRAIL',
        'PICK UR TRIAL',

        // HOLIDAY TRIANGLE
        'HOLIDAY TRIANGLE TRAVEL PRIVATE LIMITED',
        'HOLIDAY TRIANGLE',
        'TRAVEL TRIANGLE',

        // TRAVEL TROOPS
        'TRAVEL TROOPS GLOBAL PRIVATE LIMITED',
        'TRAVEL TROOPS',
    ];

    /**
     * MAIN CLASSIFICATION
     */
    public function classify($emailBody, $fromEmail, $subject, $agentName = null)
    {
        $detectedAgent = $agentName ?: $this->detectAgentName(
            $emailBody,
            $fromEmail,
            $subject
        );

        $detectedAgent = $this->normalizeAgentName($detectedAgent);

        Log::info("Classification - Detected Agent: " . ($detectedAgent ?: 'Unknown'));

        // Check if agent is CREDIT (USD - No Handling Fee)
        if ($this->isCreditAgent($detectedAgent)) {
            Log::info("Agent classified as CREDIT (USD/No Fee): " . $detectedAgent);
            return [
                'credit_type' => 'credit',
                'display_type' => 'CREDIT',
                'currency' => 'USD',
                'has_handling_fee' => false,
                'invoice_format' => 'apple_holidays',
                'should_generate_invoice' => true,
                'reason' => 'Credit Agent (USD/No Fee): ' . $detectedAgent,
                'agent_normalized' => $detectedAgent,
                'account_details' => $this->getCreditAccountDetails()
            ];
        }

        // Check if agent is CREDIT INR (With Handling Fee)
        if ($this->isCreditInrAgent($detectedAgent)) {
            Log::info("Agent classified as CREDIT INR (With Fee): " . $detectedAgent);
            return [
                'credit_type' => 'credit',
                'display_type' => 'CREDIT',
                'currency' => 'INR',
                'has_handling_fee' => true,
                'invoice_format' => 'sharmila',
                'should_generate_invoice' => true,
                'reason' => 'Credit Agent (INR/With Fee): ' . $detectedAgent,
                'agent_normalized' => $detectedAgent,
                'account_details' => $this->getNonCreditAccountDetails()
            ];
        }

        /**
         * NON CREDIT - All other agents
         * INR Calculation with Handling Fee (Sharmila format)
         */
        Log::info("Agent classified as NON-CREDIT: " . ($detectedAgent ?: 'Unknown'));
        return [
            'credit_type' => 'non_credit',
            'display_type' => 'NON-CREDIT',
            'currency' => 'INR',
            'has_handling_fee' => true,
            'invoice_format' => 'sharmila',
            'should_generate_invoice' => true,
            'reason' => 'Non Credit Agent (INR/With Fee): ' . ($detectedAgent ?: 'Unknown'),
            'agent_normalized' => $detectedAgent,
            'account_details' => $this->getNonCreditAccountDetails()
        ];
    }

    /**
     * CHECK CREDIT AGENT (USD - No Handling Fee)
     */
    protected function isCreditAgent($agentName)
    {
        if (!$agentName) {
            return false;
        }

        $agentName = strtoupper(trim($agentName));

        foreach ($this->creditAgents as $creditAgent) {
            $creditAgent = strtoupper(trim($creditAgent));
            if (strpos($agentName, $creditAgent) !== false || strpos($creditAgent, $agentName) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * CHECK CREDIT INR AGENT (With Handling Fee)
     * Includes PICK YOUR TRAIL with all variations
     */
    protected function isCreditInrAgent($agentName)
    {
        if (!$agentName) {
            return false;
        }

        $agentName = strtoupper(trim($agentName));

        foreach ($this->creditInrAgents as $creditAgent) {
            $creditAgent = strtoupper(trim($creditAgent));
            if (strpos($agentName, $creditAgent) !== false || strpos($creditAgent, $agentName) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * CREDIT ACCOUNT DETAILS (USD - Apple Holidays)
     */
    protected function getCreditAccountDetails()
    {
        return [
            'account_name' => 'APPLE HOLIDAYS DESTINATION SERVICES (PVT) LTD',
            'bank_name' => 'COMMERCIAL BANK',
            'branch' => 'PETTAH',
            'account_no' => '1000136027',
            'swift_code' => 'CCEYLKLX',
            'bank_address' => 'Commercial Bank, Peoples Park Shopping Complex, No 180/1/31, Colombo 11, Sri Lanka'
        ];
    }

    /**
     * NON-CREDIT ACCOUNT DETAILS (INR - Sharmila)
     */
    protected function getNonCreditAccountDetails()
    {
        return [
            'account_name' => 'SHARMILA TOURS AND TRAVELS',
            'account_no' => '056205002744',
            'bank_name' => 'ICICI Bank Ltd',
            'branch' => 'TEPPAKULAM, MADURAI BRANCH',
            'ifsc_code' => 'ICIC0000562',
            'bank_address' => 'NO 199, DARSHINI TOWER, VAIGAI COLONY, ANNA NAGAR, 625020'
        ];
    }

    /**
     * DETECT AGENT FROM EMAIL
     */
    protected function detectAgentName($emailBody, $fromEmail, $subject)
    {
        // Check for Agent field in email body
        if (preg_match('/Agent\s*[:]*\s*([^\n]+)/i', $emailBody, $match)) {
            $name = trim($match[1]);
            $name = preg_replace('/\s*[-–].*$/', '', $name);
            if (!empty($name)) {
                Log::info("Detected agent from Agent field: " . $name);
                return $name;
            }
        }

        // Check from email patterns
        $fromEmailLower = strtolower($fromEmail);
        
        $patterns = [
            'makemytrip' => 'MAKE MY TRIP',
            'riya' => 'RIYA',
            '30sundays' => '30 SUNDAYS',
            'tripfactory' => 'TRIP FACTORY',
            'nexus' => 'NEXUS DMC',
            'pickyourtrail' => 'PICK YOUR TRAIL',
            'pickurtrail' => 'PICK YOUR TRAIL',
            'holidaytriangle' => 'HOLIDAY TRIANGLE',
            'traveltroops' => 'TRAVEL TROOPS',
        ];

        foreach ($patterns as $pattern => $agent) {
            if (strpos($fromEmailLower, $pattern) !== false) {
                Log::info("Detected agent from email pattern: " . $agent);
                return $agent;
            }
        }

        return null;
    }

    /**
     * NORMALIZE AGENT NAME
     */
    protected function normalizeAgentName($name)
    {
        if (!$name) {
            return null;
        }

        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name);

        $mappings = [
            '/makemytrip/i' => 'MAKE MY TRIP',
            '/make my trip/i' => 'MAKE MY TRIP',
            '/riya/i' => 'RIYA',
            '/trip\s*factory/i' => 'TRIP FACTORY',
            '/30\s*sundays/i' => '30 SUNDAYS',
            '/holiday\s*triangle/i' => 'HOLIDAY TRIANGLE',
            '/travel\s*troops/i' => 'TRAVEL TROOPS',
            '/pick\s*your\s*trail/i' => 'PICK YOUR TRAIL',
            '/pick\s*your\s*trial/i' => 'PICK YOUR TRAIL',  // Handle misspelling
            '/pick\s*ur\s*trail/i' => 'PICK YOUR TRAIL',
            '/pick\s*ur\s*trial/i' => 'PICK YOUR TRAIL',
            '/nexus/i' => 'NEXUS DMC',
            '/i\s*trip/i' => 'I TRIP',
        ];

        foreach ($mappings as $pattern => $replacement) {
            if (preg_match($pattern, $name)) {
                Log::info("Normalized agent name: {$name} -> {$replacement}");
                return $replacement;
            }
        }

        return strtoupper($name);
    }

    /**
     * EXTRACT CURRENCY FROM CONTENT
     */
    protected function extractCurrency($content)
    {
        if (preg_match('/SGD/i', $content)) {
            return 'SGD';
        }
        if (preg_match('/INR/i', $content) || preg_match('/₹/', $content)) {
            return 'INR';
        }
        if (preg_match('/USD/i', $content) || preg_match('/\$/', $content)) {
            return 'USD';
        }
        return 'USD';
    }
}