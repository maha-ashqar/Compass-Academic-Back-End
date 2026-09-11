<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainerStudentController extends Controller
{
    public function index(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $studentIds = $this->trainerStudentIds($trainer->id);

        $students = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->whereIn('s.id', $studentIds)
            ->where('u.role', 'student')
            ->orderBy('u.name')
            ->get([
                's.id',
                's.user_id',
                's.student_code',
                's.professional_summary',
                's.portfolio_code',
                's.is_verified',
                's.created_at',
                'u.name',
                'u.email',
                'u.avatar',
                'u.last_active_at',
            ])
            ->map(fn ($student) => $this->studentData($student, $trainer->id))
            ->values();

        $stats = [
            'total_students' => $students->count(),
            'on_track' => $students->where('status', 'on-track')->count(),
            'needs_feedback' => $students->where('status', 'needs-feedback')->count(),
            'inactive' => $students->where('status', 'inactive')->count(),
            'awaiting_grading' => $students->sum('assignmentsPending'),
            'competition_entries' => $students->sum('competitionsEntered'),
            'projects' => $students->sum('projectsSubmitted'),
        ];

        return response()->json([
            'students' => $students,
            'stats' => $stats,
        ]);
    }

    public function show(Request $request, int $studentId)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        if (!$this->trainerStudentIds($trainer->id)->contains($studentId)) {
            return response()->json([
                'message' => 'Student not found.',
            ], 404);
        }

        $student = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $studentId)
            ->where('u.role', 'student')
            ->first([
                's.id',
                's.user_id',
                's.student_code',
                's.professional_summary',
                's.portfolio_code',
                's.is_verified',
                's.created_at',
                'u.name',
                'u.email',
                'u.avatar',
                'u.last_active_at',
            ]);

        if (!$student) {
            return response()->json([
                'message' => 'Student not found.',
            ], 404);
        }

        $data = $this->studentData($student, $trainer->id);

        $data['assignments'] = $this->studentAssignments(
            $studentId,
            $trainer->id
        );

        $data['competitions'] = $this->studentCompetitions(
            $studentId,
            $trainer->id
        );

        $data['projects'] = $this->studentProjects(
            $studentId,
            $trainer->id
        );

        return response()->json([
            'student' => $data,
        ]);
    }

    private function studentData(object $student, int $trainerId): array
    {
        $education = DB::table('student_educations')
            ->where('student_id', $student->id)
            ->orderByDesc('is_current')
            ->orderByDesc('id')
            ->first();

        $enrollments = DB::table('enrollments as e')
            ->join('courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.student_id', $student->id)
            ->where('c.trainer_id', $trainerId)
            ->whereIn('e.status', ['active', 'completed'])
            ->orderByDesc('e.enrolled_at')
            ->get([
                'e.id',
                'e.course_id',
                'e.status',
                'e.enrolled_at',
                'e.completed_at',
                'c.title',
            ]);

        $courses = $enrollments
            ->map(function ($enrollment) use ($student) {
                return [
                    'id' => (int) $enrollment->course_id,
                    'title' => $enrollment->title,
                    'enrollmentStatus' => $enrollment->status,
                    'joinedAt' => $enrollment->enrolled_at,
                    'completedAt' => $enrollment->completed_at,
                    'progress' => $this->courseProgress(
                        $student->id,
                        $enrollment->course_id
                    ),
                ];
            })
            ->values();

        $progress = $courses->count()
            ? (int) round($courses->avg('progress'))
            : 0;

        $assignmentStats = $this->assignmentStats(
            $student->id,
            $trainerId
        );

        $competitionsEntered = $this->competitionCount(
            $student->id,
            $trainerId
        );

        $projectsSubmitted = $this->projectCount(
            $student->id,
            $trainerId
        );

        $lastActive = $student->last_active_at;

        $activityDays = $lastActive
            ? now()->diffInDays($lastActive)
            : null;

        $status = $assignmentStats['pending'] > 0
            ? 'needs-feedback'
            : (
                $activityDays === null || $activityDays > 14
                    ? 'inactive'
                    : 'on-track'
            );

        $joinedAt = $enrollments->min('enrolled_at')
            ?: $student->created_at;

        return [
            'id' => (int) $student->id,
            'userId' => (int) $student->user_id,
            'studentId' => $student->student_code,
            'name' => $student->name,
            'email' => $student->email,
            'avatar' => $this->fileUrl($student->avatar),
            'professionalSummary' => $student->professional_summary,
            'portfolioCode' => $student->portfolio_code,
            'isVerified' => (bool) $student->is_verified,
            'major' => $education?->major,
            'degree' => $education?->degree,
            'university' => $education?->university,
            'faculty' => $education?->faculty,
            'department' => $education?->department,
            'courseTitle' => $courses->pluck('title')->implode(' · '),
            'courses' => $courses,
            'progress' => $progress,
            'assignmentsSubmitted' => $assignmentStats['submitted'],
            'assignmentsGraded' => $assignmentStats['graded'],
            'assignmentsPending' => $assignmentStats['pending'],
            'competitionsEntered' => $competitionsEntered,
            'projectsSubmitted' => $projectsSubmitted,
            'lastActive' => $lastActive,
            'activityDays' => $activityDays,
            'status' => $status,
            'access' => $enrollments
                ->where('status', 'active')
                ->isNotEmpty()
                ? 'active'
                : 'limited',
            'joinedAt' => $joinedAt,
        ];
    }

    private function assignmentStats(int $studentId, int $trainerId): array
    {
        $submissions = DB::table('submissions as s')
            ->join('assignments as a', 'a.id', '=', 's.assignment_id')
            ->where('s.student_id', $studentId)
            ->where('a.trainer_id', $trainerId)
            ->get([
                's.status',
            ]);

        $submitted = $submissions
            ->whereIn('status', [
                'submitted',
                'late',
                'graded',
                'revision_requested',
                'resubmitted',
            ])
            ->count();

        $graded = $submissions
            ->where('status', 'graded')
            ->count();

        $pending = $submissions
            ->whereIn('status', [
                'submitted',
                'late',
                'resubmitted',
            ])
            ->count();

        return [
            'submitted' => $submitted,
            'graded' => $graded,
            'pending' => $pending,
        ];
    }

    private function competitionCount(int $studentId, int $trainerId): int
    {
        return DB::table('competition_registration_members as crm')
            ->join(
                'competition_registrations as cr',
                'cr.id',
                '=',
                'crm.competition_registration_id'
            )
            ->join('competitions as c', 'c.id', '=', 'cr.competition_id')
            ->where('crm.student_id', $studentId)
            ->where('c.created_by', $trainerId)
            ->where('cr.status', 'registered')
            ->distinct()
            ->count('cr.id');
    }

    private function projectCount(int $studentId, int $trainerId): int
    {
        $ownedIds = DB::table('projects as p')
            ->leftJoin('courses as c', 'c.id', '=', 'p.course_id')
            ->where('p.owner_student_id', $studentId)
            ->where(function ($query) use ($trainerId) {
                $query
                    ->where('c.trainer_id', $trainerId)
                    ->orWhereExists(function ($review) use ($trainerId) {
                        $review
                            ->select(DB::raw(1))
                            ->from('project_reviews as pr')
                            ->whereColumn('pr.project_id', 'p.id')
                            ->where('pr.trainer_id', $trainerId);
                    });
            })
            ->pluck('p.id');

        $memberIds = DB::table('project_members as pm')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->leftJoin('courses as c', 'c.id', '=', 'p.course_id')
            ->where('pm.student_id', $studentId)
            ->where(function ($query) use ($trainerId) {
                $query
                    ->where('c.trainer_id', $trainerId)
                    ->orWhereExists(function ($review) use ($trainerId) {
                        $review
                            ->select(DB::raw(1))
                            ->from('project_reviews as pr')
                            ->whereColumn('pr.project_id', 'p.id')
                            ->where('pr.trainer_id', $trainerId);
                    });
            })
            ->pluck('p.id');

        return $ownedIds
            ->merge($memberIds)
            ->unique()
            ->count();
    }

    private function courseProgress(int $studentId, int $courseId): int
    {
        $lessonIds = DB::table('lessons as l')
            ->join(
                'course_modules as cm',
                'cm.id',
                '=',
                'l.course_module_id'
            )
            ->where('cm.course_id', $courseId)
            ->where('l.is_published', true)
            ->pluck('l.id');

        if ($lessonIds->isEmpty()) {
            return 0;
        }

        $completed = DB::table('lesson_progress')
            ->where('student_id', $studentId)
            ->whereIn('lesson_id', $lessonIds)
            ->where('is_completed', true)
            ->count();

        return (int) round(
            ($completed / $lessonIds->count()) * 100
        );
    }

    private function studentAssignments(int $studentId, int $trainerId)
    {
        return DB::table('assignments as a')
            ->join('courses as c', 'c.id', '=', 'a.course_id')
            ->leftJoin('submissions as s', function ($join) use ($studentId) {
                $join
                    ->on('s.assignment_id', '=', 'a.id')
                    ->where('s.student_id', '=', $studentId);
            })
            ->where('a.trainer_id', $trainerId)
            ->whereExists(function ($query) use ($studentId) {
                $query
                    ->select(DB::raw(1))
                    ->from('enrollments as e')
                    ->whereColumn('e.course_id', 'a.course_id')
                    ->where('e.student_id', $studentId)
                    ->whereIn('e.status', ['active', 'completed']);
            })
            ->orderByDesc('a.deadline_at')
            ->get([
                'a.id',
                'a.course_id',
                'a.title',
                'a.deadline_at',
                'a.max_grade',
                'a.status',
                'c.title as courseTitle',
                's.id as submissionId',
                's.status as submissionStatus',
                's.submitted_at as submittedAt',
                's.grade',
                's.feedback',
                's.graded_at as gradedAt',
            ]);
    }

    private function studentCompetitions(int $studentId, int $trainerId)
    {
        return DB::table('competition_registration_members as crm')
            ->join(
                'competition_registrations as cr',
                'cr.id',
                '=',
                'crm.competition_registration_id'
            )
            ->join('competitions as c', 'c.id', '=', 'cr.competition_id')
            ->where('crm.student_id', $studentId)
            ->where('c.created_by', $trainerId)
            ->orderByDesc('cr.registered_at')
            ->get([
                'c.id',
                'c.title',
                'c.status',
                'cr.id as registrationId',
                'cr.team_name as teamName',
                'cr.status as registrationStatus',
                'cr.registered_at as registeredAt',
                'crm.role',
            ]);
    }

    private function studentProjects(int $studentId, int $trainerId)
    {
        $projectIds = DB::table('projects as p')
            ->leftJoin('project_members as pm', 'pm.project_id', '=', 'p.id')
            ->leftJoin('courses as c', 'c.id', '=', 'p.course_id')
            ->where(function ($query) use ($studentId) {
                $query
                    ->where('p.owner_student_id', $studentId)
                    ->orWhere('pm.student_id', $studentId);
            })
            ->where(function ($query) use ($trainerId) {
                $query
                    ->where('c.trainer_id', $trainerId)
                    ->orWhereExists(function ($review) use ($trainerId) {
                        $review
                            ->select(DB::raw(1))
                            ->from('project_reviews as pr')
                            ->whereColumn('pr.project_id', 'p.id')
                            ->where('pr.trainer_id', $trainerId);
                    });
            })
            ->distinct()
            ->pluck('p.id');

        return DB::table('projects as p')
            ->leftJoin('courses as c', 'c.id', '=', 'p.course_id')
            ->whereIn('p.id', $projectIds)
            ->orderByDesc('p.updated_at')
            ->get([
                'p.id',
                'p.title',
                'p.project_type as projectType',
                'p.status',
                'p.updated_at as updatedAt',
                'p.submitted_for_review_at as submittedAt',
                'c.title as courseTitle',
            ]);
    }

    private function trainerStudentIds(int $trainerId)
    {
        $enrollmentIds = DB::table('enrollments as e')
            ->join('courses as c', 'c.id', '=', 'e.course_id')
            ->where('c.trainer_id', $trainerId)
            ->whereIn('e.status', ['active', 'completed'])
            ->pluck('e.student_id');

        $submissionIds = DB::table('submissions as s')
            ->join('assignments as a', 'a.id', '=', 's.assignment_id')
            ->where('a.trainer_id', $trainerId)
            ->pluck('s.student_id');

        $competitionIds = DB::table(
            'competition_registration_members as crm'
        )
            ->join(
                'competition_registrations as cr',
                'cr.id',
                '=',
                'crm.competition_registration_id'
            )
            ->join('competitions as c', 'c.id', '=', 'cr.competition_id')
            ->where('c.created_by', $trainerId)
            ->where('cr.status', 'registered')
            ->pluck('crm.student_id');

        $ownedProjectIds = DB::table('projects as p')
            ->join('courses as c', 'c.id', '=', 'p.course_id')
            ->where('c.trainer_id', $trainerId)
            ->pluck('p.owner_student_id');

        $memberProjectIds = DB::table('project_members as pm')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->join('courses as c', 'c.id', '=', 'p.course_id')
            ->where('c.trainer_id', $trainerId)
            ->pluck('pm.student_id');

        return $enrollmentIds
            ->merge($submissionIds)
            ->merge($competitionIds)
            ->merge($ownedProjectIds)
            ->merge($memberProjectIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function trainerFromRequest(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'trainer') {
            return null;
        }

        return DB::table('trainers')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }

    private function fileUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (
            str_starts_with($path, 'http://') ||
            str_starts_with($path, 'https://') ||
            str_starts_with($path, 'data:')
        ) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }
}
