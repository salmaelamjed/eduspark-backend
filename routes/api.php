<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CourseAccessController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\StripeController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\Teacher\StudentController;
use App\Http\Controllers\Api\Teacher\StatsController;
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
    //stripe connection
    Route::post('/stripe/connect', [StripeController::class, 'createConnectAccount']);
    Route::get('/stripe/status', [StripeController::class, 'getAccountStatus']);
    Route::post('/stripe/refresh-link', [StripeController::class, 'refreshOnboarding']);

    //Payment
    Route::post('/courses/{course}/checkout', [PaymentController::class, 'createPaymentIntent']);

    //Purchase
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);

    Route::get('/courses/{course}/access', [CourseAccessController::class, 'check']);

    //chat
    Route::get('/rooms', [ChatController::class, 'index'])->name('rooms.index');
    Route::post('/rooms', [ChatController::class, 'store'])->name('rooms.store');
    Route::get('/rooms/{room}/messages', [ChatController::class, 'messages'])->name('rooms.messages');
    Route::post('/rooms/{room}/messages', [ChatController::class, 'sendMessage'])->name('rooms.messages.store');
    Route::post('/rooms/{room}/switch-to-human', [ChatController::class, 'switchToHuman'])->name('rooms.switch-human');
    Route::post('/rooms/{room}/switch-to-ai', [ChatController::class, 'switchToAi'])->name('rooms.switch-ai');
    Route::post('/rooms/{room}/read', [ChatController::class, 'markAsRead']);

    Route::prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/{id}', [NotificationController::class, 'destroy']);
});


});
    //les routes de courses pour les personnes non authentifie
    Route::get('/courses', action: [CourseController::class, 'index']);
    Route::get('/courses/{course}', [CourseController::class, 'show']);
    Route::get('/courses/{course:slug}', [CourseController::class, 'showBySlug']);
    Route::get('/stripe/success', [StripeController::class, 'onboardingSuccess'])->name('stripe.success');
Route::get('/stripe/refresh', [StripeController::class, 'onboardingRefresh'])->name('stripe.refresh');

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);



Route::middleware(['auth:sanctum', 'role:teacher'])->prefix('teacher')->group(function () {

    // Gestion des étudiants
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/{student}', [StudentController::class, 'show'])->whereNumber('student');
    Route::delete('/students/{student}', [StudentController::class, 'destroy'])->whereNumber('student');
    Route::patch('/enrollments/{enrollment}', [StudentController::class, 'updateEnrollment'])->whereNumber('enrollment');
    Route::get('/courses/{course}/students', [StudentController::class, 'byCourse'])->whereNumber('course');

    // Statistiques
    Route::get('/stats/dashboard', [StatsController::class, 'dashboard']);
    Route::get('/stats/courses/{course}', [StatsController::class, 'courseStats'])->whereNumber('course');
});




Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('profile')->group(function () {

    Route::get('/', [ProfileController::class, 'show']);
    Route::get('/{id}', [ProfileController::class, 'showPublic'])->whereNumber('id');

    Route::put('/', [ProfileController::class, 'update']);
    Route::put('/social-links', [ProfileController::class, 'updateSocialLinks']);

    Route::post('/avatar', [ProfileController::class, 'updateAvatar'])
        ->middleware('throttle.profile:avatar,10,15');

    Route::delete('/avatar', [ProfileController::class, 'deleteAvatar'])
        ->middleware('throttle.profile:avatar,10,15');

    Route::put('/password', [ProfileController::class, 'updatePassword'])
        ->middleware('throttle.profile:password,5,15');

    Route::put('/email', [ProfileController::class, 'updateEmail'])
        ->middleware('throttle.profile:email,3,30');

    Route::post('/deactivate', [ProfileController::class, 'deactivate'])
        ->middleware('throttle.profile:deactivate,3,60');
            Route::post('/admin/users/{id}/deactivate', [ProfileController::class, 'adminDeactivate']);

});


