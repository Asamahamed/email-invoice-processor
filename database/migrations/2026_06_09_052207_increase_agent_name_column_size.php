<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->string('agent_name', 255)->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->string('agent_name', 255)->nullable()->change();
        });
    }
};