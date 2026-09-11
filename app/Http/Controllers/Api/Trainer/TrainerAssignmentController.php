<?php


namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TrainerAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $this->syncStatuses($trainer->id);

        $assignments = DB::table('assignments')
            ->where('trainer_id', $trainer->id)
            ->orderByRaw("CASE WHEN status = 'archived' THEN 1 ELSE 0 END")
            ->orderByRaw('deadline_at IS NULL')
            ->orderBy('deadline_at')
            ->orderByDesc('id')
            ->get()
            ->map(
                fn ($assignment) =>
                    $this->assignmentData($assignment)
            )
            ->values();

        $courses = DB::table('courses')
            ->where('trainer_id', $trainer->id)
            ->where('status', '!=', 'archived')
            ->orderBy('title')
            ->get([
                'id',
                'title',
                'status',
            ])
            ->map(fn ($course) => [
                'id' => (int) $course->id,
                'title' => $course->title,
                'status' => $course->status,
            ])
            ->values();

        $activeAssignments = $assignments
            ->where('status', '!=', 'archived');

        $pendingSubmissions = DB::table('submissions as s')
            ->join(
                'assignments as a',
                'a.id',
                '=',
                's.assignment_id'
            )
            ->where('a.trainer_id', $trainer->id)
            ->whereIn('s.status', [
                'submitted',
                'late',
                'resubmitted',
            ])
            ->count();

        $gradedSubmissions = DB::table('submissions as s')
            ->join(
                'assignments as a',
                'a.id',
                '=',
                's.assignment_id'
            )
            ->where('a.trainer_id', $trainer->id)
            ->where('s.status', 'graded')
            ->count();

        $closingSoon = $activeAssignments
            ->filter(function ($assignment) {
                if (
                    !$assignment['dueAt'] ||
                    !in_array(
                        $assignment['state'],
                        ['open', 'scheduled'],
                        true
                    )
                ) {
                    return false;
                }

                $deadline = strtotime(
                    $assignment['dueAt']
                );

                $distance =
                    $deadline - now()->timestamp;

                return $distance > 0 &&
                    $distance <= 48 * 3600;
            })
            ->count();

        $gradedValues = DB::table('submissions as s')
            ->join(
                'assignments as a',
                'a.id',
                '=',
                's.assignment_id'
            )
            ->where('a.trainer_id', $trainer->id)
            ->where('s.status', 'graded')
            ->whereNotNull('s.grade')
            ->pluck('s.grade');

        return response()->json([
            'assignments' => $assignments,
            'courses' => $courses,
            'stats' => [
                'active' => $activeAssignments
                    ->where('state', 'open')
                    ->count(),
                'pending' => $pendingSubmissions,
                'graded' => $gradedSubmissions,
                'closingSoon' => $closingSoon,
                'averageGrade' => $gradedValues->count()
                    ? (int) round($gradedValues->avg())
                    : 0,
            ],
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    public function show(
        Request $request,
        int $assignmentId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $this->syncStatuses($trainer->id);

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        return response()->json([
            'assignment' => $this->assignmentData(
                $assignment,
                true
            ),
        ]);
    }

    public function store(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $validated = $this->validateAssignment(
            $request
        );

        if (
            !$this->courseForTrainer(
                $validated['course_id'],
                $trainer->id
            )
        ) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $assignmentId = DB::table('assignments')
            ->insertGetId([
                'course_id' =>
                    $validated['course_id'],
                'trainer_id' =>
                    $trainer->id,
                'title' =>
                    $validated['title'],
                'description' =>
                    $validated['description'] ?? null,
                'submission_instructions' =>
                    $validated['submission_instructions']
                        ?? null,
                'max_grade' =>
                    $validated['max_grade'] ?? 100,
                'opens_at' =>
                    $validated['opens_at'] ?? null,
                'deadline_at' =>
                    $validated['deadline_at'] ?? null,
                'status' => 'draft',
                'close_reason' => null,
                'archived_at' => null,
                'published_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $assignment = DB::table('assignments')
            ->where('id', $assignmentId)
            ->first();

        return response()->json([
            'message' => 'Assignment created as draft.',
            'assignment' => $this->assignmentData(
                $assignment,
                true
            ),
        ], 201);
    }

    public function update(
        Request $request,
        int $assignmentId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        if ($assignment->status === 'archived') {
            return response()->json([
                'message' => 'Archived assignments cannot be edited.',
            ], 422);
        }

        $validated = $this->validateAssignment(
            $request
        );

        if (
            !$this->courseForTrainer(
                $validated['course_id'],
                $trainer->id
            )
        ) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        DB::table('assignments')
            ->where('id', $assignmentId)
            ->update([
                'course_id' =>
                    $validated['course_id'],
                'title' =>
                    $validated['title'],
                'description' =>
                    $validated['description'] ?? null,
                'submission_instructions' =>
                    $validated['submission_instructions']
                        ?? null,
                'max_grade' =>
                    $validated['max_grade'] ?? 100,
                'opens_at' =>
                    $validated['opens_at'] ?? null,
                'deadline_at' =>
                    $validated['deadline_at'] ?? null,
                'updated_at' => now(),
            ]);

        $this->refreshAssignmentStatus(
            $assignmentId
        );

        return response()->json([
            'message' => 'Assignment updated successfully.',
            'assignment' => $this->assignmentData(
                DB::table('assignments')
                    ->where('id', $assignmentId)
                    ->first(),
                true
            ),
        ]);
    }

    public function publish(
        Request $request,
        int $assignmentId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        if ($assignment->status === 'archived') {
            return response()->json([
                'message' => 'Archived assignments cannot be published.',
            ], 422);
        }

        $status = $this->publishedStatus(
            $assignment->opens_at,
            $assignment->deadline_at
        );

        DB::table('assignments')
            ->where('id', $assignmentId)
            ->update([
                'status' => $status,
                'close_reason' => null,
                'published_at' =>
                    $assignment->published_at
                        ?: now(),
                'updated_at' => now(),
            ]);

        $this->notifyAssignmentStudents(
            $assignment->course_id,
            'New assignment',
            '"' . $assignment->title .
                '" is now available.',
            $assignmentId
        );

        return response()->json([
            'message' => 'Assignment published successfully.',
            'assignment' => $this->assignmentData(
                DB::table('assignments')
                    ->where('id', $assignmentId)
                    ->first(),
                true
            ),
        ]);
    }

    public function duplicate(
        Request $request,
        int $assignmentId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        $newId = DB::table('assignments')
            ->insertGetId([
                'course_id' =>
                    $assignment->course_id,
                'trainer_id' =>
                    $trainer->id,
                'title' =>
                    $assignment->title . ' (Copy)',
                'description' =>
                    $assignment->description,
                'submission_instructions' =>
                    $assignment
                        ->submission_instructions,
                'max_grade' =>
                    $assignment->max_grade,
                'opens_at' =>
                    $assignment->opens_at,
                'deadline_at' =>
                    $assignment->deadline_at,
                'status' => 'draft',
                'close_reason' => null,
                'archived_at' => null,
                'published_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Assignment duplicated successfully.',
            'assignment' => $this->assignmentData(
                DB::table('assignments')
                    ->where('id', $newId)
                    ->first(),
                true
            ),
        ], 201);
    }

    public function archive(
        Request $request,
        int $assignmentId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        DB::table('assignments')
            ->where('id', $assignmentId)
            ->update([
                'status' => 'archived',
                'archived_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Assignment archived successfully.',
            'assignment' => $this->assignmentData(
                DB::table('assignments')
                    ->where('id', $assignmentId)
                    ->first(),
                true
            ),
        ]);
    }

    public function destroy(
        Request $request,
        int $assignmentId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $submissionCount = DB::table('submissions')
            ->where(
                'assignment_id',
                $assignmentId
            )
            ->count();

        if ($submissionCount > 0) {
            DB::table('assignments')
                ->where('id', $assignmentId)
                ->update([
                    'status' => 'archived',
                    'archived_at' => now(),
                    'close_reason' =>
                        'Deletion requested: ' .
                        $validated['reason'],
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' => 'Assignment has submission history and was archived instead of deleted.',
                'archived' => true,
                'assignment' => $this->assignmentData(
                    DB::table('assignments')
                        ->where('id', $assignmentId)
                        ->first(),
                    true
                ),
            ]);
        }

        DB::table('assignments')
            ->where('id', $assignmentId)
            ->delete();

        return response()->json([
            'message' => 'Assignment deleted successfully.',
            'archived' => false,
        ]);
    }

    public function extendDeadline(
        Request $request,
        int $assignmentId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        if ($assignment->status === 'archived') {
            return response()->json([
                'message' => 'Archived assignments cannot be updated.',
            ], 422);
        }

        $validated = $request->validate([
            'deadline_at' => [
                'required',
                'date',
                'after:now',
            ],
        ]);

        $status = $assignment->status === 'draft'
            ? 'draft'
            : $this->publishedStatus(
                $assignment->opens_at,
                $validated['deadline_at']
            );

        DB::table('assignments')
            ->where('id', $assignmentId)
            ->update([
                'deadline_at' =>
                    $validated['deadline_at'],
                'status' => $status,
                'close_reason' => null,
                'updated_at' => now(),
            ]);

        $this->notifyAssignmentStudents(
            $assignment->course_id,
            'Assignment deadline updated',
            'The deadline for "' .
                $assignment->title .
                '" has been updated.',
            $assignmentId
        );

        return response()->json([
            'message' => 'Assignment deadline extended successfully.',
            'assignment' => $this->assignmentData(
                DB::table('assignments')
                    ->where('id', $assignmentId)
                    ->first(),
                true
            ),
        ]);
    }

    public function close(
        Request $request,
        int $assignmentId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        if ($assignment->status === 'archived') {
            return response()->json([
                'message' => 'Archived assignments cannot be closed.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        DB::table('assignments')
            ->where('id', $assignmentId)
            ->update([
                'status' => 'closed',
                'close_reason' =>
                    $validated['reason'],
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Assignment closed successfully.',
            'assignment' => $this->assignmentData(
                DB::table('assignments')
                    ->where('id', $assignmentId)
                    ->first(),
                true
            ),
        ]);
    }

    public function reopen(
        Request $request,
        int $assignmentId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        if ($assignment->status === 'archived') {
            return response()->json([
                'message' => 'Archived assignments cannot be reopened.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
            'deadline_at' => [
                'required',
                'date',
                'after:now',
            ],
        ]);

        $status = $this->publishedStatus(
            $assignment->opens_at,
            $validated['deadline_at']
        );

        DB::table('assignments')
            ->where('id', $assignmentId)
            ->update([
                'status' => $status,
                'deadline_at' =>
                    $validated['deadline_at'],
                'close_reason' => null,
                'published_at' =>
                    $assignment->published_at
                        ?: now(),
                'updated_at' => now(),
            ]);

        $this->notifyAssignmentStudents(
            $assignment->course_id,
            'Assignment reopened',
            '"' . $assignment->title .
                '" has been reopened with a new deadline.',
            $assignmentId
        );

        return response()->json([
            'message' => 'Assignment reopened successfully.',
            'assignment' => $this->assignmentData(
                DB::table('assignments')
                    ->where('id', $assignmentId)
                    ->first(),
                true
            ),
        ]);
    }

    public function submissions(
        Request $request,
        int $assignmentId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        return response()->json([
            'assignment' =>
                $this->assignmentData($assignment),
            'submissions' =>
                $this->submissionList(
                    $assignmentId
                ),
        ]);
    }

    public function gradeSubmission(
        Request $request,
        int $assignmentId,
        int $submissionId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        $submission = $this->submissionForAssignment(
            $submissionId,
            $assignmentId
        );

        if (!$submission) {
            return response()->json([
                'message' => 'Submission not found.',
            ], 404);
        }

        $validated = $request->validate([
            'grade' => [
                'required',
                'numeric',
                'min:0',
            ],
            'feedback' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'private_note' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        if (
            (float) $validated['grade'] >
            (float) $assignment->max_grade
        ) {
            return response()->json([
                'message' =>
                    'Grade cannot exceed the assignment maximum grade.',
            ], 422);
        }

        DB::table('submissions')
            ->where('id', $submissionId)
            ->update([
                'status' => 'graded',
                'grade' =>
                    $validated['grade'],
                'feedback' =>
                    $validated['feedback'] ?? null,
                'private_note' =>
                    $validated['private_note'] ?? null,
                'graded_by' =>
                    $trainer->id,
                'graded_at' => now(),
                'resubmission_due_at' => null,
                'updated_at' => now(),
            ]);

        $this->notifySubmissionStudent(
            $submission->student_id,
            'Assignment graded',
            'Your submission for "' .
                $assignment->title .
                '" has been graded.',
            $assignmentId
        );

        return response()->json([
            'message' => 'Grade and feedback published successfully.',
            'submission' =>
                $this->submissionData(
                    $submissionId
                ),
        ]);
    }

    public function requestResubmission(
        Request $request,
        int $assignmentId,
        int $submissionId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        $submission = $this->submissionForAssignment(
            $submissionId,
            $assignmentId
        );

        if (!$submission) {
            return response()->json([
                'message' => 'Submission not found.',
            ], 404);
        }

        $validated = $request->validate([
            'feedback' => [
                'required',
                'string',
                'max:5000',
            ],
            'deadline_at' => [
                'nullable',
                'date',
                'after:now',
            ],
        ]);

        DB::table('submissions')
            ->where('id', $submissionId)
            ->update([
                'status' =>
                    'revision_requested',
                'feedback' =>
                    $validated['feedback'],
                'grade' => null,
                'graded_by' => null,
                'graded_at' => null,
                'resubmission_due_at' =>
                    $validated['deadline_at']
                        ?? null,
                'updated_at' => now(),
            ]);

        $this->notifySubmissionStudent(
            $submission->student_id,
            'Resubmission requested',
            'Changes were requested for "' .
                $assignment->title .
                '".',
            $assignmentId
        );

        return response()->json([
            'message' => 'Resubmission requested successfully.',
            'submission' =>
                $this->submissionData(
                    $submissionId
                ),
        ]);
    }

    public function destroySubmission(
        Request $request,
        int $assignmentId,
        int $submissionId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForTrainer(
            $assignmentId,
            $trainer->id
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        $submission = $this->submissionForAssignment(
            $submissionId,
            $assignmentId
        );

        if (!$submission) {
            return response()->json([
                'message' => 'Submission not found.',
            ], 404);
        }

        $request->validate([
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $files = DB::table('submission_files')
            ->where(
                'submission_id',
                $submissionId
            )
            ->get();

        foreach ($files as $file) {
            if ($file->file_path) {
                Storage::disk('public')
                    ->delete(
                        $file->file_path
                    );
            }
        }

        DB::transaction(function () use (
            $submissionId
        ) {
            DB::table('submission_files')
                ->where(
                    'submission_id',
                    $submissionId
                )
                ->delete();

            DB::table('submissions')
                ->where('id', $submissionId)
                ->delete();
        });

        return response()->json([
            'message' => 'Submission deleted successfully.',
        ]);
    }

    private function assignmentData(
        object $assignment,
        bool $withSubmissions = false
    ): array {
        $course = DB::table('courses')
            ->where(
                'id',
                $assignment->course_id
            )
            ->first([
                'id',
                'title',
            ]);

        $counts = DB::table('submissions')
            ->where(
                'assignment_id',
                $assignment->id
            )
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                "SUM(CASE WHEN status IN ('submitted','late','resubmitted') THEN 1 ELSE 0 END) as pending"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'graded' THEN 1 ELSE 0 END) as graded"
            )
            ->first();

        $state = $this->assignmentState(
            $assignment
        );

        return [
            'id' =>
                (int) $assignment->id,
            'courseId' =>
                (int) $assignment->course_id,
            'courseTitle' =>
                $course?->title
                    ?: 'General course',
            'title' =>
                $assignment->title,
            'description' =>
                $assignment->description,
            'instructions' =>
                $assignment
                    ->submission_instructions,
            'maxGrade' =>
                (int) $assignment->max_grade,
            'openAt' =>
                $assignment->opens_at,
            'dueAt' =>
                $assignment->deadline_at,
            'status' =>
                in_array(
                    $assignment->status,
                    [
                        'scheduled',
                        'active',
                        'closed',
                    ],
                    true
                )
                    ? 'published'
                    : $assignment->status,
            'databaseStatus' =>
                $assignment->status,
            'state' => $state,
            'publishedAt' =>
                $assignment->published_at,
            'closeReason' =>
                $assignment->close_reason,
            'closedManually' =>
                $assignment->status ===
                    'closed' &&
                !empty(
                    $assignment
                        ->close_reason
                ),
            'archivedAt' =>
                $assignment->archived_at,
            'createdAt' =>
                $assignment->created_at,
            'updatedAt' =>
                $assignment->updated_at,
            'submissionCount' =>
                (int) (
                    $counts->total ?? 0
                ),
            'pendingSubmissions' =>
                (int) (
                    $counts->pending ?? 0
                ),
            'gradedSubmissions' =>
                (int) (
                    $counts->graded ?? 0
                ),
            'submissions' =>
                $withSubmissions
                    ? $this
                        ->submissionList(
                            $assignment->id
                        )
                    : [],
        ];
    }

    private function submissionList(
        int $assignmentId
    ) {
        return DB::table('submissions as s')
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
            ->where(
                's.assignment_id',
                $assignmentId
            )
            ->where(
                's.status',
                '!=',
                'draft'
            )
            ->orderByRaw(
                "CASE
                    WHEN s.status = 'submitted' THEN 0
                    WHEN s.status = 'late' THEN 1
                    WHEN s.status = 'resubmitted' THEN 2
                    WHEN s.status = 'revision_requested' THEN 3
                    WHEN s.status = 'graded' THEN 4
                    ELSE 5
                END"
            )
            ->orderByDesc(
                's.submitted_at'
            )
            ->pluck('s.id')
            ->map(
                fn ($submissionId) =>
                    $this->submissionData(
                        $submissionId
                    )
            )
            ->values();
    }

    private function submissionData(
        int $submissionId
    ): ?array {
        $submission = DB::table('submissions as s')
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
            ->where('s.id', $submissionId)
            ->first([
                's.id',
                's.assignment_id',
                's.student_id',
                's.submission_name',
                's.note',
                's.status',
                's.submitted_at',
                's.grade',
                's.feedback',
                's.private_note',
                's.resubmission_due_at',
                's.graded_at',
                's.created_at',
                's.updated_at',
                'st.student_code',
                'u.name',
                'u.email',
                'u.avatar',
            ]);

        if (!$submission) {
            return null;
        }

        $files = DB::table(
            'submission_files'
        )
            ->where(
                'submission_id',
                $submissionId
            )
            ->orderBy('id')
            ->get()
            ->map(fn ($file) => [
                'id' => (int) $file->id,
                'name' =>
                    $file->original_name,
                'originalName' =>
                    $file->original_name,
                'type' =>
                    $file->file_type,
                'size' =>
                    $file->file_size !== null
                        ? (int) $file
                            ->file_size
                        : null,
                'url' =>
                    $this->fileUrl(
                        $file->file_path
                    ),
            ])
            ->values();

        $frontendStatus =
            $submission->status ===
            'revision_requested'
                ? 'changes-requested'
                : $submission->status;

        return [
            'id' =>
                (int) $submission->id,
            'assignmentId' =>
                (int) $submission
                    ->assignment_id,
            'studentId' =>
                $submission->student_code
                    ?: (string) $submission
                        ->student_id,
            'studentDatabaseId' =>
                (int) $submission
                    ->student_id,
            'studentName' =>
                $submission->name,
            'studentEmail' =>
                $submission->email,
            'studentAvatar' =>
                $this->fileUrl(
                    $submission->avatar
                ),
            'title' =>
                $submission
                    ->submission_name,
            'text' =>
                $submission->note,
            'files' => $files,
            'status' =>
                $frontendStatus,
            'databaseStatus' =>
                $submission->status,
            'submittedAt' =>
                $submission
                    ->submitted_at,
            'updatedAt' =>
                $submission
                    ->updated_at,
            'grade' =>
                $submission->grade !==
                null
                    ? (float) $submission
                        ->grade
                    : null,
            'feedback' =>
                $submission->feedback,
            'privateNote' =>
                $submission
                    ->private_note,
            'resubmissionDueAt' =>
                $submission
                    ->resubmission_due_at,
            'gradedAt' =>
                $submission->graded_at,
        ];
    }

    private function validateAssignment(
        Request $request
    ): array {
        return $request->validate([
            'course_id' => [
                'required',
                'integer',
            ],
            'title' => [
                'required',
                'string',
                'max:191',
            ],
            'description' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'submission_instructions' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'max_grade' => [
                'required',
                'integer',
                'min:1',
                'max:10000',
            ],
            'opens_at' => [
                'nullable',
                'date',
            ],
            'deadline_at' => [
                'nullable',
                'date',
            ],
        ]);
    }

    private function assignmentForTrainer(
        int $assignmentId,
        int $trainerId
    ) {
        return DB::table('assignments')
            ->where('id', $assignmentId)
            ->where(
                'trainer_id',
                $trainerId
            )
            ->first();
    }

    private function submissionForAssignment(
        int $submissionId,
        int $assignmentId
    ) {
        return DB::table('submissions')
            ->where('id', $submissionId)
            ->where(
                'assignment_id',
                $assignmentId
            )
            ->first();
    }

    private function courseForTrainer(
        int $courseId,
        int $trainerId
    ) {
        return DB::table('courses')
            ->where('id', $courseId)
            ->where(
                'trainer_id',
                $trainerId
            )
            ->where(
                'status',
                '!=',
                'archived'
            )
            ->first();
    }

    private function trainerFromRequest(
        Request $request
    ) {
        $user = $request->user();

        if (
            !$user ||
            $user->role !== 'trainer'
        ) {
            return null;
        }

        return DB::table('trainers')
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'status',
                'active'
            )
            ->first();
    }

    private function syncStatuses(
        int $trainerId
    ): void {
        DB::table('assignments')
            ->where(
                'trainer_id',
                $trainerId
            )
            ->whereIn(
                'status',
                ['scheduled', 'active']
            )
            ->whereNotNull(
                'deadline_at'
            )
            ->where(
                'deadline_at',
                '<=',
                now()
            )
            ->update([
                'status' => 'closed',
                'updated_at' => now(),
            ]);

        DB::table('assignments')
            ->where(
                'trainer_id',
                $trainerId
            )
            ->where(
                'status',
                'scheduled'
            )
            ->where(function ($query) {
                $query
                    ->whereNull(
                        'opens_at'
                    )
                    ->orWhere(
                        'opens_at',
                        '<=',
                        now()
                    );
            })
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);
    }

    private function refreshAssignmentStatus(
        int $assignmentId
    ): void {
        $assignment = DB::table('assignments')
            ->where('id', $assignmentId)
            ->first();

        if (
            !$assignment ||
            in_array(
                $assignment->status,
                ['draft', 'archived'],
                true
            )
        ) {
            return;
        }

        DB::table('assignments')
            ->where('id', $assignmentId)
            ->update([
                'status' =>
                    $this->publishedStatus(
                        $assignment->opens_at,
                        $assignment->deadline_at
                    ),
                'updated_at' => now(),
            ]);
    }

    private function publishedStatus(
        $opensAt,
        $deadlineAt
    ): string {
        if (
            $deadlineAt &&
            strtotime($deadlineAt) <=
                now()->timestamp
        ) {
            return 'closed';
        }

        if (
            $opensAt &&
            strtotime($opensAt) >
                now()->timestamp
        ) {
            return 'scheduled';
        }

        return 'active';
    }

    private function assignmentState(
        object $assignment
    ): string {
        if (
            $assignment->status ===
            'draft'
        ) {
            return 'draft';
        }

        if (
            $assignment->status ===
            'archived'
        ) {
            return 'archived';
        }

        if (
            $assignment->status ===
            'closed'
        ) {
            return 'closed';
        }

        if (
            $assignment->deadline_at &&
            strtotime(
                $assignment
                    ->deadline_at
            ) <= now()->timestamp
        ) {
            return 'closed';
        }

        if (
            $assignment->opens_at &&
            strtotime(
                $assignment->opens_at
            ) > now()->timestamp
        ) {
            return 'scheduled';
        }

        return 'open';
    }

    private function notifyAssignmentStudents(
        int $courseId,
        string $title,
        string $message,
        int $assignmentId
    ): void {
        $userIds = DB::table('enrollments as e')
            ->join(
                'students as s',
                's.id',
                '=',
                'e.student_id'
            )
            ->where(
                'e.course_id',
                $courseId
            )
            ->whereIn(
                'e.status',
                ['active', 'completed']
            )
            ->pluck('s.user_id')
            ->unique();

        foreach ($userIds as $userId) {
            NotificationService::create(
                $userId,
                'assignment',
                $title,
                $message,
                [
                    'category' =>
                        'academics',
                    'icon' => '📝',
                    'action_label' =>
                        'View assignments',
                    'action_tab' =>
                        'Assignments',
                    'assignment_id' =>
                        $assignmentId,
                ]
            );
        }
    }

    private function notifySubmissionStudent(
        int $studentId,
        string $title,
        string $message,
        int $assignmentId
    ): void {
        $userId = DB::table('students')
            ->where('id', $studentId)
            ->value('user_id');

        if (!$userId) {
            return;
        }

        NotificationService::create(
            $userId,
            'assignment',
            $title,
            $message,
            [
                'category' => 'academics',
                'icon' => '📝',
                'action_label' =>
                    'View assignments',
                'action_tab' =>
                    'Assignments',
                'assignment_id' =>
                    $assignmentId,
            ]
        );
    }

    private function fileUrl(
        ?string $path
    ): ?string {
        if (!$path) {
            return null;
        }

        if (
            str_starts_with(
                $path,
                'http://'
            ) ||
            str_starts_with(
                $path,
                'https://'
            ) ||
            str_starts_with(
                $path,
                'data:'
            )
        ) {
            return $path;
        }

        return asset(
            'storage/' .
            ltrim($path, '/')
        );
    }
}

