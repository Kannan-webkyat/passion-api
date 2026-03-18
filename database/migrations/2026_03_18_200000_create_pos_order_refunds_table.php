<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('pos_orders')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->enum('method', ['cash', 'card', 'upi', 'room_charge']);
            $table->string('reference_no')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Add 'refunded' to pos_orders status enum
        DB::statement("ALTER TABLE pos_orders MODIFY COLUMN status ENUM('open', 'billed', 'paid', 'void', 'refunded') NOT NULL DEFAULT 'open'");
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_refunds');
        DB::statement("ALTER TABLE pos_orders MODIFY COLUMN status ENUM('open', 'billed', 'paid', 'void') NOT NULL DEFAULT 'open'");
    }
};
