<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AssignmentController extends Controller
{
    public function index(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $assignments = DB::table('assignments as a')
            ->join('courses as c', 'c.id', '=', 'a.course_id')
            ->join('enrollments as e', function ($join) use ($student) {
                $join
                    ->on('e.course_id', '=', 'a.course_id')
                    ->where('e.student_id', '=', $student->id)
                    ->whereIn('e.status', ['active', 'completed']);
            })
            ->leftJoin('submissions as s', function ($join) use ($student) {
                $join
                    ->on('s.assignment_id', '=', 'a.id')
                    ->where('s.student_id', '=', $student->id);
            })
            ->whereIn('a.status', ['scheduled', 'active', 'closed'])
            ->select(
                'a.id',
                'a.course_id',
                'a.title',
                'a.description',
                'a.submission_instructions',
                'a.max_grade',
                'a.opens_at',
                'a.deadline_at',
                'a.status',
                'a.published_at',
                'c.title as course_title',
                's.id as submission_id',
                's.submission_name',
                's.note',
                's.status as submission_status',
                's.submitted_at',
                's.grade',
                's.feedback',
                's.graded_at'
            )
            ->orderByRaw('a.deadline_at IS NULL')
            ->orderBy('a.deadline_at')
            ->orderByDesc('a.id')
            ->get()
            ->map(fn($assignment) => $this->formatAssignment($assignment))
            ->values();

        $counts = [
            'all' => $assignments->count(),
            'pending' => $assignments
                ->filter(fn($item) => $item['filter_status'] === 'pending')
                ->count(),
            'overdue' => $assignments
                ->filter(fn($item) => $item['filter_status'] === 'overdue')
                ->count(),
            'submitted' => $assignments
                ->filter(fn($item) => $item['filter_status'] === 'submitted')
                ->count(),
            'graded' => $assignments
                ->filter(fn($item) => $item['filter_status'] === 'graded')
                ->count(),
        ];

        return response()->json([
            'assignments' => $assignments,
            'counts' => $counts,
        ]);
    }

    public function show(Request $request, int $assignmentId)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForStudent(
            $student->id,
            $assignmentId
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        return response()->json([
            'assignment' => $this->formatAssignment(
                $assignment,
                true
            ),
        ]);
    }

    public function saveSubmission(
        Request $request,
        int $assignmentId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForStudent(
            $student->id,
            $assignmentId
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        if ($this->assignmentState($assignment) !== 'open') {
            return response()->json([
                'message' => 'This assignment is not open for submission.',
            ], 422);
        }

        $validated = $request->validate([
            'submission_name' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $existing = DB::table('submissions')
            ->where('assignment_id', $assignmentId)
            ->where('student_id', $student->id)
            ->first();

        if (
            $existing &&
            in_array(
                $existing->status,
                ['submitted', 'late', 'resubmitted', 'graded'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This submission is locked.',
            ], 422);
        }

        $now = now();

        if ($existing) {
            DB::table('submissions')
                ->where('id', $existing->id)
                ->update([
                    'submission_name' => $validated['submission_name'],
                    'note' => $validated['note'] ?? null,
                    'status' => 'draft',
                    'updated_at' => $now,
                ]);

            $submissionId = $existing->id;
        } else {
            $submissionId = DB::table('submissions')
                ->insertGetId([
                    'assignment_id' => $assignmentId,
                    'student_id' => $student->id,
                    'submission_name' => $validated['submission_name'],
                    'note' => $validated['note'] ?? null,
                    'status' => 'draft',
                    'submitted_at' => null,
                    'grade' => null,
                    'feedback' => null,
                    'graded_by' => null,
                    'graded_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        return response()->json([
            'message' => 'Submission draft saved successfully.',
            'submission' => $this->submissionData($submissionId),
        ]);
    }

    public function submit(
        Request $request,
        int $assignmentId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForStudent(
            $student->id,
            $assignmentId
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        if ($this->assignmentState($assignment) !== 'open') {
            return response()->json([
                'message' => 'This assignment is not open for submission.',
            ], 422);
        }

        $validated = $request->validate([
            'submission_name' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $submission = DB::table('submissions')
            ->where('assignment_id', $assignmentId)
            ->where('student_id', $student->id)
            ->first();

        if (!$submission) {
            return response()->json([
                'message' => 'Save the submission before final submission.',
            ], 422);
        }

        if (
            in_array(
                $submission->status,
                ['submitted', 'late', 'resubmitted', 'graded'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This submission is locked.',
            ], 422);
        }

        $fileCount = DB::table('submission_files')
            ->where('submission_id', $submission->id)
            ->count();

        if ($fileCount < 1) {
            return response()->json([
                'message' => 'Attach at least one file before submitting.',
            ], 422);
        }

        $now = now();
        $wasPreviouslySubmitted = $submission->submitted_at !== null;

        if ($wasPreviouslySubmitted) {
            $status = 'resubmitted';
        } elseif (
            $assignment->deadline_at &&
            Carbon::parse($assignment->deadline_at)->isPast()
        ) {
            $status = 'late';
        } else {
            $status = 'submitted';
        }

        DB::table('submissions')
            ->where('id', $submission->id)
            ->update([
                'submission_name' => $validated['submission_name'],
                'note' => $validated['note'] ?? null,
                'status' => $status,
                'submitted_at' => $now,
                'updated_at' => $now,
            ]);

        return response()->json([
            'message' => $status === 'late'
                ? 'Assignment submitted late successfully.'
                : 'Assignment submitted successfully.',
            'submission' => $this->submissionData(
                $submission->id
            ),
        ]);
    }

    public function uploadFiles(
        Request $request,
        int $assignmentId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForStudent(
            $student->id,
            $assignmentId
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        if ($this->assignmentState($assignment) !== 'open') {
            return response()->json([
                'message' => 'This assignment is not open for submission.',
            ], 422);
        }

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:3'],
            'files.*' => [
                'required',
                'file',
                'mimes:pdf,doc,docx,zip,ppt,pptx,png,jpg,jpeg,webp',
                'max:1024',
            ],
        ]);

        $submission = DB::table('submissions')
            ->where('assignment_id', $assignmentId)
            ->where('student_id', $student->id)
            ->first();

        if (!$submission) {
            return response()->json([
                'message' => 'Save the submission draft before uploading files.',
            ], 422);
        }

        if (
            in_array(
                $submission->status,
                ['submitted', 'late', 'resubmitted', 'graded'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This submission is locked.',
            ], 422);
        }

        $currentFiles = DB::table('submission_files')
            ->where('submission_id', $submission->id)
            ->count();

        $newFiles = $request->file('files', []);

        if ($currentFiles + count($newFiles) > 3) {
            return response()->json([
                'message' => 'You can attach up to 3 files.',
            ], 422);
        }

        $storedFiles = [];

        foreach ($newFiles as $file) {
            $path = $file->store(
                'submissions/' . $student->id . '/' . $assignmentId,
                'public'
            );

            $fileId = DB::table('submission_files')
                ->insertGetId([
                    'submission_id' => $submission->id,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $storedFiles[] = $this->submissionFileData(
                DB::table('submission_files')
                    ->where('id', $fileId)
                    ->first()
            );
        }

        return response()->json([
            'message' => 'Files uploaded successfully.',
            'files' => $storedFiles,
            'submission' => $this->submissionData(
                $submission->id
            ),
        ], 201);
    }

    public function deleteFile(
        Request $request,
        int $assignmentId,
        int $fileId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $assignment = $this->assignmentForStudent(
            $student->id,
            $assignmentId
        );

        if (!$assignment) {
            return response()->json([
                'message' => 'Assignment not found.',
            ], 404);
        }

        $submission = DB::table('submissions')
            ->where('assignment_id', $assignmentId)
            ->where('student_id', $student->id)
            ->first();

        if (!$submission) {
            return response()->json([
                'message' => 'Submission not found.',
            ], 404);
        }

        if (
            in_array(
                $submission->status,
                ['submitted', 'late', 'resubmitted', 'graded'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This submission is locked.',
            ], 422);
        }

        $file = DB::table('submission_files')
            ->where('id', $fileId)
            ->where('submission_id', $submission->id)
            ->first();

        if (!$file) {
            return response()->json([
                'message' => 'Submission file not found.',
            ], 404);
        }

        Storage::disk('public')->delete($file->file_path);

        DB::table('submission_files')
            ->where('id', $file->id)
            ->delete();

        return response()->json([
            'message' => 'Submission file deleted successfully.',
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

    private function assignmentForStudent(
        int $studentId,
        int $assignmentId
    ) {
        return DB::table('assignments as a')
            ->join('courses as c', 'c.id', '=', 'a.course_id')
            ->join('enrollments as e', function ($join) use ($studentId) {
                $join
                    ->on('e.course_id', '=', 'a.course_id')
                    ->where('e.student_id', '=', $studentId)
                    ->whereIn('e.status', ['active', 'completed']);
            })
            ->leftJoin('submissions as s', function ($join) use ($studentId) {
                $join
                    ->on('s.assignment_id', '=', 'a.id')
                    ->where('s.student_id', '=', $studentId);
            })
            ->where('a.id', $assignmentId)
            ->whereIn('a.status', ['scheduled', 'active', 'closed'])
            ->select(
                'a.id',
                'a.course_id',
                'a.title',
                'a.description',
                'a.submission_instructions',
                'a.max_grade',
                'a.opens_at',
                'a.deadline_at',
                'a.status',
                'a.published_at',
                'c.title as course_title',
                's.id as submission_id',
                's.submission_name',
                's.note',
                's.status as submission_status',
                's.submitted_at',
                's.grade',
                's.feedback',
                's.graded_at'
            )
            ->first();
    }

    private function formatAssignment(
        $assignment,
        bool $withFiles = false
    ): array {
        $state = $this->assignmentState($assignment);
        $daysLeft = $this->daysLeft($assignment->deadline_at);

        $submission = null;

        if ($assignment->submission_id) {
            $submission = [
                'id' => $assignment->submission_id,
                'submission_name' => $assignment->submission_name,
                'note' => $assignment->note,
                'status' => $assignment->submission_status,
                'submitted_at' => $assignment->submitted_at,
                'grade' => $assignment->grade !== null
                    ? (float) $assignment->grade
                    : null,
                'feedback' => $assignment->feedback,
                'graded_at' => $assignment->graded_at,
                'files' => $withFiles
                    ? $this->submissionFiles(
                        $assignment->submission_id
                    )
                    : [],
            ];
        }

        $filterStatus = $this->filterStatus(
            $state,
            $daysLeft,
            $assignment->submission_status
        );

        return [
            'id' => $assignment->id,
            'title' => $assignment->title,
            'description' => $assignment->description,
            'submission_instructions' =>
            $assignment->submission_instructions,
            'max_grade' => (int) $assignment->max_grade,
            'opens_at' => $assignment->opens_at,
            'deadline_at' => $assignment->deadline_at,
            'status' => $assignment->status,
            'assignment_state' => $state,
            'days_left' => $daysLeft,
            'filter_status' => $filterStatus,
            'can_submit' => $this->canSubmit(
                $state,
                $assignment->submission_status
            ),
            'course' => [
                'id' => $assignment->course_id,
                'title' => $assignment->course_title,
            ],
            'submission' => $submission,
        ];
    }

    private function assignmentState($assignment): string
    {
        if ($assignment->status === 'closed') {
            return 'closed';
        }

        if (
            $assignment->deadline_at &&
            Carbon::parse($assignment->deadline_at)->isPast()
        ) {
            return 'closed';
        }

        if (
            $assignment->opens_at &&
            Carbon::parse($assignment->opens_at)->isFuture()
        ) {
            return 'scheduled';
        }

        if ($assignment->status === 'scheduled') {
            return $assignment->opens_at &&
                Carbon::parse($assignment->opens_at)->isPast()
                ? 'open'
                : 'scheduled';
        }

        return 'open';
    }

    private function daysLeft($deadlineAt): ?int
    {
        if (!$deadlineAt) {
            return null;
        }

        return now()
            ->startOfDay()
            ->diffInDays(
                Carbon::parse($deadlineAt)->startOfDay(),
                false
            );
    }

    private function filterStatus(
        string $state,
        ?int $daysLeft,
        ?string $submissionStatus
    ): string {
        if ($submissionStatus === 'graded') {
            return 'graded';
        }

        if (
            in_array(
                $submissionStatus,
                ['submitted', 'late', 'resubmitted'],
                true
            )
        ) {
            return 'submitted';
        }

        if (
            $state === 'closed' ||
            ($daysLeft !== null && $daysLeft < 0)
        ) {
            return 'overdue';
        }

        return 'pending';
    }

    private function canSubmit(
        string $state,
        ?string $submissionStatus
    ): bool {
        if ($state !== 'open') {
            return false;
        }

        return !in_array(
            $submissionStatus,
            ['submitted', 'late', 'resubmitted', 'graded'],
            true
        );
    }

    private function submissionData(int $submissionId): array
    {
        $submission = DB::table('submissions')
            ->where('id', $submissionId)
            ->first();

        return [
            'id' => $submission->id,
            'assignment_id' => $submission->assignment_id,
            'submission_name' => $submission->submission_name,
            'note' => $submission->note,
            'status' => $submission->status,
            'submitted_at' => $submission->submitted_at,
            'grade' => $submission->grade !== null
                ? (float) $submission->grade
                : null,
            'feedback' => $submission->feedback,
            'graded_at' => $submission->graded_at,
            'files' => $this->submissionFiles(
                $submission->id
            ),
        ];
    }

    private function submissionFiles(int $submissionId): array
    {
        return DB::table('submission_files')
            ->where('submission_id', $submissionId)
            ->orderBy('id')
            ->get()
            ->map(fn($file) => $this->submissionFileData($file))
            ->values()
            ->all();
    }

    private function submissionFileData($file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'type' => $file->file_type,
            'size' => (int) $file->file_size,
            'url' => asset(
                'storage/' . ltrim($file->file_path, '/')
            ),
        ];
    }
}
