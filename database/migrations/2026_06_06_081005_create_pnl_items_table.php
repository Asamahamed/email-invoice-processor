<?php
// database/migrations/xxxx_create_pnl_items_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('pnl_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pnl_record_id')->constrained('pnl_records')->onDelete('cascade');
            $table->string('control_number')->nullable(); // CNTL number
            $table->string('invoice_number')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('type'); // Hotel, Transport, Ticket, Activities, Meals, etc.
            $table->string('credit_type')->nullable(); // credit or non_credit
            $table->string('agent_name')->nullable();
            $table->string('client_name')->nullable();
            $table->date('check_in_date')->nullable(); // For hotels
            $table->date('check_out_date')->nullable(); // For hotels
            $table->string('hotel_name')->nullable(); // For hotels
            $table->string('transport_name')->nullable(); // For transport
            $table->string('service_name')->nullable(); // For tickets/activities
            $table->string('country_code')->nullable(); // SG, MY, VN, LK, etc.
            $table->string('currency')->default('USD');
            $table->decimal('amount_original', 12, 2)->default(0);
            $table->decimal('exchange_rate', 10, 4)->default(1);
            $table->decimal('amount_converted', 12, 2)->default(0);
            $table->json('item_details')->nullable(); // Additional details
            $table->timestamps();
            
            $table->index('control_number');
            $table->index('type');
            $table->index('country_code');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pnl_items');
    }
};