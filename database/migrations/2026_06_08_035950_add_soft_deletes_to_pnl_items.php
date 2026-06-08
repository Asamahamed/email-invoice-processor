<?php
// database/migrations/2026_06_06_000001_add_soft_deletes_to_pnl_items.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pnl_items', function (Blueprint $table) {
            if (!Schema::hasColumn('pnl_items', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down()
    {
        Schema::table('pnl_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};