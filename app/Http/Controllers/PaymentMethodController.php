<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\PaymentMethod;

class PaymentMethodController extends Controller
{
    public function index()
    {
        return response()->json(PaymentMethod::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|unique:payment_methods,name',
            'code'       => 'nullable|string|in:cash,card,upi,room_charge',
            'is_active'  => 'boolean',
            'is_default' => 'boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            PaymentMethod::where('is_default', true)->update(['is_default' => false]);
        }

        $method = PaymentMethod::create($validated);
        return response()->json($method, 201);
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $validated = $request->validate([
            'name'       => 'required|string|unique:payment_methods,name,' . $paymentMethod->id,
            'code'       => 'nullable|string|in:cash,card,upi,room_charge',
            'is_active'  => 'boolean',
            'is_default' => 'boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            PaymentMethod::where('id', '!=', $paymentMethod->id)->update(['is_default' => false]);
        }

        $paymentMethod->update($validated);
        return response()->json($paymentMethod);
    }

    public function destroy(PaymentMethod $paymentMethod)
    {
        $paymentMethod->delete();
        return response()->json(null, 204);
    }
}
