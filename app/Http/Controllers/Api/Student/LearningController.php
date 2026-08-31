<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningController extends Controller
{
    public function index(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $enrollments = DB::table('enrollments')
            ->where('student_id', $student->id)
            ->whereIn('status', ['active', 'completed'])
            ->orderByDesc('enrolled_at')
            ->get();

        $courses = $enrollments
            ->map(function ($enrollment) use ($student) {
                $course = $this->baseCourseQuery()
                    ->where('c.id', $enrollment->course_id)
                    ->where('c.status', 'published')
                    ->first();

                if (!$course) {
                    return null;
                }

                $progress = $this->courseProgress(
                    $student->id,
                    $course->id
                );

                $nextLesson = $this->nextLesson(
                    $student->id,
                    $course->id
                );

                $upcomingAssignments = DB::table('assignments')
                    ->where('course_id', $course->id)
                    ->whereIn('status', ['scheduled', 'active'])
                    ->where(function ($query) {
                        $query
                            ->whereNull('deadline_at')
                            ->orWhere('deadline_at', '>=', now());
                    })
                    ->orderByRaw('deadline_at IS NULL')
                    ->orderBy('deadline_at')
                    ->limit(3)
                    ->get([
                        'id',
                        'title',
                        'deadline_at',
                        'status',
                    ]);

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                    'description' => $course->description,
                    'level' => $course->level,
                    'duration_weeks' => $course->duration_weeks,
                    'cover_image' => $this->fileUrl($course->cover_image),
                    'category' => [
                        'id' => $course->category_id,
                        'name' => $course->category_name,
                        'slug' => $course->category_slug,
                    ],
                    'instructor' => [
                        'id' => $course->trainer_id,
                        'name' => $course->instructor_name,
                        'title' => $course->instructor_title,
                    ],
                    'enrollment' => [
                        'id' => $enrollment->id,
                        'status' => $enrollment->status,
                        'enrolled_at' => $enrollment->enrolled_at,
                        'completed_at' => $enrollment->completed_at,
                    ],
                    'progress' => $progress['percentage'],
                    'completed_lessons' => $progress['completed_lessons'],
                    'total_lessons' => $progress['total_lessons'],
                    'next_lesson' => $nextLesson,
                    'upcoming_assignments' => $upcomingAssignments,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'courses' => $courses,
        ]);
    }

    public function show(Request $request, int $courseId)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $enrollment = $this->activeEnrollment(
            $student->id,
            $courseId
        );

        if (!$enrollment) {
            return response()->json([
                'message' => 'You are not enrolled in this course.',
            ], 403);
        }

        $course = $this->baseCourseQuery()
            ->where('c.id', $courseId)
            ->where('c.status', 'published')
            ->first();

        if (!$course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $progressRows = DB::table('lesson_progress')
            ->where('student_id', $student->id)
            ->get()
            ->keyBy('lesson_id');

        $bookmarks = DB::table('lesson_bookmarks')
            ->where('student_id', $student->id)
            ->pluck('lesson_id')
            ->mapWithKeys(fn ($lessonId) => [$lessonId => true]);

        $modules = DB::table('course_modules')
            ->where('course_id', $courseId)
            ->orderBy('position')
            ->get()
            ->map(function ($module) use ($progressRows, $bookmarks) {
                $lessons = DB::table('lessons')
                    ->where('course_module_id', $module->id)
                    ->where('is_published', true)
                    ->orderBy('position')
                    ->get()
                    ->map(function ($lesson) use ($progressRows, $bookmarks) {
                        $progress = $progressRows->get($lesson->id);

                        return [
                            'id' => $lesson->id,
                            'title' => $lesson->title,
                            'description' => $lesson->description,
                            'type' => $lesson->type,
                            'content_url' => $this->fileUrl(
                                $lesson->content_url
                            ),
                            'duration_minutes' => $lesson->duration_minutes,
                            'position' => $lesson->position,
                            'progress_percentage' => $progress
                                ? (int) $progress->progress_percentage
                                : 0,
                            'is_completed' => $progress
                                ? (bool) $progress->is_completed
                                : false,
                            'started_at' => $progress?->started_at,
                            'last_viewed_at' => $progress?->last_viewed_at,
                            'completed_at' => $progress?->completed_at,
                            'is_bookmarked' => (bool) $bookmarks->get(
                                $lesson->id,
                                false
                            ),
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
                    'url' => $this->fileUrl(
                        $resource->url ?: $resource->file_path
                    ),
                    'position' => $resource->position,
                ];
            })
            ->values();

        $assignments = DB::table('assignments as a')
            ->leftJoin('submissions as s', function ($join) use ($student) {
                $join
                    ->on('s.assignment_id', '=', 'a.id')
                    ->where('s.student_id', '=', $student->id);
            })
            ->where('a.course_id', $courseId)
            ->whereIn('a.status', ['scheduled', 'active', 'closed'])
            ->orderByRaw('a.deadline_at IS NULL')
            ->orderBy('a.deadline_at')
            ->select(
                'a.id',
                'a.title',
                'a.description',
                'a.submission_instructions',
                'a.max_grade',
                'a.opens_at',
                'a.deadline_at',
                'a.status',
                's.id as submission_id',
                's.status as submission_status',
                's.submitted_at',
                's.grade',
                's.feedback'
            )
            ->get()
            ->map(function ($assignment) {
                return [
                    'id' => $assignment->id,
                    'title' => $assignment->title,
                    'description' => $assignment->description,
                    'submission_instructions' =>
                        $assignment->submission_instructions,
                    'max_grade' => $assignment->max_grade,
                    'opens_at' => $assignment->opens_at,
                    'deadline_at' => $assignment->deadline_at,
                    'status' => $assignment->status,
                    'submission' => $assignment->submission_id
                        ? [
                            'id' => $assignment->submission_id,
                            'status' => $assignment->submission_status,
                            'submitted_at' => $assignment->submitted_at,
                            'grade' => $assignment->grade,
                            'feedback' => $assignment->feedback,
                        ]
                        : null,
                ];
            })
            ->values();

        $courseProgress = $this->courseProgress(
            $student->id,
            $courseId
        );

        return response()->json([
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'description' => $course->description,
                'level' => $course->level,
                'duration_weeks' => $course->duration_weeks,
                'cover_image' => $this->fileUrl($course->cover_image),
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
                'enrollment' => [
                    'id' => $enrollment->id,
                    'status' => $enrollment->status,
                    'enrolled_at' => $enrollment->enrolled_at,
                    'completed_at' => $enrollment->completed_at,
                ],
                'progress' => $courseProgress['percentage'],
                'completed_lessons' =>
                    $courseProgress['completed_lessons'],
                'total_lessons' =>
                    $courseProgress['total_lessons'],
                'next_lesson' => $this->nextLesson(
                    $student->id,
                    $courseId
                ),
                'learning_outcomes' => $learningOutcomes,
                'requirements' => $requirements,
                'resources' => $resources,
                'modules' => $modules,
                'assignments' => $assignments,
            ],
        ]);
    }

    public function updateProgress(
        Request $request,
        int $courseId,
        int $lessonId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $enrollment = $this->activeEnrollment(
            $student->id,
            $courseId
        );

        if (!$enrollment) {
            return response()->json([
                'message' => 'You are not enrolled in this course.',
            ], 403);
        }

        $lesson = $this->courseLesson($courseId, $lessonId);

        if (!$lesson) {
            return response()->json([
                'message' => 'Lesson not found in this course.',
            ], 404);
        }

        $validated = $request->validate([
            'is_completed' => ['required', 'boolean'],
            'progress_percentage' => [
                'nullable',
                'integer',
                'min:0',
                'max:100',
            ],
        ]);

        $existing = DB::table('lesson_progress')
            ->where('student_id', $student->id)
            ->where('lesson_id', $lessonId)
            ->first();

        $isCompleted = (bool) $validated['is_completed'];

        $percentage = $isCompleted
            ? 100
            : min(
                (int) (
                    $validated['progress_percentage']
                    ?? $existing?->progress_percentage
                    ?? 0
                ),
                99
            );

        $now = now();

        if ($existing) {
            DB::table('lesson_progress')
                ->where('id', $existing->id)
                ->update([
                    'progress_percentage' => $percentage,
                    'is_completed' => $isCompleted,
                    'started_at' => $existing->started_at ?: $now,
                    'last_viewed_at' => $now,
                    'completed_at' => $isCompleted
                        ? ($existing->completed_at ?: $now)
                        : null,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('lesson_progress')->insert([
                'student_id' => $student->id,
                'lesson_id' => $lessonId,
                'progress_percentage' => $percentage,
                'is_completed' => $isCompleted,
                'started_at' => $now,
                'last_viewed_at' => $now,
                'completed_at' => $isCompleted ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $courseProgress = $this->courseProgress(
            $student->id,
            $courseId
        );

        if (
            $courseProgress['total_lessons'] > 0 &&
            $courseProgress['completed_lessons'] ===
                $courseProgress['total_lessons']
        ) {
            DB::table('enrollments')
                ->where('id', $enrollment->id)
                ->update([
                    'status' => 'completed',
                    'completed_at' =>
                        $enrollment->completed_at ?: $now,
                    'updated_at' => $now,
                ]);
        } elseif ($enrollment->status === 'completed') {
            DB::table('enrollments')
                ->where('id', $enrollment->id)
                ->update([
                    'status' => 'active',
                    'completed_at' => null,
                    'updated_at' => $now,
                ]);
        }

        $progress = DB::table('lesson_progress')
            ->where('student_id', $student->id)
            ->where('lesson_id', $lessonId)
            ->first();

        return response()->json([
            'message' => 'Lesson progress updated successfully.',
            'lesson_progress' => [
                'lesson_id' => $lessonId,
                'progress_percentage' =>
                    (int) $progress->progress_percentage,
                'is_completed' => (bool) $progress->is_completed,
                'started_at' => $progress->started_at,
                'last_viewed_at' => $progress->last_viewed_at,
                'completed_at' => $progress->completed_at,
            ],
            'course_progress' => $courseProgress,
            'next_lesson' => $this->nextLesson(
                $student->id,
                $courseId
            ),
        ]);
    }

    public function updateBookmark(
        Request $request,
        int $courseId,
        int $lessonId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        if (!$this->activeEnrollment($student->id, $courseId)) {
            return response()->json([
                'message' => 'You are not enrolled in this course.',
            ], 403);
        }

        if (!$this->courseLesson($courseId, $lessonId)) {
            return response()->json([
                'message' => 'Lesson not found in this course.',
            ], 404);
        }

        $validated = $request->validate([
            'is_bookmarked' => ['required', 'boolean'],
        ]);

        $isBookmarked = (bool) $validated['is_bookmarked'];

        if ($isBookmarked) {
            DB::table('lesson_bookmarks')->updateOrInsert(
                [
                    'student_id' => $student->id,
                    'lesson_id' => $lessonId,
                ],
                [
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        } else {
            DB::table('lesson_bookmarks')
                ->where('student_id', $student->id)
                ->where('lesson_id', $lessonId)
                ->delete();
        }

        return response()->json([
            'message' => $isBookmarked
                ? 'Lesson bookmarked successfully.'
                : 'Lesson bookmark removed successfully.',
            'lesson_id' => $lessonId,
            'is_bookmarked' => $isBookmarked,
        ]);
    }

    public function destroy(Request $request, int $courseId)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $enrollment = DB::table('enrollments')
            ->where('student_id', $student->id)
            ->where('course_id', $courseId)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Active course enrollment not found.',
            ], 404);
        }

        DB::table('enrollments')
            ->where('id', $enrollment->id)
            ->update([
                'status' => 'dropped',
                'completed_at' => null,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Course removed from My Courses successfully.',
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

    private function activeEnrollment(
        int $studentId,
        int $courseId
    ) {
        return DB::table('enrollments')
            ->where('student_id', $studentId)
            ->where('course_id', $courseId)
            ->whereIn('status', ['active', 'completed'])
            ->first();
    }

    private function courseLesson(
        int $courseId,
        int $lessonId
    ) {
        return DB::table('lessons as l')
            ->join(
                'course_modules as cm',
                'cm.id',
                '=',
                'l.course_module_id'
            )
            ->where('l.id', $lessonId)
            ->where('cm.course_id', $courseId)
            ->where('l.is_published', true)
            ->select('l.*')
            ->first();
    }

    private function courseProgress(
        int $studentId,
        int $courseId
    ): array {
        $totalLessons = DB::table('lessons as l')
            ->join(
                'course_modules as cm',
                'cm.id',
                '=',
                'l.course_module_id'
            )
            ->where('cm.course_id', $courseId)
            ->where('l.is_published', true)
            ->count();

        $completedLessons = DB::table('lesson_progress as lp')
            ->join('lessons as l', 'l.id', '=', 'lp.lesson_id')
            ->join(
                'course_modules as cm',
                'cm.id',
                '=',
                'l.course_module_id'
            )
            ->where('lp.student_id', $studentId)
            ->where('cm.course_id', $courseId)
            ->where('l.is_published', true)
            ->where('lp.is_completed', true)
            ->count();

        $percentage = $totalLessons > 0
            ? (int) round(
                ($completedLessons / $totalLessons) * 100
            )
            : 0;

        return [
            'percentage' => $percentage,
            'completed_lessons' => $completedLessons,
            'total_lessons' => $totalLessons,
        ];
    }

    private function nextLesson(
        int $studentId,
        int $courseId
    ): ?array {
        $lesson = DB::table('lessons as l')
            ->join(
                'course_modules as cm',
                'cm.id',
                '=',
                'l.course_module_id'
            )
            ->leftJoin('lesson_progress as lp', function ($join) use (
                $studentId
            ) {
                $join
                    ->on('lp.lesson_id', '=', 'l.id')
                    ->where('lp.student_id', '=', $studentId);
            })
            ->where('cm.course_id', $courseId)
            ->where('l.is_published', true)
            ->where(function ($query) {
                $query
                    ->whereNull('lp.id')
                    ->orWhere('lp.is_completed', false);
            })
            ->orderBy('cm.position')
            ->orderBy('l.position')
            ->select(
                'l.id',
                'l.title',
                'l.type',
                'l.duration_minutes',
                'cm.id as module_id',
                'cm.title as module_title'
            )
            ->first();

        if (!$lesson) {
            return null;
        }

        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'type' => $lesson->type,
            'duration_minutes' => $lesson->duration_minutes,
            'module' => [
                'id' => $lesson->module_id,
                'title' => $lesson->module_title,
            ],
        ];
    }

    private function baseCourseQuery()
    {
        return DB::table('courses as c')
            ->join(
                'categories as cat',
                'cat.id',
                '=',
                'c.category_id'
            )
            ->join(
                'trainers as t',
                't.id',
                '=',
                'c.trainer_id'
            )
            ->join(
                'users as u',
                'u.id',
                '=',
                't.user_id'
            )
            ->select(
                'c.id',
                'c.title',
                'c.slug',
                'c.description',
                'c.level',
                'c.duration_weeks',
                'c.cover_image',
                'cat.id as category_id',
                'cat.name as category_name',
                'cat.slug as category_slug',
                't.id as trainer_id',
                't.job_title as instructor_title',
                't.bio as instructor_bio',
                'u.name as instructor_name'
            );
    }

    private function fileUrl(?string $path): ?string
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

        if (str_starts_with($path, 'storage/')) {
            return asset($path);
        }

        return asset('storage/' . ltrim($path, '/'));
    }
}
