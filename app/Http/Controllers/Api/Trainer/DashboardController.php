<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'trainer') {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $trainer = DB::table('trainers')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $courseIds = DB::table('courses')
            ->where('trainer_id', $trainer->id)
            ->pluck('id');

        $publishedCourseIds = DB::table('courses')
            ->where('trainer_id', $trainer->id)
            ->where('status', 'published')
            ->pluck('id');

        $activeStudentIds = DB::table('enrollments')
            ->whereIn('course_id', $publishedCourseIds)
            ->where('status', 'active')
            ->distinct()
            ->pluck('student_id');

        $ungradedSubmissions = DB::table('submissions as s')
            ->join('assignments as a', 'a.id', '=', 's.assignment_id')
            ->where('a.trainer_id', $trainer->id)
            ->whereIn('s.status', [
                'submitted',
                'late',
                'resubmitted',
            ])
            ->count();

        $pendingProjects = DB::table('projects')
            ->whereIn('course_id', $courseIds)
            ->where('status', 'in_review')
            ->count();

        $unreadMessages = DB::table('messages as m')
            ->join(
                'conversation_participants as cp',
                'cp.conversation_id',
                '=',
                'm.conversation_id'
            )
            ->where('cp.user_id', $user->id)
            ->where('m.sender_id', '!=', $user->id)
            ->whereNull('m.read_at')
            ->count();

        $pendingCompetitionRegistrations = DB::table(
            'competition_registrations as cr'
        )
            ->join(
                'competitions as c',
                'c.id',
                '=',
                'cr.competition_id'
            )
            ->where('c.created_by', $trainer->id)
            ->where('cr.status', 'pending')
            ->count();

        $assignmentQueue = DB::table('submissions as s')
            ->join(
                'assignments as a',
                'a.id',
                '=',
                's.assignment_id'
            )
            ->join(
                'students as st',
                'st.id',
                '=',
                's.student_id'
            )
            ->join(
                'users as u',
                'u.id',
                '=',
                'st.user_id'
            )
            ->where('a.trainer_id', $trainer->id)
            ->whereIn('s.status', [
                'submitted',
                'late',
                'resubmitted',
            ])
            ->get([
                's.id',
                'a.title',
                'u.name as student_name',
                's.submitted_at',
                's.updated_at',
            ])
            ->map(function ($item) {
                return [
                    'id' => 'assignment-' . $item->id,
                    'type' => 'Assignment',
                    'title' => $item->title,
                    'owner' => $item->student_name,
                    'status' => 'Pending review',
                    'target' => 'Assignments',
                    'queued_at' =>
                        $item->submitted_at
                        ?: $item->updated_at,
                ];
            });

        $projectQueue = DB::table('projects as p')
            ->join(
                'students as st',
                'st.id',
                '=',
                'p.owner_student_id'
            )
            ->join(
                'users as u',
                'u.id',
                '=',
                'st.user_id'
            )
            ->whereIn('p.course_id', $courseIds)
            ->where('p.status', 'in_review')
            ->get([
                'p.id',
                'p.title',
                'u.name as student_name',
                'p.submitted_for_review_at',
                'p.updated_at',
            ])
            ->map(function ($item) {
                return [
                    'id' => 'project-' . $item->id,
                    'type' => 'Project',
                    'title' => $item->title,
                    'owner' => $item->student_name,
                    'status' => 'Waiting',
                    'target' => 'Projects',
                    'queued_at' =>
                        $item->submitted_for_review_at
                        ?: $item->updated_at,
                ];
            });

        $reviewQueue = $assignmentQueue
            ->merge($projectQueue)
            ->sortByDesc('queued_at')
            ->take(4)
            ->values()
            ->map(function ($item) {
                unset($item['queued_at']);

                return $item;
            });

        $attentionStudents = $activeStudentIds
            ->map(function ($studentId) use (
                $publishedCourseIds
            ) {
                $student = DB::table('students as s')
                    ->join(
                        'users as u',
                        'u.id',
                        '=',
                        's.user_id'
                    )
                    ->where('s.id', $studentId)
                    ->first([
                        's.id',
                        'u.name',
                        'u.email',
                        'u.avatar',
                    ]);

                if (!$student) {
                    return null;
                }

                $studentCourseIds = DB::table(
                    'enrollments'
                )
                    ->where(
                        'student_id',
                        $studentId
                    )
                    ->whereIn(
                        'course_id',
                        $publishedCourseIds
                    )
                    ->where(
                        'status',
                        'active'
                    )
                    ->pluck('course_id');

                $totalLessons = DB::table(
                    'lessons as l'
                )
                    ->join(
                        'course_modules as cm',
                        'cm.id',
                        '=',
                        'l.course_module_id'
                    )
                    ->whereIn(
                        'cm.course_id',
                        $studentCourseIds
                    )
                    ->where(
                        'l.is_published',
                        true
                    )
                    ->count();

                $completedLessons = DB::table(
                    'lesson_progress as lp'
                )
                    ->join(
                        'lessons as l',
                        'l.id',
                        '=',
                        'lp.lesson_id'
                    )
                    ->join(
                        'course_modules as cm',
                        'cm.id',
                        '=',
                        'l.course_module_id'
                    )
                    ->where(
                        'lp.student_id',
                        $studentId
                    )
                    ->whereIn(
                        'cm.course_id',
                        $studentCourseIds
                    )
                    ->where(
                        'l.is_published',
                        true
                    )
                    ->where(
                        'lp.is_completed',
                        true
                    )
                    ->count();

                $progress = $totalLessons > 0
                    ? (int) round(
                        (
                            $completedLessons
                            / $totalLessons
                        ) * 100
                    )
                    : 0;

                return [
                    'id' => (int) $student->id,
                    'name' => $student->name,
                    'email' => $student->email,
                    'avatar' => $this->avatarUrl(
                        $student->avatar
                    ),
                    'progress' => $progress,
                ];
            })
            ->filter()
            ->filter(
                fn ($student) =>
                    $student['progress'] < 60
            )
            ->sortBy('progress')
            ->take(3)
            ->values();

        $activeCourses = DB::table('courses as c')
            ->leftJoin(
                'categories as cat',
                'cat.id',
                '=',
                'c.category_id'
            )
            ->where(
                'c.trainer_id',
                $trainer->id
            )
            ->where(
                'c.status',
                'published'
            )
            ->orderByDesc('c.published_at')
            ->get([
                'c.id',
                'c.title',
                'cat.name as category',
            ])
            ->map(function ($course) {
                $studentIds = DB::table(
                    'enrollments'
                )
                    ->where(
                        'course_id',
                        $course->id
                    )
                    ->where(
                        'status',
                        'active'
                    )
                    ->pluck('student_id');

                $lessonIds = DB::table(
                    'lessons as l'
                )
                    ->join(
                        'course_modules as cm',
                        'cm.id',
                        '=',
                        'l.course_module_id'
                    )
                    ->where(
                        'cm.course_id',
                        $course->id
                    )
                    ->where(
                        'l.is_published',
                        true
                    )
                    ->pluck('l.id');

                $completedLessons = 0;

                if (
                    $studentIds->isNotEmpty() &&
                    $lessonIds->isNotEmpty()
                ) {
                    $completedLessons = DB::table(
                        'lesson_progress'
                    )
                        ->whereIn(
                            'student_id',
                            $studentIds
                        )
                        ->whereIn(
                            'lesson_id',
                            $lessonIds
                        )
                        ->where(
                            'is_completed',
                            true
                        )
                        ->count();
                }

                $possibleLessons =
                    $studentIds->count()
                    * $lessonIds->count();

                $progress =
                    $possibleLessons > 0
                    ? (int) round(
                        (
                            $completedLessons
                            / $possibleLessons
                        ) * 100
                    )
                    : 0;

                return [
                    'id' => (int) $course->id,
                    'title' => $course->title,
                    'category' =>
                        $course->category
                        ?: 'Course',
                    'students' =>
                        $studentIds->count(),
                    'lessons' =>
                        $lessonIds->count(),
                    'progress' => $progress,
                ];
            })
            ->take(3)
            ->values();

        $conversationIds = DB::table(
            'conversation_participants'
        )
            ->where(
                'user_id',
                $user->id
            )
            ->pluck('conversation_id');

        $recentConversationIds = DB::table(
            'conversations'
        )
            ->whereIn(
                'id',
                $conversationIds
            )
            ->orderByDesc('updated_at')
            ->limit(3)
            ->pluck('id');

        $recentMessages = $recentConversationIds
            ->map(function ($conversationId) use (
                $user
            ) {
                $student = DB::table(
                    'conversation_participants as cp'
                )
                    ->join(
                        'users as u',
                        'u.id',
                        '=',
                        'cp.user_id'
                    )
                    ->where(
                        'cp.conversation_id',
                        $conversationId
                    )
                    ->where(
                        'cp.user_id',
                        '!=',
                        $user->id
                    )
                    ->where(
                        'u.role',
                        'student'
                    )
                    ->first([
                        'u.id',
                        'u.name',
                        'u.avatar',
                    ]);

                if (!$student) {
                    return null;
                }

                $lastMessage = DB::table(
                    'messages'
                )
                    ->where(
                        'conversation_id',
                        $conversationId
                    )
                    ->orderByDesc('id')
                    ->first([
                        'message',
                        'created_at',
                    ]);

                $unreadCount = DB::table(
                    'messages'
                )
                    ->where(
                        'conversation_id',
                        $conversationId
                    )
                    ->where(
                        'sender_id',
                        '!=',
                        $user->id
                    )
                    ->whereNull('read_at')
                    ->count();

                return [
                    'id' =>
                        (int) $conversationId,
                    'name' =>
                        $student->name,
                    'avatar' =>
                        $this->avatarUrl(
                            $student->avatar
                        ),
                    'message' =>
                        $lastMessage?->message
                        ?: 'Attachment',
                    'created_at' =>
                        $lastMessage?->created_at,
                    'unread_count' =>
                        $unreadCount,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'trainer' => [
                'id' =>
                    (int) $trainer->id,
                'user_id' =>
                    (int) $user->id,
                'name' =>
                    $user->name,
                'email' =>
                    $user->email,
                'avatar' =>
                    $this->avatarUrl(
                        $user->avatar
                    ),
                'job_title' =>
                    $trainer->job_title,
            ],
            'stats' => [
                'pending_reviews' =>
                    $ungradedSubmissions
                    + $pendingProjects,
                'ungraded_assignments' =>
                    $ungradedSubmissions,
                'pending_projects' =>
                    $pendingProjects,
                'active_students' =>
                    $activeStudentIds->count(),
                'active_courses' =>
                    $publishedCourseIds->count(),
                'unread_messages' =>
                    $unreadMessages,
                'pending_competition_registrations' =>
                    $pendingCompetitionRegistrations,
            ],
            'review_queue' =>
                $reviewQueue,
            'attention_students' =>
                $attentionStudents,
            'active_courses' =>
                $activeCourses,
            'recent_messages' =>
                $recentMessages,
        ]);
    }

    private function avatarUrl(?string $avatar): ?string
    {
        if (!$avatar) {
            return null;
        }

        if (
            str_starts_with(
                $avatar,
                'http://'
            ) ||
            str_starts_with(
                $avatar,
                'https://'
            )
        ) {
            return $avatar;
        }

        return asset(
            'storage/' . $avatar
        );
    }
}
