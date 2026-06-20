<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grns', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('purchase_order_id')->constrained('vendors')->nullOnDelete();
            $table->foreignId('inventory_location_id')->nullable()->after('vendor_id')->constrained('inventory_locations')->nullOnDelete();
            $table->string('delivery_note_number')->nullable()->after('received_date');
            $table->string('supplier_invoice_number')->nullable()->after('delivery_note_number');
            $table->enum('status', ['draft', 'pending', 'received', 'cancelled'])->default('draft')->after('supplier_invoice_number');
            $table->boolean('allow_over_receive')->default(false)->after('status');
            $table->foreignId('created_by')->nullable()->after('allow_over_receive')->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('cancelled_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->text('cancel_reason')->nullable()->after('cancelled_at');
            $table->string('document_path')->nullable()->after('cancel_reason');
        });

        Schema::create('grn_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grn_id')->constrained('grns')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('quantity_ordered', 12, 3);
            $table->decimal('quantity_previously_received', 12, 3)->default(0);
            $table->decimal('quantity_received', 12, 3)->default(0);
            $table->decimal('quantity_rejected', 12, 3)->default(0);
            $table->decimal('quantity_accepted', 12, 3)->default(0);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_subtotal_accepted', 15, 2)->default(0);
            $table->decimal('line_tax_accepted', 15, 2)->default(0);
            $table->string('rejection_reason')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('batch_number')->nullable();
            $table->timestamps();
        });

        Schema::create('grn_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grn_id')->constrained('grns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('old_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->json('payload')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_audit_logs');
        Schema::dropIfExists('grn_items');
        Schema::table('grns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropConstrainedForeignId('inventory_location_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'delivery_note_number',
                'supplier_invoice_number',
                'status',
                'allow_over_receive',
                'submitted_at',
                'approved_at',
                'cancelled_at',
                'cancel_reason',
                'document_path',
            ]);
        });
    }
};
