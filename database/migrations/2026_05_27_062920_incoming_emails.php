<?php
// database/migrations/2026_05_27_000001_create_incoming_emails_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('incoming_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique(); // Microsoft Graph message ID
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject');
            $table->longText('body')->nullable();
            $table->text('body_preview')->nullable(); // Preview for listing
            $table->timestamp('received_at');
            
            // Extracted tour confirmation details
            $table->string('agent_name')->nullable(); // e.g., "30 Sundays"
            $table->string('guest_name')->nullable(); // e.g., "Kaustubh Bhattashali"
            $table->string('tour_ref')->nullable(); // e.g., "VN19471"
            $table->string('file_handler')->nullable(); // e.g., "Yogi"
            $table->date('travel_start_date')->nullable();
            $table->date('travel_end_date')->nullable();
            $table->integer('number_of_guests')->nullable(); // e.g., 2
            $table->string('destination')->nullable(); // e.g., "Vietnam"
            
            // Financial details
            $table->decimal('total_amount', 10, 2)->nullable(); // e.g., 761.88
            $table->string('currency', 3)->nullable(); // USD, INR
            $table->decimal('cost_per_person', 10, 2)->nullable(); // e.g., 380.94
            $table->string('invoice_number')->nullable(); // Reference number from email
            
            // Classification
            $table->enum('credit_type', ['credit', 'non_credit', 'pending'])->default('pending');
            $table->string('classification_reason')->nullable();
            
            // Status tracking
            $table->enum('read_status', ['unread', 'read'])->default('unread');
            $table->enum('processing_status', ['pending', 'processed', 'invoice_generated', 'failed'])->default('pending');
            $table->boolean('has_attachments')->default(false);
            $table->boolean('is_tour_confirmation')->default(false); // Flag for tour confirmations
            
            // Additional metadata
            $table->json('email_metadata')->nullable(); // Store extra data as JSON
            $table->text('error_message')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for better performance
            $table->index('from_email');
            $table->index('received_at');
            $table->index('credit_type');
            $table->index('read_status');
            $table->index('processing_status');
            $table->index('is_tour_confirmation');
        });
    }

    public function down()
    {
        Schema::dropIfExists('incoming_emails');
    }
};