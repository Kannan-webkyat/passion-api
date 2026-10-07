<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portal_notifications')) {
            Schema::create('portal_notifications', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 64);
                $table->string('title');
                $table->text('message');
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('portal_notification_reads')) {
            Schema::create('portal_notification_reads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('portal_notification_id')->constrained('portal_notifications')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('read_at');
                $table->timestamps();
                $table->unique(['portal_notification_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_notification_reads');
        Schema::dropIfExists('portal_notifications');
    }
};
