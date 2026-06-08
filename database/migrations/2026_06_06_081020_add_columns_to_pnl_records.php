<?php
// database/migrations/xxxx_add_columns_to_pnl_records.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->string('sno')->nullable()->after('id');
            $table->string('from_address')->nullable()->after('from_email');
            $table->enum('read_status', ['unread', 'read'])->default('unread')->change();
            $table->string('is_number')->nullable()->after('invoice_number');
            $table->string('country_code')->nullable()->after('category');
            $table->decimal('exchange_rate_used', 10, 4)->nullable()->after('amount');
            $table->json('extracted_data')->nullable()->after('body_html');
        });
    }

    public function down()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->dropColumn(['sno', 'from_address', 'is_number', 'country_code', 'exchange_rate_used', 'extracted_data']);
        });
    }
};