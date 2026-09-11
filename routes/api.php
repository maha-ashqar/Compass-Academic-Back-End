<?php

use App\Http\Controllers\Api\Auth\StudentAuthController;
use App\Http\Controllers\Api\Auth\TrainerAuthController;
use App\Http\Controllers\Api\Student\AchievementController;
use App\Http\Controllers\Api\Student\AssignmentController;
use App\Http\Controllers\Api\Student\CompetitionController;
use App\Http\Controllers\Api\Student\CourseController;
use App\Http\Controllers\Api\Student\DashboardController;
use App\Http\Controllers\Api\Student\LearningController;
use App\Http\Controllers\Api\Student\MessageController;
use App\Http\Controllers\Api\Student\NotificationController;
use App\Http\Controllers\Api\Student\ProfileController;
use App\Http\Controllers\Api\Student\ProjectController;
use App\Http\Controllers\Api\Student\SettingsController;
use App\Http\Controllers\Api\Student\StudentAnnouncementController;
use App\Http\Controllers\Api\Trainer\DashboardController as TrainerDashboardController;
use App\Http\Controllers\Api\Trainer\TrainerAnnouncementController;
use App\Http\Controllers\Api\Trainer\TrainerAssignmentController;
use App\Http\Controllers\Api\Trainer\TrainerCompetitionController;
use App\Http\Controllers\Api\Trainer\TrainerCourseController;
use App\Http\Controllers\Api\Trainer\TrainerMessageController;
use App\Http\Controllers\Api\Trainer\TrainerNotificationController;
use App\Http\Controllers\Api\Trainer\TrainerProfileController;
use App\Http\Controllers\Api\Trainer\TrainerProjectController;
use App\Http\Controllers\Api\Trainer\TrainerSettingsController;
use App\Http\Controllers\Api\Trainer\TrainerStudentController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HomeController;



Route::get('/home', [HomeController::class, 'index']);


