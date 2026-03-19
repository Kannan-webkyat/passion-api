<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember("setting.{$key}", 300, fn () => self::where('key', $key)->first());
        return $setting ? ($setting->value ?: $default) : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting.{$key}");
    }

    public static function getReceiptDefaults(): array
    {
        return [
            'address' => self::get('receipt_address', ''),
            'email'   => self::get('receipt_email', ''),
            'phone'   => self::get('receipt_phone', ''),
            'logo_url' => self::get('receipt_logo_path') ? asset('storage/' . self::get('receipt_logo_path')) : null,
        ];
    }
}
