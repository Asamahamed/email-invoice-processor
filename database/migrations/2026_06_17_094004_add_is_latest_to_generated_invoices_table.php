<?php
// database/migrations/xxxx_xx_xx_add_is_latest_to_generated_invoices_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('generated_invoices', function (Blueprint $table) {
            $table->boolean('is_latest')->default(true)->after('revision_number');
            $table->index('is_latest');
        });
    }

    public function down()
    {
        Schema::table('generated_invoices', function (Blueprint $table) {
            $table->dropColumn('is_latest');
        });
    }
};