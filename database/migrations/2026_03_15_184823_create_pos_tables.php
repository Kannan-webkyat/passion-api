<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_orders')) {
            Schema::create('pos_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('table_id')->constrained('restaurant_tables');
                $table->foreignId('restaurant_id')->constrained('restaurant_masters');
                $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();
                $table->integer('covers')->default(1);
                $table->enum('status', ['open', 'billed', 'paid', 'void'])->default('open');
                $table->enum('discount_type', ['percent', 'flat'])->nullable();
                $table->decimal('discount_value', 10, 2)->default(0);
                $table->decimal('subtotal', 10, 2)->default(0);
                $table->decimal('tax_amount', 10, 2)->default(0);
                $table->decimal('discount_amount', 10, 2)->default(0);
                $table->decimal('total_amount', 10, 2)->default(0);
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('pos_order_items')) {
            Schema::create('pos_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('pos_orders')->cascadeOnDelete();
                $table->foreignId('menu_item_id')->constrained('menu_items');
                $table->integer('quantity')->default(1);
                $table->decimal('unit_price', 10, 2);
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->decimal('line_total', 10, 2)->default(0);
                $table->boolean('kot_sent')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('pos_payments')) {
            Schema::create('pos_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('pos_orders')->cascadeOnDelete();
                $table->enum('method', ['cash', 'card', 'upi', 'room_charge']);
                $table->decimal('amount', 10, 2);
                $table->string('reference_no')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payments');
        Schema::dropIfExists('pos_order_items');
        Schema::dropIfExists('pos_orders');
    }
};
