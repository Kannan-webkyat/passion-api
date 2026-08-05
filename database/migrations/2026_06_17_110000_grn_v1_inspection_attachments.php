<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('grns')->where('status', 'received')->update(['status' => 'approved']);
        DB::statement("ALTER TABLE grns MODIFY COLUMN status ENUM('draft','pending','approved','cancelled') NOT NULL DEFAULT 'draft'");

        Schema::table('grns', function (Blueprint $table) {
            $table->date('invoice_date')->nullable()->after('supplier_invoice_number');
            $table->date('payment_due_date')->nullable()->after('invoice_date');
            $table->string('currency', 3)->default('INR')->after('payment_due_date');
            $table->decimal('exchange_rate', 12, 6)->default(1)->after('currency');
            $table->foreignId('inspected_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable()->after('inspected_by');
        });

        Schema::table('grn_items', function (Blueprint $table) {
            $table->enum('quality_status', ['accepted', 'rejected', 'partial_acceptance'])->nullable()->after('quantity_accepted');
            $table->date('manufacture_date')->nullable()->after('batch_number');
            $table->string('storage_condition')->nullable()->after('manufacture_date');
        });

        Schema::create('grn_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grn_id')->constrained('grns')->cascadeOnDelete();
            $table->enum('document_type', [
                'delivery_note',
                'supplier_invoice',
                'transport_document',
                'photo',
            ]);
            $table->string('file_path');
            $table->string('original_filename');
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_attachments');

        Schema::table('grn_items', function (Blueprint $table) {
            $table->dropColumn(['quality_status', 'manufacture_date', 'storage_condition']);
        });

        Schema::table('grns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inspected_by');
            $table->dropColumn(['invoice_date', 'payment_due_date', 'currency', 'exchange_rate', 'inspected_at']);
        });

        DB::table('grns')->where('status', 'approved')->update(['status' => 'received']);
        DB::statement("ALTER TABLE grns MODIFY COLUMN status ENUM('draft','pending','received','cancelled') NOT NULL DEFAULT 'draft'");
    }
};
