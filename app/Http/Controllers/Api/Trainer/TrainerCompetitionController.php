<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainerCompetitionController extends Controller
{
    public function index(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $competitions = DB::table('competitions')
            ->where('created_by', $trainer->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(
                fn ($competition) =>
                    $this->competitionListData($competition)
            )
            ->values();

        return response()->json([
            'stats' => [
                'total' => $competitions->count(),

                'draft' => $competitions
                    ->where('status', 'draft')
                    ->count(),

                'registration_open' => $competitions
                    ->where(
                        'status',
                        'registration_open'
                    )
                    ->count(),

                'submissions_open' => $competitions
                    ->where(
                        'status',
                        'submissions_open'
                    )
                    ->count(),

                'judging' => $competitions
                    ->where('status', 'judging')
                    ->count(),

                'completed' => $competitions
                    ->whereIn('status', [
                        'results_published',
                        'completed',
                    ])
                    ->count(),
            ],

            'competitions' => $competitions,
        ]);
    }

    public function show(
        Request $request,
        int $competitionId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $competition = $this->ownedCompetition(
            $trainer->id,
            $competitionId
        );

        if (!$competition) {
            return response()->json([
                'message' => 'Competition not found.',
            ], 404);
        }

        return response()->json([
            'competition' =>
                $this->competitionData(
                    $competition
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

        $validated =
            $this->validateCompetitionRequest(
                $request
            );

        $error = $this->validateCompetitionData(
            $validated
        );

        if ($error) {
            return response()->json([
                'message' => $error,
            ], 422);
        }

        $competitionId = DB::transaction(
            function () use (
                $trainer,
                $validated
            ) {
                $status =
                    $validated['status']
                    ?? 'draft';

                $maxTeamMembers =
                    $validated[
                        'participation_type'
                    ] === 'individual'
                        ? 1
                        : (
                            $validated[
                                'max_team_members'
                            ] ?? 2
                        );

                $competitionId =
                    DB::table('competitions')
                        ->insertGetId([
                            'created_by' =>
                                $trainer->id,

                            'title' =>
                                $validated['title'],

                            'category' =>
                                $validated[
                                    'category'
                                ] ?? null,

                            'description' =>
                                $validated[
                                    'description'
                                ] ?? null,

                            'objective' =>
                                $validated[
                                    'objective'
                                ] ?? null,

                            'participation_type' =>
                                $validated[
                                    'participation_type'
                                ],

                            'max_team_members' =>
                                $maxTeamMembers,

                            'registration_start_at' =>
                                $validated[
                                    'registration_start_at'
                                ] ?? null,

                            'registration_end_at' =>
                                $validated[
                                    'registration_end_at'
                                ] ?? null,

                            'work_start_at' =>
                                $validated[
                                    'work_start_at'
                                ] ?? null,

                            'work_end_at' =>
                                $validated[
                                    'work_end_at'
                                ] ?? null,

                            'submission_deadline_at' =>
                                $validated[
                                    'submission_deadline_at'
                                ] ?? null,

                            'results_at' =>
                                $validated[
                                    'results_at'
                                ] ?? null,

                            'prize' =>
                                $validated[
                                    'prize'
                                ] ?? null,

                            'status' =>
                                $status,

                            'results_published_at' =>
                                $status ===
                                'results_published'
                                    ? now()
                                    : null,

                            'created_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ]);

                $this->syncCompetitionChildren(
                    $competitionId,
                    $validated,
                    true
                );

                return $competitionId;
            }
        );

        $competition =
            DB::table('competitions')
                ->where(
                    'id',
                    $competitionId
                )
                ->first();

        return response()->json([
            'message' =>
                'Competition created successfully.',

            'competition' =>
                $this->competitionData(
                    $competition
                ),
        ], 201);
    }

    public function update(
        Request $request,
        int $competitionId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $competition = $this->ownedCompetition(
            $trainer->id,
            $competitionId
        );

        if (!$competition) {
            return response()->json([
                'message' => 'Competition not found.',
            ], 404);
        }

        $validated =
            $this->validateCompetitionRequest(
                $request,
                true
            );

        $merged = array_merge(
            (array) $competition,
            $validated
        );

        $error =
            $this->validateCompetitionData(
                $merged,
                array_key_exists(
                    'evaluation_criteria',
                    $validated
                )
            );

        if ($error) {
            return response()->json([
                'message' => $error,
            ], 422);
        }

        $registrationsCount =
            DB::table(
                'competition_registrations'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->count();

        if ($registrationsCount > 0) {
            if (
                array_key_exists(
                    'participation_type',
                    $validated
                ) &&
                $validated[
                    'participation_type'
                ] !==
                    $competition
                        ->participation_type
            ) {
                return response()->json([
                    'message' =>
                        'Participation type cannot be changed after registrations have started.',
                ], 422);
            }

            if (
                array_key_exists(
                    'max_team_members',
                    $validated
                ) &&
                (int) $validated[
                    'max_team_members'
                ] !==
                    (int) $competition
                        ->max_team_members
            ) {
                return response()->json([
                    'message' =>
                        'Maximum team members cannot be changed after registrations have started.',
                ], 422);
            }
        }

        if (
            array_key_exists(
                'evaluation_criteria',
                $validated
            )
        ) {
            $scoresExist =
                DB::table(
                    'competition_scores as cs'
                )
                    ->join(
                        'competition_submissions as sub',
                        'sub.id',
                        '=',
                        'cs.competition_submission_id'
                    )
                    ->join(
                        'competition_registrations as cr',
                        'cr.id',
                        '=',
                        'sub.competition_registration_id'
                    )
                    ->where(
                        'cr.competition_id',
                        $competitionId
                    )
                    ->exists();

            if ($scoresExist) {
                return response()->json([
                    'message' =>
                        'Evaluation criteria cannot be changed after scoring has started.',
                ], 422);
            }
        }

        DB::transaction(
            function () use (
                $competition,
                $competitionId,
                $validated
            ) {
                $fields = [
                    'title',
                    'category',
                    'description',
                    'objective',
                    'participation_type',
                    'max_team_members',
                    'registration_start_at',
                    'registration_end_at',
                    'work_start_at',
                    'work_end_at',
                    'submission_deadline_at',
                    'results_at',
                    'prize',
                    'status',
                ];

                $updates = [];

                foreach ($fields as $field) {
                    if (
                        array_key_exists(
                            $field,
                            $validated
                        )
                    ) {
                        $updates[$field] =
                            $validated[$field];
                    }
                }

                $participationType =
                    $updates[
                        'participation_type'
                    ] ??
                    $competition
                        ->participation_type;

                if (
                    $participationType ===
                    'individual'
                ) {
                    $updates[
                        'max_team_members'
                    ] = 1;
                }

                if (
                    isset(
                        $updates['status']
                    )
                ) {
                    if (
                        $updates['status'] ===
                        'results_published'
                    ) {
                        $updates[
                            'results_published_at'
                        ] =
                            $competition
                                ->results_published_at
                            ?? now();
                    } elseif (
                        $competition->status ===
                        'results_published'
                    ) {
                        $updates[
                            'results_published_at'
                        ] = null;
                    }
                }

                if (!empty($updates)) {
                    $updates['updated_at'] =
                        now();

                    DB::table('competitions')
                        ->where(
                            'id',
                            $competitionId
                        )
                        ->update($updates);
                }

                $this->syncCompetitionChildren(
                    $competitionId,
                    $validated
                );
            }
        );

        $competition =
            DB::table('competitions')
                ->where(
                    'id',
                    $competitionId
                )
                ->first();

        return response()->json([
            'message' =>
                'Competition updated successfully.',

            'competition' =>
                $this->competitionData(
                    $competition
                ),
        ]);
    }

    public function destroy(
        Request $request,
        int $competitionId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $competition = $this->ownedCompetition(
            $trainer->id,
            $competitionId
        );

        if (!$competition) {
            return response()->json([
                'message' => 'Competition not found.',
            ], 404);
        }

        $registrationsCount =
            DB::table(
                'competition_registrations'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->count();

        if ($registrationsCount > 0) {
            return response()->json([
                'message' =>
                    'A competition with registrations cannot be deleted.',
            ], 422);
        }

        DB::table('competitions')
            ->where(
                'id',
                $competitionId
            )
            ->delete();

        return response()->json([
            'message' =>
                'Competition deleted successfully.',
        ]);
    }

    public function updateStatus(
        Request $request,
        int $competitionId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $competition = $this->ownedCompetition(
            $trainer->id,
            $competitionId
        );

        if (!$competition) {
            return response()->json([
                'message' => 'Competition not found.',
            ], 404);
        }

        $validated = $request->validate([
            'status' => [
                'required',
                'in:draft,registration_open,registration_closed,submissions_open,judging,completed',
            ],
        ]);

        DB::table('competitions')
            ->where(
                'id',
                $competitionId
            )
            ->update([
                'status' =>
                    $validated['status'],

                'results_published_at' =>
                    null,

                'updated_at' =>
                    now(),
            ]);

        $competition =
            DB::table('competitions')
                ->where(
                    'id',
                    $competitionId
                )
                ->first();

        return response()->json([
            'message' =>
                'Competition status updated successfully.',

            'competition' =>
                $this->competitionData(
                    $competition
                ),
        ]);
    }

    public function registrations(
        Request $request,
        int $competitionId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $competition = $this->ownedCompetition(
            $trainer->id,
            $competitionId
        );

        if (!$competition) {
            return response()->json([
                'message' => 'Competition not found.',
            ], 404);
        }

        $registrations =
            DB::table(
                'competition_registrations'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->orderByDesc(
                    'registered_at'
                )
                ->get()
                ->map(
                    fn ($registration) =>
                        $this->registrationData(
                            $registration
                        )
                )
                ->values();

        return response()->json([
            'counts' => [
                'all' =>
                    $registrations->count(),

                'pending' =>
                    $registrations
                        ->where(
                            'status',
                            'pending'
                        )
                        ->count(),

                'approved' =>
                    $registrations
                        ->where(
                            'status',
                            'approved'
                        )
                        ->count(),

                'rejected' =>
                    $registrations
                        ->where(
                            'status',
                            'rejected'
                        )
                        ->count(),

                'withdrawn' =>
                    $registrations
                        ->where(
                            'status',
                            'withdrawn'
                        )
                        ->count(),

                'disqualified' =>
                    $registrations
                        ->where(
                            'status',
                            'disqualified'
                        )
                        ->count(),
            ],

            'registrations' =>
                $registrations,
        ]);
    }

    public function approveRegistration(
        Request $request,
        int $competitionId,
        int $registrationId
    ) {
        $context =
            $this->registrationContext(
                $request,
                $competitionId,
                $registrationId
            );

        if ($context['error']) {
            return $context['error'];
        }

        $registration =
            $context['registration'];

        if (
            in_array(
                $registration->status,
                [
                    'withdrawn',
                    'disqualified',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'This registration cannot be approved.',
            ], 422);
        }

        DB::table(
            'competition_registrations'
        )
            ->where(
                'id',
                $registrationId
            )
            ->update([
                'status' =>
                    'approved',

                'rejection_reason' =>
                    null,

                'reviewed_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);

        $this->notifyRegistrationStudents(
            $registrationId,
            'Competition application approved',
            'Your application for "' .
                $context['competition']->title .
                '" has been approved.',
            $competitionId,
            '✅'
        );

        return response()->json([
            'message' =>
                'Competition registration approved successfully.',

            'registration' =>
                $this->registrationData(
                    DB::table(
                        'competition_registrations'
                    )
                        ->where(
                            'id',
                            $registrationId
                        )
                        ->first()
                ),
        ]);
    }

    public function rejectRegistration(
        Request $request,
        int $competitionId,
        int $registrationId
    ) {
        $context =
            $this->registrationContext(
                $request,
                $competitionId,
                $registrationId
            );

        if ($context['error']) {
            return $context['error'];
        }

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:5000',
            ],
        ]);

        DB::table(
            'competition_registrations'
        )
            ->where(
                'id',
                $registrationId
            )
            ->update([
                'status' =>
                    'rejected',

                'rejection_reason' =>
                    $validated['reason'],

                'reviewed_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);

        $this->notifyRegistrationStudents(
            $registrationId,
            'Competition application update',
            'Your application for "' .
                $context['competition']->title .
                '" was not approved. ' .
                $validated['reason'],
            $competitionId,
            'ℹ️'
        );

        return response()->json([
            'message' =>
                'Competition registration rejected successfully.',

            'registration' =>
                $this->registrationData(
                    DB::table(
                        'competition_registrations'
                    )
                        ->where(
                            'id',
                            $registrationId
                        )
                        ->first()
                ),
        ]);
    }

    public function disqualifyRegistration(
        Request $request,
        int $competitionId,
        int $registrationId
    ) {
        $context =
            $this->registrationContext(
                $request,
                $competitionId,
                $registrationId
            );

        if ($context['error']) {
            return $context['error'];
        }

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:5000',
            ],
        ]);

        DB::table(
            'competition_registrations'
        )
            ->where(
                'id',
                $registrationId
            )
            ->update([
                'status' =>
                    'disqualified',

                'rejection_reason' =>
                    $validated['reason'],

                'reviewed_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);

        $this->notifyRegistrationStudents(
            $registrationId,
            'Competition registration disqualified',
            'Your registration for "' .
                $context['competition']->title .
                '" has been disqualified. ' .
                $validated['reason'],
            $competitionId,
            '⚠️'
        );

        return response()->json([
            'message' =>
                'Competition registration disqualified successfully.',

            'registration' =>
                $this->registrationData(
                    DB::table(
                        'competition_registrations'
                    )
                        ->where(
                            'id',
                            $registrationId
                        )
                        ->first()
                ),
        ]);
    }

    public function submissions(
        Request $request,
        int $competitionId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $competition = $this->ownedCompetition(
            $trainer->id,
            $competitionId
        );

        if (!$competition) {
            return response()->json([
                'message' => 'Competition not found.',
            ], 404);
        }

        $submissionIds =
            DB::table(
                'competition_submissions as cs'
            )
                ->join(
                    'competition_registrations as cr',
                    'cr.id',
                    '=',
                    'cs.competition_registration_id'
                )
                ->where(
                    'cr.competition_id',
                    $competitionId
                )
                ->where(
                    'cs.status',
                    '!=',
                    'deleted'
                )
                ->orderByDesc(
                    'cs.submitted_at'
                )
                ->pluck('cs.id');

        $submissions =
            $submissionIds
                ->map(
                    fn ($submissionId) =>
                        $this->submissionData(
                            (int) $submissionId
                        )
                )
                ->filter()
                ->values();

        return response()->json([
            'counts' => [
                'all' =>
                    $submissions->count(),

                'submitted' =>
                    $submissions
                        ->where(
                            'status',
                            'submitted'
                        )
                        ->count(),

                'under_review' =>
                    $submissions
                        ->where(
                            'status',
                            'under_review'
                        )
                        ->count(),

                'changes_requested' =>
                    $submissions
                        ->where(
                            'status',
                            'changes_requested'
                        )
                        ->count(),

                'approved' =>
                    $submissions
                        ->where(
                            'status',
                            'approved'
                        )
                        ->count(),

                'scored' =>
                    $submissions
                        ->where(
                            'status',
                            'scored'
                        )
                        ->count(),

                'judged' =>
                    $submissions
                        ->where(
                            'status',
                            'judged'
                        )
                        ->count(),
            ],

            'submissions' =>
                $submissions,
        ]);
    }

    public function showSubmission(
        Request $request,
        int $competitionId,
        int $submissionId
    ) {
        $context =
            $this->submissionContext(
                $request,
                $competitionId,
                $submissionId
            );

        if ($context['error']) {
            return $context['error'];
        }

        return response()->json([
            'submission' =>
                $this->submissionData(
                    $submissionId
                ),
        ]);
    }

    public function reviewSubmission(
        Request $request,
        int $competitionId,
        int $submissionId
    ) {
        $context =
            $this->submissionContext(
                $request,
                $competitionId,
                $submissionId
            );

        if ($context['error']) {
            return $context['error'];
        }

        $submission =
            $context['submission'];

        if (
            in_array(
                $submission->status,
                [
                    'draft',
                    'deleted',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'This submission cannot be reviewed.',
            ], 422);
        }

        $validated = $request->validate([
            'status' => [
                'required',
                'in:under_review,changes_requested,approved',
            ],

            'feedback' => [
                'nullable',
                'string',
                'max:10000',
            ],
        ]);

        if (
            $validated['status'] ===
                'changes_requested' &&
            empty($validated['feedback'])
        ) {
            return response()->json([
                'message' =>
                    'Feedback is required when requesting changes.',
            ], 422);
        }

        DB::transaction(
            function () use (
                $submission,
                $validated
            ) {
                DB::table(
                    'competition_submissions'
                )
                    ->where(
                        'id',
                        $submission->id
                    )
                    ->update([
                        'status' =>
                            $validated[
                                'status'
                            ],

                        'feedback' =>
                            $validated[
                                'feedback'
                            ] ?? null,

                        'reviewed_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);

                if (
                    $validated['status'] ===
                    'changes_requested'
                ) {
                    DB::table(
                        'competition_scores'
                    )
                        ->where(
                            'competition_submission_id',
                            $submission->id
                        )
                        ->delete();

                    DB::table(
                        'competition_results'
                    )
                        ->where(
                            'competition_registration_id',
                            $submission
                                ->competition_registration_id
                        )
                        ->delete();
                }
            }
        );

        $title = match (
            $validated['status']
        ) {
            'changes_requested' =>
                'Changes requested for competition work',

            'approved' =>
                'Competition submission approved',

            default =>
                'Competition submission under review',
        };

        $message = match (
            $validated['status']
        ) {
            'changes_requested' =>
                'Your trainer requested changes for your submission in "' .
                $context['competition']->title .
                '".',

            'approved' =>
                'Your submission for "' .
                $context['competition']->title .
                '" has been approved.',

            default =>
                'Your submission for "' .
                $context['competition']->title .
                '" is now under review.',
        };

        if (
            !empty(
                $validated['feedback']
            )
        ) {
            $message .= ' ' .
                $validated['feedback'];
        }

        $this->notifyRegistrationStudents(
            $submission
                ->competition_registration_id,
            $title,
            $message,
            $competitionId,
            $validated['status'] ===
                'changes_requested'
                ? '🔄'
                : '✅'
        );

        return response()->json([
            'message' =>
                'Competition submission reviewed successfully.',

            'submission' =>
                $this->submissionData(
                    $submissionId
                ),
        ]);
    }

    public function scoreSubmission(
        Request $request,
        int $competitionId,
        int $submissionId
    ) {
        $context =
            $this->submissionContext(
                $request,
                $competitionId,
                $submissionId
            );

        if ($context['error']) {
            return $context['error'];
        }

        $trainer =
            $context['trainer'];

        $submission =
            $context['submission'];

        if (
            in_array(
                $submission->status,
                [
                    'draft',
                    'changes_requested',
                    'deleted',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'This submission is not ready for scoring.',
            ], 422);
        }

        $validated = $request->validate([
            'scores' => [
                'required',
                'array',
                'min:1',
            ],

            'scores.*.criterion_id' => [
                'required',
                'integer',
            ],

            'scores.*.score' => [
                'required',
                'numeric',
                'min:0',
            ],

            'scores.*.feedback' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'feedback' => [
                'nullable',
                'string',
                'max:10000',
            ],
        ]);

        $criteria =
            DB::table(
                'competition_evaluation_criteria'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->orderBy('position')
                ->get();

        if ($criteria->isEmpty()) {
            return response()->json([
                'message' =>
                    'This competition does not have evaluation criteria.',
            ], 422);
        }

        $scoreRows = collect(
            $validated['scores']
        )->keyBy(
            fn ($item) =>
                (int) $item[
                    'criterion_id'
                ]
        );

        $criteriaIds =
            $criteria
                ->pluck('id')
                ->map(
                    fn ($id) =>
                        (int) $id
                )
                ->sort()
                ->values();

        $scoreIds =
            $scoreRows
                ->keys()
                ->map(
                    fn ($id) =>
                        (int) $id
                )
                ->sort()
                ->values();

        if (
            $criteriaIds->toArray() !==
            $scoreIds->toArray()
        ) {
            return response()->json([
                'message' =>
                    'A score must be provided for every evaluation criterion.',
            ], 422);
        }

        foreach ($criteria as $criterion) {
            $item =
                $scoreRows->get(
                    (int) $criterion->id
                );

            if (
                (float) $item['score'] >
                (float) $criterion->weight
            ) {
                return response()->json([
                    'message' =>
                        'Score for "' .
                        $criterion->title .
                        '" cannot exceed ' .
                        $criterion->weight .
                        '.',
                ], 422);
            }
        }

        DB::transaction(
            function () use (
                $trainer,
                $submission,
                $criteria,
                $scoreRows,
                $validated
            ) {
                foreach (
                    $criteria as $criterion
                ) {
                    $item =
                        $scoreRows->get(
                            (int) $criterion->id
                        );

                    DB::table(
                        'competition_scores'
                    )
                        ->updateOrInsert(
                            [
                                'competition_submission_id' =>
                                    $submission->id,

                                'judge_id' =>
                                    $trainer->id,

                                'criterion_id' =>
                                    $criterion->id,
                            ],
                            [
                                'score' =>
                                    $item['score'],

                                'feedback' =>
                                    $item[
                                        'feedback'
                                    ] ?? null,

                                'updated_at' =>
                                    now(),

                                'created_at' =>
                                    now(),
                            ]
                        );
                }

                DB::table(
                    'competition_submissions'
                )
                    ->where(
                        'id',
                        $submission->id
                    )
                    ->update([
                        'status' =>
                            'scored',

                        'feedback' =>
                            $validated[
                                'feedback'
                            ] ??
                            $submission
                                ->feedback,

                        'reviewed_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);
            }
        );

        $finalScore =
            $this->finalScoreForSubmission(
                $submissionId,
                $competitionId
            );

        $this->notifyRegistrationStudents(
            $submission
                ->competition_registration_id,
            'Competition work evaluated',
            'Your work for "' .
                $context['competition']->title .
                '" has been evaluated.',
            $competitionId,
            '⭐'
        );

        return response()->json([
            'message' =>
                'Competition submission scored successfully.',

            'final_score' =>
                $finalScore,

            'submission' =>
                $this->submissionData(
                    $submissionId
                ),
        ]);
    }

    public function results(
        Request $request,
        int $competitionId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $competition = $this->ownedCompetition(
            $trainer->id,
            $competitionId
        );

        if (!$competition) {
            return response()->json([
                'message' => 'Competition not found.',
            ], 404);
        }

        $results =
            DB::table(
                'competition_results as r'
            )
                ->join(
                    'competition_registrations as cr',
                    'cr.id',
                    '=',
                    'r.competition_registration_id'
                )
                ->where(
                    'cr.competition_id',
                    $competitionId
                )
                ->orderByRaw(
                    'r.rank IS NULL'
                )
                ->orderBy('r.rank')
                ->orderByDesc(
                    'r.final_score'
                )
                ->get()
                ->map(function ($result) {
                    return [
                        'id' =>
                            (int) $result->id,

                        'registration_id' =>
                            (int) $result
                                ->competition_registration_id,

                        'rank' =>
                            $result->rank !== null
                                ? (int) $result->rank
                                : null,

                        'final_score' =>
                            $result->final_score !== null
                                ? (float) $result->final_score
                                : null,

                        'award' =>
                            $result->award,

                        'published_at' =>
                            $result->published_at,

                        'registration' =>
                            $this->registrationData(
                                DB::table(
                                    'competition_registrations'
                                )
                                    ->where(
                                        'id',
                                        $result
                                            ->competition_registration_id
                                    )
                                    ->first()
                            ),
                    ];
                })
                ->values();

        return response()->json([
            'results_published' =>
                $competition->status ===
                'results_published',

            'results_published_at' =>
                $competition
                    ->results_published_at,

            'results' =>
                $results,
        ]);
    }

    public function publishResults(
        Request $request,
        int $competitionId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $competition = $this->ownedCompetition(
            $trainer->id,
            $competitionId
        );

        if (!$competition) {
            return response()->json([
                'message' => 'Competition not found.',
            ], 404);
        }

        $validated = $request->validate([
            'awards' => [
                'nullable',
                'array',
            ],

            'awards.*.registration_id' => [
                'required',
                'integer',
            ],

            'awards.*.award' => [
                'nullable',
                'string',
                'max:191',
            ],
        ]);

        $criteriaCount =
            DB::table(
                'competition_evaluation_criteria'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->count();

        if ($criteriaCount < 1) {
            return response()->json([
                'message' =>
                    'Evaluation criteria are required before publishing results.',
            ], 422);
        }

        $submissionIds =
            DB::table(
                'competition_submissions as cs'
            )
                ->join(
                    'competition_registrations as cr',
                    'cr.id',
                    '=',
                    'cs.competition_registration_id'
                )
                ->where(
                    'cr.competition_id',
                    $competitionId
                )
                ->where(
                    'cr.status',
                    'approved'
                )
                ->whereIn(
                    'cs.status',
                    [
                        'approved',
                        'scored',
                        'judged',
                    ]
                )
                ->pluck(
                    'cs.id'
                );

        $ranked = collect();

        foreach (
            $submissionIds as $submissionId
        ) {
            $finalScore =
                $this->finalScoreForSubmission(
                    (int) $submissionId,
                    $competitionId
                );

            if ($finalScore === null) {
                continue;
            }

            $registrationId =
                DB::table(
                    'competition_submissions'
                )
                    ->where(
                        'id',
                        $submissionId
                    )
                    ->value(
                        'competition_registration_id'
                    );

            $ranked->push([
                'submission_id' =>
                    (int) $submissionId,

                'registration_id' =>
                    (int) $registrationId,

                'final_score' =>
                    $finalScore,
            ]);
        }

        if ($ranked->isEmpty()) {
            return response()->json([
                'message' =>
                    'There are no fully scored submissions to publish.',
            ], 422);
        }

        $ranked =
            $ranked
                ->sortByDesc(
                    'final_score'
                )
                ->values();

        $awardMap = collect(
            $validated['awards'] ?? []
        )->keyBy(
            fn ($item) =>
                (int) $item[
                    'registration_id'
                ]
        );

        DB::transaction(
            function () use (
                $ranked,
                $awardMap,
                $competitionId
            ) {
                foreach (
                    $ranked as $index => $item
                ) {
                    $registrationId =
                        $item[
                            'registration_id'
                        ];

                    $award =
                        $awardMap->get(
                            $registrationId
                        );

                    DB::table(
                        'competition_results'
                    )
                        ->updateOrInsert(
                            [
                                'competition_registration_id' =>
                                    $registrationId,
                            ],
                            [
                                'rank' =>
                                    $index + 1,

                                'final_score' =>
                                    $item[
                                        'final_score'
                                    ],

                                'award' =>
                                    $award[
                                        'award'
                                    ] ?? null,

                                'published_at' =>
                                    now(),

                                'updated_at' =>
                                    now(),

                                'created_at' =>
                                    now(),
                            ]
                        );

                    DB::table(
                        'competition_submissions'
                    )
                        ->where(
                            'id',
                            $item[
                                'submission_id'
                            ]
                        )
                        ->update([
                            'status' =>
                                'judged',

                            'reviewed_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ]);
                }

                DB::table('competitions')
                    ->where(
                        'id',
                        $competitionId
                    )
                    ->update([
                        'status' =>
                            'results_published',

                        'results_published_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);
            }
        );

        foreach ($ranked as $item) {
            $result =
                DB::table(
                    'competition_results'
                )
                    ->where(
                        'competition_registration_id',
                        $item[
                            'registration_id'
                        ]
                    )
                    ->first();

            $this->notifyRegistrationStudents(
                $item[
                    'registration_id'
                ],
                'Competition results published',
                'The results for "' .
                    $competition->title .
                    '" are now available. Rank #' .
                    $result->rank .
                    ' with a score of ' .
                    $result->final_score .
                    '/100.',
                $competitionId,
                '🏆'
            );
        }

        return $this->results(
            $request,
            $competitionId
        );
    }

    private function competitionListData(
        $competition
    ): array {
        $competitionId =
            (int) $competition->id;

        return [
            'id' =>
                $competitionId,

            'title' =>
                $competition->title,

            'category' =>
                $competition->category,

            'description' =>
                $competition->description,

            'objective' =>
                $competition->objective,

            'participation_type' =>
                $competition
                    ->participation_type,

            'max_team_members' =>
                (int) (
                    $competition
                        ->max_team_members
                    ?? 1
                ),

            'registration_start_at' =>
                $competition
                    ->registration_start_at,

            'registration_end_at' =>
                $competition
                    ->registration_end_at,

            'work_start_at' =>
                $competition
                    ->work_start_at,

            'work_end_at' =>
                $competition
                    ->work_end_at,

            'submission_deadline_at' =>
                $competition
                    ->submission_deadline_at,

            'results_at' =>
                $competition->results_at,

            'prize' =>
                $competition->prize,

            'status' =>
                $competition->status,

            'results_published_at' =>
                $competition
                    ->results_published_at,

            'registrations_count' =>
                DB::table(
                    'competition_registrations'
                )
                    ->where(
                        'competition_id',
                        $competitionId
                    )
                    ->count(),

            'pending_registrations_count' =>
                DB::table(
                    'competition_registrations'
                )
                    ->where(
                        'competition_id',
                        $competitionId
                    )
                    ->where(
                        'status',
                        'pending'
                    )
                    ->count(),

            'approved_registrations_count' =>
                DB::table(
                    'competition_registrations'
                )
                    ->where(
                        'competition_id',
                        $competitionId
                    )
                    ->where(
                        'status',
                        'approved'
                    )
                    ->count(),

            'submissions_count' =>
                DB::table(
                    'competition_submissions as cs'
                )
                    ->join(
                        'competition_registrations as cr',
                        'cr.id',
                        '=',
                        'cs.competition_registration_id'
                    )
                    ->where(
                        'cr.competition_id',
                        $competitionId
                    )
                    ->where(
                        'cs.status',
                        '!=',
                        'deleted'
                    )
                    ->count(),

            'created_at' =>
                $competition->created_at,

            'updated_at' =>
                $competition->updated_at,
        ];
    }

    private function competitionData(
        $competition
    ): array {
        $competitionId =
            (int) $competition->id;

        $data =
            $this->competitionListData(
                $competition
            );

        $data['requirements'] =
            DB::table(
                'competition_requirements'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->orderBy('position')
                ->get([
                    'id',
                    'requirement',
                    'position',
                ]);

        $data['rules'] =
            DB::table(
                'competition_rules'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->orderBy('position')
                ->get([
                    'id',
                    'rule',
                    'position',
                ]);

        $data[
            'submission_requirements'
        ] =
            DB::table(
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
                ]);

        $data[
            'evaluation_criteria'
        ] =
            DB::table(
                'competition_evaluation_criteria'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->orderBy('position')
                ->get([
                    'id',
                    'title',
                    'weight',
                    'position',
                ])
                ->map(function ($item) {
                    return [
                        'id' =>
                            (int) $item->id,

                        'title' =>
                            $item->title,

                        'weight' =>
                            (float) $item->weight,

                        'position' =>
                            (int) $item->position,
                    ];
                })
                ->values();

        return $data;
    }

    private function registrationData(
        $registration
    ): array {
        $members =
            DB::table(
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
                ->orderBy(
                    'crm.id'
                )
                ->select([
                    'crm.id',
                    'crm.student_id',
                    'crm.member_name',
                    'crm.member_email',
                    'crm.role',
                    'crm.member_role',
                    'u.name as student_name',
                    'u.email as student_email',
                    'u.avatar',
                ])
                ->get()
                ->map(function ($member) {
                    return [
                        'id' =>
                            (int) $member->id,

                        'student_id' =>
                            $member->student_id !== null
                                ? (int) $member->student_id
                                : null,

                        'name' =>
                            $member->student_name
                            ?: $member->member_name,

                        'email' =>
                            $member->student_email
                            ?: $member->member_email,

                        'membership_role' =>
                            $member->role,

                        'role' =>
                            $member->member_role,

                        'avatar' =>
                            $member->avatar,
                    ];
                })
                ->values();

        return [
            'id' =>
                (int) $registration->id,

            'competition_id' =>
                (int) $registration
                    ->competition_id,

            'team_name' =>
                $registration->team_name,

            'participation_type' =>
                $registration->team_name
                    ? 'team'
                    : 'individual',

            'status' =>
                $registration->status,

            'rejection_reason' =>
                $registration
                    ->rejection_reason,

            'reviewed_at' =>
                $registration->reviewed_at,

            'registered_at' =>
                $registration
                    ->registered_at,

            'members' =>
                $members,
        ];
    }

    private function submissionData(
        int $submissionId
    ): ?array {
        $submission =
            DB::table(
                'competition_submissions'
            )
                ->where(
                    'id',
                    $submissionId
                )
                ->first();

        if (!$submission) {
            return null;
        }

        $registration =
            DB::table(
                'competition_registrations'
            )
                ->where(
                    'id',
                    $submission
                        ->competition_registration_id
                )
                ->first();

        $scores =
            DB::table(
                'competition_scores as cs'
            )
                ->join(
                    'competition_evaluation_criteria as cec',
                    'cec.id',
                    '=',
                    'cs.criterion_id'
                )
                ->join(
                    'trainers as t',
                    't.id',
                    '=',
                    'cs.judge_id'
                )
                ->join(
                    'users as u',
                    'u.id',
                    '=',
                    't.user_id'
                )
                ->where(
                    'cs.competition_submission_id',
                    $submissionId
                )
                ->orderBy(
                    'cec.position'
                )
                ->select([
                    'cec.id as criterion_id',
                    'cec.title',
                    'cec.weight',
                    'cs.judge_id',
                    'u.name as judge_name',
                    'cs.score',
                    'cs.feedback',
                ])
                ->get()
                ->map(function ($score) {
                    return [
                        'criterion_id' =>
                            (int) $score
                                ->criterion_id,

                        'title' =>
                            $score->title,

                        'weight' =>
                            (float) $score
                                ->weight,

                        'judge_id' =>
                            (int) $score
                                ->judge_id,

                        'judge_name' =>
                            $score->judge_name,

                        'score' =>
                            (float) $score
                                ->score,

                        'feedback' =>
                            $score->feedback,
                    ];
                })
                ->values();

        $result =
            DB::table(
                'competition_results'
            )
                ->where(
                    'competition_registration_id',
                    $submission
                        ->competition_registration_id
                )
                ->first();

        return [
            'id' =>
                (int) $submission->id,

            'registration_id' =>
                (int) $submission
                    ->competition_registration_id,

            'registration' =>
                $registration
                    ? $this->registrationData(
                        $registration
                    )
                    : null,

            'title' =>
                $submission->title,

            'description' =>
                $submission->description,

            'github_url' =>
                $submission->github_url,

            'demo_url' =>
                $submission->demo_url,

            'status' =>
                $submission->status,

            'feedback' =>
                $submission->feedback,

            'reviewed_at' =>
                $submission->reviewed_at,

            'submitted_at' =>
                $submission->submitted_at,

            'files' =>
                DB::table(
                    'competition_submission_files'
                )
                    ->where(
                        'competition_submission_id',
                        $submissionId
                    )
                    ->orderBy('id')
                    ->get()
                    ->map(function ($file) {
                        return [
                            'id' =>
                                (int) $file->id,

                            'name' =>
                                $file
                                    ->original_name,

                            'type' =>
                                $file->file_type,

                            'size' =>
                                (int) (
                                    $file
                                        ->file_size
                                    ?? 0
                                ),

                            'url' =>
                                asset(
                                    'storage/' .
                                    ltrim(
                                        $file
                                            ->file_path,
                                        '/'
                                    )
                                ),
                        ];
                    })
                    ->values(),

            'scores' =>
                $scores,

            'calculated_score' =>
                $this->finalScoreForSubmission(
                    $submissionId,
                    (int) $registration
                        ->competition_id
                ),

            'result' =>
                $result
                    ? [
                        'rank' =>
                            $result->rank !== null
                                ? (int) $result->rank
                                : null,

                        'final_score' =>
                            $result->final_score !== null
                                ? (float) $result->final_score
                                : null,

                        'award' =>
                            $result->award,

                        'published_at' =>
                            $result
                                ->published_at,
                    ]
                    : null,
        ];
    }

    private function syncCompetitionChildren(
        int $competitionId,
        array $data,
        bool $creating = false
    ): void {
        if (
            $creating ||
            array_key_exists(
                'requirements',
                $data
            )
        ) {
            DB::table(
                'competition_requirements'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->delete();

            foreach (
                $data['requirements']
                ?? []
                as $index => $requirement
            ) {
                DB::table(
                    'competition_requirements'
                )->insert([
                    'competition_id' =>
                        $competitionId,

                    'requirement' =>
                        trim($requirement),

                    'position' =>
                        $index + 1,

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);
            }
        }

        if (
            $creating ||
            array_key_exists(
                'rules',
                $data
            )
        ) {
            DB::table(
                'competition_rules'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->delete();

            foreach (
                $data['rules']
                ?? []
                as $index => $rule
            ) {
                DB::table(
                    'competition_rules'
                )->insert([
                    'competition_id' =>
                        $competitionId,

                    'rule' =>
                        trim($rule),

                    'position' =>
                        $index + 1,

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);
            }
        }

        if (
            $creating ||
            array_key_exists(
                'submission_requirements',
                $data
            )
        ) {
            DB::table(
                'competition_submission_requirements'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->delete();

            foreach (
                $data[
                    'submission_requirements'
                ] ?? []
                as $index => $item
            ) {
                DB::table(
                    'competition_submission_requirements'
                )->insert([
                    'competition_id' =>
                        $competitionId,

                    'title' =>
                        trim(
                            $item['title']
                        ),

                    'type' =>
                        $item['type'],

                    'position' =>
                        $index + 1,

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);
            }
        }

        if (
            $creating ||
            array_key_exists(
                'evaluation_criteria',
                $data
            )
        ) {
            DB::table(
                'competition_evaluation_criteria'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->delete();

            foreach (
                $data[
                    'evaluation_criteria'
                ] ?? []
                as $index => $item
            ) {
                DB::table(
                    'competition_evaluation_criteria'
                )->insert([
                    'competition_id' =>
                        $competitionId,

                    'title' =>
                        trim(
                            $item['title']
                        ),

                    'weight' =>
                        $item['weight'],

                    'position' =>
                        $index + 1,

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);
            }
        }
    }

    private function validateCompetitionRequest(
        Request $request,
        bool $partial = false
    ): array {
        $required =
            $partial
                ? 'sometimes'
                : 'required';

        return $request->validate([
            'title' => [
                $required,
                'string',
                'max:191',
            ],

            'category' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'objective' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'participation_type' => [
                $required,
                'in:individual,team,individual_or_team',
            ],

            'max_team_members' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],

            'registration_start_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'registration_end_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'work_start_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'work_end_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'submission_deadline_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'results_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'prize' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'status' => [
                'sometimes',
                'in:draft,registration_open,registration_closed,submissions_open,judging,results_published,completed',
            ],

            'requirements' => [
                'sometimes',
                'array',
            ],

            'requirements.*' => [
                'required',
                'string',
                'max:5000',
            ],

            'rules' => [
                'sometimes',
                'array',
            ],

            'rules.*' => [
                'required',
                'string',
                'max:5000',
            ],

            'submission_requirements' => [
                'sometimes',
                'array',
            ],

            'submission_requirements.*.title' => [
                'required',
                'string',
                'max:191',
            ],

            'submission_requirements.*.type' => [
                'required',
                'in:text,file,link,github,demo',
            ],

            'evaluation_criteria' => [
                'sometimes',
                'array',
            ],

            'evaluation_criteria.*.title' => [
                'required',
                'string',
                'max:191',
            ],

            'evaluation_criteria.*.weight' => [
                'required',
                'numeric',
                'min:0.01',
                'max:100',
            ],
        ]);
    }

    private function validateCompetitionData(
        array $data,
        bool $checkCriteria = true
    ): ?string {
        if (
            isset(
                $data[
                    'participation_type'
                ]
            ) &&
            $data[
                'participation_type'
            ] !== 'individual' &&
            isset(
                $data[
                    'max_team_members'
                ]
            ) &&
            (int) $data[
                'max_team_members'
            ] < 2
        ) {
            return 'Team competitions must allow at least 2 members.';
        }

        if (
            $checkCriteria &&
            array_key_exists(
                'evaluation_criteria',
                $data
            ) &&
            !empty(
                $data[
                    'evaluation_criteria'
                ]
            )
        ) {
            $totalWeight =
                collect(
                    $data[
                        'evaluation_criteria'
                    ]
                )->sum(
                    fn ($item) =>
                        (float) $item[
                            'weight'
                        ]
                );

            if (
                abs(
                    $totalWeight - 100
                ) > 0.01
            ) {
                return 'Evaluation criteria weights must total 100%.';
            }
        }

        $registrationStart =
            $this->timestamp(
                $data[
                    'registration_start_at'
                ] ?? null
            );

        $registrationEnd =
            $this->timestamp(
                $data[
                    'registration_end_at'
                ] ?? null
            );

        $workStart =
            $this->timestamp(
                $data[
                    'work_start_at'
                ] ?? null
            );

        $workEnd =
            $this->timestamp(
                $data[
                    'work_end_at'
                ] ?? null
            );

        $submissionDeadline =
            $this->timestamp(
                $data[
                    'submission_deadline_at'
                ] ?? null
            );

        $resultsAt =
            $this->timestamp(
                $data[
                    'results_at'
                ] ?? null
            );

        if (
            $registrationStart &&
            $registrationEnd &&
            $registrationEnd <
                $registrationStart
        ) {
            return 'Registration end date must be after registration start date.';
        }

        if (
            $registrationEnd &&
            $workStart &&
            $workStart <
                $registrationEnd
        ) {
            return 'Work start date must be after registration end date.';
        }

        if (
            $workStart &&
            $workEnd &&
            $workEnd <
                $workStart
        ) {
            return 'Work end date must be after work start date.';
        }

        if (
            $workStart &&
            $submissionDeadline &&
            $submissionDeadline <
                $workStart
        ) {
            return 'Submission deadline must be after work start date.';
        }

        if (
            $submissionDeadline &&
            $resultsAt &&
            $resultsAt <
                $submissionDeadline
        ) {
            return 'Results date must be after submission deadline.';
        }

        return null;
    }

    private function timestamp(
        $value
    ): ?int {
        if (!$value) {
            return null;
        }

        $timestamp =
            strtotime($value);

        return $timestamp !== false
            ? $timestamp
            : null;
    }

    private function finalScoreForSubmission(
        int $submissionId,
        int $competitionId
    ): ?float {
        $criteria =
            DB::table(
                'competition_evaluation_criteria'
            )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->get();

        if ($criteria->isEmpty()) {
            return null;
        }

        $total = 0;

        foreach ($criteria as $criterion) {
            $scores =
                DB::table(
                    'competition_scores'
                )
                    ->where(
                        'competition_submission_id',
                        $submissionId
                    )
                    ->where(
                        'criterion_id',
                        $criterion->id
                    )
                    ->pluck('score');

            if ($scores->isEmpty()) {
                return null;
            }

            $average =
                $scores->avg();

            $total +=
                min(
                    (float) $average,
                    (float) $criterion->weight
                );
        }

        return round(
            $total,
            2
        );
    }

    private function registrationContext(
        Request $request,
        int $competitionId,
        int $registrationId
    ): array {
        $trainer =
            $this->trainerFromRequest(
                $request
            );

        if (!$trainer) {
            return [
                'error' =>
                    response()->json([
                        'message' =>
                            'Trainer profile not found.',
                    ], 404),
            ];
        }

        $competition =
            $this->ownedCompetition(
                $trainer->id,
                $competitionId
            );

        if (!$competition) {
            return [
                'error' =>
                    response()->json([
                        'message' =>
                            'Competition not found.',
                    ], 404),
            ];
        }

        $registration =
            DB::table(
                'competition_registrations'
            )
                ->where(
                    'id',
                    $registrationId
                )
                ->where(
                    'competition_id',
                    $competitionId
                )
                ->first();

        if (!$registration) {
            return [
                'error' =>
                    response()->json([
                        'message' =>
                            'Competition registration not found.',
                    ], 404),
            ];
        }

        return [
            'error' => null,
            'trainer' => $trainer,
            'competition' =>
                $competition,
            'registration' =>
                $registration,
        ];
    }

    private function submissionContext(
        Request $request,
        int $competitionId,
        int $submissionId
    ): array {
        $trainer =
            $this->trainerFromRequest(
                $request
            );

        if (!$trainer) {
            return [
                'error' =>
                    response()->json([
                        'message' =>
                            'Trainer profile not found.',
                    ], 404),
            ];
        }

        $competition =
            $this->ownedCompetition(
                $trainer->id,
                $competitionId
            );

        if (!$competition) {
            return [
                'error' =>
                    response()->json([
                        'message' =>
                            'Competition not found.',
                    ], 404),
            ];
        }

        $submission =
            DB::table(
                'competition_submissions as cs'
            )
                ->join(
                    'competition_registrations as cr',
                    'cr.id',
                    '=',
                    'cs.competition_registration_id'
                )
                ->where(
                    'cs.id',
                    $submissionId
                )
                ->where(
                    'cr.competition_id',
                    $competitionId
                )
                ->select(
                    'cs.*'
                )
                ->first();

        if (!$submission) {
            return [
                'error' =>
                    response()->json([
                        'message' =>
                            'Competition submission not found.',
                    ], 404),
            ];
        }

        return [
            'error' => null,
            'trainer' => $trainer,
            'competition' =>
                $competition,
            'submission' =>
                $submission,
        ];
    }

    private function notifyRegistrationStudents(
        int $registrationId,
        string $title,
        string $message,
        int $competitionId,
        string $icon
    ): void {
        $studentIds =
            DB::table(
                'competition_registration_members'
            )
                ->where(
                    'competition_registration_id',
                    $registrationId
                )
                ->whereNotNull(
                    'student_id'
                )
                ->pluck(
                    'student_id'
                )
                ->unique();

        foreach ($studentIds as $studentId) {
            NotificationService::createForStudent(
                (int) $studentId,
                'competition',
                $title,
                $message,
                [
                    'category' =>
                        'competitions',

                    'icon' =>
                        $icon,

                    'action_label' =>
                        'View competition',

                    'action_tab' =>
                        'Competitions',

                    'competition_id' =>
                        $competitionId,
                ]
            );
        }
    }

    private function ownedCompetition(
        int $trainerId,
        int $competitionId
    ) {
        return DB::table(
            'competitions'
        )
            ->where(
                'id',
                $competitionId
            )
            ->where(
                'created_by',
                $trainerId
            )
            ->first();
    }

    private function trainerFromRequest(
        Request $request
    ) {
        $user =
            $request->user();

        if (
            !$user ||
            $user->role !== 'trainer'
        ) {
            return null;
        }

        return DB::table(
            'trainers'
        )
            ->where(
                'user_id',
                $user->id
            )
            ->first();
    }
}
