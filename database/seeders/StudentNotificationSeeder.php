<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudentNotificationSeeder extends Seeder
{
    public function run(): void
    {
        $studentUser = DB::table('users')
            ->where('email', 'student@test.com')
            ->first();

        if (!$studentUser) {
            return;
        }

        $titles = [
            'New assignment available',
            'Competition application approved',
            'Certificate added to your portfolio',
            'Course progress updated',
            'Platform maintenance notice',
            'Competition results published',
        ];

        DB::table('notifications')
            ->where('user_id', $studentUser->id)
            ->whereIn('title', $titles)
            ->delete();

        DB::table('notifications')->insert([
            [
                'user_id' => $studentUser->id,
                'type' => 'assignment',
                'title' => 'New assignment available',
                'message' => 'A new assignment is available in one of your enrolled courses.',
                'data' => json_encode([
                    'category' => 'academics',
                    'icon' => '📝',
                    'action_label' => 'View assignments',
                    'action_tab' => 'Assignments',
                ]),
                'read_at' => null,
                'created_at' => now()->subMinutes(12),
                'updated_at' => now()->subMinutes(12),
            ],
            [
                'user_id' => $studentUser->id,
                'type' => 'competition',
                'title' => 'Competition application approved',
                'message' => 'Your application was approved. You can now continue with the competition submission.',
                'data' => json_encode([
                    'category' => 'competitions',
                    'icon' => '🏆',
                    'featured' => true,
                    'action_label' => 'Open competition',
                    'action_tab' => 'Competitions',
                ]),
                'read_at' => null,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
            [
                'user_id' => $studentUser->id,
                'type' => 'certificate',
                'title' => 'Certificate added to your portfolio',
                'message' => 'A verified learning record is now available in your career portfolio.',
                'data' => json_encode([
                    'category' => 'academics',
                    'icon' => '🏅',
                    'action_label' => 'View portfolio',
                    'action_tab' => 'Achievements',
                ]),
                'read_at' => null,
                'created_at' => now()->subDay()->subHours(2),
                'updated_at' => now()->subDay()->subHours(2),
            ],
            [
                'user_id' => $studentUser->id,
                'type' => 'course',
                'title' => 'Course progress updated',
                'message' => 'Your latest lesson progress has been saved successfully.',
                'data' => json_encode([
                    'category' => 'academics',
                    'icon' => '📘',
                    'action_label' => 'My courses',
                    'action_tab' => 'MyCourses',
                ]),
                'read_at' => now()->subDay(),
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDay(),
            ],
            [
                'user_id' => $studentUser->id,
                'type' => 'system',
                'title' => 'Platform maintenance notice',
                'message' => 'Compass Academy will undergo scheduled maintenance this weekend.',
                'data' => json_encode([
                    'category' => 'system',
                    'icon' => '⚙️',
                ]),
                'read_at' => null,
                'created_at' => now()->subDays(4),
                'updated_at' => now()->subDays(4),
            ],
            [
                'user_id' => $studentUser->id,
                'type' => 'competition',
                'title' => 'Competition results published',
                'message' => 'The results for one of your completed competitions are now available.',
                'data' => json_encode([
                    'category' => 'competitions',
                    'icon' => '🥈',
                    'action_label' => 'View results',
                    'action_tab' => 'Competitions',
                ]),
                'read_at' => now()->subDays(6),
                'created_at' => now()->subDays(6),
                'updated_at' => now()->subDays(6),
            ],
        ]);
    }
}
