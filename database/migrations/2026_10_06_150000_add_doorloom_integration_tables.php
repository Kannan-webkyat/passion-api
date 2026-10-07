<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('room_types') && ! Schema::hasColumn('room_types', 'doorloom_property_id')) {
            Schema::table('room_types', function (Blueprint $table) {
                $table->unsignedBigInteger('doorloom_property_id')->nullable()->unique();
            });
        }

        if (Schema::hasTable('rooms') && ! Schema::hasColumn('rooms', 'doorloom_inventory_id')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->unsignedBigInteger('doorloom_inventory_id')->nullable()->unique();
            });
        }

        if (! Schema::hasTable('doorloom_integrations')) {
            Schema::create('doorloom_integrations', function (Blueprint $table) {
                $table->id();
                $table->boolean('enabled')->default(false);
                $table->text('api_key')->nullable();
                $table->text('webhook_secret')->nullable();
                $table->string('integration_name')->nullable();
                $table->unsignedBigInteger('highest_sequence')->default(0);
                $table->timestamp('last_full_sync_at')->nullable();
                $table->string('address_line_1')->nullable();
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->string('pin_code')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('doorloom_nights')) {
            Schema::create('doorloom_nights', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('doorloom_property_id');
                $table->unsignedBigInteger('room_type_id')->nullable();
                $table->date('night_date');
                $table->decimal('base_price', 12, 2)->nullable();
                $table->boolean('is_weekend')->nullable();
                $table->string('price_source')->nullable();
                $table->unsignedInteger('base_guests')->nullable();
                $table->decimal('extra_adult_price', 12, 2)->nullable();
                $table->decimal('extra_child_price', 12, 2)->nullable();
                $table->string('currency', 8)->default('INR');
                $table->boolean('gst_applicable')->nullable();
                $table->boolean('price_includes_gst')->nullable();
                $table->json('meal_plans')->nullable();
                $table->unsignedInteger('available_units')->nullable();
                $table->unsignedInteger('total_units')->nullable();
                $table->boolean('is_blocked')->nullable();
                $table->string('block_scope')->nullable();
                $table->boolean('stop_sell')->nullable();
                $table->unsignedInteger('min_nights')->nullable();
                $table->unsignedInteger('max_nights')->nullable();
                $table->timestamps();
                $table->unique(['doorloom_property_id', 'night_date']);
            });
        }

        if (! Schema::hasTable('doorloom_event_cursors')) {
            Schema::create('doorloom_event_cursors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('doorloom_property_id');
                $table->string('event_type');
                $table->unsignedBigInteger('last_sequence')->default(0);
                $table->timestamps();
                $table->unique(['doorloom_property_id', 'event_type']);
            });
        }

        if (! Schema::hasTable('doorloom_booking_links')) {
            Schema::create('doorloom_booking_links', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('booking_id');
                $table->unsignedBigInteger('room_type_id');
                $table->unsignedBigInteger('doorloom_booking_id')->nullable();
                $table->unsignedInteger('revision')->default(0);
                $table->string('external_booking_id');
                $table->string('idempotency_key')->nullable();
                $table->string('payload_hash')->nullable();
                $table->string('sync_status')->default('pending');
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->unique(['booking_id', 'room_type_id']);
                $table->unique('external_booking_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('doorloom_booking_links');
        Schema::dropIfExists('doorloom_event_cursors');
        Schema::dropIfExists('doorloom_nights');
        Schema::dropIfExists('doorloom_integrations');

        if (Schema::hasTable('rooms') && Schema::hasColumn('rooms', 'doorloom_inventory_id')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->dropUnique(['doorloom_inventory_id']);
                $table->dropColumn('doorloom_inventory_id');
            });
        }

        if (Schema::hasTable('room_types') && Schema::hasColumn('room_types', 'doorloom_property_id')) {
            Schema::table('room_types', function (Blueprint $table) {
                $table->dropUnique(['doorloom_property_id']);
                $table->dropColumn('doorloom_property_id');
            });
        }
    }
};
