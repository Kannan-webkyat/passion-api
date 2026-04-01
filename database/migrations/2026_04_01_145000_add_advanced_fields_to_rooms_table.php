<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('intercom_extension')->nullable()->after('floor');
            $table->string('view_type')->default('standard')->after('intercom_extension');
            $table->boolean('is_smoking_allowed')->default(false)->after('view_type');
            $table->unsignedBigInteger('connected_room_id')->nullable()->after('is_smoking_allowed');
            $table->text('internal_notes')->nullable()->after('connected_room_id');
            
            $table->foreign('connected_room_id')->references('id')->on('rooms')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['connected_room_id']);
            $table->dropColumn(['intercom_extension', 'view_type', 'is_smoking_allowed', 'connected_room_id', 'internal_notes']);
        });
    }
};
