<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TrainerAnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $this->publishDueForTrainer((int) $trainer->id);

        $announcements = DB::table('announcements')
            ->where('created_by', $trainer->id)
            ->where('status', '!=', 'archived')
            ->orderByDesc('created_at')
            ->get()
            ->map(
                fn ($announcement) =>
                    $this->announcementData($announcement)
            )
            ->values();

        return response()->json([
            'announcements' => $announcements,
        ]);
    }

    public function show(
        Request $request,
        int $announcementId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $this->publishDueForTrainer((int) $trainer->id);

        $announcement = $this->ownedAnnouncement(
            (int) $trainer->id,
            $announcementId
        );

        if (!$announcement) {
            return response()->json([
                'message' => 'Announcement not found.',
            ], 404);
        }

        return response()->json([
            'announcement' =>
                $this->announcementData($announcement),
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

        $data = $this->validateAnnouncementRequest(
            $request
        );

        $attachment = null;

        if ($request->hasFile('attachment')) {
            $attachment = $this->storeAttachment(
                $request->file('attachment')
            );
        }

        $announcementId = DB::transaction(
            function () use (
                $trainer,
                $data,
                $attachment
            ) {
                $now = now();

                $announcementId =
                    DB::table('announcements')
                        ->insertGetId([
                            'created_by' =>
                                $trainer->id,
                            'title' =>
                                trim($data['title']),
                            'type' =>
                                $this->databaseType(
                                    $data['type']
                                ),
                            'content' =>
                                trim($data['content']),
                            'related_link' =>
                                $this->nullableTrim(
                                    $data['link'] ?? null
                                ),
                            'attachment_path' =>
                                $attachment['path']
                                ?? null,
                            'attachment_name' =>
                                $attachment['name']
                                ?? null,
                            'attachment_size' =>
                                $attachment['size']
                                ?? null,
                            'attachment_mime' =>
                                $attachment['mime']
                                ?? null,
                            'status' => 'draft',
                            'scheduled_at' => null,
                            'published_at' => null,
                            'archived_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                $this->syncAudience(
                    $announcementId,
                    (int) $trainer->id,
                    $data
                );

                return $announcementId;
            }
        );

        $announcement = $this->ownedAnnouncement(
            (int) $trainer->id,
            $announcementId
        );

        return response()->json([
            'message' =>
                'Announcement draft created successfully.',
            'announcement' =>
                $this->announcementData($announcement),
        ], 201);
    }

    public function update(
        Request $request,
        int $announcementId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $announcement = $this->ownedAnnouncement(
            (int) $trainer->id,
            $announcementId
        );

        if (!$announcement) {
            return response()->json([
                'message' => 'Announcement not found.',
            ], 404);
        }

        if ($announcement->status === 'archived') {
            return response()->json([
                'message' =>
                    'Archived announcements cannot be edited.',
            ], 422);
        }

        $data = $this->validateAnnouncementRequest(
            $request
        );

        $newAttachment = null;

        if ($request->hasFile('attachment')) {
            $newAttachment = $this->storeAttachment(
                $request->file('attachment')
            );
        }

        $oldAttachmentPath =
            $announcement->attachment_path;

        DB::transaction(function () use (
            $announcementId,
            $trainer,
            $data,
            $announcement,
            $newAttachment
        ) {
            $updates = [
                'title' => trim($data['title']),
                'type' =>
                    $this->databaseType(
                        $data['type']
                    ),
                'content' =>
                    trim($data['content']),
                'related_link' =>
                    $this->nullableTrim(
                        $data['link'] ?? null
                    ),
                'updated_at' => now(),
            ];

            if ($newAttachment) {
                $updates['attachment_path'] =
                    $newAttachment['path'];

                $updates['attachment_name'] =
                    $newAttachment['name'];

                $updates['attachment_size'] =
                    $newAttachment['size'];

                $updates['attachment_mime'] =
                    $newAttachment['mime'];
            } elseif (
                !empty($data['removeAttachment'])
            ) {
                $updates['attachment_path'] = null;
                $updates['attachment_name'] = null;
                $updates['attachment_size'] = null;
                $updates['attachment_mime'] = null;
            }

            DB::table('announcements')
                ->where('id', $announcementId)
                ->update($updates);

            $this->syncAudience(
                $announcementId,
                (int) $trainer->id,
                $data
            );
        });

        if (
            $oldAttachmentPath &&
            (
                $newAttachment ||
                !empty($data['removeAttachment'])
            )
        ) {
            Storage::disk('public')->delete(
                $oldAttachmentPath
            );
        }

        $announcement = $this->ownedAnnouncement(
            (int) $trainer->id,
            $announcementId
        );

        return response()->json([
            'message' =>
                'Announcement updated successfully.',
            'announcement' =>
                $this->announcementData($announcement),
        ]);
    }

    public function publish(
        Request $request,
        int $announcementId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $announcement = $this->ownedAnnouncement(
            (int) $trainer->id,
            $announcementId
        );

        if (!$announcement) {
            return response()->json([
                'message' => 'Announcement not found.',
            ], 404);
        }

        if ($announcement->status === 'archived') {
            return response()->json([
                'message' =>
                    'Archived announcements cannot be published.',
            ], 422);
        }

        if ($announcement->status !== 'published') {
            $this->publishAnnouncement(
                $announcement,
                (int) $trainer->id
            );
        }

        $announcement = $this->ownedAnnouncement(
            (int) $trainer->id,
            $announcementId
        );

        return response()->json([
            'message' =>
                'Announcement published successfully.',
            'announcement' =>
                $this->announcementData($announcement),
        ]);
    }

    public function schedule(
    Request $request,
    int $announcementId
) {
    $trainer = $this->trainerFromRequest($request);

    if (!$trainer) {
        return response()->json([
            'message' => 'Trainer profile not found.',
        ], 404);
    }

    $announcement = $this->ownedAnnouncement(
        (int) $trainer->id,
        $announcementId
    );

    if (!$announcement) {
        return response()->json([
            'message' => 'Announcement not found.',
        ], 404);
    }

    if ($announcement->status === 'archived') {
        return response()->json([
            'message' =>
                'Archived announcements cannot be scheduled.',
        ], 422);
    }

    if ($announcement->status === 'published') {
        return response()->json([
            'message' =>
                'Published announcements cannot be scheduled again.',
        ], 422);
    }

    $validated = $request->validate([
        'publishAt' => [
            'required',
            'date',
            'after:now',
        ],
    ]);

    $scheduledAt = Carbon::parse(
        $validated['publishAt']
    );

    DB::table('announcements')
        ->where('id', $announcementId)
        ->update([
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
            'archived_at' => null,
            'updated_at' => now(),
        ]);

    $announcement = $this->ownedAnnouncement(
        (int) $trainer->id,
        $announcementId
    );

    return response()->json([
        'message' =>
            'Announcement scheduled successfully.',
        'announcement' =>
            $this->announcementData($announcement),
    ]);
}

    public function archive(
        Request $request,
        int $announcementId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $announcement = $this->ownedAnnouncement(
            (int) $trainer->id,
            $announcementId
        );

        if (!$announcement) {
            return response()->json([
                'message' => 'Announcement not found.',
            ], 404);
        }

        DB::table('announcements')
            ->where('id', $announcementId)
            ->update([
                'status' => 'archived',
                'archived_at' => now(),
                'updated_at' => now(),
            ]);

        $announcement = $this->ownedAnnouncement(
            (int) $trainer->id,
            $announcementId
        );

        return response()->json([
            'message' =>
                'Announcement archived successfully.',
            'announcement' =>
                $this->announcementData($announcement),
        ]);
    }

    public function duplicate(
        Request $request,
        int $announcementId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $source = $this->ownedAnnouncement(
            (int) $trainer->id,
            $announcementId
        );

        if (!$source) {
            return response()->json([
                'message' => 'Announcement not found.',
            ], 404);
        }

        $newAttachmentPath =
            $this->duplicateAttachment(
                $source->attachment_path
            );

        $newId = DB::transaction(
            function () use (
                $source,
                $trainer,
                $newAttachmentPath
            ) {
                $now = now();

                $newId =
                    DB::table('announcements')
                        ->insertGetId([
                            'created_by' =>
                                $trainer->id,
                            'title' =>
                                $source->title .
                                ' (Copy)',
                            'type' => $source->type,
                            'content' =>
                                $source->content,
                            'related_link' =>
                                $source->related_link,
                            'attachment_path' =>
                                $newAttachmentPath,
                            'attachment_name' =>
                                $source->attachment_name,
                            'attachment_size' =>
                                $source->attachment_size,
                            'attachment_mime' =>
                                $source->attachment_mime,
                            'status' => 'draft',
                            'scheduled_at' => null,
                            'published_at' => null,
                            'archived_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                $audiences =
                    DB::table(
                        'announcement_audiences'
                    )
                        ->where(
                            'announcement_id',
                            $source->id
                        )
                        ->get();

                foreach ($audiences as $audience) {
                    DB::table(
                        'announcement_audiences'
                    )->insert([
                        'announcement_id' =>
                            $newId,
                        'audience_type' =>
                            $audience->audience_type,
                        'audience_id' =>
                            $audience->audience_id,
                        'audience_value' =>
                            $audience->audience_value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return $newId;
            }
        );

        $announcement = $this->ownedAnnouncement(
            (int) $trainer->id,
            $newId
        );

        return response()->json([
            'message' =>
                'Announcement duplicated successfully.',
            'announcement' =>
                $this->announcementData($announcement),
        ], 201);
    }

    public function destroy(
        Request $request,
        int $announcementId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $announcement = $this->ownedAnnouncement(
            (int) $trainer->id,
            $announcementId
        );

        if (!$announcement) {
            return response()->json([
                'message' => 'Announcement not found.',
            ], 404);
        }

        if ($announcement->status === 'published') {
            return response()->json([
                'message' =>
                    'Published announcements should be archived instead of deleted.',
            ], 422);
        }

        DB::table('announcements')
            ->where('id', $announcementId)
            ->delete();

        if ($announcement->attachment_path) {
            Storage::disk('public')->delete(
                $announcement->attachment_path
            );
        }

        return response()->json([
            'message' =>
                'Announcement deleted successfully.',
        ]);
    }

    public function stats(
        Request $request,
        int $announcementId
    ) {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $announcement = $this->ownedAnnouncement(
            (int) $trainer->id,
            $announcementId
        );

        if (!$announcement) {
            return response()->json([
                'message' => 'Announcement not found.',
            ], 404);
        }

        return response()->json([
            'stats' =>
                $this->deliveryStats(
                    $announcementId
                ),
        ]);
    }

    public function publishDue(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $count = $this->publishDueForTrainer(
            (int) $trainer->id
        );

        return response()->json([
            'message' =>
                'Scheduled announcements checked successfully.',
            'published_count' => $count,
        ]);
    }

    private function publishDueForTrainer(
        int $trainerId
    ): int {
        $announcements =
            DB::table('announcements')
                ->where('created_by', $trainerId)
                ->where('status', 'scheduled')
                ->whereNotNull('scheduled_at')
                ->where(
                    'scheduled_at',
                    '<=',
                    now()
                )
                ->get();

        foreach ($announcements as $announcement) {
            $this->publishAnnouncement(
                $announcement,
                $trainerId
            );
        }

        return $announcements->count();
    }

    private function publishAnnouncement(
        $announcement,
        int $trainerId
    ): void {
        $studentIds =
            $this->resolveRecipients(
                (int) $announcement->id,
                $trainerId
            );

        $students =
            DB::table('students')
                ->whereIn('id', $studentIds)
                ->get([
                    'id',
                    'user_id',
                ]);

        DB::transaction(function () use (
            $announcement,
            $students
        ) {
            $now = now();

            DB::table('announcements')
                ->where('id', $announcement->id)
                ->update([
                    'status' => 'published',
                    'published_at' =>
                        $announcement->published_at
                        ?: $now,
                    'scheduled_at' => null,
                    'archived_at' => null,
                    'updated_at' => $now,
                ]);

            DB::table('announcement_recipients')
                ->where(
                    'announcement_id',
                    $announcement->id
                )
                ->delete();

            foreach ($students as $student) {
                DB::table(
                    'announcement_recipients'
                )->insert([
                    'announcement_id' =>
                        $announcement->id,
                    'user_id' =>
                        $student->user_id,
                    'notified_at' => $now,
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                NotificationService::createForStudent(
                    (int) $student->id,
                    'announcement',
                    $announcement->title,
                    $announcement->content,
                    [
                        'icon' =>
                            $announcement->type ===
                            'competition'
                                ? '🏆'
                                : '📢',
                        'category' => 'system',
                        'action_label' =>
                            'Read announcement',
                        'action_path' =>
                            '/student-dashboard/announcements/' .
                            $announcement->id,
                        'announcement_id' =>
                            (int) $announcement->id,
                    ]
                );
            }
        });
    }

    private function resolveRecipients(
        int $announcementId,
        int $trainerId
    ): array {
        $audiences =
            DB::table(
                'announcement_audiences'
            )
                ->where(
                    'announcement_id',
                    $announcementId
                )
                ->get();

        $roster =
            $this->trainerRosterStudentIds(
                $trainerId
            );

        $ids = collect();

        foreach ($audiences as $audience) {
            if (
                $audience->audience_type ===
                'all_students'
            ) {
                $ids = $ids->merge($roster);
            }

            if (
                $audience->audience_type ===
                'course' &&
                $audience->audience_id
            ) {
                $ids = $ids->merge(
                    $this->courseStudentIds(
                        $trainerId,
                        (int) $audience->audience_id
                    )
                );
            }

            if (
                $audience->audience_type ===
                'faculty' &&
                $audience->audience_value
            ) {
                $ids = $ids->merge(
                    $this->studentsByEducation(
                        $roster,
                        'faculty',
                        $audience->audience_value
                    )
                );
            }

            if (
                $audience->audience_type ===
                'major' &&
                $audience->audience_value
            ) {
                $ids = $ids->merge(
                    $this->studentsByEducation(
                        $roster,
                        'major',
                        $audience->audience_value
                    )
                );
            }

            if (
                $audience->audience_type ===
                'specific_student' &&
                $audience->audience_id &&
                $roster->contains(
                    (int) $audience->audience_id
                )
            ) {
                $ids->push(
                    (int) $audience->audience_id
                );
            }
        }

        return $ids
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function syncAudience(
        int $announcementId,
        int $trainerId,
        array $data
    ): void {
        DB::table('announcement_audiences')
            ->where(
                'announcement_id',
                $announcementId
            )
            ->delete();

        $type = $data['audienceType'];
        $value =
            $this->nullableTrim(
                $data['audienceValue'] ?? null
            );

        if ($type === 'all') {
            $this->insertAudience(
                $announcementId,
                'all_students'
            );

            return;
        }

        if ($type === 'faculty') {
            if (!$value) {
                throw ValidationException::withMessages([
                    'audienceValue' =>
                        'Faculty is required.',
                ]);
            }

            $this->insertAudience(
                $announcementId,
                'faculty',
                null,
                $value
            );

            return;
        }

        if ($type === 'major') {
            if (!$value) {
                throw ValidationException::withMessages([
                    'audienceValue' =>
                        'Major is required.',
                ]);
            }

            $this->insertAudience(
                $announcementId,
                'major',
                null,
                $value
            );

            return;
        }

        if ($type === 'course') {
            if (!$value) {
                throw ValidationException::withMessages([
                    'audienceValue' =>
                        'Course is required.',
                ]);
            }

            $course =
                DB::table('courses')
                    ->where(
                        'trainer_id',
                        $trainerId
                    )
                    ->where(function ($query) use (
                        $value
                    ) {
                        if (ctype_digit($value)) {
                            $query->where(
                                'id',
                                (int) $value
                            )->orWhere(
                                'title',
                                $value
                            );
                        } else {
                            $query->where(
                                'title',
                                $value
                            );
                        }
                    })
                    ->first();

            if (!$course) {
                throw ValidationException::withMessages([
                    'audienceValue' =>
                        'Selected course was not found.',
                ]);
            }

            $this->insertAudience(
                $announcementId,
                'course',
                (int) $course->id,
                $course->title
            );

            return;
        }

        if ($type === 'students') {
            $ids = collect(
                $data['audienceStudentIds'] ?? []
            )
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($ids->isEmpty()) {
                throw ValidationException::withMessages([
                    'audienceStudentIds' =>
                        'Select at least one student.',
                ]);
            }

            $roster =
                $this->trainerRosterStudentIds(
                    $trainerId
                );

            $invalid =
                $ids->diff($roster);

            if ($invalid->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'audienceStudentIds' =>
                        'One or more selected students are not in your roster.',
                ]);
            }

            foreach ($ids as $studentId) {
                $this->insertAudience(
                    $announcementId,
                    'specific_student',
                    $studentId
                );
            }
        }
    }

    private function insertAudience(
        int $announcementId,
        string $type,
        ?int $id = null,
        ?string $value = null
    ): void {
        DB::table('announcement_audiences')
            ->insert([
                'announcement_id' =>
                    $announcementId,
                'audience_type' => $type,
                'audience_id' => $id,
                'audience_value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function trainerRosterStudentIds(
        int $trainerId
    ): Collection {
        return DB::table('enrollments as e')
            ->join(
                'courses as c',
                'c.id',
                '=',
                'e.course_id'
            )
            ->where(
                'c.trainer_id',
                $trainerId
            )
            ->whereIn(
                'e.status',
                ['active', 'completed']
            )
            ->pluck('e.student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function courseStudentIds(
        int $trainerId,
        int $courseId
    ): Collection {
        return DB::table('enrollments as e')
            ->join(
                'courses as c',
                'c.id',
                '=',
                'e.course_id'
            )
            ->where(
                'c.trainer_id',
                $trainerId
            )
            ->where(
                'c.id',
                $courseId
            )
            ->whereIn(
                'e.status',
                ['active', 'completed']
            )
            ->pluck('e.student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function studentsByEducation(
        Collection $roster,
        string $column,
        string $value
    ): Collection {
        if (
            !in_array(
                $column,
                ['faculty', 'major'],
                true
            )
        ) {
            return collect();
        }

        if ($roster->isEmpty()) {
            return collect();
        }

        return DB::table('student_educations')
            ->whereIn(
                'student_id',
                $roster->all()
            )
            ->where($column, $value)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function announcementData(
        $announcement
    ): array {
        $audience =
            $this->audienceData(
                (int) $announcement->id
            );

        $stats =
            $this->deliveryStats(
                (int) $announcement->id
            );

        $author =
            DB::table('trainers as t')
                ->join(
                    'users as u',
                    'u.id',
                    '=',
                    't.user_id'
                )
                ->where(
                    't.id',
                    $announcement->created_by
                )
                ->value('u.name');

        $readBy =
            DB::table(
                'announcement_recipients as ar'
            )
                ->join(
                    'students as s',
                    's.user_id',
                    '=',
                    'ar.user_id'
                )
                ->where(
                    'ar.announcement_id',
                    $announcement->id
                )
                ->whereNotNull('ar.read_at')
                ->pluck('s.id')
                ->map(fn ($id) => (int) $id)
                ->values();

        return [
            'id' => (int) $announcement->id,
            'title' => $announcement->title,
            'content' =>
                $announcement->content,
            'type' =>
                $this->frontendType(
                    $announcement->type
                ),
            'status' =>
                $announcement->status,
            'audienceType' =>
                $audience['audienceType'],
            'audienceValue' =>
                $audience['audienceValue'],
            'audienceLabel' =>
                $audience['audienceLabel'],
            'audienceStudentIds' =>
                $audience[
                    'audienceStudentIds'
                ],
            'link' =>
                $announcement->related_link,
            'attachment' =>
                $this->attachmentData(
                    $announcement
                ),
            'publishAt' =>
                $announcement->scheduled_at,
            'createdAt' =>
                $announcement->created_at,
            'updatedAt' =>
                $announcement->updated_at,
            'publishedAt' =>
                $announcement->published_at,
            'archivedAt' =>
                $announcement->archived_at,
            'author' =>
                $author ?: 'Trainer',
            'recipients' =>
                $stats['recipients'],
            'readBy' => $readBy,
        ];
    }

    private function audienceData(
        int $announcementId
    ): array {
        $rows =
            DB::table(
                'announcement_audiences'
            )
                ->where(
                    'announcement_id',
                    $announcementId
                )
                ->get();

        if ($rows->isEmpty()) {
            return [
                'audienceType' => 'all',
                'audienceValue' => '',
                'audienceLabel' =>
                    'All students',
                'audienceStudentIds' => [],
            ];
        }

        $first = $rows->first();

        if (
            $first->audience_type ===
            'all_students'
        ) {
            return [
                'audienceType' => 'all',
                'audienceValue' => '',
                'audienceLabel' =>
                    'All students',
                'audienceStudentIds' => [],
            ];
        }

        if (
            $first->audience_type ===
            'faculty'
        ) {
            return [
                'audienceType' => 'faculty',
                'audienceValue' =>
                    $first->audience_value
                    ?: '',
                'audienceLabel' =>
                    $first->audience_value
                    ?: 'Faculty',
                'audienceStudentIds' => [],
            ];
        }

        if (
            $first->audience_type ===
            'major'
        ) {
            return [
                'audienceType' => 'major',
                'audienceValue' =>
                    $first->audience_value
                    ?: '',
                'audienceLabel' =>
                    $first->audience_value
                    ?: 'Major',
                'audienceStudentIds' => [],
            ];
        }

        if (
            $first->audience_type ===
            'course'
        ) {
            $title =
                $first->audience_value
                ?: DB::table('courses')
                    ->where(
                        'id',
                        $first->audience_id
                    )
                    ->value('title');

            return [
                'audienceType' => 'course',
                'audienceValue' =>
                    $title ?: '',
                'audienceLabel' =>
                    $title ?: 'Course students',
                'audienceStudentIds' => [],
            ];
        }

        $studentIds =
            $rows
                ->where(
                    'audience_type',
                    'specific_student'
                )
                ->pluck('audience_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values();

        $names =
            DB::table('students as s')
                ->join(
                    'users as u',
                    'u.id',
                    '=',
                    's.user_id'
                )
                ->whereIn(
                    's.id',
                    $studentIds->all()
                )
                ->orderBy('u.name')
                ->pluck('u.name');

        return [
            'audienceType' => 'students',
            'audienceValue' => '',
            'audienceLabel' =>
                $names->isNotEmpty()
                    ? $names->implode(', ')
                    : 'Selected students',
            'audienceStudentIds' =>
                $studentIds,
        ];
    }

    private function deliveryStats(
        int $announcementId
    ): array {
        $query =
            DB::table(
                'announcement_recipients'
            )
                ->where(
                    'announcement_id',
                    $announcementId
                );

        return [
            'recipients' =>
                (clone $query)->count(),
            'views' =>
                (clone $query)
                    ->whereNotNull('read_at')
                    ->count(),
        ];
    }

    private function validateAnnouncementRequest(
        Request $request
    ): array {
        $data = $request->validate([
            'title' => [
                'required',
                'string',
                'max:191',
            ],
            'content' => [
                'required',
                'string',
            ],
            'type' => [
                'required',
                Rule::in([
                    'Policy',
                    'Competition',
                    'Faculty instructions',
                    'General',
                ]),
            ],
            'audienceType' => [
                'required',
                Rule::in([
                    'all',
                    'faculty',
                    'major',
                    'course',
                    'students',
                ]),
            ],
            'audienceValue' => [
                'nullable',
                'string',
                'max:191',
            ],
            'audienceStudentIds' => [
                'nullable',
                'array',
            ],
            'audienceStudentIds.*' => [
                'integer',
                'exists:students,id',
            ],
            'link' => [
                'nullable',
                'string',
                'max:191',
            ],
            'publishAt' => [
                'nullable',
                'date',
            ],
            'attachment' => [
                'nullable',
                'file',
                'mimes:pdf,doc,docx,ppt,pptx,zip,jpg,jpeg,png,webp',
                'max:5120',
            ],
            'removeAttachment' => [
                'nullable',
                'boolean',
            ],
        ]);

        if (
            in_array(
                $data['audienceType'],
                ['faculty', 'major', 'course'],
                true
            ) &&
            !$this->nullableTrim(
                $data['audienceValue'] ?? null
            )
        ) {
            throw ValidationException::withMessages([
                'audienceValue' =>
                    'Audience value is required.',
            ]);
        }

        if (
            $data['audienceType'] ===
            'students' &&
            empty($data['audienceStudentIds'])
        ) {
            throw ValidationException::withMessages([
                'audienceStudentIds' =>
                    'Select at least one student.',
            ]);
        }

        return $data;
    }

    private function storeAttachment(
        $file
    ): array {
        $original =
            $file->getClientOriginalName();

        $safe =
            preg_replace(
                '/[^A-Za-z0-9._-]/',
                '_',
                $original
            );

        $filename =
            Str::uuid()->toString() .
            '_' .
            $safe;

        $path =
            $file->storeAs(
                'announcements',
                $filename,
                'public'
            );

        return [
            'path' => $path,
            'name' => $original,
            'size' => $file->getSize(),
            'mime' =>
                $file->getClientMimeType(),
        ];
    }

    private function duplicateAttachment(
        ?string $path
    ): ?string {
        if (
            !$path ||
            !Storage::disk('public')->exists(
                $path
            )
        ) {
            return null;
        }

        $extension =
            pathinfo(
                $path,
                PATHINFO_EXTENSION
            );

        $newPath =
            'announcements/' .
            Str::uuid()->toString() .
            ($extension
                ? '.' . $extension
                : '');

        Storage::disk('public')->copy(
            $path,
            $newPath
        );

        return $newPath;
    }

    private function attachmentData(
        $announcement
    ): ?array {
        if (!$announcement->attachment_path) {
            return null;
        }

        return [
            'name' =>
                $announcement->attachment_name
                ?: basename(
                    $announcement->attachment_path
                ),
            'size' =>
                $this->formatSize(
                    (int) (
                        $announcement->attachment_size
                        ?? 0
                    )
                ),
            'type' =>
                $announcement->attachment_mime,
            'dataUrl' =>
                asset(
                    'storage/' .
                    $announcement->attachment_path
                ),
        ];
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        if ($bytes >= 1048576) {
            return round(
                $bytes / 1048576,
                1
            ) . ' MB';
        }

        return (int) ceil(
            $bytes / 1024
        ) . ' KB';
    }

    private function databaseType(
        string $type
    ): string {
        return match ($type) {
            'Policy' => 'policy',
            'Competition' => 'competition',
            'Faculty instructions' =>
                'faculty_instructions',
            default => 'general',
        };
    }

    private function frontendType(
        string $type
    ): string {
        return match ($type) {
            'policy' => 'Policy',
            'competition' => 'Competition',
            'faculty_instructions' =>
                'Faculty instructions',
            default => 'General',
        };
    }

    private function nullableTrim(
        $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }

    private function ownedAnnouncement(
        int $trainerId,
        int $announcementId
    ) {
        return DB::table('announcements')
            ->where('id', $announcementId)
            ->where('created_by', $trainerId)
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
            ->where('user_id', $user->id)
            ->first();
    }
}