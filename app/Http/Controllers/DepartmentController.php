<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Department::runtimeVisible()
            ->select('id', 'name', 'code', 'faculty_id')
            ->orderBy('code');

        if ($request->has('faculty_id')) {
            $query->where('faculty_id', $request->faculty_id);
        }

        return response()->json($query->get());
    }

    public function basicList()
    {
        return response()->json(
            Department::runtimeVisible()->orderBy('code')->get(['id', 'code', 'name'])
        );
    }
}
