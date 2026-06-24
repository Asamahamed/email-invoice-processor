<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('generated_invoices', function (Blueprint $table) {
            $table->string('base_invoice_number')->nullable()->after('invoice_number');
            $table->integer('revision_number')->default(1)->after('base_invoice_number');
        });
    }

    public function down()
    {
        Schema::table('generated_invoices', function (Blueprint $table) {
            $table->dropColumn(['base_invoice_number', 'revision_number']);
        });
    }
};