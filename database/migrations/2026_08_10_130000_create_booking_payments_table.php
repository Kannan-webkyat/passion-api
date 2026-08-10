<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('type', 32); // payment | refund | adjustment
            $table->decimal('amount', 12, 2); // always positive; sign from type (adjustment may be signed via meta.direction)
            $table->string('method', 32)->nullable(); // cash|card|upi|bank_transfer
            $table->string('reference_no', 128)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 64)->default('manual'); // deposit|checkout|cancellation|booking_create|legacy_patch|migration|manual
            $table->json('meta')->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'paid_at']);
            $table->index(['booking_id', 'type']);
        });

        // Backfill: one payment / refund row per booking that already has cash on file.
        if (Schema::hasTable('bookings')) {
            $now = now();
            DB::table('bookings')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($now) {
                    $insert = [];
                    foreach ($rows as $b) {
                        $deposit = round((float) ($b->deposit_amount ?? 0), 2);
                        $refund = round((float) ($b->refund_amount ?? 0), 2);
                        $method = $b->payment_method ?: 'cash';
                        $paidAt = $b->created_at ?? $now;
                        if ($deposit > 0.004) {
                            $insert[] = [
                                'booking_id' => $b->id,
                                'type' => 'payment',
                                'amount' => $deposit,
                                'method' => $method,
                                'reference_no' => null,
                                'notes' => 'Backfilled from deposit_amount',
                                'source' => 'migration',
                                'meta' => json_encode(['backfill' => true]),
                                'paid_at' => $paidAt,
                                'received_by' => $b->created_by ?? null,
                                'voided_at' => null,
                                'voided_by' => null,
                                'void_reason' => null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                        if ($refund > 0.004) {
                            $insert[] = [
                                'booking_id' => $b->id,
                                'type' => 'refund',
                                'amount' => $refund,
                                'method' => $b->refund_method ?: $method,
                                'reference_no' => null,
                                'notes' => 'Backfilled from refund_amount',
                                'source' => 'migration',
                                'meta' => json_encode(['backfill' => true]),
                                'paid_at' => $b->updated_at ?? $paidAt,
                                'received_by' => null,
                                'voided_at' => null,
                                'voided_by' => null,
                                'void_reason' => null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                    if ($insert !== []) {
                        DB::table('booking_payments')->insert($insert);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payments');
    }
};
