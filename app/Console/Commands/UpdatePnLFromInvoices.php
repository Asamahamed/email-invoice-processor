<?php

namespace App\Console\Commands;

use App\Models\IncomingEmail;
use App\Models\PnlRecord;
use App\Models\PnlItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdatePnLFromInvoices extends Command
{
    protected $signature = 'pnl:update-from-invoices
                            {--dry-run : Show what would be updated without making changes}';
    
    protected $description = 'Update PnL items with guest names and travel dates from incoming invoices';

    public function handle()
    {
        $this->info('🔍 Starting PnL items update from invoices...');
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->warn('⚠️ DRY RUN MODE - No changes will be made');
        }

        $stats = [
            'total_pnl_items' => 0,
            'updated_items' => 0,
            'no_match' => 0,
            'errors' => 0
        ];

        // Get all PnL items that need updating
        $pnlItems = PnlItem::where(function($query) {
            $query->whereNull('client_name')
                  ->orWhere('client_name', '')
                  ->orWhere('client_name', 'NA');
        })->get();

        $stats['total_pnl_items'] = $pnlItems->count();

        if ($pnlItems->isEmpty()) {
            $this->info('✅ All PnL items already have client names!');
            return 0;
        }

        $this->info("📊 Found {$stats['total_pnl_items']} PnL items without client names");

        $progressBar = $this->output->createProgressBar($pnlItems->count());

        foreach ($pnlItems as $pnlItem) {
            $progressBar->advance();
            
            try {
                // Get the PnL record for this item
                $pnlRecord = PnlRecord::find($pnlItem->pnl_record_id);
                
                if (!$pnlRecord) {
                    continue;
                }

                $invoiceNumber = $pnlRecord->invoice_number;
                $tourRef = $pnlRecord->tour_ref;
                
                $matchedEmail = null;
                $matchType = null;

                // Try to match by invoice_number
                if ($invoiceNumber && $invoiceNumber !== 'NA' && $invoiceNumber !== 'N/A' && $invoiceNumber !== 'NULL') {
                    $matchedEmail = IncomingEmail::where('invoice_number', $invoiceNumber)
                        ->whereNotNull('guest_name')
                        ->where('guest_name', '!=', 'NA')
                        ->where('guest_name', '!=', '')
                        ->first();
                    
                    if ($matchedEmail) {
                        $matchType = 'invoice_number';
                    }
                }

                // If not found, try by tour_ref
                if (!$matchedEmail && $tourRef && $tourRef !== 'NA' && $tourRef !== 'N/A' && $tourRef !== 'NULL') {
                    $matchedEmail = IncomingEmail::where('tour_ref', $tourRef)
                        ->whereNotNull('guest_name')
                        ->where('guest_name', '!=', 'NA')
                        ->where('guest_name', '!=', '')
                        ->first();
                    
                    if ($matchedEmail) {
                        $matchType = 'tour_ref';
                    }
                }

                // If still not found, try partial tour_ref (remove CNTL)
                if (!$matchedEmail && $tourRef && $tourRef !== 'NA' && $tourRef !== 'N/A') {
                    $tourNumber = str_replace('CNTL', '', $tourRef);
                    $tourNumber = str_replace('cntl', '', $tourNumber);
                    
                    if ($tourNumber && $tourNumber != $tourRef) {
                        $matchedEmail = IncomingEmail::where('tour_ref', 'LIKE', "%{$tourNumber}%")
                            ->whereNotNull('guest_name')
                            ->where('guest_name', '!=', 'NA')
                            ->where('guest_name', '!=', '')
                            ->first();
                        
                        if ($matchedEmail) {
                            $matchType = 'tour_ref_partial';
                        }
                    }
                }

                if ($matchedEmail) {
                    if (!$dryRun) {
                        $updatedFields = [];
                        
                        // Update client_name
                        if ($matchedEmail->guest_name && $matchedEmail->guest_name !== 'NA' && $matchedEmail->guest_name !== '') {
                            $pnlItem->client_name = $matchedEmail->guest_name;
                            $updatedFields[] = 'client_name';
                        }
                        
                        // Update start_date
                        if ($matchedEmail->travel_start_date) {
                            $pnlItem->start_date = $matchedEmail->travel_start_date;
                            $updatedFields[] = 'start_date';
                        }
                        
                        // Update end_date
                        if ($matchedEmail->travel_end_date) {
                            $pnlItem->end_date = $matchedEmail->travel_end_date;
                            $updatedFields[] = 'end_date';
                        }

                        if (!empty($updatedFields)) {
                            $pnlItem->save();
                            $stats['updated_items']++;
                            
                            // Also update check_in_date and check_out_date if they exist
                            if ($matchedEmail->travel_start_date && isset($pnlItem->check_in_date)) {
                                $pnlItem->check_in_date = $matchedEmail->travel_start_date;
                            }
                            if ($matchedEmail->travel_end_date && isset($pnlItem->check_out_date)) {
                                $pnlItem->check_out_date = $matchedEmail->travel_end_date;
                            }
                            
                            Log::info("Updated PnL item {$pnlItem->id} from invoice", [
                                'match_type' => $matchType,
                                'invoice_number' => $invoiceNumber,
                                'tour_ref' => $tourRef,
                                'fields' => $updatedFields
                            ]);
                        }
                    } else {
                        // Dry run - show what would be updated
                        $this->line("   🔍 Would update PnL item #{$pnlItem->id} ({$matchType})");
                        $this->line("      Client: {$pnlItem->client_name} -> {$matchedEmail->guest_name}");
                        $this->line("      Start: {$pnlItem->start_date} -> {$matchedEmail->travel_start_date}");
                        $this->line("      End: {$pnlItem->end_date} -> {$matchedEmail->travel_end_date}");
                    }
                } else {
                    $stats['no_match']++;
                }
                
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error("Error updating PnL item {$pnlItem->id}: " . $e->getMessage());
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info("📊 SUMMARY:");
        $this->info("   Total PnL items checked: {$stats['total_pnl_items']}");
        $this->info("   Items updated: {$stats['updated_items']}");
        $this->info("   No match found: {$stats['no_match']}");
        $this->info("   Errors: {$stats['errors']}");

        if ($dryRun) {
            $this->warn('⚠️ DRY RUN COMPLETE - No changes were made');
            $this->info('Run without --dry-run to apply changes');
        }

        return 0;
    }
}