<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiosellIntegration extends Model
{
    protected $fillable = [
        'enabled',
        'username',
        'password',
        'partner_id',
        'hotel_code',
        'last_error',
        'inventory_dirty',
        'rates_pending_room_type_ids',
        'connected_channels',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'username' => 'encrypted',
        'password' => 'encrypted',
        'inventory_dirty' => 'boolean',
        'rates_pending_room_type_ids' => 'array',
        'connected_channels' => 'array',
    ];

    protected $hidden = [
        'username',
        'password',
    ];

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }

    public function ready(): bool
    {
        return $this->enabled
            && trim((string) $this->username) !== ''
            && trim((string) $this->password) !== ''
            && trim((string) $this->partner_id) !== ''
            && trim((string) $this->hotel_code) !== '';
    }
}
