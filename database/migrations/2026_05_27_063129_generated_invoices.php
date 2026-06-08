<?php
// database/migrations/2026_05_27_000003_create_generated_invoices_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('generated_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('incoming_emails')->onDelete('cascade');
            $table->string('invoice_number')->unique(); // e.g., INV000001
            $table->date('invoice_date');
            $table->string('customer_name');
            $table->string('guest_name')->nullable();
            $table->string('tour_ref')->nullable();
            
            // Financial
            $table->decimal('total_amount', 10, 2);
            $table->decimal('handling_fee', 10, 2)->default(0);
            $table->decimal('grand_total', 10, 2);
            $table->string('currency', 3)->default('USD');
            
            // Type
            $table->enum('invoice_type', ['credit', 'non_credit']);
            $table->string('status')->default('draft'); // draft, sent, paid, cancelled
            $table->string('file_path')->nullable(); // PDF file path
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('invoice_number');
            $table->index('invoice_date');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('generated_invoices');
    }
};