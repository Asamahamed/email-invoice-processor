<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('generated_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('generated_invoices', 'revision_number')) {
                $table->integer('revision_number')->default(0)->after('invoice_number');
            }
            if (!Schema::hasColumn('generated_invoices', 'original_invoice_number')) {
                $table->string('original_invoice_number')->nullable()->after('revision_number');
            }
            if (!Schema::hasColumn('generated_invoices', 'is_revision')) {
                $table->boolean('is_revision')->default(false)->after('original_invoice_number');
            }
        });
    }

    public function down()
    {
        Schema::table('generated_invoices', function (Blueprint $table) {
            $table->dropColumn(['revision_number', 'original_invoice_number', 'is_revision']);
        });
    }
};