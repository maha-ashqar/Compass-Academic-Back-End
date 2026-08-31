<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
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

        $studentId = $student->id;

        $enrollments = DB::table('enrollments as e')
            ->join('courses as c', 'e.course_id', '=', 'c.id')
            ->join('categories as cat', 'c.category_id', '=', 'cat.id')
            ->where('e.student_id', $studentId)
            ->whereIn('e.status', ['active', 'completed'])
            ->select(
                'e.id as enrollment_id',
                'e.status as enrollment_status',
                'e.enrolled_at',
                'e.completed_at',
                'c.id',
                'c.title',
                'c.slug',
                'c.description',
                'c.level',
                'c.duration_weeks',
                'c.cover_image',
                'cat.name as category'
            )
            ->get();

        $courseIds = $enrollments
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $activeEnrollments = $enrollments
            ->where('enrollment_status', 'active');

        $coursesWithProgress = $activeEnrollments
            ->map(function ($course) use ($studentId) {
                $totalModules = DB::table('course_modules')
                    ->where('course_id', $course->id)
                    ->count();

                $totalLessons = DB::table('lessons as l')
                    ->join(
                        'course_modules as cm',
                        'l.course_module_id',
                        '=',
                        'cm.id'
                    )
                    ->where('cm.course_id', $course->id)
                    ->where('l.is_published', true)
                    ->count();

                $completedLessons = DB::table('lesson_progress as lp')
                    ->join('lessons as l', 'lp.lesson_id', '=', 'l.id')
                    ->join(
                        'course_modules as cm',
                        'l.course_module_id',
                        '=',
                        'cm.id'
                    )
                    ->where('lp.student_id', $studentId)
                    ->where('cm.course_id', $course->id)
                    ->where('l.is_published', true)
                    ->where('lp.is_completed', true)
                    ->count();

                $progress = $totalLessons > 0
                    ? (int) round(($completedLessons / $totalLessons) * 100)
                    : 0;

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                    'description' => $course->description,
                    'category' => $course->category,
                    'level' => $course->level,
                    'duration_weeks' => $course->duration_weeks,
                    'cover_image' => $course->cover_image,
                    'enrolled_at' => $course->enrolled_at,
                    'total_modules' => $totalModules,
                    'total_lessons' => $totalLessons,
                    'completed_lessons' => $completedLessons,
                    'progress' => $progress,
                ];
            })
            ->sortByDesc('progress')
            ->values();

        $currentCourse = $coursesWithProgress
            ->first(fn ($course) => $course['progress'] < 100);

        if (!$currentCourse) {
            $currentCourse = $coursesWithProgress->first();
        }

        if ($currentCourse) {
            $nextLesson = DB::table('lessons as l')
                ->join(
                    'course_modules as cm',
                    'l.course_module_id',
                    '=',
                    'cm.id'
                )
                ->leftJoin('lesson_progress as lp', function ($join) use ($studentId) {
                    $join->on('lp.lesson_id', '=', 'l.id')
                        ->where('lp.student_id', '=', $studentId);
                })
                ->where('cm.course_id', $currentCourse['id'])
                ->where('l.is_published', true)
                ->where(function ($query) {
                    $query->whereNull('lp.id')
                        ->orWhere('lp.is_completed', false);
                })
                ->orderBy('cm.position')
                ->orderBy('l.position')
                ->select(
                    'l.id',
                    'l.title',
                    'l.description',
                    'l.type',
                    'l.content_url',
                    'l.duration_minutes',
                    'cm.id as module_id',
                    'cm.title as module_title'
                )
                ->first();

            $nextMilestone = DB::table('assignments as a')
                ->leftJoin('submissions as s', function ($join) use ($studentId) {
                    $join->on('s.assignment_id', '=', 'a.id')
                        ->where('s.student_id', '=', $studentId);
                })
                ->where('a.course_id', $currentCourse['id'])
                ->whereIn('a.status', ['scheduled', 'active'])
                ->whereNotNull('a.deadline_at')
                ->where(function ($query) {
                    $query->whereNull('s.id')
                        ->orWhereIn('s.status', [
                            'draft',
                            'revision_requested',
                        ]);
                })
                ->orderBy('a.deadline_at')
                ->select(
                    'a.id',
                    'a.title',
                    'a.deadline_at'
                )
                ->first();

            $currentCourse['next_lesson'] = $nextLesson;
            $currentCourse['next_milestone'] = $nextMilestone;
        }

        $pendingAssignments = collect();

        if (!empty($courseIds)) {
            $pendingAssignments = DB::table('assignments as a')
                ->join('courses as c', 'a.course_id', '=', 'c.id')
                ->leftJoin('submissions as s', function ($join) use ($studentId) {
                    $join->on('s.assignment_id', '=', 'a.id')
                        ->where('s.student_id', '=', $studentId);
                })
                ->whereIn('a.course_id', $courseIds)
                ->whereIn('a.status', ['scheduled', 'active'])
                ->whereNotNull('a.deadline_at')
                ->where(function ($query) {
                    $query->whereNull('s.id')
                        ->orWhereIn('s.status', [
                            'draft',
                            'revision_requested',
                        ]);
                })
                ->orderBy('a.deadline_at')
                ->select(
                    'a.id',
                    'a.title',
                    'a.deadline_at',
                    'c.id as course_id',
                    'c.title as course_title'
                )
                ->get();
        }

        $nextTasks = $pendingAssignments
            ->take(3)
            ->map(function ($assignment) {
                return [
                    'id' => $assignment->id,
                    'type' => 'assignment',
                    'title' => $assignment->title,
                    'course_id' => $assignment->course_id,
                    'course_title' => $assignment->course_title,
                    'date' => $assignment->deadline_at,
                ];
            })
            ->values();

        $weekStart = now()->startOfDay();
        $weekEnd = now()->copy()->addDays(7)->endOfDay();

        $tasksDueThisWeek = $pendingAssignments
            ->filter(function ($assignment) use ($weekStart, $weekEnd) {
                $deadline = \Carbon\Carbon::parse($assignment->deadline_at);

                return $deadline->between($weekStart, $weekEnd);
            })
            ->count();

        $certificatesEarned = DB::table('certificates')
            ->where('student_id', $studentId)
            ->count();

        $totalProjects = DB::table('projects')
            ->where('owner_student_id', $studentId)
            ->count();

        $completedProjects = DB::table('projects')
            ->where('owner_student_id', $studentId)
            ->where('status', 'published')
            ->count();

        $projectCompletion = $totalProjects > 0
            ? (int) round(($completedProjects / $totalProjects) * 100)
            : 0;

        $interestCategoryIds = DB::table('student_interests')
            ->where('student_id', $studentId)
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $recommendedQuery = DB::table('courses as c')
            ->join('categories as cat', 'c.category_id', '=', 'cat.id')
            ->leftJoin('course_reviews as cr', 'c.id', '=', 'cr.course_id')
            ->leftJoin('enrollments as ce', 'c.id', '=', 'ce.course_id')
            ->where('c.status', 'published');

        if (!empty($courseIds)) {
            $recommendedQuery->whereNotIn('c.id', $courseIds);
        }

        $recommendedCourses = $recommendedQuery
            ->groupBy(
                'c.id',
                'c.category_id',
                'c.title',
                'c.slug',
                'c.description',
                'c.level',
                'c.duration_weeks',
                'c.cover_image',
                'cat.name'
            )
            ->select(
                'c.id',
                'c.category_id',
                'c.title',
                'c.slug',
                'c.description',
                'c.level',
                'c.duration_weeks',
                'c.cover_image',
                'cat.name as category',
                DB::raw('ROUND(COALESCE(AVG(cr.rating), 0), 1) as rating'),
                DB::raw('COUNT(DISTINCT ce.id) as students')
            )
            ->get()
            ->sort(function ($a, $b) use ($interestCategoryIds) {
                $aInterested = in_array(
                    (int) $a->category_id,
                    $interestCategoryIds,
                    true
                );

                $bInterested = in_array(
                    (int) $b->category_id,
                    $interestCategoryIds,
                    true
                );

                if ($aInterested !== $bInterested) {
                    return $aInterested ? -1 : 1;
                }

                if ((float) $a->rating !== (float) $b->rating) {
                    return (float) $b->rating <=> (float) $a->rating;
                }

                return (int) $b->students <=> (int) $a->students;
            })
            ->take(3)
            ->values()
            ->map(function ($course) {
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                    'description' => $course->description,
                    'category' => $course->category,
                    'level' => $course->level,
                    'duration_weeks' => $course->duration_weeks,
                    'cover_image' => $course->cover_image,
                    'rating' => (float) $course->rating,
                    'students' => (int) $course->students,
                ];
            });

        return response()->json([
            'dashboard' => [
                'student' => [
                    'id' => $studentId,
                    'name' => $user->name,
                    'first_name' => explode(' ', trim($user->name))[0],
                ],
                'stats' => [
                    'courses_in_progress' => $activeEnrollments->count(),
                    'project_completion' => $projectCompletion,
                    'tasks_due_this_week' => $tasksDueThisWeek,
                    'certificates_earned' => $certificatesEarned,
                ],
                'current_course' => $currentCourse,
                'next_tasks' => $nextTasks,
                'recommended_courses' => $recommendedCourses,
                'updated_at' => now()->toISOString(),
            ],
        ]);
    }
}
