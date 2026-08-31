<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $student = DB::table('students')
            ->where('user_id', $user->id)
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.'
            ], 404);
        }

        $enrollments = DB::table('enrollments')
            ->where('student_id', $student->id)
            ->get()
            ->keyBy('course_id');

        $courses = $this->courseQuery()
            ->where('c.status', 'published')
            ->orderByDesc('c.published_at')
            ->orderByDesc('c.id')
            ->get()
            ->map(function ($course) use ($enrollments) {
                return $this->formatCourse(
                    $course,
                    $enrollments->get($course->id)
                );
            })
            ->values();

        $categories = DB::table('categories as cat')
            ->join('courses as c', 'c.category_id', '=', 'cat.id')
            ->where('c.status', 'published')
            ->select(
                'cat.id',
                'cat.name',
                'cat.slug'
            )
            ->distinct()
            ->orderBy('cat.name')
            ->get();

        return response()->json([
            'courses' => $courses,
            'categories' => $categories,
        ]);
    }

    public function show(Request $request, int $courseId)
    {
        $user = $request->user();

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $student = DB::table('students')
            ->where('user_id', $user->id)
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.'
            ], 404);
        }

        $course = $this->courseQuery()
            ->where('c.id', $courseId)
            ->where('c.status', 'published')
            ->first();

        if (!$course) {
            return response()->json([
                'message' => 'Course not found.'
            ], 404);
        }

        $enrollment = DB::table('enrollments')
            ->where('student_id', $student->id)
            ->where('course_id', $courseId)
            ->first();

        $learningOutcomes = DB::table('course_learning_outcomes')
            ->where('course_id', $courseId)
            ->orderBy('position')
            ->pluck('title')
            ->values();

        $requirements = DB::table('course_requirements')
            ->where('course_id', $courseId)
            ->orderBy('position')
            ->pluck('requirement')
            ->values();

        $resources = DB::table('course_resources')
            ->where('course_id', $courseId)
            ->where('is_published', true)
            ->orderBy('position')
            ->get()
            ->map(function ($resource) {
                return [
                    'id' => $resource->id,
                    'title' => $resource->title,
                    'type' => $resource->type,
                    'url' => $resource->url,
                    'file_url' => $resource->file_path
                        ? asset('storage/' . ltrim($resource->file_path, '/'))
                        : null,
                ];
            })
            ->values();

        $modules = DB::table('course_modules')
            ->where('course_id', $courseId)
            ->orderBy('position')
            ->get()
            ->map(function ($module) {
                $lessons = DB::table('lessons')
                    ->where('course_module_id', $module->id)
                    ->where('is_published', true)
                    ->orderBy('position')
                    ->get()
                    ->map(function ($lesson) {
                        return [
                            'id' => $lesson->id,
                            'title' => $lesson->title,
                            'description' => $lesson->description,
                            'type' => $lesson->type,
                            'duration_minutes' => $lesson->duration_minutes,
                            'position' => $lesson->position,
                        ];
                    })
                    ->values();

                return [
                    'id' => $module->id,
                    'title' => $module->title,
                    'position' => $module->position,
                    'lessons' => $lessons,
                ];
            })
            ->values();

        $formattedCourse = $this->formatCourse(
            $course,
            $enrollment
        );

        return response()->json([
            'course' => [
                ...$formattedCourse,
                'learning_outcomes' => $learningOutcomes,
                'requirements' => $requirements,
                'modules' => $modules,
                'resources' => $resources,
            ],
        ]);
    }

    public function enroll(Request $request, int $courseId)
    {
        $user = $request->user();

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $student = DB::table('students')
            ->where('user_id', $user->id)
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.'
            ], 404);
        }

        $course = DB::table('courses')
            ->where('id', $courseId)
            ->where('status', 'published')
            ->first();

        if (!$course) {
            return response()->json([
                'message' => 'Course not found.'
            ], 404);
        }

        $enrollment = DB::table('enrollments')
            ->where('student_id', $student->id)
            ->where('course_id', $courseId)
            ->first();

        if (!$enrollment) {
            $enrollmentId = DB::table('enrollments')->insertGetId([
                'student_id' => $student->id,
                'course_id' => $courseId,
                'status' => 'active',
                'enrolled_at' => now(),
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $enrollment = DB::table('enrollments')
                ->where('id', $enrollmentId)
                ->first();

            return response()->json([
                'message' => 'Course enrollment successful.',
                'enrollment' => $enrollment,
            ], 201);
        }

        if ($enrollment->status === 'dropped') {
            DB::table('enrollments')
                ->where('id', $enrollment->id)
                ->update([
                    'status' => 'active',
                    'enrolled_at' => now(),
                    'completed_at' => null,
                    'updated_at' => now(),
                ]);

            $enrollment = DB::table('enrollments')
                ->where('id', $enrollment->id)
                ->first();
        }

        return response()->json([
            'message' => 'Student is already enrolled in this course.',
            'enrollment' => $enrollment,
        ]);
    }

    private function courseQuery()
    {
        $reviewStats = DB::table('course_reviews')
            ->select(
                'course_id',
                DB::raw('ROUND(AVG(rating), 1) as rating'),
                DB::raw('COUNT(*) as reviews')
            )
            ->groupBy('course_id');

        $studentStats = DB::table('enrollments')
            ->whereIn('status', ['active', 'completed'])
            ->select(
                'course_id',
                DB::raw('COUNT(*) as students')
            )
            ->groupBy('course_id');

        $lessonStats = DB::table('course_modules as cm')
            ->join(
                'lessons as l',
                'l.course_module_id',
                '=',
                'cm.id'
            )
            ->where('l.is_published', true)
            ->select(
                'cm.course_id',
                DB::raw('COUNT(l.id) as lessons')
            )
            ->groupBy('cm.course_id');

        $assignmentStats = DB::table('assignments')
            ->whereIn(
                'status',
                ['scheduled', 'active', 'closed']
            )
            ->select(
                'course_id',
                DB::raw('COUNT(*) as assignments')
            )
            ->groupBy('course_id');

        return DB::table('courses as c')
            ->join(
                'categories as cat',
                'c.category_id',
                '=',
                'cat.id'
            )
            ->join(
                'trainers as t',
                'c.trainer_id',
                '=',
                't.id'
            )
            ->join(
                'users as u',
                't.user_id',
                '=',
                'u.id'
            )
            ->leftJoinSub(
                $reviewStats,
                'review_stats',
                'review_stats.course_id',
                '=',
                'c.id'
            )
            ->leftJoinSub(
                $studentStats,
                'student_stats',
                'student_stats.course_id',
                '=',
                'c.id'
            )
            ->leftJoinSub(
                $lessonStats,
                'lesson_stats',
                'lesson_stats.course_id',
                '=',
                'c.id'
            )
            ->leftJoinSub(
                $assignmentStats,
                'assignment_stats',
                'assignment_stats.course_id',
                '=',
                'c.id'
            )
            ->select(
                'c.id',
                'c.title',
                'c.slug',
                'c.description',
                'c.level',
                'c.duration_weeks',
                'c.cover_image',
                'c.published_at',
                'cat.id as category_id',
                'cat.name as category_name',
                'cat.slug as category_slug',
                't.id as trainer_id',
                't.job_title as instructor_title',
                't.bio as instructor_bio',
                'u.name as instructor_name',
                DB::raw('COALESCE(review_stats.rating, 0) as rating'),
                DB::raw('COALESCE(review_stats.reviews, 0) as reviews'),
                DB::raw('COALESCE(student_stats.students, 0) as students'),
                DB::raw('COALESCE(lesson_stats.lessons, 0) as lessons'),
                DB::raw('COALESCE(assignment_stats.assignments, 0) as assignments')
            );
    }

    private function formatCourse($course, $enrollment): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'description' => $course->description,
            'level' => $course->level,
            'duration_weeks' => $course->duration_weeks,
            'cover_image' => $this->coverUrl(
                $course->cover_image
            ),
            'published_at' => $course->published_at,
            'category' => [
                'id' => $course->category_id,
                'name' => $course->category_name,
                'slug' => $course->category_slug,
            ],
            'instructor' => [
                'id' => $course->trainer_id,
                'name' => $course->instructor_name,
                'title' => $course->instructor_title,
                'bio' => $course->instructor_bio,
            ],
            'rating' => (float) $course->rating,
            'reviews' => (int) $course->reviews,
            'students' => (int) $course->students,
            'lessons' => (int) $course->lessons,
            'assignments' => (int) $course->assignments,
            'is_enrolled' => $enrollment !== null &&
                in_array(
                    $enrollment->status,
                    ['active', 'completed'],
                    true
                ),
            'enrollment_status' => $enrollment?->status,
            'enrolled_at' => $enrollment?->enrolled_at,
        ];
    }

    private function coverUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (
            str_starts_with($path, 'http://') ||
            str_starts_with($path, 'https://')
        ) {
            return $path;
        }

        return asset(
            'storage/' . ltrim($path, '/')
        );
    }
}
