<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $query = PaymentMethod::query();
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-settings');
        $validated = $request->validate([
            'name' => 'required|string|unique:payment_methods,name',
            'code' => 'nullable|string|in:cash,card,upi,room_charge',
            'is_active' => 'boolean',
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
        $this->checkPermission('manage-settings');
        $validated = $request->validate([
            'name' => 'required|string|unique:payment_methods,name,'.$paymentMethod->id,
            'code' => 'nullable|string|in:cash,card,upi,room_charge',
            'is_active' => 'boolean',
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
        $this->checkPermission('manage-settings');
        $paymentMethod->delete();

        return response()->json(null, 204);
    }
}
