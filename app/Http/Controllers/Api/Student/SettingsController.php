<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $settings = $this->ensureSettings($student->id);

        return response()->json([
            'settings' => $this->settingsData($settings),
        ]);
    }

    public function update(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $validated = $request->validate([
            'theme' => [
                'sometimes',
                Rule::in(['light', 'dark']),
            ],
            'language' => [
                'sometimes',
                Rule::in(['en', 'ar']),
            ],
            'accessibility' => [
                'sometimes',
                'array',
            ],
            'accessibility.fontSize' => [
                'sometimes',
                Rule::in(['small', 'medium', 'large']),
            ],
            'notifications' => [
                'sometimes',
                'array',
            ],
            'notifications.assignment' => [
                'sometimes',
                'boolean',
            ],
            'notifications.grade' => [
                'sometimes',
                'boolean',
            ],
            'notifications.course' => [
                'sometimes',
                'boolean',
            ],
            'notifications.project' => [
                'sometimes',
                'boolean',
            ],
            'notifications.competition' => [
                'sometimes',
                'boolean',
            ],
            'notifications.message' => [
                'sometimes',
                'boolean',
            ],
            'notifications.announcement' => [
                'sometimes',
                'boolean',
            ],
            'notifications.achievement' => [
                'sometimes',
                'boolean',
            ],
            'privacy' => [
                'sometimes',
                'array',
            ],
            'privacy.profileVisibility' => [
                'sometimes',
                Rule::in([
                    'academy',
                    'instructors',
                    'private',
                ]),
            ],
            'privacy.showActivity' => [
                'sometimes',
                'boolean',
            ],
            'privacy.showAchievements' => [
                'sometimes',
                'boolean',
            ],
            'privacy.allowInstructorMessages' => [
                'sometimes',
                'boolean',
            ],
            'privacy.portfolioVisibility' => [
                'sometimes',
                Rule::in(['public', 'private']),
            ],
        ]);

        $this->ensureSettings($student->id);

        $updates = [];

        if (Arr::has($validated, 'theme')) {
            $updates['theme'] = Arr::get($validated, 'theme');
        }

        if (Arr::has($validated, 'language')) {
            $updates['language'] = Arr::get($validated, 'language');
        }

        if (Arr::has($validated, 'accessibility.fontSize')) {
            $updates['font_size'] = Arr::get(
                $validated,
                'accessibility.fontSize'
            );
        }

        $notificationMap = [
            'assignment' => 'assignment_notifications',
            'grade' => 'grade_notifications',
            'course' => 'course_notifications',
            'project' => 'project_notifications',
            'competition' => 'competition_notifications',
            'message' => 'message_notifications',
            'announcement' => 'announcement_notifications',
            'achievement' => 'achievement_notifications',
        ];

        foreach ($notificationMap as $key => $column) {
            $path = 'notifications.' . $key;

            if (Arr::has($validated, $path)) {
                $updates[$column] = (bool) Arr::get(
                    $validated,
                    $path
                );
            }
        }

        $privacyMap = [
            'profileVisibility' => 'profile_visibility',
            'showActivity' => 'show_activity',
            'showAchievements' => 'show_achievements',
            'allowInstructorMessages' =>
                'allow_instructor_messages',
            'portfolioVisibility' => 'portfolio_visibility',
        ];

        foreach ($privacyMap as $key => $column) {
            $path = 'privacy.' . $key;

            if (Arr::has($validated, $path)) {
                $value = Arr::get($validated, $path);

                $updates[$column] = is_bool($value)
                    ? $value
                    : $value;
            }
        }

        if ($updates) {
            $updates['updated_at'] = now();

            DB::table('student_settings')
                ->where('student_id', $student->id)
                ->update($updates);
        }

        $settings = DB::table('student_settings')
            ->where('student_id', $student->id)
            ->first();

        return response()->json([
            'message' => 'Settings updated successfully.',
            'settings' => $this->settingsData($settings),
        ]);
    }

    public function reset(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $this->ensureSettings($student->id);

        DB::table('student_settings')
            ->where('student_id', $student->id)
            ->update([
                ...$this->defaultColumns(),
                'updated_at' => now(),
            ]);

        $settings = DB::table('student_settings')
            ->where('student_id', $student->id)
            ->first();

        return response()->json([
            'message' => 'Default settings restored successfully.',
            'settings' => $this->settingsData($settings),
        ]);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'student') {
            return response()->json([
                'message' => 'Student access required.',
            ], 403);
        }

        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'confirmed',
            ],
        ]);

        if (!Hash::check(
            $validated['current_password'],
            $user->password
        )) {
            return response()->json([
                'message' => 'The current password is incorrect.',
            ], 422);
        }

        if (Hash::check(
            $validated['password'],
            $user->password
        )) {
            return response()->json([
                'message' => 'The new password must be different from the current password.',
            ], 422);
        }

        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'password' => Hash::make(
                    $validated['password']
                ),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    private function studentFromRequest(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'student') {
            return null;
        }

        return DB::table('students')
            ->where('user_id', $user->id)
            ->first();
    }

    private function ensureSettings(int $studentId)
    {
        $settings = DB::table('student_settings')
            ->where('student_id', $studentId)
            ->first();

        if ($settings) {
            return $settings;
        }

        DB::table('student_settings')->insert([
            'student_id' => $studentId,
            ...$this->defaultColumns(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('student_settings')
            ->where('student_id', $studentId)
            ->first();
    }

    private function defaultColumns(): array
    {
        return [
            'theme' => 'light',
            'language' => 'en',
            'font_size' => 'medium',
            'assignment_notifications' => true,
            'grade_notifications' => true,
            'course_notifications' => true,
            'project_notifications' => true,
            'competition_notifications' => true,
            'message_notifications' => true,
            'announcement_notifications' => true,
            'achievement_notifications' => true,
            'profile_visibility' => 'academy',
            'show_activity' => true,
            'show_achievements' => true,
            'allow_instructor_messages' => true,
            'portfolio_visibility' => 'public',
        ];
    }

    private function settingsData($settings): array
    {
        return [
            'theme' => $settings->theme,
            'language' => $settings->language,
            'notifications' => [
                'assignment' =>
                    (bool) $settings->assignment_notifications,
                'grade' =>
                    (bool) $settings->grade_notifications,
                'course' =>
                    (bool) $settings->course_notifications,
                'project' =>
                    (bool) $settings->project_notifications,
                'competition' =>
                    (bool) $settings->competition_notifications,
                'message' =>
                    (bool) $settings->message_notifications,
                'announcement' =>
                    (bool) $settings->announcement_notifications,
                'achievement' =>
                    (bool) $settings->achievement_notifications,
            ],
            'privacy' => [
                'profileVisibility' =>
                    $settings->profile_visibility,
                'showActivity' =>
                    (bool) $settings->show_activity,
                'showAchievements' =>
                    (bool) $settings->show_achievements,
                'allowInstructorMessages' =>
                    (bool) $settings->allow_instructor_messages,
                'portfolioVisibility' =>
                    $settings->portfolio_visibility,
            ],
            'accessibility' => [
                'fontSize' => $settings->font_size,
            ],
            'updated_at' => $settings->updated_at,
        ];
    }
}
