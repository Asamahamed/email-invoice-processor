// database/migrations/xxxx_add_profit_loss_to_pnl_records.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->decimal('profit_loss', 12, 2)->nullable()->after('amount');
        });
    }

    public function down()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->dropColumn('profit_loss');
        });
    }
};