<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_payment_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_order_id')->constrained('pos_orders')->cascadeOnDelete();
            $table->json('previous_payments');
            $table->json('new_payments');
            $table->string('reason', 500);
            $table->foreignId('amended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('amended_at');
            $table->timestamps();
        });

        $permission = Permission::firstOrCreate([
            'name' => 'pos-amend-payment',
            'guard_name' => 'web',
        ]);

        foreach (['Admin', 'Outlet Manager'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payment_amendments');
    }
};
