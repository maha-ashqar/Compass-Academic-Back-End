<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TrainerCourseController extends Controller
{
    public function index(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $courseIds = DB::table('courses')
            ->where('trainer_id', $trainer->id)
            ->orderByDesc('updated_at')
            ->pluck('id');

        return response()->json([
            'courses' => $courseIds
                ->map(
                    fn ($courseId) =>
                        $this->courseData(
                            (int) $courseId,
                            (int) $trainer->id
                        )
                )
                ->filter()
                ->values(),
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
            'title' => [
                'required',
                'string',
                'max:191',
            ],
            'category' => [
                'required',
                'string',
                'max:191',
            ],
            'level' => [
                'required',
                'string',
                Rule::in([
                    'beginner',
                    'intermediate',
                    'advanced',
                ]),
            ],
            'duration_weeks' => [
                'nullable',
                'integer',
                'min:1',
                'max:520',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'cover_image' => [
                'nullable',
                'string',
                'max:2048',
            ],
        ]);

        $categoryId = $this->categoryId(
            $validated['category']
        );

        $courseId = DB::table('courses')
            ->insertGetId([
                'trainer_id' => $trainer->id,
                'category_id' => $categoryId,
                'title' => trim($validated['title']),
                'slug' => $this->uniqueCourseSlug(
                    $validated['title']
                ),
                'description' =>
                    $validated['description']
                    ?? null,
                'level' => $validated['level'],
                'duration_weeks' =>
                    $validated['duration_weeks']
                    ?? null,
                'cover_image' =>
                    $validated['cover_image']
                    ?? null,
                'status' => 'draft',
                'published_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' =>
                'Course created successfully.',
            'course' =>
                $this->courseData(
                    $courseId,
                    (int) $trainer->id
                ),
        ], 201);
    }

    public function update(
        Request $request,
        int $courseId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $course = $this->courseForTrainer(
            $trainer->id,
            $courseId
        );

        if (!$course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:191',
            ],
            'category' => [
                'required',
                'string',
                'max:191',
            ],
            'level' => [
                'required',
                'string',
                Rule::in([
                    'beginner',
                    'intermediate',
                    'advanced',
                ]),
            ],
            'duration_weeks' => [
                'nullable',
                'integer',
                'min:1',
                'max:520',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'cover_image' => [
                'nullable',
                'string',
                'max:2048',
            ],
        ]);

        $categoryId = $this->categoryId(
            $validated['category']
        );

        DB::table('courses')
            ->where('id', $courseId)
            ->update([
                'category_id' => $categoryId,
                'title' => trim($validated['title']),
                'description' =>
                    $validated['description']
                    ?? null,
                'level' => $validated['level'],
                'duration_weeks' =>
                    $validated['duration_weeks']
                    ?? null,
                'cover_image' =>
                    $validated['cover_image']
                    ?? null,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' =>
                'Course updated successfully.',
            'course' =>
                $this->courseData(
                    $courseId,
                    (int) $trainer->id
                ),
        ]);
    }

    public function publish(
        Request $request,
        int $courseId
    ) {
        return $this->changeStatus(
            $request,
            $courseId,
            'published'
        );
    }

    public function hide(
        Request $request,
        int $courseId
    ) {
        return $this->changeStatus(
            $request,
            $courseId,
            'hidden'
        );
    }

    public function archive(
        Request $request,
        int $courseId
    ) {
        return $this->changeStatus(
            $request,
            $courseId,
            'archived'
        );
    }

    public function duplicate(
        Request $request,
        int $courseId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $course = $this->courseForTrainer(
            $trainer->id,
            $courseId
        );

        if (!$course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $newCourseId = DB::transaction(
            function () use (
                $course,
                $trainer
            ) {
                $newCourseId = DB::table('courses')
                    ->insertGetId([
                        'trainer_id' =>
                            $trainer->id,
                        'category_id' =>
                            $course->category_id,
                        'title' =>
                            $course->title
                            . ' Copy',
                        'slug' =>
                            $this->uniqueCourseSlug(
                                $course->title
                                . ' Copy'
                            ),
                        'description' =>
                            $course->description,
                        'level' =>
                            $course->level,
                        'duration_weeks' =>
                            $course->duration_weeks,
                        'cover_image' =>
                            $course->cover_image,
                        'status' => 'draft',
                        'published_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                $modules = DB::table(
                    'course_modules'
                )
                    ->where(
                        'course_id',
                        $course->id
                    )
                    ->orderBy('position')
                    ->get();

                foreach ($modules as $module) {
                    $newModuleId = DB::table(
                        'course_modules'
                    )->insertGetId([
                        'course_id' =>
                            $newCourseId,
                        'title' =>
                            $module->title,
                        'position' =>
                            $module->position,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $lessons = DB::table(
                        'lessons'
                    )
                        ->where(
                            'course_module_id',
                            $module->id
                        )
                        ->orderBy('position')
                        ->get();

                    foreach ($lessons as $lesson) {
                        DB::table('lessons')
                            ->insert([
                                'course_module_id' =>
                                    $newModuleId,
                                'title' =>
                                    $lesson->title,
                                'description' =>
                                    $lesson->description,
                                'type' =>
                                    $lesson->type,
                                'content_url' =>
                                    $lesson->content_url,
                                'duration_minutes' =>
                                    $lesson->duration_minutes,
                                'position' =>
                                    $lesson->position,
                                'is_published' =>
                                    $lesson->is_published,
                                'created_at' =>
                                    now(),
                                'updated_at' =>
                                    now(),
                            ]);
                    }
                }

                foreach (
                    DB::table(
                        'course_requirements'
                    )
                        ->where(
                            'course_id',
                            $course->id
                        )
                        ->get()
                    as $requirement
                ) {
                    DB::table(
                        'course_requirements'
                    )->insert([
                        'course_id' =>
                            $newCourseId,
                        'requirement' =>
                            $requirement
                                ->requirement,
                        'position' =>
                            $requirement
                                ->position,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach (
                    DB::table(
                        'course_learning_outcomes'
                    )
                        ->where(
                            'course_id',
                            $course->id
                        )
                        ->get()
                    as $outcome
                ) {
                    DB::table(
                        'course_learning_outcomes'
                    )->insert([
                        'course_id' =>
                            $newCourseId,
                        'title' =>
                            $outcome->title,
                        'position' =>
                            $outcome->position,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach (
                    DB::table(
                        'course_resources'
                    )
                        ->where(
                            'course_id',
                            $course->id
                        )
                        ->get()
                    as $resource
                ) {
                    DB::table(
                        'course_resources'
                    )->insert([
                        'course_id' =>
                            $newCourseId,
                        'title' =>
                            $resource->title,
                        'type' =>
                            $resource->type,
                        'url' =>
                            $resource->url,
                        'file_path' =>
                            $resource->file_path,
                        'position' =>
                            $resource->position,
                        'is_published' =>
                            $resource->is_published,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $newCourseId;
            }
        );

        return response()->json([
            'message' =>
                'Course duplicated successfully.',
            'course' =>
                $this->courseData(
                    $newCourseId,
                    (int) $trainer->id
                ),
        ], 201);
    }

    public function destroy(
        Request $request,
        int $courseId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $course = $this->courseForTrainer(
            $trainer->id,
            $courseId
        );

        if (!$course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $request->validate([
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $hasEnrollments = DB::table(
            'enrollments'
        )
            ->where(
                'course_id',
                $courseId
            )
            ->exists();

        if ($hasEnrollments) {
            return response()->json([
                'message' =>
                    'This course has enrollment history and cannot be deleted. Archive it instead.',
            ], 422);
        }

        DB::table('courses')
            ->where('id', $courseId)
            ->delete();

        return response()->json([
            'message' =>
                'Course deleted successfully.',
        ]);
    }

    public function storeModule(
        Request $request,
        int $courseId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        if (
            !$this->courseForTrainer(
                $trainer->id,
                $courseId
            )
        ) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:191',
            ],
        ]);

        $position = DB::table(
            'course_modules'
        )
            ->where(
                'course_id',
                $courseId
            )
            ->max('position');

        $moduleId = DB::table(
            'course_modules'
        )->insertGetId([
            'course_id' => $courseId,
            'title' => trim(
                $validated['title']
            ),
            'position' =>
                ((int) $position) + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' =>
                'Module created successfully.',
            'module' =>
                DB::table('course_modules')
                    ->where(
                        'id',
                        $moduleId
                    )
                    ->first(),
            'course' =>
                $this->courseData(
                    $courseId,
                    (int) $trainer->id
                ),
        ], 201);
    }

    public function reorderModules(
        Request $request,
        int $courseId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        if (
            !$this->courseForTrainer(
                $trainer->id,
                $courseId
            )
        ) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $validated = $request->validate([
            'module_ids' => [
                'required',
                'array',
            ],
            'module_ids.*' => [
                'required',
                'integer',
            ],
        ]);

        $existingIds = DB::table(
            'course_modules'
        )
            ->where(
                'course_id',
                $courseId
            )
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values();

        $requestedIds = collect(
            $validated['module_ids']
        )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();

        if (
            $existingIds->values()->all()
            !==
            $requestedIds->values()->all()
        ) {
            return response()->json([
                'message' =>
                    'Module order is invalid.',
            ], 422);
        }

        DB::transaction(
            function () use ($validated) {
                foreach (
                    $validated['module_ids']
                    as $index => $moduleId
                ) {
                    DB::table(
                        'course_modules'
                    )
                        ->where(
                            'id',
                            $moduleId
                        )
                        ->update([
                            'position' =>
                                $index + 1,
                            'updated_at' =>
                                now(),
                        ]);
                }
            }
        );

        return response()->json([
            'message' =>
                'Modules reordered successfully.',
            'course' =>
                $this->courseData(
                    $courseId,
                    (int) $trainer->id
                ),
        ]);
    }

    public function storeLesson(
        Request $request,
        int $courseId,
        int $moduleId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        if (
            !$this->courseForTrainer(
                $trainer->id,
                $courseId
            )
        ) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $module = DB::table(
            'course_modules'
        )
            ->where('id', $moduleId)
            ->where(
                'course_id',
                $courseId
            )
            ->first();

        if (!$module) {
            return response()->json([
                'message' => 'Module not found.',
            ], 404);
        }

        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:191',
            ],
            'type' => [
                'required',
                Rule::in([
                    'video',
                    'file',
                    'link',
                    'activity',
                    'practice',
                ]),
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'content_url' => [
                'nullable',
                'string',
                'max:191',
            ],
            'duration_minutes' => [
                'nullable',
                'integer',
                'min:1',
                'max:100000',
            ],
            'is_published' => [
                'nullable',
                'boolean',
            ],
        ]);

        $position = DB::table('lessons')
            ->where(
                'course_module_id',
                $moduleId
            )
            ->max('position');

        $lessonId = DB::table('lessons')
            ->insertGetId([
                'course_module_id' =>
                    $moduleId,
                'title' =>
                    trim($validated['title']),
                'description' =>
                    $validated['description']
                    ?? null,
                'type' =>
                    $validated['type']
                    === 'practice'
                    ? 'activity'
                    : $validated['type'],
                'content_url' =>
                    $validated['content_url']
                    ?? null,
                'duration_minutes' =>
                    $validated[
                        'duration_minutes'
                    ] ?? null,
                'position' =>
                    ((int) $position) + 1,
                'is_published' =>
                    $validated['is_published']
                    ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' =>
                'Lesson created successfully.',
            'lesson_id' => $lessonId,
            'course' =>
                $this->courseData(
                    $courseId,
                    (int) $trainer->id
                ),
        ], 201);
    }

    public function updateLesson(
        Request $request,
        int $courseId,
        int $lessonId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        if (
            !$this->courseForTrainer(
                $trainer->id,
                $courseId
            )
        ) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $lesson = DB::table('lessons as l')
            ->join(
                'course_modules as cm',
                'cm.id',
                '=',
                'l.course_module_id'
            )
            ->where(
                'l.id',
                $lessonId
            )
            ->where(
                'cm.course_id',
                $courseId
            )
            ->select('l.*')
            ->first();

        if (!$lesson) {
            return response()->json([
                'message' => 'Lesson not found.',
            ], 404);
        }

        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:191',
            ],
            'type' => [
                'required',
                Rule::in([
                    'video',
                    'file',
                    'link',
                    'activity',
                    'practice',
                ]),
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'content_url' => [
                'nullable',
                'string',
                'max:191',
            ],
            'duration_minutes' => [
                'nullable',
                'integer',
                'min:1',
                'max:100000',
            ],
            'is_published' => [
                'required',
                'boolean',
            ],
        ]);

        DB::table('lessons')
            ->where(
                'id',
                $lessonId
            )
            ->update([
                'title' =>
                    trim($validated['title']),
                'description' =>
                    $validated['description']
                    ?? null,
                'type' =>
                    $validated['type']
                    === 'practice'
                    ? 'activity'
                    : $validated['type'],
                'content_url' =>
                    $validated['content_url']
                    ?? null,
                'duration_minutes' =>
                    $validated[
                        'duration_minutes'
                    ] ?? null,
                'is_published' =>
                    $validated[
                        'is_published'
                    ],
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' =>
                'Lesson updated successfully.',
            'course' =>
                $this->courseData(
                    $courseId,
                    (int) $trainer->id
                ),
        ]);
    }

    public function destroyLesson(
        Request $request,
        int $courseId,
        int $lessonId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        if (
            !$this->courseForTrainer(
                $trainer->id,
                $courseId
            )
        ) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $lesson = DB::table('lessons as l')
            ->join(
                'course_modules as cm',
                'cm.id',
                '=',
                'l.course_module_id'
            )
            ->where(
                'l.id',
                $lessonId
            )
            ->where(
                'cm.course_id',
                $courseId
            )
            ->select(
                'l.id'
            )
            ->first();

        if (!$lesson) {
            return response()->json([
                'message' => 'Lesson not found.',
            ], 404);
        }

        DB::table('lessons')
            ->where(
                'id',
                $lessonId
            )
            ->delete();

        return response()->json([
            'message' =>
                'Lesson deleted successfully.',
            'course' =>
                $this->courseData(
                    $courseId,
                    (int) $trainer->id
                ),
        ]);
    }

    private function changeStatus(
        Request $request,
        int $courseId,
        string $status
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $course = $this->courseForTrainer(
            $trainer->id,
            $courseId
        );

        if (!$course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        DB::table('courses')
            ->where(
                'id',
                $courseId
            )
            ->update([
                'status' => $status,
                'published_at' =>
                    $status === 'published'
                    ? (
                        $course->published_at
                        ?: now()
                    )
                    : $course->published_at,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' =>
                'Course status updated successfully.',
            'course' =>
                $this->courseData(
                    $courseId,
                    (int) $trainer->id
                ),
        ]);
    }

    private function courseData(
        int $courseId,
        int $trainerId
    ): ?array {
        $course = DB::table('courses as c')
            ->join(
                'categories as cat',
                'cat.id',
                '=',
                'c.category_id'
            )
            ->where(
                'c.id',
                $courseId
            )
            ->where(
                'c.trainer_id',
                $trainerId
            )
            ->first([
                'c.*',
                'cat.name as category_name',
            ]);

        if (!$course) {
            return null;
        }

        $modules = DB::table(
            'course_modules'
        )
            ->where(
                'course_id',
                $courseId
            )
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function ($module) {
                $lessons = DB::table(
                    'lessons'
                )
                    ->where(
                        'course_module_id',
                        $module->id
                    )
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get()
                    ->map(function ($lesson) {
                        $views = DB::table(
                            'lesson_progress'
                        )
                            ->where(
                                'lesson_id',
                                $lesson->id
                            )
                            ->get([
                                'student_id',
                                'progress_percentage',
                            ])
                            ->map(
                                fn ($progress) => [
                                    'studentId' =>
                                        (int) $progress
                                            ->student_id,
                                    'percent' =>
                                        (int) $progress
                                            ->progress_percentage,
                                ]
                            )
                            ->values();

                        return [
                            'id' =>
                                (int) $lesson->id,
                            'title' =>
                                $lesson->title,
                            'type' =>
                                $lesson->type
                                === 'activity'
                                ? 'practice'
                                : $lesson->type,
                            'description' =>
                                $lesson->description
                                ?? '',
                            'url' =>
                                $lesson->content_url
                                ?? '',
                            'duration' =>
                                $lesson
                                    ->duration_minutes
                                ? $lesson
                                    ->duration_minutes
                                    . ' min'
                                : '',
                            'durationMinutes' =>
                                $lesson
                                    ->duration_minutes
                                ? (int) $lesson
                                    ->duration_minutes
                                : null,
                            'position' =>
                                (int) $lesson
                                    ->position,
                            'published' =>
                                (bool) $lesson
                                    ->is_published,
                            'views' => $views,
                            'comments' => [],
                        ];
                    })
                    ->values();

                return [
                    'id' =>
                        (int) $module->id,
                    'title' =>
                        $module->title,
                    'position' =>
                        (int) $module->position,
                    'lessons' =>
                        $lessons,
                ];
            })
            ->values();

        $students = DB::table(
            'enrollments'
        )
            ->where(
                'course_id',
                $courseId
            )
            ->whereIn(
                'status',
                [
                    'active',
                    'completed',
                ]
            )
            ->count();

        return [
            'id' => (int) $course->id,
            'title' => $course->title,
            'category' =>
                $course->category_name,
            'categoryId' =>
                (int) $course->category_id,
            'level' =>
                ucfirst($course->level),
            'levelValue' =>
                $course->level,
            'duration' =>
                $course->duration_weeks
                ? $course->duration_weeks
                    . ' Weeks'
                : '',
            'durationWeeks' =>
                $course->duration_weeks
                ? (int) $course
                    ->duration_weeks
                : null,
            'description' =>
                $course->description
                ?? '',
            'coverImage' =>
                $this->fileUrl(
                    $course->cover_image
                ),
            'status' =>
                $course->status,
            'students' =>
                $students,
            'publishedAt' =>
                $course->published_at,
            'updatedAt' =>
                $course->updated_at,
            'modules' =>
                $modules,
        ];
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

    private function courseForTrainer(
        int $trainerId,
        int $courseId
    ) {
        return DB::table('courses')
            ->where(
                'id',
                $courseId
            )
            ->where(
                'trainer_id',
                $trainerId
            )
            ->first();
    }

    private function categoryId(
        string $name
    ): int {
        $name = trim($name);

        $category = DB::table(
            'categories'
        )
            ->whereRaw(
                'LOWER(name) = ?',
                [Str::lower($name)]
            )
            ->first();

        if ($category) {
            return (int) $category->id;
        }

        $baseSlug =
            Str::slug($name)
            ?: 'category';

        $slug = $baseSlug;
        $number = 2;

        while (
            DB::table('categories')
                ->where(
                    'slug',
                    $slug
                )
                ->exists()
        ) {
            $slug =
                $baseSlug
                . '-'
                . $number;

            $number++;
        }

        return DB::table(
            'categories'
        )->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function uniqueCourseSlug(
        string $title
    ): string {
        $baseSlug =
            Str::slug($title)
            ?: 'course';

        $slug = $baseSlug;
        $number = 2;

        while (
            DB::table('courses')
                ->where(
                    'slug',
                    $slug
                )
                ->exists()
        ) {
            $slug =
                $baseSlug
                . '-'
                . $number;

            $number++;
        }

        return $slug;
    }

    private function fileUrl(
        ?string $value
    ): ?string {
        if (!$value) {
            return null;
        }

        if (
            Str::startsWith(
                $value,
                [
                    'http://',
                    'https://',
                    'data:',
                ]
            )
        ) {
            return $value;
        }

        return asset(
            'storage/' . $value
        );
    }
}
