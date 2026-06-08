<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ModifyPnlItemsColumnsForLongText extends Migration
{
    public function up()
    {
        // Check if table exists
        if (Schema::hasTable('pnl_items')) {
            // Modify columns to TEXT type for long content
            Schema::table('pnl_items', function (Blueprint $table) {
                // Change string columns to text to accommodate long HTML content
                $table->text('service_name')->nullable()->change();
                $table->text('hotel_name')->nullable()->change();
                $table->text('transport_name')->nullable()->change();
                $table->text('item_details')->nullable()->change();
                $table->text('agent_name')->nullable()->change();
                $table->text('client_name')->nullable()->change();
                
                // Add missing columns if they don't exist
                if (!Schema::hasColumn('pnl_items', 'control_number')) {
                    $table->string('control_number', 100)->nullable()->after('pnl_record_id');
                }
                
                if (!Schema::hasColumn('pnl_items', 'invoice_number')) {
                    $table->string('invoice_number', 100)->nullable()->after('control_number');
                }
                
                if (!Schema::hasColumn('pnl_items', 'start_date')) {
                    $table->date('start_date')->nullable()->after('invoice_number');
                }
                
                if (!Schema::hasColumn('pnl_items', 'end_date')) {
                    $table->date('end_date')->nullable()->after('start_date');
                }
                
                if (!Schema::hasColumn('pnl_items', 'credit_type')) {
                    $table->string('credit_type', 50)->default('Credit')->after('type');
                }
                
                if (!Schema::hasColumn('pnl_items', 'check_in_date')) {
                    $table->date('check_in_date')->nullable()->after('client_name');
                }
                
                if (!Schema::hasColumn('pnl_items', 'check_out_date')) {
                    $table->date('check_out_date')->nullable()->after('check_in_date');
                }
                
                if (!Schema::hasColumn('pnl_items', 'country_code')) {
                    $table->string('country_code', 5)->nullable()->after('service_name');
                }
                
                if (!Schema::hasColumn('pnl_items', 'amount_converted')) {
                    $table->decimal('amount_converted', 15, 2)->nullable()->after('exchange_rate');
                }
                
                if (!Schema::hasColumn('pnl_items', 'status')) {
                    $table->string('status', 50)->default('pending')->after('item_details');
                }
            });
        }
        
        // Add processed_to_excel column to pnl_records
        if (Schema::hasTable('pnl_records')) {
            Schema::table('pnl_records', function (Blueprint $table) {
                if (!Schema::hasColumn('pnl_records', 'processed_to_excel')) {
                    $table->boolean('processed_to_excel')->default(false)->after('status');
                }
                if (!Schema::hasColumn('pnl_records', 'start_date')) {
                    $table->date('start_date')->nullable()->after('processed_to_excel');
                }
                if (!Schema::hasColumn('pnl_records', 'end_date')) {
                    $table->date('end_date')->nullable()->after('start_date');
                }
            });
        }
    }

    public function down()
    {
        // Revert changes if needed
        if (Schema::hasTable('pnl_items')) {
            Schema::table('pnl_items', function (Blueprint $table) {
                $table->string('service_name', 255)->nullable()->change();
                $table->string('hotel_name', 255)->nullable()->change();
                $table->string('transport_name', 255)->nullable()->change();
            });
        }
        
        if (Schema::hasTable('pnl_records')) {
            Schema::table('pnl_records', function (Blueprint $table) {
                $table->dropColumn(['processed_to_excel', 'start_date', 'end_date']);
            });
        }
    }
}