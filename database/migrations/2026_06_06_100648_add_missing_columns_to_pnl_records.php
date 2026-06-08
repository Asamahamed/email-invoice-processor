<?php
// database/migrations/2026_06_06_000000_add_missing_columns_to_pnl_records.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            if (!Schema::hasColumn('pnl_records', 'sno')) {
                $table->integer('sno')->nullable()->after('id');
            }
            if (!Schema::hasColumn('pnl_records', 'from_address')) {
                $table->string('from_address')->nullable()->after('from_email');
            }
            if (!Schema::hasColumn('pnl_records', 'is_number')) {
                $table->string('is_number')->nullable()->after('invoice_number');
            }
            if (!Schema::hasColumn('pnl_records', 'country_code')) {
                $table->string('country_code', 2)->nullable()->after('currency');
            }
            if (!Schema::hasColumn('pnl_records', 'exchange_rate_used')) {
                $table->decimal('exchange_rate_used', 10, 4)->nullable()->after('country_code');
            }
            if (!Schema::hasColumn('pnl_records', 'extracted_data')) {
                $table->json('extracted_data')->nullable()->after('body_html');
            }
        });
    }

    public function down()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->dropColumn(['sno', 'from_address', 'is_number', 'country_code', 'exchange_rate_used', 'extracted_data']);
        });
    }
};