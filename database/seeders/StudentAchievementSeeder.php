<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudentAchievementSeeder extends Seeder
{
    public function run(): void
    {
        $studentUser = DB::table('users')
            ->where('email', 'student@test.com')
            ->first();

        $trainerUser = DB::table('users')
            ->where('email', 'trainer@test.com')
            ->first();

        if (!$studentUser || !$trainerUser) {
            return;
        }

        $student = DB::table('students')
            ->where('user_id', $studentUser->id)
            ->first();

        $trainer = DB::table('trainers')
            ->where('user_id', $trainerUser->id)
            ->first();

        if (!$student || !$trainer) {
            return;
        }

        $badges = [
            [
                'name' => 'Getting Started',
                'description' => 'Enroll in your first course',
                'icon' => '🎯',
                'tier' => 'bronze',
                'condition_type' => 'enrolled_courses',
                'condition_value' => 1,
            ],
            [
                'name' => 'First Lesson Complete',
                'description' => 'Complete your first lesson',
                'icon' => '📖',
                'tier' => 'bronze',
                'condition_type' => 'completed_lessons',
                'condition_value' => 1,
            ],
            [
                'name' => 'Halfway There',
                'description' => 'Reach 50% progress in any course',
                'icon' => '⚡',
                'tier' => 'silver',
                'condition_type' => 'course_progress',
                'condition_value' => 50,
            ],
            [
                'name' => 'Course Graduate',
                'description' => 'Complete 100% of a course',
                'icon' => '🎓',
                'tier' => 'gold',
                'condition_type' => 'completed_courses',
                'condition_value' => 1,
            ],
            [
                'name' => 'Multi-Track Learner',
                'description' => 'Be enrolled in 3 or more courses',
                'icon' => '🧭',
                'tier' => 'silver',
                'condition_type' => 'enrolled_courses',
                'condition_value' => 3,
            ],
            [
                'name' => 'Knowledge Seeker',
                'description' => 'Complete at least one lesson in 3 different courses',
                'icon' => '🔍',
                'tier' => 'silver',
                'condition_type' => 'courses_with_completed_lesson',
                'condition_value' => 3,
            ],
            [
                'name' => 'Assignment Ace',
                'description' => 'Submit 5 assignments',
                'icon' => '📝',
                'tier' => 'gold',
                'condition_type' => 'submitted_assignments',
                'condition_value' => 5,
            ],
            [
                'name' => 'Competitor',
                'description' => 'Register for your first competition',
                'icon' => '🏆',
                'tier' => 'bronze',
                'condition_type' => 'competition_registrations',
                'condition_value' => 1,
            ],
        ];

        foreach ($badges as $badge) {
            DB::table('badges')->updateOrInsert(
                [
                    'name' => $badge['name'],
                ],
                [
                    ...$badge,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $programs = [
            [
                'title' => 'Advanced React Development',
                'provider' => 'Compass Academy',
                'duration_hours' => 40,
                'description' => 'Advanced practical React training.',
            ],
            [
                'title' => 'RESTful API Engineering',
                'provider' => 'Compass Academy',
                'duration_hours' => 32,
                'description' => 'Practical API design and implementation training.',
            ],
        ];

        foreach ($programs as $index => $program) {
            $existing = DB::table('training_programs')
                ->where('title', $program['title'])
                ->where('provider', $program['provider'])
                ->first();

            if ($existing) {
                $programId = $existing->id;

                DB::table('training_programs')
                    ->where('id', $programId)
                    ->update([
                        'duration_hours' =>
                            $program['duration_hours'],
                        'description' =>
                            $program['description'],
                        'updated_at' => now(),
                    ]);
            } else {
                $programId = DB::table(
                    'training_programs'
                )->insertGetId([
                    ...$program,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table(
                'student_training_programs'
            )->updateOrInsert(
                [
                    'student_id' => $student->id,
                    'training_program_id' =>
                        $programId,
                ],
                [
                    'completed_at' =>
                        now()->subDays(
                            60 - ($index * 20)
                        ),
                    'is_verified' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        DB::table('mentor_evaluations')
            ->where('student_id', $student->id)
            ->where('trainer_id', $trainer->id)
            ->whereIn('evaluation', [
                'Demonstrates strong engineering judgment, reliable delivery, and thoughtful attention to product quality.',
                'A proactive learner who communicates clearly and turns feedback into measurable improvements.',
            ])
            ->delete();

        DB::table('mentor_evaluations')->insert([
            [
                'student_id' => $student->id,
                'trainer_id' => $trainer->id,
                'score' => 94,
                'evaluation' => 'Demonstrates strong engineering judgment, reliable delivery, and thoughtful attention to product quality.',
                'is_verified' => true,
                'created_at' => now()->subDays(30),
                'updated_at' => now()->subDays(30),
            ],
            [
                'student_id' => $student->id,
                'trainer_id' => $trainer->id,
                'score' => 92,
                'evaluation' => 'A proactive learner who communicates clearly and turns feedback into measurable improvements.',
                'is_verified' => true,
                'created_at' => now()->subDays(15),
                'updated_at' => now()->subDays(15),
            ],
        ]);
    }
}
