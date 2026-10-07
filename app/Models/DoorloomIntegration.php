<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoorloomIntegration extends Model
{
    protected $fillable = [
        'enabled',
        'api_key',
        'webhook_secret',
        'integration_name',
        'highest_sequence',
        'last_full_sync_at',
        'address_line_1',
        'city',
        'state',
        'pin_code',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'api_key' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'highest_sequence' => 'integer',
        'last_full_sync_at' => 'datetime',
    ];

    protected $hidden = [
        'api_key',
        'webhook_secret',
    ];

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }

    public function ready(): bool
    {
        return $this->enabled && trim((string) $this->api_key) !== '';
    }
}
