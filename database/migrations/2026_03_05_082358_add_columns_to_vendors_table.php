<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (!Schema::hasColumn('vendors', 'name')) {
                $table->string('name')->after('id');
            }
            if (!Schema::hasColumn('vendors', 'contact_person')) {
                $table->string('contact_person')->nullable()->after('name');
            }
            if (!Schema::hasColumn('vendors', 'phone')) {
                $table->string('phone')->nullable()->after('contact_person');
            }
            if (!Schema::hasColumn('vendors', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('vendors', 'address')) {
                $table->text('address')->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['name', 'contact_person', 'phone', 'email', 'address']);
        });
    }
};
