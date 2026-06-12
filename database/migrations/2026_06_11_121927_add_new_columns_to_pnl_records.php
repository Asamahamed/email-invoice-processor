<?php
// database/migrations/2024_01_15_add_new_columns_to_pnl_records.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNewColumnsToPnlRecords extends Migration
{
    public function up()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            // Date/Month/Year columns (to be added before hotel_name in Excel)
            $table->date('pnl_date')->nullable()->after('is_number');
            $table->string('pnl_month', 20)->nullable()->after('pnl_date');
            $table->string('pnl_year', 10)->nullable()->after('pnl_month');
            
            // New financial columns
            $table->decimal('actual_amount', 15, 2)->nullable()->after('amount');
            $table->decimal('budget_amount', 15, 2)->nullable()->after('actual_amount');
            $table->string('process', 50)->nullable()->after('budget_amount');
            $table->decimal('paid_amount', 15, 2)->nullable()->after('process');
            $table->decimal('exchange_rate', 10, 4)->nullable()->after('paid_amount');
            $table->decimal('gst', 10, 2)->nullable()->after('exchange_rate');
            $table->string('invoice_no', 100)->nullable()->after('gst');
            $table->text('remarks')->nullable()->after('invoice_no');
            
            // To track if already updated to Excel
            $table->boolean('excel_updated')->default(false)->after('status');
            $table->timestamp('excel_updated_at')->nullable()->after('excel_updated');
            $table->string('excel_update_hash', 64)->nullable()->after('excel_updated_at');
        });
    }

    public function down()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->dropColumn([
                'pnl_date', 'pnl_month', 'pnl_year',
                'actual_amount', 'budget_amount', 'process',
                'paid_amount', 'exchange_rate', 'gst', 'invoice_no',
                'remarks', 'excel_updated', 'excel_updated_at', 'excel_update_hash'
            ]);
        });
    }
}