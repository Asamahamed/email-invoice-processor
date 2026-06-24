<?php
// database/migrations/2026_06_12_000000_add_pnl_extra_columns.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            if (!Schema::hasColumn('pnl_records', 'total_pax')) {
                $table->integer('total_pax')->nullable()->after('amount');
            }
            if (!Schema::hasColumn('pnl_records', 'total_nights')) {
                $table->integer('total_nights')->nullable()->after('total_pax');
            }
            if (!Schema::hasColumn('pnl_records', 'start_date')) {
                $table->date('start_date')->nullable()->after('total_nights');
            }
            if (!Schema::hasColumn('pnl_records', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }
            if (!Schema::hasColumn('pnl_records', 'tour_ref')) {
                $table->string('tour_ref')->nullable()->after('end_date');
            }
        });
    }

    public function down()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->dropColumn(['total_pax', 'total_nights', 'start_date', 'end_date', 'tour_ref']);
        });
    }
};