<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('pnl_records', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject');
            $table->longText('body')->nullable();
            $table->longText('body_html')->nullable();
            $table->timestamp('received_at');
            
            // PnL specific fields
            $table->string('vendor_name')->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('category')->nullable(); // Travel, Hotel, Transport, etc.
            $table->string('status')->default('pending'); // pending, approved, rejected
            
            // Booking reference
            $table->string('tour_ref')->nullable();
            $table->string('agent_name')->nullable();
            
            $table->enum('read_status', ['unread', 'read'])->default('unread');
            $table->enum('processing_status', ['pending', 'processed', 'failed'])->default('pending');
            $table->boolean('has_attachments')->default(false);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('vendor_name');
            $table->index('invoice_number');
            $table->index('received_at');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pnl_records');
    }
};