<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Department::withCount('users', 'locations');
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:departments,code',
            'is_active' => 'boolean',
        ]);

        $department = Department::create($validated);

        return response()->json($department, 201);
    }

    public function show(Department $department)
    {
        return response()->json($department->load('users', 'locations'));
    }

    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:departments,code,'.$department->id,
            'is_active' => 'boolean',
        ]);

        $department->update($validated);

        return response()->json($department);
    }

    public function destroy(Department $department)
    {
        try {
            $department->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete department as it has linked locations, users, or historical transactions.'], 409);
            }
            throw $e;
        }
    }
}
