<?php

use App\Http\Controllers\Api\Auth\StudentAuthController;
use App\Http\Controllers\Api\Auth\TrainerAuthController;
use App\Http\Controllers\Api\Student\AchievementController;
use App\Http\Controllers\Api\Student\AssignmentController;
use App\Http\Controllers\Api\Student\CompetitionController;
use App\Http\Controllers\Api\Student\CourseController;
use App\Http\Controllers\Api\Student\DashboardController;
use App\Http\Controllers\Api\Student\LearningController;
use App\Http\Controllers\Api\Student\ProfileController;
use App\Http\Controllers\Api\Student\ProjectController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/student/login', [StudentAuthController::class, 'login']);

Route::middleware('auth:sanctum')->prefix('student')->group(function () {

    Route::get('/me', [
        StudentAuthController::class,
        'me'
    ]);

    Route::post('/logout', [
        StudentAuthController::class,
        'logout'
    ]);
    Route::get('/profile', [
        ProfileController::class,
        'show'
    ]);
    Route::put('/profile', [
        ProfileController::class,
        'update'
    ]);
    Route::post('/profile/avatar', [
        ProfileController::class,
        'updateAvatar'
    ]);
    Route::get('/dashboard', [
        DashboardController::class,
        'index'
    ]);
    Route::get('/courses', [
        CourseController::class,
        'index'
    ]);

    Route::get('/courses/{courseId}', [
        CourseController::class,
        'show'
    ]);

    Route::post('/courses/{courseId}/enroll', [
        CourseController::class,
        'enroll'
    ]);
    Route::get('/my-courses', [
        LearningController::class,
        'index'
    ]);

    Route::get('/my-courses/{courseId}', [
        LearningController::class,
        'show'
    ]);

    Route::put('/my-courses/{courseId}/lessons/{lessonId}/progress', [
        LearningController::class,
        'updateProgress'
    ]);

    Route::put('/my-courses/{courseId}/lessons/{lessonId}/bookmark', [
        LearningController::class,
        'updateBookmark'
    ]);

    Route::delete('/my-courses/{courseId}', [
        LearningController::class,
        'destroy'
    ]);
    Route::get('/assignments', [
        AssignmentController::class,
        'index'
    ]);

    Route::get('/assignments/{assignmentId}', [
        AssignmentController::class,
        'show'
    ]);

    Route::put('/assignments/{assignmentId}/submission', [
        AssignmentController::class,
        'saveSubmission'
    ]);

    Route::post('/assignments/{assignmentId}/submission/submit', [
        AssignmentController::class,
        'submit'
    ]);

    Route::post('/assignments/{assignmentId}/submission/files', [
        AssignmentController::class,
        'uploadFiles'
    ]);

    Route::delete('/assignments/{assignmentId}/submission/files/{fileId}', [
        AssignmentController::class,
        'deleteFile'
    ]);
    Route::get('/projects/meta', [
        ProjectController::class,
        'meta'
    ]);

    Route::get('/projects', [
        ProjectController::class,
        'index'
    ]);

    Route::get('/projects/{projectId}', [
        ProjectController::class,
        'show'
    ]);

    Route::post('/projects', [
        ProjectController::class,
        'store'
    ]);

    Route::put('/projects/{projectId}', [
        ProjectController::class,
        'update'
    ]);

    Route::post('/projects/{projectId}/media', [
        ProjectController::class,
        'uploadMedia'
    ]);

    Route::delete('/projects/{projectId}/media/{type}', [
        ProjectController::class,
        'deleteMedia'
    ]);

    Route::post('/projects/{projectId}/submit', [
        ProjectController::class,
        'submit'
    ]);

    Route::delete('/projects/{projectId}', [
        ProjectController::class,
        'destroy'
    ]);

    Route::post('/projects/{projectId}/like', [
        ProjectController::class,
        'toggleLike'
    ]);

    Route::put('/projects/{projectId}/rating', [
        ProjectController::class,
        'rate'
    ]);
    Route::get('/competitions', [
        CompetitionController::class,
        'index'
    ]);

    Route::get('/competitions/{competitionId}', [
        CompetitionController::class,
        'show'
    ]);

    Route::post('/competitions/{competitionId}/register', [
        CompetitionController::class,
        'register'
    ]);

    Route::put('/competitions/{competitionId}/submission', [
        CompetitionController::class,
        'saveSubmission'
    ]);

    Route::post('/competitions/{competitionId}/submission/files', [
        CompetitionController::class,
        'uploadFiles'
    ]);

    Route::delete('/competitions/{competitionId}/submission/files/{fileId}', [
        CompetitionController::class,
        'deleteFile'
    ]);

    Route::post('/competitions/{competitionId}/submission/submit', [
        CompetitionController::class,
        'submit'
    ]);
    Route::get('/achievements', [
        AchievementController::class,
        'index'
    ]);

    Route::post('/achievements/credentials', [
        AchievementController::class,
        'storeCredential'
    ]);

    Route::delete('/achievements/credentials/{credentialId}', [
        AchievementController::class,
        'deleteCredential'
    ]);
});



Route::get('/portfolio/{portfolioCode}', [
    AchievementController::class,
    'publicShow'
]);








Route::post('/trainer/login', [TrainerAuthController::class, 'login']);
