<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite schema for the reservation / room chart endpoints (BookingController,
 * RoomStatusBlockController, BookingRoomTransferService). Columns mirror the MySQL tables.
 */
trait MigratesRoomChartTestSchema
{
    protected function migrateRoomChartTestSchema(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->boolean('is_active')->default(true);
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_id', 'model_type']);
            });
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type']);
            });
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
        }

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventory_taxes')) {
            Schema::create('inventory_taxes', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('rate', 5, 2)->default(0);
                $table->string('type')->default('local');
                $table->boolean('is_input_credit_eligible')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('room_types')) {
            Schema::create('room_types', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('breakfast_price', 10, 2)->default(0);
                $table->decimal('child_breakfast_price', 10, 2)->default(0);
                $table->decimal('adult_meal_price', 10, 2)->default(0);
                $table->decimal('child_meal_price', 10, 2)->default(0);
                $table->decimal('adult_lunch_price', 10, 2)->default(0);
                $table->decimal('child_lunch_price', 10, 2)->default(0);
                $table->decimal('adult_dinner_price', 10, 2)->default(0);
                $table->decimal('child_dinner_price', 10, 2)->default(0);
                $table->integer('child_age_limit')->default(12);
                $table->unsignedTinyInteger('child_age_from')->default(0);
                $table->decimal('early_check_in_fee', 10, 2)->nullable();
                $table->string('early_check_in_type')->nullable();
                $table->integer('early_check_in_buffer_minutes')->default(0);
                $table->decimal('late_check_out_fee', 10, 2)->nullable();
                $table->string('late_check_out_type')->nullable();
                $table->integer('late_check_out_buffer_minutes')->default(0);
                $table->integer('base_occupancy')->default(2);
                $table->decimal('extra_bed_cost', 10, 2)->default(0);
                $table->decimal('child_extra_bed_cost', 10, 2)->default(0);
                $table->integer('capacity')->nullable();
                $table->integer('extra_bed_capacity')->default(1);
                $table->integer('child_sharing_limit')->default(1);
                $table->unsignedBigInteger('tax_id')->nullable();
                $table->string('bed_config')->nullable();
                $table->json('amenities')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('room_types') && ! Schema::hasColumn('room_types', 'child_age_from')) {
            Schema::table('room_types', function (Blueprint $table) {
                $table->unsignedTinyInteger('child_age_from')->default(0);
            });
        }

        if (Schema::hasTable('room_types') && ! Schema::hasColumn('room_types', 'adult_meal_price')) {
            Schema::table('room_types', function (Blueprint $table) {
                $table->decimal('adult_meal_price', 10, 2)->default(0);
                $table->decimal('child_meal_price', 10, 2)->default(0);
            });
        }

        if (Schema::hasTable('room_types') && ! Schema::hasColumn('room_types', 'bedrooms')) {
            Schema::table('room_types', function (Blueprint $table) {
                $table->unsignedTinyInteger('bedrooms')->default(1);
                $table->unsignedTinyInteger('washrooms')->default(1);
                $table->decimal('weekday_price', 10, 2)->default(0);
                $table->decimal('weekend_price', 10, 2)->default(0);
            });
        }

        if (! Schema::hasTable('room_type_seasons')) {
            Schema::create('room_type_seasons', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_type_id');
                $table->string('season_name')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->string('adjustment_type')->default('override');
                $table->decimal('price_adjustment', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rate_plans')) {
            Schema::create('rate_plans', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_type_id');
                $table->string('name');
                $table->string('billing_unit')->default('day');
                $table->unsignedInteger('package_hours')->nullable();
                $table->decimal('package_price', 10, 2)->nullable();
                $table->unsignedInteger('grace_minutes')->default(0);
                $table->unsignedInteger('overtime_step_minutes')->default(60);
                $table->decimal('overtime_hour_price', 10, 2)->nullable();
                $table->string('meal_plan_type')->default('room_only');
                $table->decimal('base_price', 10, 2)->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('price_modifiers')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rooms')) {
            Schema::create('rooms', function (Blueprint $table) {
                $table->id();
                $table->string('room_number')->unique();
                $table->unsignedBigInteger('room_type_id');
                $table->unsignedBigInteger('par_template_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('status')->default('available');
                $table->string('floor')->nullable();
                $table->string('bed_config')->nullable();
                $table->json('amenities')->nullable();
                $table->string('intercom_extension')->nullable();
                $table->string('view_type')->default('standard');
                $table->boolean('is_smoking_allowed')->default(false);
                $table->unsignedBigInteger('connected_room_id')->nullable();
                $table->text('internal_notes')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booking_groups')) {
            Schema::create('booking_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('contact_person')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('status')->default('confirmed');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bookings')) {
            Schema::create('bookings', function (Blueprint $table) {
                $table->id();
                $table->string('booking_number', 32)->nullable()->unique();
                $table->unsignedInteger('invoice_seq')->nullable()->unique();
                $table->string('invoice_number', 32)->nullable();
                $table->timestamp('invoice_issued_at')->nullable();
                $table->unsignedBigInteger('room_id')->nullable();
                $table->unsignedBigInteger('rate_plan_id')->nullable();
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->json('guest_identities')->nullable();
                $table->json('guest_identity_types')->nullable();
                $table->string('city')->nullable();
                $table->string('country')->default('India');
                $table->string('bill_to_name')->nullable();
                $table->string('guest_gstin', 15)->nullable();
                $table->integer('adults_count')->default(1);
                $table->integer('children_count')->default(0);
                $table->json('child_ages')->nullable();
                $table->integer('infants_count')->default(0);
                $table->integer('extra_beds_count')->default(0);
                $table->integer('adult_breakfast_count')->default(0);
                $table->integer('child_breakfast_count')->default(0);
                $table->date('check_in')->nullable();
                $table->dateTime('check_in_at')->nullable();
                $table->date('check_out')->nullable();
                $table->dateTime('check_out_at')->nullable();
                $table->string('booking_unit')->default('day');
                $table->string('estimated_arrival_time')->nullable();
                $table->time('early_checkin_time')->nullable();
                $table->time('late_checkout_time')->nullable();
                $table->decimal('total_price', 10, 2)->nullable();
                $table->decimal('grand_total', 10, 2)->default(0);
                $table->string('payment_status')->default('pending');
                $table->string('payment_method')->nullable();
                $table->decimal('deposit_amount', 10, 2)->default(0);
                $table->decimal('refund_amount', 12, 2)->nullable();
                $table->string('refund_method', 32)->nullable();
                $table->decimal('cancellation_fee_amount', 10, 2)->default(0);
                $table->string('cancellation_reason', 64)->nullable();
                $table->string('cancellation_notes', 500)->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->decimal('extra_charges', 10, 2)->default(0);
                $table->decimal('checkout_discount_amount', 10, 2)->default(0);
                $table->string('checkout_discount_reason', 500)->nullable();
                $table->string('status')->default('confirmed');
                $table->string('booking_source')->default('walk-in');
                $table->string('source_reference')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('booking_group_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booking_segments')) {
            Schema::create('booking_segments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('room_id');
                $table->date('check_in')->nullable();
                $table->dateTime('check_in_at')->nullable();
                $table->date('check_out')->nullable();
                $table->dateTime('check_out_at')->nullable();
                $table->unsignedBigInteger('rate_plan_id')->nullable();
                $table->integer('adults_count')->default(1);
                $table->integer('children_count')->default(0);
                $table->integer('extra_beds_count')->default(0);
                $table->decimal('total_price', 10, 2)->default(0);
                $table->string('status')->default('confirmed');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booking_payments')) {
            Schema::create('booking_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->string('type', 32);
                $table->decimal('amount', 12, 2);
                $table->string('method', 32)->nullable();
                $table->string('reference_no', 128)->nullable();
                $table->text('notes')->nullable();
                $table->string('source', 64)->default('manual');
                $table->json('meta')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('received_by')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booking_room_transfers')) {
            Schema::create('booking_room_transfers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('booking_id');
                $table->unsignedBigInteger('booking_segment_id')->nullable();
                $table->unsignedBigInteger('from_room_id')->nullable();
                $table->unsignedBigInteger('to_room_id')->nullable();
                $table->unsignedBigInteger('from_room_type_id')->nullable();
                $table->unsignedBigInteger('to_room_type_id')->nullable();
                $table->string('transfer_reason', 64)->nullable();
                $table->text('internal_notes')->nullable();
                $table->string('rate_mode', 32)->nullable();
                $table->boolean('is_complimentary_upgrade')->default(false);
                $table->decimal('old_total_price', 12, 2)->default(0);
                $table->decimal('new_total_price', 12, 2)->default(0);
                $table->decimal('price_delta', 12, 2)->default(0);
                $table->decimal('segment_price', 12, 2)->default(0);
                $table->timestamp('transferred_at')->nullable();
                $table->unsignedBigInteger('performed_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booking_extra_charges')) {
            Schema::create('booking_extra_charges', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('booking_id');
                $table->string('source', 50)->default('inspection');
                $table->string('kind', 50)->default('other');
                $table->string('label')->nullable();
                $table->string('description', 500)->nullable();
                $table->decimal('qty', 10, 2)->default(1);
                $table->decimal('unit_amount', 10, 2)->default(0);
                $table->decimal('total_amount', 10, 2)->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('room_status_blocks')) {
            Schema::create('room_status_blocks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_id');
                $table->string('status');
                $table->date('start_date');
                $table->date('end_date');
                $table->string('note')->nullable();
                $table->json('inspection_snapshot')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('assigned_to')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('room_cleaning_releases')) {
            Schema::create('room_cleaning_releases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_id');
                $table->unsignedBigInteger('booking_id')->nullable();
                $table->unsignedBigInteger('room_status_block_id')->nullable();
                $table->unsignedBigInteger('daily_room_cleaning_id')->nullable();
                $table->date('release_date');
                $table->dateTime('window_start');
                $table->dateTime('window_end');
                $table->string('status')->default('available');
                $table->string('priority')->default('normal');
                $table->string('service_type')->default('daily');
                $table->string('service_subtype', 32)->nullable();
                $table->unsignedBigInteger('assigned_to')->nullable();
                $table->text('remarks')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->unsignedBigInteger('started_by')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('restaurant_masters')) {
            Schema::create('restaurant_masters', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pos_orders')) {
            Schema::create('pos_orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_type', 20)->default('dine_in');
                $table->unsignedBigInteger('room_id')->nullable();
                $table->unsignedBigInteger('booking_id')->nullable();
                $table->unsignedBigInteger('restaurant_id')->nullable();
                $table->string('status')->default('open');
                $table->decimal('cgst_amount', 14, 2)->default(0);
                $table->decimal('sgst_amount', 14, 2)->default(0);
                $table->decimal('igst_amount', 14, 2)->default(0);
                $table->decimal('vat_tax_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 10, 2)->default(0);
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
            Schema::create('pos_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->date('business_date')->nullable();
                $table->string('method');
                $table->decimal('amount', 10, 2);
                $table->string('reference_no')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('received_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventory_items')) {
            Schema::create('inventory_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('category_id')->nullable();
                $table->string('sku')->nullable();
                $table->string('name');
                $table->decimal('cost_price', 12, 2)->default(0);
                $table->decimal('conversion_factor', 12, 4)->default(1);
                $table->decimal('current_stock', 14, 4)->default(0);
                $table->boolean('is_minibar')->default(false);
                $table->unsignedBigInteger('tax_id')->nullable();
                $table->timestamps();
            });
            Schema::create('inventory_locations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('type')->nullable();
                $table->unsignedBigInteger('room_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            Schema::create('inventory_item_locations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('inventory_item_id');
                $table->unsignedBigInteger('inventory_location_id');
                $table->decimal('quantity', 14, 4)->default(0);
                $table->timestamps();
            });
            Schema::create('menu_items', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedBigInteger('tax_id')->nullable();
                $table->string('type')->default('veg');
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('inventory_item_id')->nullable();
                $table->timestamps();
            });
            Schema::create('restaurant_menu_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('menu_item_id');
                $table->unsignedBigInteger('restaurant_master_id');
                $table->decimal('price', 10, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->boolean('price_tax_inclusive')->default(true);
                $table->timestamps();
            });
        }

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

        $this->truncateDateColumnsLikeMysql('room_status_blocks', ['start_date', 'end_date']);
        $this->truncateDateColumnsLikeMysql('room_type_seasons', ['start_date', 'end_date']);
        $this->truncateDateColumnsLikeMysql('doorloom_nights', ['night_date']);
    }

    /**
     * Eloquent `date` casts serialize as "Y-m-d H:i:s"; MySQL DATE columns drop the time but SQLite
     * keeps the string, which breaks exclusive end_date comparisons. Normalize like MySQL does.
     *
     * @param  list<string>  $columns
     */
    private function truncateDateColumnsLikeMysql(string $table, array $columns): void
    {
        $sets = implode(', ', array_map(fn(string $c) => "{$c} = date({$c})", $columns));
        foreach (['INSERT', 'UPDATE'] as $event) {
            $name = "trg_{$table}_date_" . strtolower($event);
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared(
                "CREATE TRIGGER {$name} AFTER {$event} ON {$table} FOR EACH ROW "
                . "BEGIN UPDATE {$table} SET {$sets} WHERE id = NEW.id; END"
            );
        }
    }
}
