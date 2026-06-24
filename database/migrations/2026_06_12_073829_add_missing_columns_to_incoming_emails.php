<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMissingColumnsToIncomingEmails extends Migration
{
    public function up()
    {
        Schema::table('incoming_emails', function (Blueprint $table) {
            if (!Schema::hasColumn('incoming_emails', 'invoice_number')) {
                $table->string('invoice_number')->nullable()->after('tour_ref');
            }
            if (!Schema::hasColumn('incoming_emails', 'reference_no')) {
                $table->string('reference_no')->nullable()->after('invoice_number');
            }
            if (!Schema::hasColumn('incoming_emails', 'travel_end_date')) {
                $table->date('travel_end_date')->nullable()->after('travel_start_date');
            }
            if (!Schema::hasColumn('incoming_emails', 'pax_count')) {
                $table->integer('pax_count')->nullable()->after('number_of_guests');
            }
        });
        
        Schema::table('generated_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('generated_invoices', 'original_invoice_number')) {
                $table->string('original_invoice_number')->nullable()->after('invoice_number');
            }
            if (!Schema::hasColumn('generated_invoices', 'revision_number')) {
                $table->integer('revision_number')->default(0)->after('original_invoice_number');
            }
            if (!Schema::hasColumn('generated_invoices', 'is_revision')) {
                $table->boolean('is_revision')->default(false)->after('revision_number');
            }
        });
    }
    
    public function down()
    {
        Schema::table('incoming_emails', function (Blueprint $table) {
            $table->dropColumn(['invoice_number', 'reference_no', 'travel_end_date', 'pax_count']);
        });
        
        Schema::table('generated_invoices', function (Blueprint $table) {
            $table->dropColumn(['original_invoice_number', 'revision_number', 'is_revision']);
        });
    }
}