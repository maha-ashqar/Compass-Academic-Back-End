<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentAnnouncementController extends Controller
{
    public function show(
        Request $request,
        int $announcementId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $recipient = DB::table('announcement_recipients')
            ->where('announcement_id', $announcementId)
            ->where('user_id', $student->user_id)
            ->first();

        if (!$recipient) {
            return response()->json([
                'message' => 'Announcement unavailable.',
            ], 404);
        }

        $announcement = DB::table('announcements')
            ->where('id', $announcementId)
            ->where('status', 'published')
            ->first();

        if (!$announcement) {
            return response()->json([
                'message' => 'Announcement unavailable.',
            ], 404);
        }

        return response()->json([
            'announcement' => $this->announcementData(
                $announcement,
                $recipient
            ),
        ]);
    }

    public function markAsRead(
        Request $request,
        int $announcementId
    ) {
        $student = $this->studentFromRequest($request);

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 404);
        }

        $announcement = DB::table('announcements')
            ->where('id', $announcementId)
            ->where('status', 'published')
            ->first();

        if (!$announcement) {
            return response()->json([
                'message' => 'Announcement unavailable.',
            ], 404);
        }

        $recipient = DB::table('announcement_recipients')
            ->where('announcement_id', $announcementId)
            ->where('user_id', $student->user_id)
            ->first();

        if (!$recipient) {
            return response()->json([
                'message' => 'Announcement unavailable.',
            ], 404);
        }

        if (!$recipient->read_at) {
            DB::table('announcement_recipients')
                ->where('id', $recipient->id)
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $recipient = DB::table('announcement_recipients')
            ->where('id', $recipient->id)
            ->first();

        return response()->json([
            'message' => 'Announcement marked as read.',
            'read_at' => $recipient->read_at,
        ]);
    }

    private function announcementData(
        $announcement,
        $recipient
    ): array {
        $author = DB::table('trainers as t')
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

        return [
            'id' => (int) $announcement->id,
            'title' => $announcement->title,
            'content' => $announcement->content,
            'type' => $this->frontendType(
                $announcement->type
            ),
            'status' => $announcement->status,
            'audienceLabel' => $this->audienceLabel(
                (int) $announcement->id
            ),
            'link' => $announcement->related_link,
            'attachment' => $this->attachmentData(
                $announcement
            ),
            'publishedAt' => $announcement->published_at,
            'author' => $author ?: 'Compass Academy',
            'readAt' => $recipient->read_at,
        ];
    }

    private function audienceLabel(
        int $announcementId
    ): string {
        $rows = DB::table('announcement_audiences')
            ->where(
                'announcement_id',
                $announcementId
            )
            ->get();

        if ($rows->isEmpty()) {
            return 'Students';
        }

        $first = $rows->first();

        if ($first->audience_type === 'all_students') {
            return 'All students';
        }

        if ($first->audience_type === 'faculty') {
            return $first->audience_value
                ?: 'Faculty students';
        }

        if ($first->audience_type === 'major') {
            return $first->audience_value
                ?: 'Major students';
        }

        if ($first->audience_type === 'department') {
            return $first->audience_value
                ?: 'Department students';
        }

        if ($first->audience_type === 'course') {
            $title = DB::table('courses')
                ->where('id', $first->audience_id)
                ->value('title');

            return $title
                ?: $first->audience_value
                ?: 'Course students';
        }

        if ($first->audience_type === 'specific_student') {
            return 'Selected students';
        }

        return 'Students';
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
            'size' => $this->formatSize(
                (int) (
                    $announcement->attachment_size
                    ?? 0
                )
            ),
            'type' =>
                $announcement->attachment_mime,
            'dataUrl' => asset(
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

    private function frontendType(string $type): string
    {
        return match ($type) {
            'policy' => 'Policy',
            'competition' => 'Competition',
            'faculty_instructions' =>
                'Faculty instructions',
            'course' => 'Course',
            default => 'General',
        };
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
}