<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aiosell_integrations')) {
            Schema::create('aiosell_integrations', function (Blueprint $table) {
                $table->id();
                $table->boolean('enabled')->default(false);
                $table->text('username')->nullable();
                $table->text('password')->nullable();
                $table->string('partner_id')->nullable();
                $table->string('hotel_code')->nullable();
                $table->text('last_error')->nullable();
                $table->boolean('inventory_dirty')->default(false);
                $table->json('connected_channels')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('aiosell_room_maps')) {
            Schema::create('aiosell_room_maps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_type_id')->nullable();
                $table->string('room_code');
                $table->string('room_name')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->unique('room_code');
            });
        }

        if (! Schema::hasTable('aiosell_rate_plan_maps')) {
            Schema::create('aiosell_rate_plan_maps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_type_id')->nullable();
                $table->unsignedBigInteger('rate_plan_id')->nullable();
                $table->string('room_code');
                $table->string('rateplan_code');
                $table->string('occupancy_letter', 8)->nullable();
                $table->string('meal_code', 8)->nullable();
                $table->string('rateplan_name')->nullable();
                $table->decimal('price_override', 12, 2)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->unique('rateplan_code');
            });
        }

        if (! Schema::hasTable('aiosell_booking_links')) {
            Schema::create('aiosell_booking_links', function (Blueprint $table) {
                $table->id();
                $table->string('channel');
                $table->string('booking_id');
                $table->string('cm_booking_id')->nullable();
                $table->unsignedBigInteger('passion_booking_id')->nullable();
                $table->unsignedBigInteger('booking_group_id')->nullable();
                $table->unsignedInteger('room_index')->default(0);
                $table->boolean('pah')->default(false);
                $table->string('currency', 8)->nullable();
                $table->decimal('amount_after_tax', 12, 2)->nullable();
                $table->decimal('amount_before_tax', 12, 2)->nullable();
                $table->decimal('tax', 12, 2)->nullable();
                $table->decimal('commission', 12, 2)->nullable();
                $table->decimal('tcs', 12, 2)->nullable();
                $table->decimal('tds', 12, 2)->nullable();
                $table->timestamps();
                $table->unique(['channel', 'booking_id', 'room_index']);
                $table->index('passion_booking_id');
            });
        }

        if (! Schema::hasTable('aiosell_messages')) {
            Schema::create('aiosell_messages', function (Blueprint $table) {
                $table->id();
                $table->string('message_id')->unique();
                $table->string('conversation_id');
                $table->string('hotel_id')->nullable();
                $table->string('channel')->nullable();
                $table->string('booking_id')->nullable();
                $table->unsignedBigInteger('passion_booking_id')->nullable();
                $table->string('sender_type')->nullable();
                $table->text('content');
                $table->string('time_sent')->nullable();
                $table->string('guest_name')->nullable();
                $table->string('guest_phone')->nullable();
                $table->string('guest_email')->nullable();
                $table->timestamps();
                $table->index('conversation_id');
                $table->index('passion_booking_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('aiosell_messages');
        Schema::dropIfExists('aiosell_booking_links');
        Schema::dropIfExists('aiosell_rate_plan_maps');
        Schema::dropIfExists('aiosell_room_maps');
        Schema::dropIfExists('aiosell_integrations');
    }
};
