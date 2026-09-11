<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainerProjectController extends Controller
{
    private const RUBRIC = [
        'impact' => [
            'label' => 'Idea & impact',
            'max' => 25,
        ],
        'quality' => [
            'label' => 'Technical quality',
            'max' => 30,
        ],
        'experience' => [
            'label' => 'User experience',
            'max' => 20,
        ],
        'documentation' => [
            'label' => 'Documentation',
            'max' => 15,
        ],
        'presentation' => [
            'label' => 'Presentation & delivery',
            'max' => 10,
        ],
    ];

    public function index(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $projectIds = $this->trainerProjectIds($trainer->id);

        $projects = $projectIds
            ->map(
                fn ($projectId) =>
                    $this->projectData(
                        $projectId,
                        $trainer->id
                    )
            )
            ->filter()
            ->values();

        $courses = DB::table('courses')
            ->where('trainer_id', $trainer->id)
            ->where('status', '!=', 'archived')
            ->orderBy('title')
            ->get([
                'id',
                'title',
            ])
            ->map(fn ($course) => [
                'id' => (int) $course->id,
                'title' => $course->title,
            ])
            ->values();

        $students = DB::table('enrollments as e')
            ->join(
                'courses as c',
                'c.id',
                '=',
                'e.course_id'
            )
            ->join(
                'students as s',
                's.id',
                '=',
                'e.student_id'
            )
            ->join(
                'users as u',
                'u.id',
                '=',
                's.user_id'
            )
            ->where(
                'c.trainer_id',
                $trainer->id
            )
            ->whereIn(
                'e.status',
                ['active', 'completed']
            )
            ->select(
                's.id',
                's.student_code',
                'u.name',
                'u.email'
            )
            ->orderBy('u.name')
            ->get()
            ->unique('id')
            ->values()
            ->map(fn ($student) => [
                'id' => (int) $student->id,
                'studentCode' => $student->student_code,
                'name' => $student->name,
                'email' => $student->email,
            ]);

        return response()->json([
            'projects' => $projects,
            'courses' => $courses,
            'students' => $students,
            'stats' => [
                'pending' => $projects
                    ->whereIn(
                        'status',
                        [
                            'pending-review',
                            'resubmitted',
                        ]
                    )
                    ->count(),
                'changes' => $projects
                    ->where(
                        'status',
                        'changes-requested'
                    )
                    ->count(),
                'published' => $projects
                    ->where(
                        'status',
                        'published'
                    )
                    ->count(),
            ],
            'rubric' => collect(self::RUBRIC)
                ->map(
                    fn ($item, $key) => [
                        'id' => $key,
                        'label' => $item['label'],
                        'max' => $item['max'],
                    ]
                )
                ->values(),
        ]);
    }

    public function show(
        Request $request,
        int $projectId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        if (
            !$this->projectForTrainer(
                $projectId,
                $trainer->id
            )
        ) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        return response()->json([
            'project' => $this->projectData(
                $projectId,
                $trainer->id,
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

        $validated = $request->validate([
            'student_id' => [
                'required',
                'integer',
            ],
            'course_id' => [
                'required',
                'integer',
            ],
            'category_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
            ],
            'title' => [
                'required',
                'string',
                'max:191',
            ],
            'idea' => [
                'nullable',
                'string',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'problem' => [
                'nullable',
                'string',
            ],
            'solution' => [
                'nullable',
                'string',
            ],
            'project_type' => [
                'nullable',
                'in:individual,team',
            ],
            'github_url' => [
                'nullable',
                'url',
                'max:2048',
            ],
            'live_url' => [
                'nullable',
                'url',
                'max:2048',
            ],
            'technologies' => [
                'nullable',
                'array',
                'max:30',
            ],
            'technologies.*' => [
                'string',
                'max:191',
            ],
            'publish_now' => [
                'nullable',
                'boolean',
            ],
        ]);

        $course = DB::table('courses')
            ->where(
                'id',
                $validated['course_id']
            )
            ->where(
                'trainer_id',
                $trainer->id
            )
            ->first();

        if (!$course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $student = DB::table('students as s')
            ->join(
                'users as u',
                'u.id',
                '=',
                's.user_id'
            )
            ->where(
                's.id',
                $validated['student_id']
            )
            ->select(
                's.*',
                'u.name'
            )
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student not found.',
            ], 404);
        }

        $enrolled = DB::table('enrollments')
            ->where(
                'student_id',
                $student->id
            )
            ->where(
                'course_id',
                $course->id
            )
            ->whereIn(
                'status',
                ['active', 'completed']
            )
            ->exists();

        if (!$enrolled) {
            return response()->json([
                'message' => 'Student is not enrolled in this course.',
            ], 422);
        }

        $projectId = DB::transaction(
            function () use (
                $validated,
                $student,
                $request
            ) {
                $publishNow = (bool) (
                    $validated['publish_now'] ?? false
                );

                $projectId = DB::table('projects')
                    ->insertGetId([
                        'owner_student_id' => $student->id,
                        'author_name' => $student->name,
                        'course_id' =>
                            $validated['course_id'],
                        'learning_path_id' => null,
                        'category_id' =>
                            $validated['category_id'] ?? null,
                        'title' =>
                            $validated['title'],
                        'idea' =>
                            $validated['idea'] ??
                            $validated['description'] ??
                            null,
                        'description' =>
                            $validated['description'] ?? null,
                        'problem' =>
                            $validated['problem'] ?? null,
                        'solution' =>
                            $validated['solution'] ?? null,
                        'project_type' =>
                            $validated['project_type'] ??
                            'individual',
                        'github_url' =>
                            $validated['github_url'] ?? null,
                        'live_url' =>
                            $validated['live_url'] ?? null,
                        'status' =>
                            $publishNow
                                ? 'published'
                                : 'hidden',
                        'is_featured' => false,
                        'submitted_for_review_at' =>
                            $publishNow ? now() : null,
                        'published_at' =>
                            $publishNow ? now() : null,
                        'deleted_at' => null,
                        'deletion_reason' => null,
                        'deleted_by' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                DB::table('project_members')
                    ->insert([
                        'project_id' => $projectId,
                        'student_id' => $student->id,
                        'member_name' => $student->name,
                        'role' => 'owner',
                        'project_role' => 'Project owner',
                        'specialty' => null,
                        'joined_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                $this->syncTechnologies(
                    $projectId,
                    $validated['technologies'] ?? []
                );

                $this->audit(
                    $projectId,
                    $request->user()->id,
                    $publishNow
                        ? 'Project created and published'
                        : 'Project created unpublished'
                );

                return $projectId;
            }
        );

        return response()->json([
            'message' => 'Project created successfully.',
            'project' => $this->projectData(
                $projectId,
                $trainer->id,
                true
            ),
        ], 201);
    }

    public function saveReview(
        Request $request,
        int $projectId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $project = $this->projectForTrainer(
            $projectId,
            $trainer->id
        );

        if (!$project) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        $validated = $this->validateReview(
            $request
        );

        $reviewId = $this->saveReviewRecord(
            $projectId,
            $trainer->id,
            'draft',
            $validated,
            false
        );

        $this->audit(
            $projectId,
            $request->user()->id,
            'Evaluation draft saved'
        );

        return response()->json([
            'message' => 'Evaluation draft saved successfully.',
            'reviewId' => $reviewId,
            'project' => $this->projectData(
                $projectId,
                $trainer->id,
                true
            ),
        ]);
    }

    public function approve(
        Request $request,
        int $projectId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $project = $this->projectForTrainer(
            $projectId,
            $trainer->id
        );

        if (!$project) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        if (
            !in_array(
                $project->status,
                [
                    'in_review',
                    'revision_requested',
                    'published',
                    'hidden',
                ],
                true
            )
        ) {
            return response()->json([
                'message' => 'This project cannot be approved right now.',
            ], 422);
        }

        $validated = $this->validateReview(
            $request
        );

        $this->validateFullRubric(
            $validated['scores'] ?? []
        );

        DB::transaction(
            function () use (
                $validated,
                $trainer,
                $projectId,
                $request
            ) {
                $this->saveReviewRecord(
                    $projectId,
                    $trainer->id,
                    'approved',
                    $validated,
                    true
                );

                DB::table('projects')
                    ->where('id', $projectId)
                    ->update([
                        'status' => 'published',
                        'published_at' => now(),
                        'updated_at' => now(),
                    ]);

                $this->audit(
                    $projectId,
                    $request->user()->id,
                    'Project approved and published'
                );
            }
        );

        $this->notifyProjectStudents(
            $projectId,
            'Project approved',
            'Your project "' .
                $project->title .
                '" was approved and published.'
        );

        return response()->json([
            'message' => 'Project approved and published successfully.',
            'project' => $this->projectData(
                $projectId,
                $trainer->id,
                true
            ),
        ]);
    }

    public function requestChanges(
        Request $request,
        int $projectId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $project = $this->projectForTrainer(
            $projectId,
            $trainer->id
        );

        if (!$project) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        $validated = $this->validateReview(
            $request,
            true
        );

        DB::transaction(
            function () use (
                $validated,
                $trainer,
                $projectId,
                $request
            ) {
                $this->saveReviewRecord(
                    $projectId,
                    $trainer->id,
                    'changes_requested',
                    $validated,
                    true
                );

                DB::table('projects')
                    ->where('id', $projectId)
                    ->update([
                        'status' =>
                            'revision_requested',
                        'updated_at' => now(),
                    ]);

                $this->audit(
                    $projectId,
                    $request->user()->id,
                    'Changes requested'
                );
            }
        );

        $this->notifyProjectStudents(
            $projectId,
            'Project changes requested',
            'Changes were requested for "' .
                $project->title .
                '".'
        );

        return response()->json([
            'message' => 'Changes requested successfully.',
            'project' => $this->projectData(
                $projectId,
                $trainer->id,
                true
            ),
        ]);
    }

    public function unpublish(
        Request $request,
        int $projectId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $project = $this->projectForTrainer(
            $projectId,
            $trainer->id
        );

        if (!$project) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        if ($project->status !== 'published') {
            return response()->json([
                'message' => 'Only published projects can be unpublished.',
            ], 422);
        }

        DB::table('projects')
            ->where('id', $projectId)
            ->update([
                'status' => 'hidden',
                'updated_at' => now(),
            ]);

        $this->audit(
            $projectId,
            $request->user()->id,
            'Project unpublished'
        );

        return response()->json([
            'message' => 'Project unpublished successfully.',
            'project' => $this->projectData(
                $projectId,
                $trainer->id,
                true
            ),
        ]);
    }

    public function destroy(
        Request $request,
        int $projectId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $project = $this->projectForTrainer(
            $projectId,
            $trainer->id
        );

        if (!$project) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:2000',
            ],
        ]);

        DB::table('projects')
            ->where('id', $projectId)
            ->update([
                'deleted_at' => now(),
                'deletion_reason' =>
                    $validated['reason'],
                'deleted_by' =>
                    $request->user()->id,
                'updated_at' => now(),
            ]);

        $this->audit(
            $projectId,
            $request->user()->id,
            'Project soft deleted',
            $validated['reason']
        );

        return response()->json([
            'message' => 'Project deleted successfully.',
        ]);
    }

    private function projectData(
        int $projectId,
        int $trainerId,
        bool $details = false
    ): ?array {
        $project = DB::table('projects as p')
            ->leftJoin(
                'students as s',
                's.id',
                '=',
                'p.owner_student_id'
            )
            ->leftJoin(
                'users as u',
                'u.id',
                '=',
                's.user_id'
            )
            ->leftJoin(
                'courses as c',
                'c.id',
                '=',
                'p.course_id'
            )
            ->leftJoin(
                'categories as cat',
                'cat.id',
                '=',
                'p.category_id'
            )
            ->where('p.id', $projectId)
            ->whereNull('p.deleted_at')
            ->select(
                'p.*',
                'u.name as owner_name',
                'u.email as owner_email',
                'c.title as course_title',
                'cat.name as category_name'
            )
            ->first();

        if (!$project) {
            return null;
        }

        $latestReview = DB::table('project_reviews')
            ->where('project_id', $projectId)
            ->where('trainer_id', $trainerId)
            ->orderByDesc('id')
            ->first();

        $latestDecision = DB::table('project_reviews')
            ->where('project_id', $projectId)
            ->where('trainer_id', $trainerId)
            ->where(
                'status',
                '!=',
                'draft'
            )
            ->orderByDesc('id')
            ->first();

        $status = $this->frontendStatus(
            $project,
            $latestDecision
        );

        $evaluation = null;

        if ($latestReview) {
            $scores = DB::table(
                'project_review_scores'
            )
                ->where(
                    'project_review_id',
                    $latestReview->id
                )
                ->get()
                ->mapWithKeys(
                    fn ($row) => [
                        $row->criterion_key =>
                            (int) $row->score,
                    ]
                );

            $notes = DB::table(
                'project_review_scores'
            )
                ->where(
                    'project_review_id',
                    $latestReview->id
                )
                ->whereNotNull('note')
                ->pluck(
                    'note',
                    'criterion_key'
                );

            $evaluation = [
                'id' => (int) $latestReview->id,
                'draft' =>
                    $latestReview->status === 'draft',
                'status' =>
                    $latestReview->status,
                'scores' => $scores,
                'notes' => $notes,
                'total' =>
                    (int) (
                        $latestReview->total_score ?? 0
                    ),
                'feedback' =>
                    $latestReview->feedback,
                'privateNote' =>
                    $latestReview->private_note,
                'notifyTeam' =>
                    (bool) $latestReview->notify_team,
                'changesDueAt' =>
                    $latestReview->changes_due_at,
                'reviewedAt' =>
                    $latestReview->reviewed_at,
            ];
        }

        $base = [
            'id' => (int) $project->id,
            'courseId' =>
                $project->course_id
                    ? (int) $project->course_id
                    : null,
            'courseTitle' =>
                $project->course_title
                    ?: 'Independent project',
            'category' =>
                $project->category_name
                    ?: 'General',
            'studentId' =>
                (int) $project->owner_student_id,
            'studentName' =>
                $project->owner_name
                    ?: $project->author_name
                    ?: 'Student',
            'studentEmail' =>
                $project->owner_email,
            'title' => $project->title,
            'description' =>
                $project->description
                    ?: $project->idea,
            'problem' => $project->problem,
            'solution' => $project->solution,
            'status' => $status,
            'submittedAt' =>
                $project->submitted_for_review_at
                    ?: $project->created_at,
            'createdAt' =>
                $project->created_at,
            'updatedAt' =>
                $project->updated_at,
            'publishedAt' =>
                $project->published_at,
            'evaluation' => $evaluation,
            'feedback' =>
                $latestReview?->feedback,
            'privateNote' =>
                $latestReview?->private_note,
            'notifyTeam' =>
                (bool) (
                    $latestReview?->notify_team ?? false
                ),
            'changesDueAt' =>
                $latestReview?->changes_due_at,
        ];

        if (!$details) {
            return $base;
        }

        $technologies = DB::table(
            'project_technology as pt'
        )
            ->join(
                'technologies as t',
                't.id',
                '=',
                'pt.technology_id'
            )
            ->where(
                'pt.project_id',
                $projectId
            )
            ->orderBy('t.name')
            ->pluck('t.name')
            ->values();

        $team = DB::table('project_members as pm')
            ->leftJoin(
                'students as s',
                's.id',
                '=',
                'pm.student_id'
            )
            ->leftJoin(
                'users as u',
                'u.id',
                '=',
                's.user_id'
            )
            ->where(
                'pm.project_id',
                $projectId
            )
            ->orderBy('pm.id')
            ->get([
                'pm.id',
                'pm.member_name',
                'pm.role',
                'pm.project_role',
                'pm.specialty',
                'u.name as student_name',
            ])
            ->map(fn ($member) => [
                'id' => (int) $member->id,
                'name' =>
                    $member->student_name
                    ?: $member->member_name
                    ?: 'Team member',
                'role' =>
                    $member->project_role
                    ?: (
                        $member->role === 'owner'
                            ? 'Project owner'
                            : 'Team member'
                    ),
                'specialty' =>
                    $member->specialty,
            ])
            ->values();

        $auditLog = DB::table(
            'project_audit_logs as pal'
        )
            ->leftJoin(
                'users as u',
                'u.id',
                '=',
                'pal.actor_user_id'
            )
            ->where(
                'pal.project_id',
                $projectId
            )
            ->orderBy('pal.id')
            ->get([
                'pal.id',
                'pal.action',
                'pal.details',
                'pal.created_at',
                'u.name as actor_name',
            ])
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'action' => $item->action,
                'details' => $item->details,
                'actor' =>
                    $item->actor_name
                    ?: 'Compass Academy',
                'at' => $item->created_at,
            ])
            ->values();

        $materials = [
            'cover_image' =>
                $this->fileUrl(
                    $project->cover_image
                ),
            'logo' =>
                $this->fileUrl(
                    $project->logo
                ),
            'intro_video' =>
                $this->fileUrl(
                    $project->intro_video
                ),
            'presentation_file' =>
                $this->fileUrl(
                    $project->presentation_file
                ),
            'documentation_file' =>
                $this->fileUrl(
                    $project->documentation_file
                ),
        ];

        return [
            ...$base,
            'idea' => $project->idea,
            'projectType' =>
                $project->project_type,
            'techStack' => $technologies,
            'team' => $team,
            'links' => [
                'github' =>
                    $project->github_url,
                'demo' =>
                    $project->live_url,
                'presentation' =>
                    $materials[
                        'presentation_file'
                    ],
                'documentation' =>
                    $materials[
                        'documentation_file'
                    ],
            ],
            'media' => $materials,
            'files' => collect([
                [
                    'id' =>
                        'presentation-' .
                        $projectId,
                    'name' =>
                        'Presentation',
                    'url' =>
                        $materials[
                            'presentation_file'
                        ],
                ],
                [
                    'id' =>
                        'documentation-' .
                        $projectId,
                    'name' =>
                        'Documentation',
                    'url' =>
                        $materials[
                            'documentation_file'
                        ],
                ],
            ])
                ->filter(
                    fn ($file) =>
                        !empty($file['url'])
                )
                ->values(),
            'auditLog' => $auditLog,
        ];
    }

    private function validateReview(
        Request $request,
        bool $feedbackRequired = false
    ): array {
        return $request->validate([
            'scores' => [
                'nullable',
                'array',
            ],
            'scores.*' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'notes' => [
                'nullable',
                'array',
            ],
            'notes.*' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'feedback' => [
                $feedbackRequired
                    ? 'required'
                    : 'nullable',
                'string',
                'max:10000',
            ],
            'private_note' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'notify_team' => [
                'nullable',
                'boolean',
            ],
            'changes_due_at' => [
                'nullable',
                'date',
                'after:now',
            ],
        ]);
    }

    private function validateFullRubric(
        array $scores
    ): void {
        foreach (
            self::RUBRIC as
            $key => $criterion
        ) {
            if (
                !array_key_exists(
                    $key,
                    $scores
                )
            ) {
                abort(
                    response()->json([
                        'message' =>
                            'Complete all rubric scores before approval.',
                    ], 422)
                );
            }

            if (
                (int) $scores[$key] < 0 ||
                (int) $scores[$key] >
                    $criterion['max']
            ) {
                abort(
                    response()->json([
                        'message' =>
                            $criterion['label'] .
                            ' score is invalid.',
                    ], 422)
                );
            }
        }
    }

    private function saveReviewRecord(
        int $projectId,
        int $trainerId,
        string $status,
        array $validated,
        bool $reviewed
    ): int {
        $reviewId = DB::table(
            'project_reviews'
        )
            ->insertGetId([
                'project_id' => $projectId,
                'trainer_id' => $trainerId,
                'status' => $status,
                'feedback' =>
                    $validated['feedback'] ?? null,
                'private_note' =>
                    $validated['private_note'] ?? null,
                'notify_team' =>
                    (bool) (
                        $validated['notify_team']
                        ?? false
                    ),
                'changes_due_at' =>
                    $validated['changes_due_at'] ?? null,
                'total_score' => 0,
                'reviewed_at' =>
                    $reviewed ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $total = 0;

        foreach (
            self::RUBRIC as
            $key => $criterion
        ) {
            if (
                !array_key_exists(
                    $key,
                    $validated['scores'] ?? []
                )
            ) {
                continue;
            }

            $score = min(
                $criterion['max'],
                max(
                    0,
                    (int) $validated['scores'][$key]
                )
            );

            $total += $score;

            DB::table(
                'project_review_scores'
            )
                ->insert([
                    'project_review_id' =>
                        $reviewId,
                    'criterion_key' => $key,
                    'criterion_label' =>
                        $criterion['label'],
                    'score' => $score,
                    'max_score' =>
                        $criterion['max'],
                    'note' =>
                        $validated['notes'][$key]
                        ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        DB::table('project_reviews')
            ->where('id', $reviewId)
            ->update([
                'total_score' => $total,
            ]);

        return $reviewId;
    }

    private function projectForTrainer(
        int $projectId,
        int $trainerId
    ) {
        return DB::table('projects as p')
            ->where('p.id', $projectId)
            ->whereNull('p.deleted_at')
            ->where(function ($query) use (
                $trainerId
            ) {
                $query
                    ->whereExists(
                        function ($courseQuery) use (
                            $trainerId
                        ) {
                            $courseQuery
                                ->select(DB::raw(1))
                                ->from('courses as c')
                                ->whereColumn(
                                    'c.id',
                                    'p.course_id'
                                )
                                ->where(
                                    'c.trainer_id',
                                    $trainerId
                                );
                        }
                    )
                    ->orWhereExists(
                        function ($reviewQuery) use (
                            $trainerId
                        ) {
                            $reviewQuery
                                ->select(DB::raw(1))
                                ->from(
                                    'project_reviews as pr'
                                )
                                ->whereColumn(
                                    'pr.project_id',
                                    'p.id'
                                )
                                ->where(
                                    'pr.trainer_id',
                                    $trainerId
                                );
                        }
                    );
            })
            ->first();
    }

    private function trainerProjectIds(
        int $trainerId
    ) {
        return DB::table('projects as p')
            ->whereNull('p.deleted_at')
            ->where(function ($query) use (
                $trainerId
            ) {
                $query
                    ->whereExists(
                        function ($courseQuery) use (
                            $trainerId
                        ) {
                            $courseQuery
                                ->select(DB::raw(1))
                                ->from('courses as c')
                                ->whereColumn(
                                    'c.id',
                                    'p.course_id'
                                )
                                ->where(
                                    'c.trainer_id',
                                    $trainerId
                                );
                        }
                    )
                    ->orWhereExists(
                        function ($reviewQuery) use (
                            $trainerId
                        ) {
                            $reviewQuery
                                ->select(DB::raw(1))
                                ->from(
                                    'project_reviews as pr'
                                )
                                ->whereColumn(
                                    'pr.project_id',
                                    'p.id'
                                )
                                ->where(
                                    'pr.trainer_id',
                                    $trainerId
                                );
                        }
                    );
            })
            ->orderByRaw(
                "CASE
                    WHEN p.status = 'in_review' THEN 0
                    WHEN p.status = 'revision_requested' THEN 1
                    WHEN p.status = 'published' THEN 2
                    ELSE 3
                END"
            )
            ->orderByDesc(
                'p.submitted_for_review_at'
            )
            ->orderByDesc('p.updated_at')
            ->pluck('p.id');
    }

    private function frontendStatus(
        object $project,
        $latestDecision
    ): string {
        if ($project->status === 'published') {
            return 'published';
        }

        if ($project->status === 'hidden') {
            return 'unpublished';
        }

        if (
            $project->status ===
            'revision_requested'
        ) {
            return 'changes-requested';
        }

        if ($project->status === 'draft') {
            return 'draft';
        }

        if ($project->status === 'rejected') {
            return 'rejected';
        }

        if ($project->status === 'in_review') {
            if (
                $latestDecision?->status ===
                'changes_requested'
            ) {
                return 'resubmitted';
            }

            return 'pending-review';
        }

        return $project->status;
    }

    private function syncTechnologies(
        int $projectId,
        array $technologies
    ): void {
        foreach (
            collect($technologies)
                ->map(
                    fn ($name) =>
                        trim((string) $name)
                )
                ->filter()
                ->unique(
                    fn ($name) =>
                        mb_strtolower($name)
                )
            as $name
        ) {
            $technology = DB::table(
                'technologies'
            )
                ->whereRaw(
                    'LOWER(name) = ?',
                    [
                        mb_strtolower($name),
                    ]
                )
                ->first();

            $technologyId =
                $technology?->id ??
                DB::table('technologies')
                    ->insertGetId([
                        'name' => $name,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

            DB::table(
                'project_technology'
            )
                ->updateOrInsert(
                    [
                        'project_id' =>
                            $projectId,
                        'technology_id' =>
                            $technologyId,
                    ],
                    [
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
        }
    }

    private function notifyProjectStudents(
        int $projectId,
        string $title,
        string $message
    ): void {
        $studentIds = DB::table(
            'project_members'
        )
            ->where(
                'project_id',
                $projectId
            )
            ->whereNotNull(
                'student_id'
            )
            ->pluck('student_id')
            ->push(
                DB::table('projects')
                    ->where(
                        'id',
                        $projectId
                    )
                    ->value(
                        'owner_student_id'
                    )
            )
            ->filter()
            ->unique();

        $userIds = DB::table('students')
            ->whereIn(
                'id',
                $studentIds
            )
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            NotificationService::create(
                $userId,
                'project',
                $title,
                $message,
                [
                    'category' => 'academics',
                    'icon' => '🚀',
                    'action_label' =>
                        'View project',
                    'action_tab' =>
                        'Projects gallery',
                    'project_id' =>
                        $projectId,
                ]
            );
        }
    }

    private function audit(
        int $projectId,
        ?int $actorUserId,
        string $action,
        ?string $details = null
    ): void {
        DB::table('project_audit_logs')
            ->insert([
                'project_id' => $projectId,
                'actor_user_id' =>
                    $actorUserId,
                'action' => $action,
                'details' => $details,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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
