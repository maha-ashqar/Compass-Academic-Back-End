<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $projectIds = DB::table('projects')
            ->where(function ($query) use ($student) {
                $query
                    ->where('owner_student_id', $student->id)
                    ->orWhere('status', 'published');
            })
            ->orderByRaw(
                'CASE WHEN owner_student_id = ? THEN 0 ELSE 1 END',
                [$student->id]
            )
            ->orderByDesc('updated_at')
            ->pluck('id');

        $projects = $projectIds
            ->map(
                fn ($projectId) => $this->projectData(
                    $projectId,
                    $student->id
                )
            )
            ->filter()
            ->values();

        return response()->json([
            'projects' => $projects,
        ]);
    }

    public function meta(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $categories = DB::table('categories')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'slug',
            ]);

        $courses = DB::table('enrollments as e')
            ->join('courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.student_id', $student->id)
            ->whereIn('e.status', ['active', 'completed'])
            ->where('c.status', 'published')
            ->orderBy('c.title')
            ->get([
                'c.id',
                'c.title',
            ]);

        return response()->json([
            'categories' => $categories,
            'courses' => $courses,
        ]);
    }

    public function show(Request $request, int $projectId)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $project = DB::table('projects')
            ->where('id', $projectId)
            ->first();

        if (
            !$project ||
            (
                (int) $project->owner_student_id !== (int) $student->id &&
                $project->status !== 'published'
            )
        ) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        return response()->json([
            'project' => $this->projectData(
                $projectId,
                $student->id
            ),
        ]);
    }

    public function store(Request $request)
    {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $validated = $this->validateDraft($request, $student->id);

        $projectId = DB::transaction(function () use (
            $validated,
            $student,
            $request
        ) {
            $projectId = DB::table('projects')->insertGetId([
                'owner_student_id' => $student->id,
                'author_name' => $request->user()->name,
                'course_id' => $validated['course_id'] ?? null,
                'learning_path_id' => null,
                'category_id' => $validated['category_id'] ?? null,
                'title' => $validated['title'] ?? '',
                'idea' => $validated['idea'] ?? null,
                'description' => $validated['description']
                    ?? ($validated['idea'] ?? null),
                'problem' => $validated['problem'] ?? null,
                'solution' => $validated['solution'] ?? null,
                'project_type' => $validated['project_type']
                    ?? 'individual',
                'github_url' => $validated['github_url'] ?? null,
                'live_url' => $validated['live_url'] ?? null,
                'status' => 'draft',
                'is_featured' => false,
                'submitted_for_review_at' => null,
                'published_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncTechnologies(
                $projectId,
                $validated['technologies'] ?? []
            );

            $this->syncMembers(
                $projectId,
                $student,
                $request->user()->name,
                $validated['project_type'] ?? 'individual',
                $validated['members'] ?? []
            );

            return $projectId;
        });

        return response()->json([
            'message' => 'Project draft created successfully.',
            'project' => $this->projectData(
                $projectId,
                $student->id
            ),
        ], 201);
    }

    public function update(
        Request $request,
        int $projectId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $project = $this->ownedProject(
            $student->id,
            $projectId
        );

        if (!$project) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        if (
            !in_array(
                $project->status,
                ['draft', 'revision_requested'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This project cannot be edited right now.',
            ], 422);
        }

        $validated = $this->validateDraft(
            $request,
            $student->id
        );

        DB::transaction(function () use (
            $validated,
            $student,
            $projectId,
            $request
        ) {
            DB::table('projects')
                ->where('id', $projectId)
                ->update([
                    'course_id' => $validated['course_id'] ?? null,
                    'category_id' => $validated['category_id'] ?? null,
                    'title' => $validated['title'] ?? '',
                    'idea' => $validated['idea'] ?? null,
                    'description' => $validated['description']
                        ?? ($validated['idea'] ?? null),
                    'problem' => $validated['problem'] ?? null,
                    'solution' => $validated['solution'] ?? null,
                    'project_type' => $validated['project_type']
                        ?? 'individual',
                    'github_url' => $validated['github_url'] ?? null,
                    'live_url' => $validated['live_url'] ?? null,
                    'updated_at' => now(),
                ]);

            $this->syncTechnologies(
                $projectId,
                $validated['technologies'] ?? []
            );

            $this->syncMembers(
                $projectId,
                $student,
                $request->user()->name,
                $validated['project_type'] ?? 'individual',
                $validated['members'] ?? []
            );
        });

        return response()->json([
            'message' => 'Project draft updated successfully.',
            'project' => $this->projectData(
                $projectId,
                $student->id
            ),
        ]);
    }

    public function uploadMedia(
        Request $request,
        int $projectId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $project = $this->ownedProject(
            $student->id,
            $projectId
        );

        if (!$project) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        if (
            !in_array(
                $project->status,
                ['draft', 'revision_requested'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This project cannot be edited right now.',
            ], 422);
        }

        $validated = $request->validate([
            'cover_image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            'logo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
            'intro_video' => [
                'nullable',
                'file',
                'mimes:mp4,webm',
                'max:20480',
            ],
            'presentation_file' => [
                'nullable',
                'file',
                'mimes:pdf,ppt,pptx',
                'max:10240',
            ],
            'documentation_file' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx',
                'max:10240',
            ],
        ]);

        $columns = [
            'cover_image',
            'logo',
            'intro_video',
            'presentation_file',
            'documentation_file',
        ];

        $updates = [];

        foreach ($columns as $column) {
            if (!$request->hasFile($column)) {
                continue;
            }

            if ($project->{$column}) {
                Storage::disk('public')->delete(
                    $project->{$column}
                );
            }

            $updates[$column] = $request
                ->file($column)
                ->store(
                    'projects/' . $student->id . '/' . $projectId,
                    'public'
                );
        }

        if ($updates) {
            $updates['updated_at'] = now();

            DB::table('projects')
                ->where('id', $projectId)
                ->update($updates);
        }

        return response()->json([
            'message' => 'Project media updated successfully.',
            'project' => $this->projectData(
                $projectId,
                $student->id
            ),
        ]);
    }

    public function deleteMedia(
        Request $request,
        int $projectId,
        string $type
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $project = $this->ownedProject(
            $student->id,
            $projectId
        );

        if (!$project) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        if (
            !in_array(
                $project->status,
                ['draft', 'revision_requested'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This project cannot be edited right now.',
            ], 422);
        }

        $allowed = [
            'cover_image',
            'logo',
            'intro_video',
            'presentation_file',
            'documentation_file',
        ];

        if (!in_array($type, $allowed, true)) {
            return response()->json([
                'message' => 'Invalid project media type.',
            ], 422);
        }

        if ($project->{$type}) {
            Storage::disk('public')->delete(
                $project->{$type}
            );

            DB::table('projects')
                ->where('id', $projectId)
                ->update([
                    $type => null,
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'message' => 'Project media removed successfully.',
            'project' => $this->projectData(
                $projectId,
                $student->id
            ),
        ]);
    }

    public function submit(
        Request $request,
        int $projectId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $project = $this->ownedProject(
            $student->id,
            $projectId
        );

        if (!$project) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        if (
            !in_array(
                $project->status,
                ['draft', 'revision_requested'],
                true
            )
        ) {
            return response()->json([
                'message' => 'This project cannot be submitted right now.',
            ], 422);
        }

        $errors = [];

        if (!trim((string) $project->title)) {
            $errors['title'][] = 'Project title is required.';
        }

        if (!$project->category_id) {
            $errors['category_id'][] = 'Project category is required.';
        }

        if (!trim((string) $project->idea)) {
            $errors['idea'][] = 'Project idea is required.';
        }

        if ($project->project_type === 'team') {
            $memberCount = DB::table('project_members')
                ->where('project_id', $projectId)
                ->where('role', 'member')
                ->count();

            if ($memberCount < 1) {
                $errors['members'][] =
                    'Add at least one team member.';
            }
        }

        if ($errors) {
            return response()->json([
                'message' => 'Project validation failed.',
                'errors' => $errors,
            ], 422);
        }

        DB::table('projects')
            ->where('id', $projectId)
            ->update([
                'status' => 'in_review',
                'submitted_for_review_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Project submitted for review successfully.',
            'project' => $this->projectData(
                $projectId,
                $student->id
            ),
        ]);
    }

    public function destroy(
        Request $request,
        int $projectId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $project = $this->ownedProject(
            $student->id,
            $projectId
        );

        if (!$project) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        if ($project->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft projects can be deleted.',
            ], 422);
        }

        foreach (
            [
                'cover_image',
                'logo',
                'intro_video',
                'presentation_file',
                'documentation_file',
            ] as $column
        ) {
            if ($project->{$column}) {
                Storage::disk('public')->delete(
                    $project->{$column}
                );
            }
        }

        DB::table('projects')
            ->where('id', $projectId)
            ->delete();

        return response()->json([
            'message' => 'Project draft deleted successfully.',
        ]);
    }

    public function toggleLike(
        Request $request,
        int $projectId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        if (!$this->publishedProject($projectId)) {
            return response()->json([
                'message' => 'Published project not found.',
            ], 404);
        }

        $like = DB::table('project_likes')
            ->where('project_id', $projectId)
            ->where('student_id', $student->id)
            ->first();

        if ($like) {
            DB::table('project_likes')
                ->where('id', $like->id)
                ->delete();

            $isLiked = false;
        } else {
            DB::table('project_likes')->insert([
                'project_id' => $projectId,
                'student_id' => $student->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $isLiked = true;
        }

        return response()->json([
            'is_liked' => $isLiked,
            'likes_count' => DB::table('project_likes')
                ->where('project_id', $projectId)
                ->count(),
        ]);
    }

    public function rate(
        Request $request,
        int $projectId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        if (!$this->publishedProject($projectId)) {
            return response()->json([
                'message' => 'Published project not found.',
            ], 404);
        }

        $validated = $request->validate([
            'rating' => [
                'required',
                'integer',
                'min:1',
                'max:5',
            ],
        ]);

        DB::table('project_ratings')->updateOrInsert(
            [
                'project_id' => $projectId,
                'student_id' => $student->id,
            ],
            [
                'rating' => $validated['rating'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $stats = DB::table('project_ratings')
            ->where('project_id', $projectId)
            ->selectRaw(
                'ROUND(AVG(rating), 1) as average, COUNT(*) as count'
            )
            ->first();

        return response()->json([
            'my_rating' => (int) $validated['rating'],
            'rating_average' => (float) ($stats->average ?? 0),
            'rating_count' => (int) ($stats->count ?? 0),
        ]);
    }

    private function validateDraft(
        Request $request,
        int $studentId
    ): array {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:191'],
            'course_id' => [
                'nullable',
                'integer',
                Rule::exists('enrollments', 'course_id')
                    ->where(
                        fn ($query) => $query
                            ->where('student_id', $studentId)
                            ->whereIn(
                                'status',
                                ['active', 'completed']
                            )
                    ),
            ],
            'category_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
            ],
            'project_type' => [
                'nullable',
                Rule::in(['individual', 'team']),
            ],
            'idea' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'problem' => ['nullable', 'string'],
            'solution' => ['nullable', 'string'],
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
            'technologies' => ['nullable', 'array', 'max:30'],
            'technologies.*' => [
                'string',
                'max:191',
            ],
            'members' => ['nullable', 'array', 'max:20'],
            'members.*.name' => [
                'required_with:members',
                'string',
                'max:191',
            ],
            'members.*.role' => [
                'nullable',
                'string',
                'max:191',
            ],
            'members.*.specialty' => [
                'nullable',
                'string',
                'max:191',
            ],
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

    private function ownedProject(
        int $studentId,
        int $projectId
    ) {
        return DB::table('projects')
            ->where('id', $projectId)
            ->where('owner_student_id', $studentId)
            ->first();
    }

    private function publishedProject(int $projectId)
    {
        return DB::table('projects')
            ->where('id', $projectId)
            ->where('status', 'published')
            ->first();
    }

    private function syncTechnologies(
        int $projectId,
        array $technologies
    ): void {
        DB::table('project_technology')
            ->where('project_id', $projectId)
            ->delete();

        foreach (
            collect($technologies)
                ->map(fn ($name) => trim((string) $name))
                ->filter()
                ->unique(fn ($name) => mb_strtolower($name))
            as $name
        ) {
            $technology = DB::table('technologies')
                ->whereRaw('LOWER(name) = ?', [
                    mb_strtolower($name),
                ])
                ->first();

            $technologyId = $technology?->id
                ?? DB::table('technologies')
                    ->insertGetId([
                        'name' => $name,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

            DB::table('project_technology')->insert([
                'project_id' => $projectId,
                'technology_id' => $technologyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function syncMembers(
        int $projectId,
        $student,
        string $ownerName,
        string $projectType,
        array $members
    ): void {
        DB::table('project_members')
            ->where('project_id', $projectId)
            ->delete();

        DB::table('project_members')->insert([
            'project_id' => $projectId,
            'student_id' => $student->id,
            'member_name' => $ownerName,
            'role' => 'owner',
            'project_role' => 'Project owner',
            'specialty' => null,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($projectType !== 'team') {
            return;
        }

        foreach ($members as $member) {
            DB::table('project_members')->insert([
                'project_id' => $projectId,
                'student_id' => null,
                'member_name' => trim($member['name']),
                'role' => 'member',
                'project_role' => $member['role'] ?? null,
                'specialty' => $member['specialty'] ?? null,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function projectData(
        int $projectId,
        int $viewerStudentId
    ): ?array {
        $project = DB::table('projects as p')
            ->leftJoin('students as s', 's.id', '=', 'p.owner_student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('courses as c', 'c.id', '=', 'p.course_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')
            ->where('p.id', $projectId)
            ->select(
                'p.*',
                'u.name as owner_name',
                'u.email as owner_email',
                'c.title as course_title',
                'cat.name as category_name',
                'cat.slug as category_slug'
            )
            ->first();

        if (!$project) {
            return null;
        }

        $technologies = DB::table('project_technology as pt')
            ->join(
                'technologies as t',
                't.id',
                '=',
                'pt.technology_id'
            )
            ->where('pt.project_id', $projectId)
            ->orderBy('t.name')
            ->pluck('t.name')
            ->values();

        $members = DB::table('project_members as pm')
            ->leftJoin('students as s', 's.id', '=', 'pm.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('pm.project_id', $projectId)
            ->orderByRaw(
                "CASE WHEN pm.role = 'owner' THEN 0 ELSE 1 END"
            )
            ->orderBy('pm.id')
            ->select(
                'pm.id',
                'pm.role',
                'pm.project_role',
                'pm.specialty',
                'pm.member_name',
                'u.name as student_name'
            )
            ->get()
            ->map(function ($member) {
                return [
                    'id' => $member->id,
                    'name' => $member->student_name
                        ?: $member->member_name,
                    'membership_role' => $member->role,
                    'role' => $member->project_role
                        ?: (
                            $member->role === 'owner'
                                ? 'Project owner'
                                : 'Team member'
                        ),
                    'specialty' => $member->specialty,
                ];
            })
            ->values();

        $latestReview = DB::table('project_reviews')
            ->where('project_id', $projectId)
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->first();

        $ratingStats = DB::table('project_ratings')
            ->where('project_id', $projectId)
            ->selectRaw(
                'ROUND(AVG(rating), 1) as average, COUNT(*) as count'
            )
            ->first();

        $myRating = DB::table('project_ratings')
            ->where('project_id', $projectId)
            ->where('student_id', $viewerStudentId)
            ->value('rating');

        $isLiked = DB::table('project_likes')
            ->where('project_id', $projectId)
            ->where('student_id', $viewerStudentId)
            ->exists();

        $isOwner =
            (int) $project->owner_student_id ===
            (int) $viewerStudentId;

        return [
            'id' => $project->id,
            'title' => $project->title,
            'idea' => $project->idea,
            'description' => $project->description,
            'problem' => $project->problem,
            'solution' => $project->solution,
            'project_type' => $project->project_type,
            'status' => $project->status,
            'is_featured' => (bool) $project->is_featured,
            'submitted_for_review_at' =>
                $project->submitted_for_review_at,
            'published_at' => $project->published_at,
            'created_at' => $project->created_at,
            'updated_at' => $project->updated_at,
            'owner' => [
                'id' => $project->owner_student_id,
                'name' => $project->owner_name
                    ?: $project->author_name,
                'email' => $project->owner_email,
            ],
            'course' => $project->course_id
                ? [
                    'id' => $project->course_id,
                    'title' => $project->course_title,
                ]
                : null,
            'category' => $project->category_id
                ? [
                    'id' => $project->category_id,
                    'name' => $project->category_name,
                    'slug' => $project->category_slug,
                ]
                : null,
            'technologies' => $technologies,
            'members' => $members,
            'media' => [
                'cover_image' => $this->fileUrl(
                    $project->cover_image
                ),
                'logo' => $this->fileUrl(
                    $project->logo
                ),
                'intro_video' => $this->fileUrl(
                    $project->intro_video
                ),
                'presentation_file' => $this->fileUrl(
                    $project->presentation_file
                ),
                'documentation_file' => $this->fileUrl(
                    $project->documentation_file
                ),
            ],
            'links' => [
                'github' => $project->github_url,
                'demo' => $project->live_url,
            ],
            'review' => $latestReview
                ? [
                    'status' => $latestReview->status,
                    'feedback' => $latestReview->feedback,
                    'reviewed_at' => $latestReview->reviewed_at,
                ]
                : null,
            'reactions' => [
                'likes_count' => DB::table('project_likes')
                    ->where('project_id', $projectId)
                    ->count(),
                'is_liked' => $isLiked,
                'rating_average' => (float) (
                    $ratingStats->average ?? 0
                ),
                'rating_count' => (int) (
                    $ratingStats->count ?? 0
                ),
                'my_rating' => (int) ($myRating ?? 0),
            ],
            'permissions' => [
                'is_owner' => $isOwner,
                'can_edit' => $isOwner &&
                    in_array(
                        $project->status,
                        ['draft', 'revision_requested'],
                        true
                    ),
                'can_delete' => $isOwner &&
                    $project->status === 'draft',
                'can_submit' => $isOwner &&
                    in_array(
                        $project->status,
                        ['draft', 'revision_requested'],
                        true
                    ),
            ],
        ];
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

        return asset(
            'storage/' . ltrim($path, '/')
        );
    }
}
