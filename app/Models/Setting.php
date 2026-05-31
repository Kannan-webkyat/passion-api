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
            'company_name' => self::get('receipt_company_name', ''),
            'gstin' => self::get('receipt_gstin', ''),
            'address' => self::get('receipt_address', ''),
            'email' => self::get('receipt_email', ''),
            'phone' => self::get('receipt_phone', ''),
            'logo_url' => self::get('receipt_logo_path') ? asset('storage/'.self::get('receipt_logo_path')) : null,
        ];
    }

    /** Guest reservation invoice header settings (separate from receipt defaults). */
    public static function getInvoiceProfile(): array
    {
        return [
            'invoice_company_name' => trim((string) self::get('invoice_company_name', '')),
        ];
    }

    /** Guest reservation invoice footer — bank block (separate from receipt defaults). */
    public static function getInvoiceBankDetails(): array
    {
        return [
            'invoice_bank_legal_name' => trim((string) self::get('invoice_bank_legal_name', '')),
            'invoice_bank_name' => trim((string) self::get('invoice_bank_name', '')),
            'invoice_bank_account_no' => trim((string) self::get('invoice_bank_account_no', '')),
            'invoice_bank_ifsc' => trim((string) self::get('invoice_bank_ifsc', '')),
            'invoice_bank_branch' => trim((string) self::get('invoice_bank_branch', '')),
            'invoice_bank_swift' => trim((string) self::get('invoice_bank_swift', '')),
        ];
    }

    /**
     * Canonical property / company identity for slips, future accounts, and cross-module reports.
     * Display name falls back to app name when company_name is not set in settings.
     *
     * @return array{name: string, address: string, email: string, phone: string, gstin: string, logo_url: ?string}
     */
    public static function getCompanyProfile(): array
    {
        $r = self::getReceiptDefaults();
        $name = trim((string) ($r['company_name'] ?? ''));
        if ($name === '') {
            $name = (string) config('app.name', '');
        }

        return [
            'name' => $name,
            'address' => trim((string) ($r['address'] ?? '')),
            'email' => trim((string) ($r['email'] ?? '')),
            'phone' => trim((string) ($r['phone'] ?? '')),
            'gstin' => trim((string) ($r['gstin'] ?? '')),
            'logo_url' => $r['logo_url'] ?? null,
        ];
    }
}
