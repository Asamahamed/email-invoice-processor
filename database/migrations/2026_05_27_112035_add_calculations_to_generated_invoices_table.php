<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_invoices', function (Blueprint $table) {
            $table->text('calculations')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('generated_invoices', function (Blueprint $table) {
            $table->dropColumn('calculations');
        });
    }
};
