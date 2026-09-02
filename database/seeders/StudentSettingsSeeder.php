<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudentSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $user = DB::table('users')
            ->where('email', 'student@test.com')
            ->where('role', 'student')
            ->first();

        if (!$user) {
            return;
        }

        $student = DB::table('students')
            ->where('user_id', $user->id)
            ->first();

        if (!$student) {
            return;
        }

        DB::table('student_settings')->updateOrInsert(
            [
                'student_id' => $student->id,
            ],
            [
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
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
