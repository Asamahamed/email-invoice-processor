<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('generated_invoices', function (Blueprint $table) {
            $table->string('gst_number')->nullable()->after('total_amount');
            $table->string('sales_person')->nullable()->after('gst_number');
        });
    }

    public function down()
    {
        Schema::table('generated_invoices', function (Blueprint $table) {
            $table->dropColumn(['gst_number', 'sales_person']);
        });
    }
};