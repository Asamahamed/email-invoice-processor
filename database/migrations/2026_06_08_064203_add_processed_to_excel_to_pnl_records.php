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
    Schema::table('pnl_records', function (Blueprint $table) {
        $table->boolean('processed_to_excel')->default(false)->after('status');
    });
}

public function down()
{
    Schema::table('pnl_records', function (Blueprint $table) {
        $table->dropColumn('processed_to_excel');
    });
}
};
