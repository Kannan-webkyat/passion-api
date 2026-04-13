<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function index()
    {
        return response()->json(Vendor::orderBy('name')->get());
    }

    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-inventory');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'gstin' => 'nullable|string|max:15|regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
            'pan' => 'nullable|string|max:10|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
            'state' => 'nullable|string|max:100',
            'is_registered_dealer' => 'boolean',
            'default_tax_price_basis' => 'nullable|string|in:tax_exclusive,tax_inclusive,non_taxable',
        ]);
        $vendor = Vendor::create($validated);

        return response()->json($vendor, 201);
    }

    public function show(Vendor $vendor)
    {
        return response()->json($vendor->load('items'));
    }

    public function update(Request $request, Vendor $vendor)
    {
        $this->checkPermission('manage-inventory');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'gstin' => 'nullable|string|max:15|regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
            'pan' => 'nullable|string|max:10|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
            'state' => 'nullable|string|max:100',
            'is_registered_dealer' => 'boolean',
            'default_tax_price_basis' => 'nullable|string|in:tax_exclusive,tax_inclusive,non_taxable',
        ]);
        $vendor->update($validated);

        return response()->json($vendor);
    }

    public function destroy(Vendor $vendor)
    {
        $this->checkPermission('manage-inventory');
        try {
            $vendor->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete vendor as it has associated historical purchase orders or inventory items.'], 409);
            }
            throw $e;
        }
    }
}