Route::prefix('trainer')->group(function () {

    Route::post('/login', [TrainerAuthController::class, 'login']);
    Route::post('register',[TrainerAuthController::class, 'register']);
    Route::post('forgot-password',[TrainerAuthController::class, 'forgotPassword']);
    Route::post('forgot-password/verify',[TrainerAuthController::class, 'verifyResetCode']);
    Route::post('reset-password',[TrainerAuthController::class, 'resetPassword']);


    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [TrainerAuthController::class, 'me']);
        Route::post('/logout', [TrainerAuthController::class, 'logout']);

        Route::get('/dashboard', [TrainerDashboardController::class, 'index']);

        Route::get('/courses', [TrainerCourseController::class, 'index']);
        Route::post('/courses', [TrainerCourseController::class, 'store']);
        Route::put('/courses/{courseId}', [TrainerCourseController::class, 'update']);
        Route::post('/courses/{courseId}/publish', [TrainerCourseController::class, 'publish']);
        Route::post('/courses/{courseId}/hide', [TrainerCourseController::class, 'hide']);
        Route::post('/courses/{courseId}/archive', [TrainerCourseController::class, 'archive']);
        Route::post('/courses/{courseId}/duplicate', [TrainerCourseController::class, 'duplicate']);
        Route::delete('/courses/{courseId}', [TrainerCourseController::class, 'destroy']);
        Route::post('/courses/{courseId}/modules', [TrainerCourseController::class, 'storeModule']);
        Route::put('/courses/{courseId}/modules/reorder', [TrainerCourseController::class, 'reorderModules']);
        Route::post('/courses/{courseId}/modules/{moduleId}/lessons', [TrainerCourseController::class, 'storeLesson']);
        Route::put('/courses/{courseId}/lessons/{lessonId}', [TrainerCourseController::class, 'updateLesson']);
        Route::delete('/courses/{courseId}/lessons/{lessonId}', [TrainerCourseController::class, 'destroyLesson']);

        Route::get('/students', [TrainerStudentController::class, 'index']);
        Route::get('/students/{studentId}', [TrainerStudentController::class, 'show']);

        Route::get('/assignments', [TrainerAssignmentController::class, 'index']);
        Route::post('/assignments', [TrainerAssignmentController::class, 'store']);
        Route::get('/assignments/{assignmentId}', [TrainerAssignmentController::class, 'show']);
        Route::put('/assignments/{assignmentId}', [TrainerAssignmentController::class, 'update']);
        Route::post('/assignments/{assignmentId}/publish', [TrainerAssignmentController::class, 'publish']);
        Route::post('/assignments/{assignmentId}/duplicate', [TrainerAssignmentController::class, 'duplicate']);
        Route::post('/assignments/{assignmentId}/archive', [TrainerAssignmentController::class, 'archive']);
        Route::delete('/assignments/{assignmentId}', [TrainerAssignmentController::class, 'destroy']);
        Route::post('/assignments/{assignmentId}/extend-deadline', [TrainerAssignmentController::class, 'extendDeadline']);
        Route::post('/assignments/{assignmentId}/close', [TrainerAssignmentController::class, 'close']);
        Route::post('/assignments/{assignmentId}/reopen', [TrainerAssignmentController::class, 'reopen']);
        Route::get('/assignments/{assignmentId}/submissions', [TrainerAssignmentController::class, 'submissions']);
        Route::put('/assignments/{assignmentId}/submissions/{submissionId}/grade', [TrainerAssignmentController::class, 'gradeSubmission']);
        Route::post('/assignments/{assignmentId}/submissions/{submissionId}/request-resubmission', [TrainerAssignmentController::class, 'requestResubmission']);
        Route::delete('/assignments/{assignmentId}/submissions/{submissionId}', [TrainerAssignmentController::class, 'destroySubmission']);

        Route::get('/projects', [TrainerProjectController::class, 'index']);
        Route::post('/projects', [TrainerProjectController::class, 'store']);
        Route::get('/projects/{projectId}', [TrainerProjectController::class, 'show']);
        Route::post('/projects/{projectId}/review', [TrainerProjectController::class, 'saveReview']);
        Route::post('/projects/{projectId}/approve', [TrainerProjectController::class, 'approve']);
        Route::post('/projects/{projectId}/request-changes', [TrainerProjectController::class, 'requestChanges']);
        Route::post('/projects/{projectId}/unpublish', [TrainerProjectController::class, 'unpublish']);
        Route::delete('/projects/{projectId}', [TrainerProjectController::class, 'destroy']);

        Route::get('/competitions', [TrainerCompetitionController::class, 'index']);
        Route::post('/competitions', [TrainerCompetitionController::class, 'store']);
        Route::get('/competitions/{competitionId}', [TrainerCompetitionController::class, 'show']);
        Route::put('/competitions/{competitionId}', [TrainerCompetitionController::class, 'update']);
        Route::patch('/competitions/{competitionId}', [TrainerCompetitionController::class, 'update']);
        Route::delete('/competitions/{competitionId}', [TrainerCompetitionController::class, 'destroy']);
        Route::patch('/competitions/{competitionId}/status', [TrainerCompetitionController::class, 'updateStatus']);
        Route::get('/competitions/{competitionId}/registrations', [TrainerCompetitionController::class, 'registrations']);
        Route::patch('/competitions/{competitionId}/registrations/{registrationId}/approve', [TrainerCompetitionController::class, 'approveRegistration']);
        Route::patch('/competitions/{competitionId}/registrations/{registrationId}/reject', [TrainerCompetitionController::class, 'rejectRegistration']);
        Route::patch('/competitions/{competitionId}/registrations/{registrationId}/disqualify', [TrainerCompetitionController::class, 'disqualifyRegistration']);
        Route::get('/competitions/{competitionId}/submissions', [TrainerCompetitionController::class, 'submissions']);
        Route::get('/competitions/{competitionId}/submissions/{submissionId}', [TrainerCompetitionController::class, 'showSubmission']);
        Route::patch('/competitions/{competitionId}/submissions/{submissionId}/review', [TrainerCompetitionController::class, 'reviewSubmission']);
        Route::put('/competitions/{competitionId}/submissions/{submissionId}/score', [TrainerCompetitionController::class, 'scoreSubmission']);
        Route::get('/competitions/{competitionId}/results', [TrainerCompetitionController::class, 'results']);
        Route::post('/competitions/{competitionId}/results/publish', [TrainerCompetitionController::class, 'publishResults']);


        Route::get('/profile', [TrainerProfileController::class, 'show']);
        Route::put('/profile', [TrainerProfileController::class, 'update']);
        Route::patch('/profile', [TrainerProfileController::class, 'update']);
        Route::post('/profile/avatar', [TrainerProfileController::class, 'uploadAvatar']);
        Route::delete('/profile/avatar', [TrainerProfileController::class, 'deleteAvatar']);
        Route::post('/profile/degree-certificate', [TrainerProfileController::class, 'uploadDegreeCertificate']);
        Route::delete('/profile/degree-certificate', [TrainerProfileController::class, 'deleteDegreeCertificate']);

        Route::get('/announcements', [TrainerAnnouncementController::class, 'index']);
        Route::post('/announcements', [TrainerAnnouncementController::class, 'store']);
        Route::post('/announcements/publish-due', [TrainerAnnouncementController::class, 'publishDue']);
        Route::get('/announcements/{announcementId}', [TrainerAnnouncementController::class, 'show']);
        Route::put('/announcements/{announcementId}', [TrainerAnnouncementController::class, 'update']);
        Route::patch('/announcements/{announcementId}', [TrainerAnnouncementController::class, 'update']);
        Route::delete('/announcements/{announcementId}', [TrainerAnnouncementController::class, 'destroy']);
        Route::post('/announcements/{announcementId}/publish', [TrainerAnnouncementController::class, 'publish']);
        Route::post('/announcements/{announcementId}/schedule', [TrainerAnnouncementController::class, 'schedule']);
        Route::post('/announcements/{announcementId}/archive', [TrainerAnnouncementController::class, 'archive']);
        Route::post('/announcements/{announcementId}/duplicate', [TrainerAnnouncementController::class, 'duplicate']);
        Route::get('/announcements/{announcementId}/stats', [TrainerAnnouncementController::class, 'stats']);

        Route::put('/settings/password', [TrainerSettingsController::class, 'changePassword']);
       
        Route::get('/notifications', [TrainerNotificationController::class, 'index']);
        Route::put('/notifications/read-all', [TrainerNotificationController::class, 'markAllAsRead']);
        Route::put('/notifications/{notificationId}/read', [TrainerNotificationController::class, 'markAsRead']);

        Route::get('/messages/conversations',[TrainerMessageController::class, 'index']);
        Route::get('/messages/conversations/{conversationId}',[TrainerMessageController::class, 'show']);
        Route::put('/messages/conversations/{conversationId}/read',[TrainerMessageController::class, 'markAsRead']);
        Route::put('/messages/conversations/{conversationId}/accept',[TrainerMessageController::class, 'accept']);
        Route::put('/messages/conversations/{conversationId}/decline',[TrainerMessageController::class, 'decline']);
        Route::post('/messages/conversations/{conversationId}/messages',[TrainerMessageController::class, 'sendMessage']);
        Route::put('/messages/conversations/{conversationId}/messages/{messageId}',[TrainerMessageController::class, 'updateMessage']);
        Route::delete('/messages/conversations/{conversationId}/messages/{messageId}',[TrainerMessageController::class, 'deleteMessage']);
        Route::put('/messages/conversations/{conversationId}/block',[TrainerMessageController::class, 'toggleBlock']);
        Route::delete('/messages/conversations/{conversationId}/messages',[TrainerMessageController::class, 'clearConversation']);
    });
});


