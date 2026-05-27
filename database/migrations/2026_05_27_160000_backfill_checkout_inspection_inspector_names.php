<?php

use App\Models\RoomStatusBlock;
use App\Models\User;
use App\Support\CheckoutInspectionInspector;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $userIds = [];
        RoomStatusBlock::query()
            ->whereNotNull('inspection_snapshot')
            ->orderBy('id')
            ->chunkById(100, function ($blocks) use (&$userIds) {
                foreach ($blocks as $block) {
                    $snap = $block->inspection_snapshot;
                    if (! is_array($snap)) {
                        continue;
                    }
                    $uid = (int) ($snap['inspector_user_id'] ?? 0);
                    if ($uid > 0 && trim((string) ($snap['inspector_name'] ?? '')) === '') {
                        $userIds[$uid] = true;
                    }
                }
            });

        if ($userIds === []) {
            return;
        }

        $names = User::query()
            ->whereIn('id', array_keys($userIds), 'and', false)
            ->get(['id', 'name', 'email']);

        $displayById = [];
        foreach ($names as $user) {
            $displayById[(int) $user->id] = CheckoutInspectionInspector::displayNameForUserId((int) $user->id, (string) $user->name);
        }

        RoomStatusBlock::query()
            ->whereNotNull('inspection_snapshot')
            ->orderBy('id')
            ->chunkById(100, function ($blocks) use ($displayById) {
                foreach ($blocks as $block) {
                    $snap = $block->inspection_snapshot;
                    if (! is_array($snap)) {
                        continue;
                    }
                    $uid = (int) ($snap['inspector_user_id'] ?? 0);
                    if ($uid <= 0 || trim((string) ($snap['inspector_name'] ?? '')) !== '') {
                        continue;
                    }
                    $display = $displayById[$uid] ?? null;
                    if ($display === null || $display === '') {
                        continue;
                    }
                    $snap['inspector_name'] = $display;
                    $block->inspection_snapshot = $snap;
                    $block->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        // Non-destructive: leave persisted inspector_name values in snapshots.
    }
};
