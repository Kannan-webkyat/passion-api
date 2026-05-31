<?php

namespace App\Support;

use App\Models\User;

final class CheckoutInspectionInspector
{
    /**
     * Display name for checkout inspection UI (snapshot, room chart, folio).
     */
    public static function displayNameForUserId(?int $userId, ?string $storedName = null): ?string
    {
        $stored = trim((string) ($storedName ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        if ($userId === null || $userId <= 0) {
            return null;
        }

        $user = User::query()->find($userId, ['id', 'name', 'email']);
        if (! $user) {
            return null;
        }

        $name = trim((string) ($user->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($user->email ?? ''));

        return $email !== '' ? $email : null;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    public static function enrichSnapshot(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $uid = isset($snapshot['inspector_user_id']) ? (int) $snapshot['inspector_user_id'] : 0;
        if ($uid <= 0) {
            return $snapshot;
        }

        $name = self::displayNameForUserId($uid, isset($snapshot['inspector_name']) ? (string) $snapshot['inspector_name'] : null);
        if ($name !== null) {
            $snapshot['inspector_name'] = $name;
        }

        return $snapshot;
    }
}