Route::post('/student/login', [StudentAuthController::class, 'login']);
Route::post('/student/register', [StudentAuthController::class, 'register']);
Route::post('/student/forgot-password', [StudentAuthController::class, 'forgotPassword']);
Route::post('/student/forgot-password/verify', [StudentAuthController::class, 'verifyResetCode']);
Route::post('/student/reset-password', [StudentAuthController::class, 'resetPassword']);


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
    Route::get('/notifications', [
        NotificationController::class,
        'index'
    ]);
    Route::put('/notifications/read-all', [
        NotificationController::class,
        'markAllAsRead'
    ]);
    Route::put('/notifications/{notificationId}/read', [
        NotificationController::class,
        'markAsRead'
    ]);

    Route::get('/settings', [
        SettingsController::class,
        'index'
    ]);

    Route::put('/settings', [
        SettingsController::class,
        'update'
    ]);

    Route::put('/settings/password', [
        SettingsController::class,
        'changePassword'
    ]);

    Route::post('/settings/reset', [
        SettingsController::class,
        'reset'
    ]);
    Route::get(
        '/messages/directory',
        [MessageController::class, 'directory']
    );

    Route::get(
        '/messages/conversations',
        [MessageController::class, 'index']
    );

    Route::post(
        '/messages/conversations',
        [MessageController::class, 'storeConversation']
    );

    Route::get(
        '/messages/conversations/{conversationId}',
        [MessageController::class, 'show']
    );

    Route::put(
        '/messages/conversations/{conversationId}/read',
        [MessageController::class, 'markAsRead']
    );

    Route::post(
        '/messages/conversations/{conversationId}/messages',
        [MessageController::class, 'sendMessage']
    );

    Route::put(
        '/messages/conversations/{conversationId}/messages/{messageId}',
        [MessageController::class, 'updateMessage']
    );

    Route::delete(
        '/messages/conversations/{conversationId}/messages/{messageId}',
        [MessageController::class, 'deleteMessage']
    );

    Route::put(
        '/messages/conversations/{conversationId}/block',
        [MessageController::class, 'toggleBlock']
    );

    Route::delete(
        '/messages/conversations/{conversationId}/messages',
        [MessageController::class, 'clearConversation']
    );


    Route::get(
        '/announcements/{announcementId}',
        [StudentAnnouncementController::class, 'show']
    );

    Route::put(
        '/announcements/{announcementId}/read',
        [StudentAnnouncementController::class, 'markAsRead']
    );
});



Route::get('/portfolio/{portfolioCode}', [
    AchievementController::class,
    'publicShow'
]);








Route::post('/trainer/login', [TrainerAuthController::class, 'login']);
