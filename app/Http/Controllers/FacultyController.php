<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use Illuminate\Http\Request;

class FacultyController extends Controller
{
    public function index()
    {
        return response()->json(
            Faculty::select('id', 'name', 'code')
                ->orderBy('code')
                ->get()
        );
    }
}
