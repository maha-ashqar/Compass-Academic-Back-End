<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AchievementController extends Controller
{
    public function index(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $portfolioCode = $this->ensurePortfolioCode(
            $student->id
        );

        $this->syncBadges($student->id);

        return response()->json([
            'portfolio' => $this->portfolioData(
                $student->id,
                $portfolioCode
            ),
        ]);
    }

    public function publicShow(string $portfolioCode)
    {
        $student = DB::table('students')
            ->where('portfolio_code', $portfolioCode)
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Portfolio not found.',
            ], 404);
        }

        $this->syncBadges($student->id);

        return response()->json([
            'portfolio' => $this->portfolioData(
                $student->id,
                $portfolioCode
            ),
        ]);
    }

    public function storeCredential(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:191',
            ],
            'issuer' => [
                'required',
                'string',
                'max:191',
            ],
            'issue_date' => [
                'nullable',
                'date',
            ],
            'credential_id' => [
                'nullable',
                'string',
                'max:191',
            ],
            'credential_url' => [
                'required',
                'url',
                'max:2048',
            ],
            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'file' => [
                'nullable',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp',
                'max:5120',
            ],
        ]);

        $filePath = null;

        if ($request->hasFile('file')) {
            $filePath = $request
                ->file('file')
                ->store(
                    'student-credentials/' . $student->id,
                    'public'
                );
        }

        $credentialId = DB::table(
            'student_credentials'
        )->insertGetId([
            'student_id' => $student->id,
            'title' => $validated['title'],
            'issuer' => $validated['issuer'],
            'issue_date' =>
                $validated['issue_date'] ?? null,
            'credential_id' =>
                $validated['credential_id'] ?? null,
            'credential_url' =>
                $validated['credential_url'],
            'description' =>
                $validated['description'] ?? null,
            'file_path' => $filePath,
            'is_verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        NotificationService::create(
            $request->user()->id,
            'certificate',
            'Certificate added to your portfolio',
            'Your certificate "' .
                $validated['title'] .
                '" was added successfully to your career portfolio.',
            [
                'category' => 'academics',
                'icon' => '🏅',
                'action_label' => 'View portfolio',
                'action_tab' => 'Achievements',
                'credential_id' => $credentialId,
            ]
        );

        return response()->json([
            'message' => 'Credential added successfully.',
            'credential' => $this->credentialData(
                DB::table('student_credentials')
                    ->where('id', $credentialId)
                    ->first()
            ),
        ], 201);
    }

    public function deleteCredential(
        Request $request,
        int $credentialId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $credential = DB::table(
            'student_credentials'
        )
            ->where('id', $credentialId)
            ->where('student_id', $student->id)
            ->first();

        if (!$credential) {
            return response()->json([
                'message' => 'Credential not found.',
            ], 404);
        }

        if ($credential->is_verified) {
            return response()->json([
                'message' => 'Verified credentials cannot be deleted by the student.',
            ], 422);
        }

        if ($credential->file_path) {
            Storage::disk('public')->delete(
                $credential->file_path
            );
        }

        DB::table('student_credentials')
            ->where('id', $credentialId)
            ->delete();

        return response()->json([
            'message' => 'Credential deleted successfully.',
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

    private function ensurePortfolioCode(
        int $studentId
    ): string {
        $student = DB::table('students')
            ->where('id', $studentId)
            ->first();

        if ($student->portfolio_code) {
            return $student->portfolio_code;
        }

        do {
            $code =
                'CA-' .
                now()->format('Y') .
                '-' .
                strtoupper(Str::random(8));

            $exists = DB::table('students')
                ->where('portfolio_code', $code)
                ->exists();
        } while ($exists);

        DB::table('students')
            ->where('id', $studentId)
            ->update([
                'portfolio_code' => $code,
                'updated_at' => now(),
            ]);

        return $code;
    }

    private function portfolioData(
        int $studentId,
        string $portfolioCode
    ): array {
        $identity = $this->identityData($studentId);
        $projects = $this->projectData($studentId);
        $learning = $this->learningData($studentId);
        $trainings = $this->trainingData($studentId);
        $evaluations = $this->evaluationData(
            $studentId
        );
        $skills = $this->skillData($studentId);
        $certificates = $this->certificateData(
            $studentId
        );
        $credentials = DB::table(
            'student_credentials'
        )
            ->where('student_id', $studentId)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get()
            ->map(
                fn ($credential) =>
                    $this->credentialData($credential)
            )
            ->values();
        $competitions = $this->competitionData(
            $studentId
        );
        $badges = $this->badgeData($studentId);

        $averageEvaluation = $evaluations->count()
            ? round(
                $evaluations->avg('score'),
                1
            )
            : 0;

        $hasVerifiedCredential =
            $certificates->count() > 0 ||
            $credentials
                ->where('is_verified', true)
                ->count() > 0;

        $verifiedCompetitionCount =
            $competitions
                ->where('is_verified', true)
                ->count();

        $checks = [
            !empty($identity['avatar']),
            !empty($identity['professional_summary']),
            $skills['all']->count() > 0,
            $projects->count() > 0,
            $learning->count() > 0,
            $hasVerifiedCredential,
            $verifiedCompetitionCount > 0,
        ];

        $profileCompleteness = (int) round(
            (
                collect($checks)
                    ->filter()
                    ->count() /
                count($checks)
            ) * 100
        );

        return [
            'portfolio_code' => $portfolioCode,
            'identity' => $identity,
            'profile_completeness' =>
                $profileCompleteness,
            'stats' => [
                'published_projects' =>
                    $projects->count(),
                'completed_courses' =>
                    $learning->count(),
                'certificates' =>
                    $certificates->count(),
                'external_credentials' =>
                    $credentials->count(),
                'verified_trainings' =>
                    $trainings->count(),
                'verified_competitions' =>
                    $verifiedCompetitionCount,
                'badges_earned' =>
                    $badges
                        ->where('earned', true)
                        ->count(),
                'badges_total' =>
                    $badges->count(),
                'mentor_evaluation_average' =>
                    $averageEvaluation,
            ],
            'projects' => $projects,
            'completed_courses' => $learning,
            'training_programs' => $trainings,
            'mentor_evaluations' => $evaluations,
            'skills' => [
                'development' =>
                    $skills['development'],
                'design' =>
                    $skills['design'],
                'professional' =>
                    $skills['professional'],
            ],
            'certificates' => $certificates,
            'credentials' => $credentials,
            'competitions' => $competitions,
            'badges' => $badges,
        ];
    }

    private function identityData(
        int $studentId
    ): array {
        $student = DB::table('students as s')
            ->join(
                'users as u',
                'u.id',
                '=',
                's.user_id'
            )
            ->where('s.id', $studentId)
            ->select(
                's.*',
                'u.name',
                'u.email',
                'u.avatar'
            )
            ->first();

        $education = DB::table(
            'student_educations'
        )
            ->where('student_id', $studentId)
            ->orderByDesc('is_current')
            ->orderByDesc('id')
            ->first();

        return [
            'student_id' => $student->id,
            'student_code' => $student->student_code,
            'name' => $student->name,
            'email' => $student->email,
            'avatar' => $this->fileUrl(
                $student->avatar
            ),
            'professional_summary' =>
                $student->professional_summary,
            'is_verified' =>
                (bool) $student->is_verified,
            'education' => $education
                ? [
                    'degree' => $education->degree,
                    'major' => $education->major,
                    'university' =>
                        $education->university,
                    'faculty' => $education->faculty,
                    'department' =>
                        $education->department,
                    'academic_year' =>
                        $education->academic_year,
                    'start_year' =>
                        $education->start_year,
                    'expected_graduation_date' =>
                        $education
                            ->expected_graduation_date,
                    'location' =>
                        $education->location,
                    'is_current' =>
                        (bool) $education->is_current,
                ]
                : null,
        ];
    }

    private function projectData(
        int $studentId
    ) {
        $projectIds = DB::table('projects as p')
            ->where('p.status', 'published')
            ->where(function ($query) use ($studentId) {
                $query
                    ->where(
                        'p.owner_student_id',
                        $studentId
                    )
                    ->orWhereExists(
                        function ($memberQuery) use (
                            $studentId
                        ) {
                            $memberQuery
                                ->select(DB::raw(1))
                                ->from(
                                    'project_members as pm'
                                )
                                ->whereColumn(
                                    'pm.project_id',
                                    'p.id'
                                )
                                ->where(
                                    'pm.student_id',
                                    $studentId
                                );
                        }
                    );
            })
            ->orderByDesc('p.is_featured')
            ->orderByDesc('p.published_at')
            ->pluck('p.id');

        return $projectIds
            ->map(function ($projectId) {
                $project = DB::table(
                    'projects as p'
                )
                    ->leftJoin(
                        'categories as c',
                        'c.id',
                        '=',
                        'p.category_id'
                    )
                    ->where('p.id', $projectId)
                    ->select(
                        'p.*',
                        'c.name as category_name'
                    )
                    ->first();

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

                $teamCount = DB::table(
                    'project_members'
                )
                    ->where(
                        'project_id',
                        $projectId
                    )
                    ->count();

                return [
                    'id' => $project->id,
                    'title' => $project->title,
                    'description' =>
                        $project->description
                            ?: $project->idea,
                    'category' =>
                        $project->category_name,
                    'project_type' =>
                        $project->project_type,
                    'is_featured' =>
                        (bool) $project->is_featured,
                    'published_at' =>
                        $project->published_at,
                    'cover_image' =>
                        $this->fileUrl(
                            $project->cover_image
                        ),
                    'technologies' =>
                        $technologies,
                    'team_count' => max(
                        1,
                        $teamCount
                    ),
                    'links' => [
                        'github' =>
                            $project->github_url,
                        'demo' =>
                            $project->live_url,
                    ],
                    'verified' => true,
                ];
            })
            ->values();
    }

    private function learningData(
        int $studentId
    ) {
        return DB::table('enrollments as e')
            ->join(
                'courses as c',
                'c.id',
                '=',
                'e.course_id'
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
            ->leftJoin(
                'categories as cat',
                'cat.id',
                '=',
                'c.category_id'
            )
            ->where('e.student_id', $studentId)
            ->where('e.status', 'completed')
            ->orderByDesc('e.completed_at')
            ->select(
                'c.id',
                'c.title',
                'u.name as instructor',
                'cat.name as category',
                'e.completed_at'
            )
            ->get()
            ->map(function ($course) use ($studentId) {
                $certificate = DB::table(
                    'certificates'
                )
                    ->where(
                        'student_id',
                        $studentId
                    )
                    ->where(
                        'course_id',
                        $course->id
                    )
                    ->first();

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'instructor' =>
                        $course->instructor,
                    'category' => $course->category,
                    'progress' => 100,
                    'completed_at' =>
                        $course->completed_at,
                    'certificate_issued' =>
                        (bool) $certificate,
                ];
            })
            ->values();
    }

    private function trainingData(
        int $studentId
    ) {
        return DB::table(
            'student_training_programs as stp'
        )
            ->join(
                'training_programs as tp',
                'tp.id',
                '=',
                'stp.training_program_id'
            )
            ->where(
                'stp.student_id',
                $studentId
            )
            ->where(
                'stp.is_verified',
                true
            )
            ->orderByDesc('stp.completed_at')
            ->select(
                'tp.id',
                'tp.title',
                'tp.provider',
                'tp.duration_hours',
                'tp.description',
                'stp.completed_at',
                'stp.is_verified'
            )
            ->get()
            ->map(function ($training) {
                return [
                    'id' => $training->id,
                    'title' => $training->title,
                    'provider' =>
                        $training->provider,
                    'hours' =>
                        $training->duration_hours,
                    'description' =>
                        $training->description,
                    'completed_at' =>
                        $training->completed_at,
                    'verified' =>
                        (bool) $training->is_verified,
                ];
            })
            ->values();
    }

    private function evaluationData(
        int $studentId
    ) {
        return DB::table(
            'mentor_evaluations as me'
        )
            ->join(
                'trainers as t',
                't.id',
                '=',
                'me.trainer_id'
            )
            ->join(
                'users as u',
                'u.id',
                '=',
                't.user_id'
            )
            ->where(
                'me.student_id',
                $studentId
            )
            ->where(
                'me.is_verified',
                true
            )
            ->orderByDesc('me.created_at')
            ->select(
                'me.id',
                'me.score',
                'me.evaluation',
                'me.is_verified',
                'me.created_at',
                'u.name as mentor_name',
                't.job_title'
            )
            ->get()
            ->map(function ($evaluation) {
                return [
                    'id' => $evaluation->id,
                    'mentor' =>
                        $evaluation->mentor_name,
                    'title' =>
                        $evaluation->job_title
                            ?: 'Compass Academy Trainer',
                    'score' =>
                        (int) $evaluation->score,
                    'note' =>
                        $evaluation->evaluation,
                    'verified' =>
                        (bool) $evaluation->is_verified,
                    'issued_at' =>
                        $evaluation->created_at,
                ];
            })
            ->values();
    }

    private function skillData(
        int $studentId
    ): array {
        $skills = DB::table(
            'student_skills as ss'
        )
            ->join(
                'skills as s',
                's.id',
                '=',
                'ss.skill_id'
            )
            ->where(
                'ss.student_id',
                $studentId
            )
            ->orderBy('s.name')
            ->get([
                's.name',
                's.category',
                'ss.is_verified',
            ]);

        $projectTechnologies = DB::table(
            'projects as p'
        )
            ->join(
                'project_technology as pt',
                'pt.project_id',
                '=',
                'p.id'
            )
            ->join(
                'technologies as t',
                't.id',
                '=',
                'pt.technology_id'
            )
            ->where('p.status', 'published')
            ->where(function ($query) use ($studentId) {
                $query
                    ->where(
                        'p.owner_student_id',
                        $studentId
                    )
                    ->orWhereExists(
                        function ($memberQuery) use (
                            $studentId
                        ) {
                            $memberQuery
                                ->select(DB::raw(1))
                                ->from(
                                    'project_members as pm'
                                )
                                ->whereColumn(
                                    'pm.project_id',
                                    'p.id'
                                )
                                ->where(
                                    'pm.student_id',
                                    $studentId
                                );
                        }
                    );
            })
            ->pluck('t.name');

        $all = $skills
            ->map(
                fn ($skill) => [
                    'name' => $skill->name,
                    'category' =>
                        $this->skillCategory(
                            $skill->name,
                            $skill->category
                        ),
                    'verified' =>
                        (bool) $skill->is_verified,
                ]
            )
            ->concat(
                $projectTechnologies->map(
                    fn ($name) => [
                        'name' => $name,
                        'category' =>
                            $this->skillCategory(
                                $name,
                                'development'
                            ),
                        'verified' => true,
                    ]
                )
            )
            ->unique(
                fn ($skill) =>
                    mb_strtolower($skill['name'])
            )
            ->values();

        return [
            'all' => $all,
            'development' => $all
                ->where(
                    'category',
                    'development'
                )
                ->pluck('name')
                ->values(),
            'design' => $all
                ->where('category', 'design')
                ->pluck('name')
                ->values(),
            'professional' => $all
                ->where(
                    'category',
                    'professional'
                )
                ->pluck('name')
                ->values(),
        ];
    }

    private function skillCategory(
        string $name,
        ?string $category
    ): string {
        $normalizedCategory = mb_strtolower(
            trim((string) $category)
        );

        if (
            in_array(
                $normalizedCategory,
                [
                    'development',
                    'design',
                    'professional',
                ],
                true
            )
        ) {
            return $normalizedCategory;
        }

        $normalizedName = mb_strtolower($name);

        foreach (
            ['figma', 'ui', 'ux', 'design', 'prototype']
            as $word
        ) {
            if (str_contains($normalizedName, $word)) {
                return 'design';
            }
        }

        foreach (
            [
                'communication',
                'teamwork',
                'leadership',
                'problem solving',
                'research',
            ] as $word
        ) {
            if (str_contains($normalizedName, $word)) {
                return 'professional';
            }
        }

        return 'development';
    }

    private function certificateData(
        int $studentId
    ) {
        return DB::table('certificates as cert')
            ->join(
                'courses as c',
                'c.id',
                '=',
                'cert.course_id'
            )
            ->where(
                'cert.student_id',
                $studentId
            )
            ->orderByDesc('cert.issued_at')
            ->select(
                'cert.*',
                'c.title as course_title'
            )
            ->get()
            ->map(function ($certificate) {
                return [
                    'id' =>
                        'course-' . $certificate->id,
                    'title' =>
                        $certificate->course_title,
                    'issuer' => 'Compass Academy',
                    'issued_at' =>
                        $certificate->issued_at,
                    'certificate_code' =>
                        $certificate->certificate_code,
                    'file_url' =>
                        $this->fileUrl(
                            $certificate->file_path
                        ),
                    'credential_url' =>
                        $certificate->verification_url,
                    'description' =>
                        'Verified course completion certificate.',
                    'is_verified' => true,
                    'platform' => true,
                ];
            })
            ->values();
    }

    private function credentialData(
        $credential
    ): array {
        return [
            'id' => $credential->id,
            'title' => $credential->title,
            'issuer' => $credential->issuer,
            'issued_at' => $credential->issue_date,
            'credential_id' =>
                $credential->credential_id,
            'credential_url' =>
                $credential->credential_url,
            'description' =>
                $credential->description,
            'file_url' =>
                $this->fileUrl(
                    $credential->file_path
                ),
            'is_verified' =>
                (bool) $credential->is_verified,
            'platform' => false,
        ];
    }

    private function competitionData(
        int $studentId
    ) {
        return DB::table(
            'competition_registration_members as crm'
        )
            ->join(
                'competition_registrations as cr',
                'cr.id',
                '=',
                'crm.competition_registration_id'
            )
            ->join(
                'competitions as c',
                'c.id',
                '=',
                'cr.competition_id'
            )
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
            ->leftJoin(
                'competition_results as r',
                'r.competition_registration_id',
                '=',
                'cr.id'
            )
            ->where(
                'crm.student_id',
                $studentId
            )
            ->whereNotIn(
                'cr.status',
                ['withdrawn', 'disqualified']
            )
            ->orderByDesc(
                'c.results_published_at'
            )
            ->orderByDesc('cr.registered_at')
            ->select(
                'c.id',
                'c.title',
                'c.status as competition_status',
                'c.results_published_at',
                'cr.status as registration_status',
                'cr.registered_at',
                'u.name as organizer',
                'r.rank',
                'r.final_score',
                'r.award',
                'r.published_at as result_published_at'
            )
            ->get()
            ->unique('id')
            ->map(function ($competition) {
                $verified =
                    $competition->competition_status ===
                        'results_published' ||
                    $competition->competition_status ===
                        'completed' ||
                    $competition->result_published_at;

                $result = $competition->award;

                if (!$result && $competition->rank) {
                    $result =
                        'Rank #' . $competition->rank;
                }

                if (
                    !$result &&
                    $competition->competition_status ===
                        'results_published'
                ) {
                    $result = 'Participant';
                }

                if (!$result) {
                    $result = ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $competition
                                ->registration_status
                        )
                    );
                }

                return [
                    'id' => $competition->id,
                    'title' =>
                        $competition->title,
                    'organizer' =>
                        $competition->organizer,
                    'registration_status' =>
                        $competition
                            ->registration_status,
                    'registered_at' =>
                        $competition->registered_at,
                    'result' => $result,
                    'rank' => $competition->rank,
                    'final_score' =>
                        $competition->final_score !==
                        null
                            ? (float) $competition
                                ->final_score
                            : null,
                    'verified' =>
                        (bool) $verified,
                ];
            })
            ->values();
    }

    private function badgeData(
        int $studentId
    ) {
        $earnedIds = DB::table(
            'student_badges'
        )
            ->where(
                'student_id',
                $studentId
            )
            ->pluck(
                'earned_at',
                'badge_id'
            );

        return DB::table('badges')
            ->orderBy('id')
            ->get()
            ->map(function ($badge) use ($earnedIds) {
                return [
                    'id' => $badge->id,
                    'name' => $badge->name,
                    'description' =>
                        $badge->description,
                    'icon' => $badge->icon,
                    'tier' => $badge->tier,
                    'earned' =>
                        $earnedIds->has(
                            $badge->id
                        ),
                    'earned_at' =>
                        $earnedIds[
                            $badge->id
                        ] ?? null,
                ];
            })
            ->values();
    }

    private function syncBadges(
        int $studentId
    ): void {
        $metrics = $this->badgeMetrics(
            $studentId
        );

        $badges = DB::table('badges')
            ->whereNotNull('condition_type')
            ->whereNotNull('condition_value')
            ->get();

        foreach ($badges as $badge) {
            $current = $metrics[
                $badge->condition_type
            ] ?? 0;

            if (
                $current <
                (int) $badge->condition_value
            ) {
                continue;
            }

            $existing = DB::table('student_badges')
                ->where('student_id', $studentId)
                ->where('badge_id', $badge->id)
                ->first();

            if ($existing) {
                DB::table('student_badges')
                    ->where('id', $existing->id)
                    ->update([
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('student_badges')->insert([
                'student_id' => $studentId,
                'badge_id' => $badge->id,
                'earned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function badgeMetrics(
        int $studentId
    ): array {
        $enrollmentCourseIds = DB::table(
            'enrollments'
        )
            ->where(
                'student_id',
                $studentId
            )
            ->whereIn(
                'status',
                ['active', 'completed']
            )
            ->pluck('course_id');

        $maxProgress = 0;

        foreach ($enrollmentCourseIds as $courseId) {
            $lessonIds = DB::table(
                'course_modules as cm'
            )
                ->join(
                    'lessons as l',
                    'l.course_module_id',
                    '=',
                    'cm.id'
                )
                ->where(
                    'cm.course_id',
                    $courseId
                )
                ->where(
                    'l.is_published',
                    true
                )
                ->pluck('l.id');

            if ($lessonIds->isEmpty()) {
                continue;
            }

            $completed = DB::table(
                'lesson_progress'
            )
                ->where(
                    'student_id',
                    $studentId
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

            $progress = (int) round(
                (
                    $completed /
                    $lessonIds->count()
                ) * 100
            );

            $maxProgress = max(
                $maxProgress,
                $progress
            );
        }

        $coursesWithCompletedLesson =
            DB::table('lesson_progress as lp')
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
                ->where(
                    'lp.is_completed',
                    true
                )
                ->distinct()
                ->count('cm.course_id');

        $competitionRegistrations =
            DB::table(
                'competition_registration_members as crm'
            )
                ->join(
                    'competition_registrations as cr',
                    'cr.id',
                    '=',
                    'crm.competition_registration_id'
                )
                ->where(
                    'crm.student_id',
                    $studentId
                )
                ->whereNotIn(
                    'cr.status',
                    ['withdrawn', 'disqualified']
                )
                ->distinct()
                ->count('cr.id');

        return [
            'enrolled_courses' =>
                $enrollmentCourseIds->count(),
            'completed_lessons' =>
                DB::table('lesson_progress')
                    ->where(
                        'student_id',
                        $studentId
                    )
                    ->where(
                        'is_completed',
                        true
                    )
                    ->count(),
            'course_progress' =>
                $maxProgress,
            'completed_courses' =>
                DB::table('enrollments')
                    ->where(
                        'student_id',
                        $studentId
                    )
                    ->where(
                        'status',
                        'completed'
                    )
                    ->count(),
            'courses_with_completed_lesson' =>
                $coursesWithCompletedLesson,
            'submitted_assignments' =>
                DB::table('submissions')
                    ->where(
                        'student_id',
                        $studentId
                    )
                    ->whereIn(
                        'status',
                        [
                            'submitted',
                            'late',
                            'graded',
                            'revision_requested',
                            'resubmitted',
                        ]
                    )
                    ->count(),
            'competition_registrations' =>
                $competitionRegistrations,
        ];
    }

    private function fileUrl(
        ?string $path
    ): ?string {
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
