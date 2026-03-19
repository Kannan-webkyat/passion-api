<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function receiptDefaults()
    {
        return response()->json(Setting::getReceiptDefaults());
    }

    public function updateReceiptDefaults(Request $request)
    {
        $validated = $request->validate([
            'address' => 'nullable|string|max:1000',
            'email'   => 'nullable|email|max:255',
            'phone'   => 'nullable|string|max:50',
        ]);

        foreach ($validated as $key => $value) {
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
}
