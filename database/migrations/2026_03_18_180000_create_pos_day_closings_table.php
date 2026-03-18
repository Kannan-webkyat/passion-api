<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_day_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurant_masters')->cascadeOnDelete();
            $table->date('closed_date');
            $table->timestamp('closed_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('opening_balance', 12, 2)->nullable();
            $table->decimal('closing_balance', 12, 2)->nullable();
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_discount', 12, 2)->default(0);
            $table->decimal('total_tax', 12, 2)->default(0);
            $table->decimal('total_paid', 12, 2)->default(0);
            $table->decimal('cash_total', 12, 2)->default(0);
            $table->decimal('card_total', 12, 2)->default(0);
            $table->decimal('upi_total', 12, 2)->default(0);
            $table->decimal('room_charge_total', 12, 2)->default(0);
            $table->integer('order_count')->default(0);
            $table->integer('void_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'closed_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_day_closings');
    }
};
