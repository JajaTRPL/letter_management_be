<?php

namespace App\Http\Controllers;

use App\Models\StudyProgram;
use Illuminate\Http\Request;

class StudyProgramController extends Controller
{
    public function index(Request $request)
    {
        $query = StudyProgram::runtimeVisible()
            ->select('id', 'name', 'code', 'department_id')
            ->with('department:id,code,name')
            ->orderBy('code');

        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        return response()->json($query->get());
    }

    public function grouped()
    {
        $departments = \App\Models\Department::runtimeVisible()
            ->whereHas('studyPrograms', fn ($query) => $query->runtimeVisible())
            ->with(['studyPrograms' => function ($query) {
                $query->runtimeVisible()
                    ->orderBy('code')
                    ->select('id', 'code', 'name', 'department_id');
            }])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $grouped = $departments->map(function ($dept) {
            return [
                'department' => [
                    'id' => $dept->id,
                    'code' => $dept->code,
                    'name' => $dept->name,
                ],
                'programs' => $dept->studyPrograms->map(function ($p) {
                    return [
                        'id' => $p->id,
                        'code' => $p->code,
                        'name' => $p->name,
                    ];
                }),
            ];
        });

        return response()->json($grouped);
    }
}
