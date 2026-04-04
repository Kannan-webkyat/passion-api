<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function receiptDefaults()
    {
        return response()->json(Setting::getReceiptDefaults());
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
        return response()->json([
            'check_in_time' => Setting::get('standard_check_in_time', '14:00'),
            'check_out_time' => Setting::get('standard_check_out_time', '11:00'),
        ]);
    }

    public function updateGlobalConfig(Request $request)
    {
        $this->checkPermission('manage-settings');
        $validated = $request->validate([
            'check_in_time' => 'required|date_format:H:i',
            'check_out_time' => 'required|date_format:H:i',
        ]);

        Setting::set('standard_check_in_time', $validated['check_in_time']);
        Setting::set('standard_check_out_time', $validated['check_out_time']);

        return response()->json([
            'message' => 'Global configuration updated successfully.',
            'settings' => $validated
        ]);
    }
}
