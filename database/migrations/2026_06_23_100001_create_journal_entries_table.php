<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number', 32)->unique();
            $table->date('entry_date');
            $table->date('business_date')->nullable();
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('source_ref', 128)->nullable();
            $table->text('memo')->nullable();
            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'status'], 'journal_entries_source_posted_unique');
            $table->index(['entry_date', 'status']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
