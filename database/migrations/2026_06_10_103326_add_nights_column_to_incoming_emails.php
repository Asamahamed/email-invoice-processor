<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   // In the migration file
public function up()
{
    Schema::table('incoming_emails', function (Blueprint $table) {
        $table->integer('total_nights')->nullable()->after('total_amount');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incoming_emails', function (Blueprint $table) {
            //
        });
    }
};
