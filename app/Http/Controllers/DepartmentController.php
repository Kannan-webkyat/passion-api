<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index()
    {
        return response()->json(Department::withCount('users', 'locations')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:departments,code',
            'is_active' => 'boolean'
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
            'code' => 'required|string|max:50|unique:departments,code,' . $department->id,
            'is_active' => 'boolean'
        ]);

        $department->update($validated);
        return response()->json($department);
    }

    public function destroy(Department $department)
    {
        if ($department->locations()->count() > 0) {
            return response()->json(['message' => 'Cannot delete department with linked locations'], 422);
        }
        $department->delete();
        return response()->json(null, 204);
    }
}
