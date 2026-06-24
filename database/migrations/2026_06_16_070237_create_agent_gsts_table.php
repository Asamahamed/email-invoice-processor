<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('agent_gsts', function (Blueprint $table) {
            $table->id();
            $table->string('agent_name')->unique();
            $table->string('gst_number')->nullable();
            $table->string('agent_code')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('agent_gsts');
    }
};