<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = Auth::user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function receiptDefaults()
    {
        return response()->json(Setting::getReceiptDefaults());
    }

    public function invoiceProfile()
    {
        return response()->json(Setting::getInvoiceProfile());
    }

    public function updateInvoiceProfile(Request $request)
    {
        $this->checkPermission('manage-settings');
        $validated = $request->validate([
            'invoice_company_name' => 'nullable|string|max:255',
        ]);

        Setting::set('invoice_company_name', trim((string) ($validated['invoice_company_name'] ?? '')));

        return response()->json(Setting::getInvoiceProfile());
    }

    public function invoiceBank()
    {
        return response()->json(Setting::getInvoiceBankDetails());
    }

    public function updateInvoiceBank(Request $request)
    {
        $this->checkPermission('manage-settings');
        $validated = $request->validate([
            'invoice_bank_legal_name' => 'nullable|string|max:255',
            'invoice_bank_name' => 'nullable|string|max:255',
            'invoice_bank_account_no' => 'nullable|string|max:50',
            'invoice_bank_ifsc' => 'nullable|string|max:20',
            'invoice_bank_branch' => 'nullable|string|max:255',
            'invoice_bank_swift' => 'nullable|string|max:20',
        ]);

        foreach (array_keys($validated) as $key) {
            Setting::set($key, trim((string) ($validated[$key] ?? '')));
        }

        return response()->json(Setting::getInvoiceBankDetails());
    }

    /**
     * Canonical company / property profile (for procurement, accounts, reports).
     */
    public function companyProfile()
    {
        return response()->json(Setting::getCompanyProfile());
    }

    public function updateReceiptDefaults(Request $request)
    {
        $this->checkPermission('manage-settings');
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) === '') {
                    $fail('The property / company name field is required.');
                }
            }],
            'gstin' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:1000',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        foreach ($validated as $key => $value) {
            if ($key === 'company_name') {
                Setting::set('receipt_company_name', trim((string) $value));

                continue;
            }
            Setting::set("receipt_{$key}", $value ?? '');
        }

        if ($request->hasFile('logo')) {
            $request->validate(['logo' => 'image|mimes:png,jpg,jpeg|max:512']);
            $old = Setting::get('receipt_logo_path');
            if ($old && Storage::disk('public')->exists($old)) {
                Storage::disk('public')->delete($old);
            }
            $path = $request->file('logo')->store('receipt-logos', 'public');
            Setting::set('receipt_logo_path', $path);
        }

        return response()->json(Setting::getReceiptDefaults());
    }

    public function globalConfig()
    {
        $normalizeClock = static function (?string $raw, string $fallback): string {
            $s = trim((string) $raw);
            if ($s === '') return $fallback;
            if (preg_match('/^\d{1,2}:\d{2}$/', $s)) {
                [$h, $m] = array_pad(explode(':', $s, 3), 2, '00');
                return str_pad((string) ((int) $h), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) ((int) $m), 2, '0', STR_PAD_LEFT);
            }
            try {
                return Carbon::parse($s)->format('H:i');
            } catch (\Throwable) {
                return $fallback;
            }
        };

        return response()->json([
            'check_in_time' => $normalizeClock(Setting::get('standard_check_in_time', '14:00'), '14:00'),
            'check_out_time' => $normalizeClock(Setting::get('standard_check_out_time', '11:00'), '11:00'),
            'room_rates_include_gst' => filter_var(Setting::get('room_rates_include_gst', '0'), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function updateGlobalConfig(Request $request)
    {
        $this->checkPermission('manage-settings');
        $validated = $request->validate([
            'check_in_time' => 'required|date_format:H:i',
            'check_out_time' => 'required|date_format:H:i',
            'room_rates_include_gst' => 'sometimes|boolean',
        ]);

        Setting::set('standard_check_in_time', $validated['check_in_time']);
        Setting::set('standard_check_out_time', $validated['check_out_time']);
        if (array_key_exists('room_rates_include_gst', $validated)) {
            Setting::set('room_rates_include_gst', $validated['room_rates_include_gst'] ? '1' : '0');
        }

        return response()->json([
            'message' => 'Global configuration updated successfully.',
            'settings' => [
                'check_in_time' => $validated['check_in_time'],
                'check_out_time' => $validated['check_out_time'],
                'room_rates_include_gst' => filter_var(Setting::get('room_rates_include_gst', '0'), FILTER_VALIDATE_BOOLEAN),
            ],
        ]);
    }
}
