<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StudentDashboardSeeder extends Seeder
{
    public function run(): void
    {
        $studentUser = User::where('email', 'student@test.com')->firstOrFail();

        $student = DB::table('students')
            ->where('user_id', $studentUser->id)
            ->first();

        $trainerUser = User::updateOrCreate(
            [
                'email' => 'trainer@test.com',
            ],
            [
                'name' => 'Ahmad Instructor',
                'password' => Hash::make('12345678'),
                'role' => 'trainer',
            ]
        );

        DB::table('trainers')->updateOrInsert(
            [
                'user_id' => $trainerUser->id,
            ],
            [
                'job_title' => 'Senior Software Engineering Trainer',
                'bio' => 'Software engineering and backend development trainer.',
                'status' => 'active',
                'is_verified' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $trainer = DB::table('trainers')
            ->where('user_id', $trainerUser->id)
            ->first();

        $categories = [
            [
                'name' => 'Backend Development',
                'slug' => 'backend-development',
            ],
            [
                'name' => 'Frontend Development',
                'slug' => 'frontend-development',
            ],
            [
                'name' => 'Database',
                'slug' => 'database',
            ],
            [
                'name' => 'Software Engineering',
                'slug' => 'software-engineering',
            ],
        ];

        foreach ($categories as $category) {
            DB::table('categories')->updateOrInsert(
                [
                    'slug' => $category['slug'],
                ],
                [
                    'name' => $category['name'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $backendCategory = DB::table('categories')
            ->where('slug', 'backend-development')
            ->first();

        $frontendCategory = DB::table('categories')
            ->where('slug', 'frontend-development')
            ->first();

        $databaseCategory = DB::table('categories')
            ->where('slug', 'database')
            ->first();

        $softwareCategory = DB::table('categories')
            ->where('slug', 'software-engineering')
            ->first();

        $courses = [
            [
                'category_id' => $backendCategory->id,
                'title' => 'Laravel Backend Development',
                'slug' => 'laravel-backend-development',
                'description' => 'Build modern backend applications and REST APIs using Laravel.',
                'level' => 'intermediate',
                'duration_weeks' => 8,
            ],
            [
                'category_id' => $databaseCategory->id,
                'title' => 'Database Design Fundamentals',
                'slug' => 'database-design-fundamentals',
                'description' => 'Learn relational database design, normalization, and SQL.',
                'level' => 'beginner',
                'duration_weeks' => 6,
            ],
            [
                'category_id' => $softwareCategory->id,
                'title' => 'Git & GitHub Essentials',
                'slug' => 'git-github-essentials',
                'description' => 'Learn practical version control workflows using Git and GitHub.',
                'level' => 'beginner',
                'duration_weeks' => 4,
            ],
            [
                'category_id' => $backendCategory->id,
                'title' => 'Advanced Laravel APIs',
                'slug' => 'advanced-laravel-apis',
                'description' => 'Authentication, API architecture, validation, resources, and advanced Laravel techniques.',
                'level' => 'advanced',
                'duration_weeks' => 7,
            ],
            [
                'category_id' => $frontendCategory->id,
                'title' => 'React Fundamentals',
                'slug' => 'react-fundamentals',
                'description' => 'Build modern interactive frontend applications using React.',
                'level' => 'beginner',
                'duration_weeks' => 6,
            ],
            [
                'category_id' => $databaseCategory->id,
                'title' => 'Advanced MySQL',
                'slug' => 'advanced-mysql',
                'description' => 'Queries, indexing, optimization, transactions, and database performance.',
                'level' => 'advanced',
                'duration_weeks' => 5,
            ],
        ];

        foreach ($courses as $course) {
            DB::table('courses')->updateOrInsert(
                [
                    'slug' => $course['slug'],
                ],
                [
                    'trainer_id' => $trainer->id,
                    'category_id' => $course['category_id'],
                    'title' => $course['title'],
                    'description' => $course['description'],
                    'level' => $course['level'],
                    'duration_weeks' => $course['duration_weeks'],
                    'cover_image' => null,
                    'status' => 'published',
                    'published_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $laravelCourse = DB::table('courses')
            ->where('slug', 'laravel-backend-development')
            ->first();

        $databaseCourse = DB::table('courses')
            ->where('slug', 'database-design-fundamentals')
            ->first();

        $gitCourse = DB::table('courses')
            ->where('slug', 'git-github-essentials')
            ->first();

        DB::table('student_interests')->updateOrInsert(
            [
                'student_id' => $student->id,
                'category_id' => $backendCategory->id,
            ],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('enrollments')->updateOrInsert(
            [
                'student_id' => $student->id,
                'course_id' => $laravelCourse->id,
            ],
            [
                'status' => 'active',
                'enrolled_at' => now()->subDays(20),
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('enrollments')->updateOrInsert(
            [
                'student_id' => $student->id,
                'course_id' => $databaseCourse->id,
            ],
            [
                'status' => 'active',
                'enrolled_at' => now()->subDays(7),
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('enrollments')->updateOrInsert(
            [
                'student_id' => $student->id,
                'course_id' => $gitCourse->id,
            ],
            [
                'status' => 'completed',
                'enrolled_at' => now()->subDays(60),
                'completed_at' => now()->subDays(15),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $moduleId = $this->upsertModule(
            $laravelCourse->id,
            'Building REST APIs',
            1
        );

        $lesson1 = $this->upsertLesson(
            $moduleId,
            'Introduction to REST APIs',
            1,
            18
        );

        $lesson2 = $this->upsertLesson(
            $moduleId,
            'API Routes and Controllers',
            2,
            25
        );

        $lesson3 = $this->upsertLesson(
            $moduleId,
            'Laravel API Validation',
            3,
            22
        );

        $lesson4 = $this->upsertLesson(
            $moduleId,
            'Laravel API Resources',
            4,
            30
        );

        DB::table('lesson_progress')->updateOrInsert(
            [
                'student_id' => $student->id,
                'lesson_id' => $lesson1,
            ],
            [
                'progress_percentage' => 100,
                'is_completed' => true,
                'started_at' => now()->subDays(5),
                'last_viewed_at' => now()->subDays(4),
                'completed_at' => now()->subDays(4),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('lesson_progress')->updateOrInsert(
            [
                'student_id' => $student->id,
                'lesson_id' => $lesson2,
            ],
            [
                'progress_percentage' => 100,
                'is_completed' => true,
                'started_at' => now()->subDays(3),
                'last_viewed_at' => now()->subDays(2),
                'completed_at' => now()->subDays(2),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('lesson_progress')->updateOrInsert(
            [
                'student_id' => $student->id,
                'lesson_id' => $lesson3,
            ],
            [
                'progress_percentage' => 35,
                'is_completed' => false,
                'started_at' => now()->subDay(),
                'last_viewed_at' => now()->subHours(5),
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $databaseModule = $this->upsertModule(
            $databaseCourse->id,
            'Relational Databases',
            1
        );

        $this->upsertLesson(
            $databaseModule,
            'Database Fundamentals',
            1,
            20
        );

        $this->upsertLesson(
            $databaseModule,
            'Normalization',
            2,
            28
        );

        $this->upsertAssignment(
            $laravelCourse->id,
            $trainer->id,
            'Build Student Authentication API',
            now()->addDays(2)
        );

        $this->upsertAssignment(
            $laravelCourse->id,
            $trainer->id,
            'Laravel Validation Exercise',
            now()->addDays(5)
        );

        $this->upsertAssignment(
            $databaseCourse->id,
            $trainer->id,
            'Design an ER Diagram',
            now()->addDays(7)
        );

        DB::table('projects')->updateOrInsert(
            [
                'owner_student_id' => $student->id,
                'title' => 'Student Management System',
            ],
            [
                'course_id' => $laravelCourse->id,
                'category_id' => $backendCategory->id,
                'description' => 'Student management platform built with Laravel.',
                'project_type' => 'individual',
                'status' => 'published',
                'is_featured' => true,
                'published_at' => now()->subDays(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('projects')->updateOrInsert(
            [
                'owner_student_id' => $student->id,
                'title' => 'Course API Platform',
            ],
            [
                'course_id' => $laravelCourse->id,
                'category_id' => $backendCategory->id,
                'description' => 'REST API project for managing online courses.',
                'project_type' => 'individual',
                'status' => 'draft',
                'is_featured' => false,
                'published_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('certificates')->updateOrInsert(
            [
                'student_id' => $student->id,
                'course_id' => $gitCourse->id,
            ],
            [
                'certificate_code' => 'CERT-STU001-GIT',
                'issued_at' => now()->subDays(15),
                'file_path' => null,
                'verification_url' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $advancedLaravel = DB::table('courses')
            ->where('slug', 'advanced-laravel-apis')
            ->first();

        $react = DB::table('courses')
            ->where('slug', 'react-fundamentals')
            ->first();

        $mysql = DB::table('courses')
            ->where('slug', 'advanced-mysql')
            ->first();

        $this->upsertReview(
            $student->id,
            $advancedLaravel->id,
            5,
            'Excellent advanced Laravel course.'
        );

        $this->upsertReview(
            $student->id,
            $react->id,
            4,
            'Clear introduction to React.'
        );

        $this->upsertReview(
            $student->id,
            $mysql->id,
            5,
            'Useful database optimization content.'
        );
    }

    private function upsertModule(
        int $courseId,
        string $title,
        int $position
    ): int {
        $module = DB::table('course_modules')
            ->where('course_id', $courseId)
            ->where('title', $title)
            ->first();

        if ($module) {
            DB::table('course_modules')
                ->where('id', $module->id)
                ->update([
                    'position' => $position,
                    'updated_at' => now(),
                ]);

            return $module->id;
        }

        return DB::table('course_modules')->insertGetId([
            'course_id' => $courseId,
            'title' => $title,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function upsertLesson(
        int $moduleId,
        string $title,
        int $position,
        int $duration
    ): int {
        $lesson = DB::table('lessons')
            ->where('course_module_id', $moduleId)
            ->where('title', $title)
            ->first();

        if ($lesson) {
            DB::table('lessons')
                ->where('id', $lesson->id)
                ->update([
                    'position' => $position,
                    'duration_minutes' => $duration,
                    'is_published' => true,
                    'updated_at' => now(),
                ]);

            return $lesson->id;
        }

        return DB::table('lessons')->insertGetId([
            'course_module_id' => $moduleId,
            'title' => $title,
            'description' => null,
            'type' => 'video',
            'content_url' => null,
            'duration_minutes' => $duration,
            'position' => $position,
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function upsertAssignment(
        int $courseId,
        int $trainerId,
        string $title,
        $deadline
    ): void {
        DB::table('assignments')->updateOrInsert(
            [
                'course_id' => $courseId,
                'title' => $title,
            ],
            [
                'trainer_id' => $trainerId,
                'description' => 'Complete the required task and submit it before the deadline.',
                'submission_instructions' => 'Submit your work through Compass Academy.',
                'max_grade' => 100,
                'opens_at' => now()->subDay(),
                'deadline_at' => $deadline,
                'status' => 'active',
                'published_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function upsertReview(
        int $studentId,
        int $courseId,
        int $rating,
        string $review
    ): void {
        DB::table('course_reviews')->updateOrInsert(
            [
                'course_id' => $courseId,
                'student_id' => $studentId,
            ],
            [
                'rating' => $rating,
                'review' => $review,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
