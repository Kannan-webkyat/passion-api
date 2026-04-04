<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            $table->string('title')->nullable();
            $table->enum('status', ['draft', 'quotation_requested', 'comparison', 'po_generated'])->default('draft');
            $table->foreignId('location_id')->constrained('inventory_locations')->onDelete('restrict');
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('procurement_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procurement_requisition_id')->constrained('procurement_requisitions')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->onDelete('restrict');
            $table->decimal('quantity', 15, 3);
            $table->decimal('winning_unit_price', 15, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('procurement_requisition_item_vendors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('procurement_requisition_item_id');
            $table->unsignedBigInteger('vendor_id');
            $table->timestamps();

            $table->unique(['procurement_requisition_item_id', 'vendor_id'], 'proc_req_item_vendor_unique');
            $table->foreign('procurement_requisition_item_id', 'fk_pr_item_vendors_line')
                ->references('id')->on('procurement_requisition_items')->cascadeOnDelete();
            $table->foreign('vendor_id', 'fk_pr_item_vendors_vendor')
                ->references('id')->on('vendors')->cascadeOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'procurement_requisition_id')) {
                $table->unsignedBigInteger('procurement_requisition_id')->nullable()->after('location_id');
                $table->foreign('procurement_requisition_id', 'fk_po_procurement_req')
                    ->references('id')->on('procurement_requisitions')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'procurement_requisition_id')) {
                $table->dropForeign(['procurement_requisition_id']);
                $table->dropColumn('procurement_requisition_id');
            }
        });

        Schema::dropIfExists('procurement_requisition_item_vendors');
        Schema::dropIfExists('procurement_requisition_items');
        Schema::dropIfExists('procurement_requisitions');
    }
};
