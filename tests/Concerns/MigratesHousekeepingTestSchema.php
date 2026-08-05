<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait MigratesHousekeepingTestSchema
{
    protected function migrateHousekeepingTestSchema(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
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

        if (! Schema::hasTable('room_types')) {
            Schema::create('room_types', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->integer('capacity')->default(2);
                $table->timestamps();
            });
            Schema::create('room_type_seasons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rooms')) {
            Schema::create('rooms', function (Blueprint $table) {
                $table->id();
                $table->string('room_number')->unique();
                $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
                $table->string('status')->default('available');
                $table->string('floor')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bookings')) {
            Schema::create('bookings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_id')->nullable();
                $table->string('status')->default('checked_in');
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('booking_source')->nullable();
                $table->string('booking_unit')->default('day');
                $table->date('check_in')->nullable();
                $table->date('check_out')->nullable();
                $table->dateTime('check_in_at')->nullable();
                $table->dateTime('check_out_at')->nullable();
                $table->decimal('total_price', 12, 2)->default(0);
                $table->decimal('deposit_amount', 12, 2)->default(0);
                $table->decimal('extra_charges', 12, 2)->default(0);
                $table->string('payment_status')->nullable();
                $table->unsignedInteger('adults_count')->default(1);
                $table->unsignedInteger('children_count')->default(0);
                $table->unsignedInteger('infants_count')->default(0);
                $table->unsignedInteger('extra_beds_count')->default(0);
                $table->unsignedBigInteger('rate_plan_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booking_segments')) {
            Schema::create('booking_segments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->foreignId('room_id')->constrained()->cascadeOnDelete();
                $table->string('status')->default('checked_in');
                $table->date('check_in')->nullable();
                $table->date('check_out')->nullable();
                $table->dateTime('check_in_at');
                $table->dateTime('check_out_at');
                $table->unsignedInteger('adults_count')->default(1);
                $table->unsignedInteger('children_count')->default(0);
                $table->unsignedInteger('extra_beds_count')->default(0);
                $table->decimal('total_price', 12, 2)->nullable();
                $table->unsignedBigInteger('rate_plan_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('room_status_blocks')) {
            Schema::create('room_status_blocks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_id')->constrained()->cascadeOnDelete();
                $table->string('status');
                $table->boolean('is_active')->default(true);
                $table->date('start_date');
                $table->date('end_date');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('daily_room_cleanings')) {
            Schema::create('daily_room_cleanings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('booking_id')->nullable();
                $table->date('service_date');
                $table->string('status')->default('pending_cleaning');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('daily_cleaning_completed_at')->nullable();
                $table->unsignedBigInteger('started_by')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->unsignedBigInteger('assigned_to')->nullable();
                $table->text('remarks')->nullable();
                $table->text('maintenance_note')->nullable();
                $table->json('checklist_done')->nullable();
                $table->timestamps();
                $table->unique(['room_id', 'service_date']);
            });
        }

        if (! Schema::hasTable('housekeeping_checklist_items')) {
            Schema::create('housekeeping_checklist_items', function (Blueprint $table) {
                $table->id();
                $table->string('task_key', 100);
                $table->string('task_name');
                $table->string('category', 64);
                $table->string('section', 64)->nullable();
                $table->unsignedInteger('display_order')->default(0);
                $table->boolean('required')->default(true);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('estimated_minutes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('service_checklist_items')) {
            Schema::create('service_checklist_items', function (Blueprint $table) {
                $table->id();
                $table->string('service_type', 64);
                $table->unsignedBigInteger('service_id');
                $table->string('task_key', 100);
                $table->string('task_name');
                $table->string('section', 64)->nullable();
                $table->unsignedInteger('display_order')->default(0);
                $table->boolean('required')->default(true);
                $table->boolean('completed')->default(false);
                $table->unsignedSmallInteger('estimated_minutes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('daily_room_cleaning_consumptions')) {
            Schema::create('daily_room_cleaning_consumptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('daily_room_cleaning_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('inventory_item_id')->nullable();
                $table->decimal('qty', 15, 3)->default(0);
                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('room_cleaning_releases')) {
            Schema::create('room_cleaning_releases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('booking_id')->nullable();
                $table->unsignedBigInteger('room_status_block_id')->nullable();
                $table->foreignId('daily_room_cleaning_id')->nullable()->constrained()->nullOnDelete();
                $table->date('release_date');
                $table->dateTime('window_start');
                $table->dateTime('window_end');
                $table->string('status')->default('available');
                $table->string('priority')->default('normal');
                $table->string('service_type')->default('daily');
                $table->string('service_subtype')->nullable();
                $table->unsignedBigInteger('assigned_to')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->unsignedBigInteger('started_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('room_cleaning_release_audits')) {
            Schema::create('room_cleaning_release_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_cleaning_release_id')->constrained()->cascadeOnDelete();
                $table->string('action', 64);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->text('remarks')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }
}
