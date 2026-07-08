<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\TeacherRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    return $request->user();
});
Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-verification-code',[AuthController::class, 'resendVerificationCode']);
Route::post('/login',[AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:10,1')
    ->middleware('throttle:10,1');
    Route::get('/domains', [DomainController::class, 'getDomains']);
    Route::get('/get-domains', [DomainController::class, 'index']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/teacher-requests', [TeacherRequestController::class, 'store']);
    // Admin seulement
    Route::get('/teacher-requests', [TeacherRequestController::class, 'index']);
    Route::get('/teacher-requests/{teacherRequest}', [TeacherRequestController::class, 'show']);
    Route::patch('/teacher-requests/{teacherRequest}', [TeacherRequestController::class, 'update']);
    Route::delete('/teacher-requests/{teacherRequest}', [TeacherRequestController::class, 'destroy']);
    Route::post('/domains', [DomainController::class, 'store']);
    Route::delete('domains/{domain}', [DomainController::class, 'destroy']);
    Route::put('/domains/{domain}', [DomainController::class, 'update']);
    Route::get('/domains/{domain}', [DomainController::class, 'show']);


    //courses
    Route::post('/courses', [CourseController::class, 'store']);
    Route::put('/courses/{course}', [CourseController::class, 'update']);
    Route::delete('/courses/{course}', [CourseController::class, 'destroy']);
    Route::get('/teacher/courses', [CourseController::class, 'myCourses']);

    //modules
    Route::post('/courses/{courseSlug}/modules', [ModuleController::class, 'store']);

    Route::post('/courses/{course}/lessons/{lesson}/blocks/{block}/quiz/submit', 
        [CourseController::class, 'submitQuiz']
    );






});
    //les routes de courses pour les personnes non authentifie
    Route::get('/courses', action: [CourseController::class, 'index']);
    Route::get('/courses/{course}', [CourseController::class, 'show']);
    Route::get('/courses/{course:slug}', [CourseController::class, 'showBySlug']);



