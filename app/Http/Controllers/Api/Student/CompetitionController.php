<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CompetitionController extends Controller
{
    public function index(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $competitions = DB::table('competitions')
            ->where('status', '!=', 'draft')
            ->orderByRaw(
                "CASE
                    WHEN status = 'registration_open' THEN 0
                    WHEN status = 'submissions_open' THEN 1
                    WHEN status = 'registration_closed' THEN 2
                    WHEN status = 'judging' THEN 3
                    WHEN status = 'results_published' THEN 4
                    ELSE 5
                END"
            )
            ->orderBy('registration_end_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->map(
                fn ($competitionId) => $this->competitionData(
                    $competitionId,
                    $student->id
                )
            )
            ->filter()
            ->values();

        $categories = $competitions
            ->pluck('category')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return response()->json([
            'competitions' => $competitions,
            'categories' => $categories,
        ]);
    }

    public function show(
        Request $request,
        int $competitionId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $competition = DB::table('competitions')
            ->where('id', $competitionId)
            ->where('status', '!=', 'draft')
            ->first();

        if (!$competition) {
            return response()->json([
                'message' => 'Competition not found.',
            ], 404);
        }

        return response()->json([
            'competition' => $this->competitionData(
                $competitionId,
                $student->id
            ),
        ]);
    }

    public function register(
        Request $request,
        int $competitionId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $competition = DB::table('competitions')
            ->where('id', $competitionId)
            ->first();

        if (!$competition) {
            return response()->json([
                'message' => 'Competition not found.',
            ], 404);
        }

        if (!$this->registrationIsOpen($competition)) {
            return response()->json([
                'message' => 'Registration is closed for this competition.',
            ], 422);
        }

        $existing = $this->studentRegistration(
            $competitionId,
            $student->id
        );

        if (
            $existing &&
            !in_array(
                $existing->status,
                ['rejected', 'withdrawn'],
                true
            )
        ) {
            return response()->json([
                'message' => 'You already have a registration for this competition.',
            ], 422);
        }

        $participationValues = match (
            $competition->participation_type
        ) {
            'individual' => ['individual'],
            'team' => ['team'],
            default => ['individual', 'team'],
        };

        $maxTeamMembers = max(
            1,
            (int) ($competition->max_team_members ?? 1)
        );

        $validated = $request->validate([
            'participation_type' => [
                'required',
                Rule::in($participationValues),
            ],
            'team_name' => [
                'nullable',
                'string',
                'max:191',
                Rule::requiredIf(
                    $request->input('participation_type') === 'team'
                ),
            ],
            'members' => [
                'nullable',
                'array',
                'max:' . max(0, $maxTeamMembers - 1),
            ],
            'members.*.name' => [
                'required_with:members',
                'string',
                'max:191',
            ],
            'members.*.email' => [
                'required_with:members',
                'email',
                'max:191',
            ],
            'members.*.role' => [
                'nullable',
                'string',
                'max:191',
            ],
        ]);

        if (
            $validated['participation_type'] === 'team' &&
            empty($validated['members'])
        ) {
            return response()->json([
                'message' => 'Add at least one team member.',
            ], 422);
        }

        $memberEmails = collect(
            $validated['members'] ?? []
        )
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower(trim($email)))
            ->filter();

        if ($memberEmails->duplicates()->isNotEmpty()) {
            return response()->json([
                'message' => 'Team member emails must be unique.',
            ], 422);
        }

        $currentEmail = mb_strtolower(
            trim((string) $request->user()->email)
        );

        if ($memberEmails->contains($currentEmail)) {
            return response()->json([
                'message' => 'Do not add yourself as a team member.',
            ], 422);
        }

        $registrationId = DB::transaction(function () use (
            $competitionId,
            $student,
            $request,
            $validated,
            $existing
        ) {
            if ($existing) {
                DB::table('competition_registration_members')
                    ->where(
                        'competition_registration_id',
                        $existing->id
                    )
                    ->delete();

                DB::table('competition_registrations')
                    ->where('id', $existing->id)
                    ->update([
                        'team_name' =>
                            $validated['participation_type'] === 'team'
                                ? $validated['team_name']
                                : null,
                        'status' => 'pending',
                        'rejection_reason' => null,
                        'reviewed_at' => null,
                        'registered_at' => now(),
                        'updated_at' => now(),
                    ]);

                $registrationId = $existing->id;
            } else {
                $registrationId = DB::table(
                    'competition_registrations'
                )->insertGetId([
                    'competition_id' => $competitionId,
                    'team_name' =>
                        $validated['participation_type'] === 'team'
                            ? $validated['team_name']
                            : null,
                    'status' => 'pending',
                    'rejection_reason' => null,
                    'reviewed_at' => null,
                    'registered_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('competition_registration_members')
                ->insert([
                    'competition_registration_id' => $registrationId,
                    'student_id' => $student->id,
                    'member_name' => $request->user()->name,
                    'member_email' => $request->user()->email,
                    'role' => 'leader',
                    'member_role' =>
                        $validated['participation_type'] === 'team'
                            ? 'Team leader'
                            : 'Individual participant',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach ($validated['members'] ?? [] as $member) {
                $memberStudentId = DB::table('users as u')
                    ->join(
                        'students as s',
                        's.user_id',
                        '=',
                        'u.id'
                    )
                    ->whereRaw(
                        'LOWER(u.email) = ?',
                        [mb_strtolower(trim($member['email']))]
                    )
                    ->value('s.id');

                DB::table('competition_registration_members')
                    ->insert([
                        'competition_registration_id' =>
                            $registrationId,
                        'student_id' => $memberStudentId,
                        'member_name' => trim($member['name']),
                        'member_email' => trim($member['email']),
                        'role' => 'member',
                        'member_role' =>
                            $member['role'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            return $registrationId;
        });

        return response()->json([
            'message' => 'Competition application sent successfully.',
            'registration' => $this->registrationData(
                DB::table('competition_registrations')
                    ->where('id', $registrationId)
                    ->first(),
                $student->id
            ),
        ], $existing ? 200 : 201);
    }

    public function saveSubmission(
        Request $request,
        int $competitionId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $context = $this->submissionContext(
            $competitionId,
            $student->id
        );

        if (!$context['competition']) {
            return response()->json([
                'message' => 'Competition not found.',
            ], 404);
        }

        if (!$context['registration']) {
            return response()->json([
                'message' => 'Competition registration not found.',
            ], 404);
        }

        if ($context['registration']->status !== 'approved') {
            return response()->json([
                'message' => 'Your competition application must be approved first.',
            ], 422);
        }

        if (!$this->submissionsAreOpen($context['competition'])) {
            return response()->json([
                'message' => 'Submissions are closed for this competition.',
            ], 422);
        }

        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:191',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'github_url' => [
                'nullable',
                'url',
                'max:2048',
            ],
            'demo_url' => [
                'nullable',
                'url',
                'max:2048',
            ],
        ]);

        $submission = DB::table('competition_submissions')
            ->where(
                'competition_registration_id',
                $context['registration']->id
            )
            ->first();

        if (
            $submission &&
            !in_array(
                $submission->status,
                ['draft', 'changes_requested'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This competition submission is locked.',
            ], 422);
        }

        $now = now();

        if ($submission) {
            DB::table('competition_submissions')
                ->where('id', $submission->id)
                ->update([
                    'title' => $validated['title'],
                    'description' =>
                        $validated['description'] ?? null,
                    'github_url' =>
                        $validated['github_url'] ?? null,
                    'demo_url' =>
                        $validated['demo_url'] ?? null,
                    'updated_at' => $now,
                ]);

            $submissionId = $submission->id;
        } else {
            $submissionId = DB::table(
                'competition_submissions'
            )->insertGetId([
                'competition_registration_id' =>
                    $context['registration']->id,
                'title' => $validated['title'],
                'description' =>
                    $validated['description'] ?? null,
                'github_url' =>
                    $validated['github_url'] ?? null,
                'demo_url' =>
                    $validated['demo_url'] ?? null,
                'status' => 'draft',
                'feedback' => null,
                'reviewed_at' => null,
                'delete_reason' => null,
                'deleted_at' => null,
                'submitted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return response()->json([
            'message' => 'Competition submission draft saved successfully.',
            'submission' => $this->submissionData(
                $submissionId
            ),
        ]);
    }

    public function uploadFiles(
        Request $request,
        int $competitionId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $context = $this->submissionContext(
            $competitionId,
            $student->id
        );

        if (
            !$context['competition'] ||
            !$context['registration']
        ) {
            return response()->json([
                'message' => 'Competition registration not found.',
            ], 404);
        }

        if (
            $context['registration']->status !== 'approved' ||
            !$this->submissionsAreOpen($context['competition'])
        ) {
            return response()->json([
                'message' => 'Competition submission is not available.',
            ], 422);
        }

        $submission = DB::table('competition_submissions')
            ->where(
                'competition_registration_id',
                $context['registration']->id
            )
            ->first();

        if (!$submission) {
            return response()->json([
                'message' => 'Save the submission draft before uploading files.',
            ], 422);
        }

        if (
            !in_array(
                $submission->status,
                ['draft', 'changes_requested'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This competition submission is locked.',
            ], 422);
        }

        $request->validate([
            'files' => [
                'required',
                'array',
                'min:1',
                'max:5',
            ],
            'files.*' => [
                'required',
                'file',
                'mimes:pdf,ppt,pptx,doc,docx,zip,png,jpg,jpeg,webp',
                'max:10240',
            ],
        ]);

        $currentCount = DB::table(
            'competition_submission_files'
        )
            ->where(
                'competition_submission_id',
                $submission->id
            )
            ->count();

        $files = $request->file('files', []);

        if ($currentCount + count($files) > 5) {
            return response()->json([
                'message' => 'You can attach up to 5 files.',
            ], 422);
        }

        $stored = [];

        foreach ($files as $file) {
            $path = $file->store(
                'competition-submissions/' .
                    $student->id .
                    '/' .
                    $competitionId,
                'public'
            );

            $fileId = DB::table(
                'competition_submission_files'
            )->insertGetId([
                'competition_submission_id' =>
                    $submission->id,
                'original_name' =>
                    $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $stored[] = $this->submissionFileData(
                DB::table('competition_submission_files')
                    ->where('id', $fileId)
                    ->first()
            );
        }

        return response()->json([
            'message' => 'Competition submission files uploaded successfully.',
            'files' => $stored,
            'submission' => $this->submissionData(
                $submission->id
            ),
        ], 201);
    }

    public function deleteFile(
        Request $request,
        int $competitionId,
        int $fileId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $context = $this->submissionContext(
            $competitionId,
            $student->id
        );

        if (!$context['registration']) {
            return response()->json([
                'message' => 'Competition registration not found.',
            ], 404);
        }

        $submission = DB::table('competition_submissions')
            ->where(
                'competition_registration_id',
                $context['registration']->id
            )
            ->first();

        if (!$submission) {
            return response()->json([
                'message' => 'Competition submission not found.',
            ], 404);
        }

        if (
            !in_array(
                $submission->status,
                ['draft', 'changes_requested'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This competition submission is locked.',
            ], 422);
        }

        $file = DB::table('competition_submission_files')
            ->where('id', $fileId)
            ->where(
                'competition_submission_id',
                $submission->id
            )
            ->first();

        if (!$file) {
            return response()->json([
                'message' => 'Competition submission file not found.',
            ], 404);
        }

        Storage::disk('public')->delete(
            $file->file_path
        );

        DB::table('competition_submission_files')
            ->where('id', $file->id)
            ->delete();

        return response()->json([
            'message' => 'Competition submission file deleted successfully.',
        ]);
    }

    public function submit(
        Request $request,
        int $competitionId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $context = $this->submissionContext(
            $competitionId,
            $student->id
        );

        if (
            !$context['competition'] ||
            !$context['registration']
        ) {
            return response()->json([
                'message' => 'Competition registration not found.',
            ], 404);
        }

        if ($context['registration']->status !== 'approved') {
            return response()->json([
                'message' => 'Your competition application must be approved first.',
            ], 422);
        }

        if (!$this->submissionsAreOpen($context['competition'])) {
            return response()->json([
                'message' => 'Submissions are closed for this competition.',
            ], 422);
        }

        $submission = DB::table('competition_submissions')
            ->where(
                'competition_registration_id',
                $context['registration']->id
            )
            ->first();

        if (!$submission) {
            return response()->json([
                'message' => 'Save the submission draft first.',
            ], 422);
        }

        if (
            !in_array(
                $submission->status,
                ['draft', 'changes_requested'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This competition submission is locked.',
            ], 422);
        }

        $fileCount = DB::table(
            'competition_submission_files'
        )
            ->where(
                'competition_submission_id',
                $submission->id
            )
            ->count();

        if (
            $fileCount < 1 &&
            !$submission->github_url &&
            !$submission->demo_url
        ) {
            return response()->json([
                'message' => 'Add at least one file, GitHub URL, or demo URL before submitting.',
            ], 422);
        }

        DB::table('competition_submissions')
            ->where('id', $submission->id)
            ->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Competition work submitted successfully.',
            'submission' => $this->submissionData(
                $submission->id
            ),
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

    private function registrationIsOpen(
        $competition
    ): bool {
        if ($competition->status !== 'registration_open') {
            return false;
        }

        $now = now();

        if (
            $competition->registration_start_at &&
            Carbon::parse(
                $competition->registration_start_at
            )->isFuture()
        ) {
            return false;
        }

        if (
            $competition->registration_end_at &&
            Carbon::parse(
                $competition->registration_end_at
            )->isPast()
        ) {
            return false;
        }

        return true;
    }

    private function submissionsAreOpen(
        $competition
    ): bool {
        if ($competition->status !== 'submissions_open') {
            return false;
        }

        $now = now();

        if (
            $competition->work_start_at &&
            Carbon::parse(
                $competition->work_start_at
            )->isFuture()
        ) {
            return false;
        }

        if (
            $competition->submission_deadline_at &&
            Carbon::parse(
                $competition->submission_deadline_at
            )->isPast()
        ) {
            return false;
        }

        return true;
    }

    private function effectivePhase($competition): string
    {
        if (
            in_array(
                $competition->status,
                ['results_published', 'completed'],
                true
            )
        ) {
            return $competition->status;
        }

        if (
            $competition->submission_deadline_at &&
            Carbon::parse(
                $competition->submission_deadline_at
            )->isPast() &&
            in_array(
                $competition->status,
                ['submissions_open', 'registration_closed'],
                true
            )
        ) {
            return 'judging';
        }

        if (
            $competition->registration_end_at &&
            Carbon::parse(
                $competition->registration_end_at
            )->isPast() &&
            $competition->status === 'registration_open'
        ) {
            return 'registration_closed';
        }

        return $competition->status;
    }

    private function studentRegistration(
        int $competitionId,
        int $studentId
    ) {
        return DB::table('competition_registrations as cr')
            ->join(
                'competition_registration_members as crm',
                'crm.competition_registration_id',
                '=',
                'cr.id'
            )
            ->where(
                'cr.competition_id',
                $competitionId
            )
            ->where('crm.student_id', $studentId)
            ->select('cr.*')
            ->orderByDesc('cr.id')
            ->first();
    }

    private function submissionContext(
        int $competitionId,
        int $studentId
    ): array {
        return [
            'competition' => DB::table('competitions')
                ->where('id', $competitionId)
                ->where('status', '!=', 'draft')
                ->first(),
            'registration' => $this->studentRegistration(
                $competitionId,
                $studentId
            ),
        ];
    }

    private function competitionData(
        int $competitionId,
        int $studentId
    ): ?array {
        $competition = DB::table('competitions as c')
            ->join(
                'trainers as t',
                't.id',
                '=',
                'c.created_by'
            )
            ->join(
                'users as u',
                'u.id',
                '=',
                't.user_id'
            )
            ->where('c.id', $competitionId)
            ->select(
                'c.*',
                'u.name as trainer_name',
                't.job_title as trainer_title'
            )
            ->first();

        if (!$competition) {
            return null;
        }

        $registration = $this->studentRegistration(
            $competitionId,
            $studentId
        );

        $submission = $registration
            ? DB::table('competition_submissions')
                ->where(
                    'competition_registration_id',
                    $registration->id
                )
                ->where('status', '!=', 'deleted')
                ->first()
            : null;

        $result = $registration
            ? DB::table('competition_results')
                ->where(
                    'competition_registration_id',
                    $registration->id
                )
                ->first()
            : null;

        $criteria = DB::table(
            'competition_evaluation_criteria'
        )
            ->where('competition_id', $competitionId)
            ->orderBy('position')
            ->get([
                'id',
                'title',
                'weight',
                'position',
            ])
            ->map(function ($criterion) {
                return [
                    'id' => $criterion->id,
                    'title' => $criterion->title,
                    'weight' => (float) $criterion->weight,
                    'position' => $criterion->position,
                ];
            })
            ->values();

        return [
            'id' => $competition->id,
            'title' => $competition->title,
            'category' => $competition->category,
            'description' => $competition->description,
            'objective' => $competition->objective,
            'participation_type' =>
                $competition->participation_type,
            'max_team_members' =>
                (int) ($competition->max_team_members ?? 1),
            'registration_start_at' =>
                $competition->registration_start_at,
            'registration_end_at' =>
                $competition->registration_end_at,
            'submission_open_at' =>
                $competition->work_start_at,
            'submission_deadline_at' =>
                $competition->submission_deadline_at,
            'results_at' => $competition->results_at,
            'prize' => $competition->prize,
            'phase' => $this->effectivePhase(
                $competition
            ),
            'results_published_at' =>
                $competition->results_published_at,
            'organizer' => [
                'trainer_id' => $competition->created_by,
                'name' => $competition->trainer_name,
                'title' => $competition->trainer_title,
            ],
            'participants_count' => DB::table(
                'competition_registrations'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->where('status', 'approved')
                ->count(),
            'requirements' => DB::table(
                'competition_requirements'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->orderBy('position')
                ->pluck('requirement')
                ->values(),
            'rules' => DB::table(
                'competition_rules'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->orderBy('position')
                ->pluck('rule')
                ->values(),
            'submission_requirements' => DB::table(
                'competition_submission_requirements'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->orderBy('position')
                ->get([
                    'id',
                    'title',
                    'type',
                    'position',
                ]),
            'evaluation_criteria' => $criteria,
            'registration' => $registration
                ? $this->registrationData(
                    $registration,
                    $studentId
                )
                : null,
            'submission' => $submission
                ? $this->submissionData(
                    $submission->id
                )
                : null,
            'result' => $result
                ? [
                    'rank' => $result->rank,
                    'final_score' =>
                        $result->final_score !== null
                            ? (float) $result->final_score
                            : null,
                    'award' => $result->award,
                    'published_at' => $result->published_at,
                ]
                : null,
            'permissions' => [
                'can_register' =>
                    $this->registrationIsOpen(
                        $competition
                    ) &&
                    (
                        !$registration ||
                        in_array(
                            $registration->status,
                            ['rejected', 'withdrawn'],
                            true
                        )
                    ),
                'can_submit' =>
                    $registration?->status === 'approved' &&
                    $this->submissionsAreOpen(
                        $competition
                    ) &&
                    (
                        !$submission ||
                        in_array(
                            $submission->status,
                            ['draft', 'changes_requested'],
                            true
                        )
                    ),
            ],
        ];
    }

    private function registrationData(
        $registration,
        int $studentId
    ): array {
        $members = DB::table(
            'competition_registration_members as crm'
        )
            ->leftJoin(
                'students as s',
                's.id',
                '=',
                'crm.student_id'
            )
            ->leftJoin(
                'users as u',
                'u.id',
                '=',
                's.user_id'
            )
            ->where(
                'crm.competition_registration_id',
                $registration->id
            )
            ->orderByRaw(
                "CASE WHEN crm.role = 'leader' THEN 0 ELSE 1 END"
            )
            ->orderBy('crm.id')
            ->select(
                'crm.id',
                'crm.student_id',
                'crm.member_name',
                'crm.member_email',
                'crm.role',
                'crm.member_role',
                'u.name as student_name',
                'u.email as student_email'
            )
            ->get()
            ->map(function ($member) use ($studentId) {
                return [
                    'id' => $member->id,
                    'student_id' => $member->student_id,
                    'name' => $member->student_name
                        ?: $member->member_name,
                    'email' => $member->student_email
                        ?: $member->member_email,
                    'membership_role' => $member->role,
                    'role' => $member->member_role,
                    'is_current_student' =>
                        (int) $member->student_id ===
                        (int) $studentId,
                ];
            })
            ->values();

        return [
            'id' => $registration->id,
            'team_name' => $registration->team_name,
            'participation_type' =>
                $registration->team_name
                    ? 'team'
                    : 'individual',
            'status' => $registration->status,
            'rejection_reason' =>
                $registration->rejection_reason,
            'reviewed_at' => $registration->reviewed_at,
            'registered_at' =>
                $registration->registered_at,
            'members' => $members,
        ];
    }

    private function submissionData(
        int $submissionId
    ): array {
        $submission = DB::table(
            'competition_submissions'
        )
            ->where('id', $submissionId)
            ->first();

        $registration = DB::table(
            'competition_registrations'
        )
            ->where(
                'id',
                $submission->competition_registration_id
            )
            ->first();

        $result = DB::table('competition_results')
            ->where(
                'competition_registration_id',
                $submission->competition_registration_id
            )
            ->first();

        $scores = DB::table('competition_scores as cs')
            ->join(
                'competition_evaluation_criteria as cec',
                'cec.id',
                '=',
                'cs.criterion_id'
            )
            ->where(
                'cs.competition_submission_id',
                $submissionId
            )
            ->select(
                'cec.id as criterion_id',
                'cec.title',
                'cec.weight',
                'cs.score',
                'cs.feedback'
            )
            ->get()
            ->map(function ($score) {
                return [
                    'criterion_id' => $score->criterion_id,
                    'title' => $score->title,
                    'weight' => (float) $score->weight,
                    'score' => (float) $score->score,
                    'feedback' => $score->feedback,
                ];
            })
            ->values();

        return [
            'id' => $submission->id,
            'registration_id' =>
                $submission->competition_registration_id,
            'title' => $submission->title,
            'description' => $submission->description,
            'github_url' => $submission->github_url,
            'demo_url' => $submission->demo_url,
            'status' => $submission->status,
            'feedback' => $submission->feedback,
            'reviewed_at' => $submission->reviewed_at,
            'submitted_at' => $submission->submitted_at,
            'files' => DB::table(
                'competition_submission_files'
            )
                ->where(
                    'competition_submission_id',
                    $submissionId
                )
                ->orderBy('id')
                ->get()
                ->map(
                    fn ($file) =>
                        $this->submissionFileData($file)
                )
                ->values(),
            'scores' => $scores,
            'result' => $result
                ? [
                    'rank' => $result->rank,
                    'final_score' =>
                        $result->final_score !== null
                            ? (float) $result->final_score
                            : null,
                    'award' => $result->award,
                    'published_at' => $result->published_at,
                ]
                : null,
            'team_name' => $registration?->team_name,
        ];
    }

    private function submissionFileData($file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'type' => $file->file_type,
            'size' => (int) ($file->file_size ?? 0),
            'url' => asset(
                'storage/' . ltrim(
                    $file->file_path,
                    '/'
                )
            ),
        ];
    }
}
