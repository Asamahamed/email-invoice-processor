<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('incoming_emails', function (Blueprint $table) {
            $table->longText('body_html')->nullable()->after('body');
        });
    }

    public function down()
    {
        Schema::table('incoming_emails', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });
    }
};