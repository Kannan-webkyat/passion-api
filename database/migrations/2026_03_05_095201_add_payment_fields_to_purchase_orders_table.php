<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_orders', 'received_document_path')) {
                $table->string('received_document_path')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('purchase_orders', 'invoice_path')) {
                $table->string('invoice_path')->nullable()->after('received_document_path');
            }
            if (!Schema::hasColumn('purchase_orders', 'payment_status')) {
                $table->enum('payment_status', ['pending', 'partially_paid', 'paid'])->default('pending')->after('status');
            }
            if (!Schema::hasColumn('purchase_orders', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('purchase_orders', 'payment_reference')) {
                $table->string('payment_reference')->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('purchase_orders', 'paid_amount')) {
                $table->decimal('paid_amount', 15, 2)->default(0)->after('total_amount');
            }
            if (!Schema::hasColumn('purchase_orders', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('paid_amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('purchase_orders', 'received_document_path')) $cols[] = 'received_document_path';
            if (Schema::hasColumn('purchase_orders', 'invoice_path')) $cols[] = 'invoice_path';
            if (Schema::hasColumn('purchase_orders', 'payment_status')) $cols[] = 'payment_status';
            if (Schema::hasColumn('purchase_orders', 'payment_method')) $cols[] = 'payment_method';
            if (Schema::hasColumn('purchase_orders', 'payment_reference')) $cols[] = 'payment_reference';
            if (Schema::hasColumn('purchase_orders', 'paid_amount')) $cols[] = 'paid_amount';
            if (Schema::hasColumn('purchase_orders', 'paid_at')) $cols[] = 'paid_at';
            
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
