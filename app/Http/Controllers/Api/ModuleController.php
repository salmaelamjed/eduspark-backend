<?php

namespace App\Http\Controllers\Api;
use  App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Module;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModuleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

  

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $courseSlug)
    {
        $course = Course::where('slug', $courseSlug)->first();
        if (!$course) {
            return response()->json([
                'message' => 'Cours non trouvé.'
            ], 404);
        }

        $user = $request->user();
        $isAuthorized = $user->role === 'admin' || $user->id === $course->teacher_id;
        
        if (!$isAuthorized) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à ajouter des modules à ce cours.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'order' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        $order = $request->input('order');
        if (!$order) {
            $lastOrder = Module::where('course_id', $course->id)
                ->max('order');
            $order = $lastOrder ? $lastOrder + 1 : 1;
        } 

        $module = Module::create([
            'course_id' => $course->id,
            'title' => $request->title,
            'description' => $request->description,
            'order' => $order,
        ]);

        return response()->json([
            'message' => 'Module créé avec succès',
            'data' => [
                'id' => $module->id,
                'title' => $module->title,
                'description' => $module->description,
                'order' => $module->order,
                'course_id' => $module->course_id,
                'course_slug' => $course->slug,
                'created_at' => $module->created_at->toDateTimeString(),
                'updated_at' => $module->updated_at->toDateTimeString(),
            ]
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

 

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
