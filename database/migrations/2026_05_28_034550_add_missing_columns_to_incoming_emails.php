<?php
// database/migrations/2026_05_28_000001_add_missing_columns_to_incoming_emails.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('incoming_emails', function (Blueprint $table) {
            // Add missing columns if they don't exist
            if (!Schema::hasColumn('incoming_emails', 'travel_end_date')) {
                $table->date('travel_end_date')->nullable()->after('travel_start_date');
            }
            
            if (!Schema::hasColumn('incoming_emails', 'pax_count')) {
                $table->integer('pax_count')->nullable()->after('number_of_guests');
            }
            
            if (!Schema::hasColumn('incoming_emails', 'reference_no')) {
                $table->string('reference_no')->nullable()->after('invoice_number');
            }
            
            if (!Schema::hasColumn('incoming_emails', 'exchange_rate')) {
                $table->decimal('exchange_rate', 10, 2)->nullable()->after('currency');
            }
            
            if (!Schema::hasColumn('incoming_emails', 'handling_fee_percent')) {
                $table->decimal('handling_fee_percent', 5, 2)->default(0.5)->after('exchange_rate');
            }
            
            if (!Schema::hasColumn('incoming_emails', 'cgst_percent')) {
                $table->decimal('cgst_percent', 5, 2)->default(9)->after('handling_fee_percent');
            }
            
            if (!Schema::hasColumn('incoming_emails', 'sgst_percent')) {
                $table->decimal('sgst_percent', 5, 2)->default(9)->after('cgst_percent');
            }
            
            // Add indexes for new columns
            $table->index('travel_start_date')->after('travel_end_date');
            $table->index('pax_count')->after('number_of_guests');
        });
    }

    public function down()
    {
        Schema::table('incoming_emails', function (Blueprint $table) {
            $table->dropColumn([
                'travel_end_date',
                'pax_count',
                'reference_no',
                'exchange_rate',
                'handling_fee_percent',
                'cgst_percent',
                'sgst_percent'
            ]);
        });
    }
};