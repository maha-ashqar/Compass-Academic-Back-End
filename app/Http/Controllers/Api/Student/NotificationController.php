<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'student') {
            return response()->json([
                'message' => 'Student access required.',
            ], 403);
        }

        $student = DB::table('students')
            ->where('user_id', $user->id)
            ->first();

        $settings = $student
            ? DB::table('student_settings')
                ->where('student_id', $student->id)
                ->first()
            : null;

        $notifications = DB::table('notifications')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->filter(
                fn ($notification) =>
                    $this->notificationEnabled(
                        $notification->type,
                        $settings
                    )
            )
            ->map(
                fn ($notification) =>
                    $this->notificationData($notification)
            )
            ->values();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $notifications
                ->where('read', false)
                ->count(),
        ]);
    }

    public function markAsRead(
        Request $request,
        int $notificationId
    ) {
        $user = $request->user();

        if (!$user || $user->role !== 'student') {
            return response()->json([
                'message' => 'Student access required.',
            ], 403);
        }

        $notification = DB::table('notifications')
            ->where('id', $notificationId)
            ->where('user_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification not found.',
            ], 404);
        }

        if (!$notification->read_at) {
            DB::table('notifications')
                ->where('id', $notificationId)
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);

            $notification = DB::table('notifications')
                ->where('id', $notificationId)
                ->first();
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'notification' =>
                $this->notificationData($notification),
        ]);
    }

    public function markAllAsRead(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'student') {
            return response()->json([
                'message' => 'Student access required.',
            ], 403);
        }

        $updated = DB::table('notifications')
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'updated_count' => $updated,
            'unread_count' => 0,
        ]);
    }

    private function notificationData(
        $notification
    ): array {
        $data = $this->decodeData(
            $notification->data
        );

        $createdAt = $notification->created_at
            ? Carbon::parse($notification->created_at)
            : now();

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'category' =>
                $data['category'] ??
                $this->categoryForType(
                    $notification->type
                ),
            'icon' =>
                $data['icon'] ??
                $this->iconForType(
                    $notification->type
                ),
            'title' => $notification->title,
            'text' => $notification->message,
            'time' => $createdAt->diffForHumans(),
            'group' => $this->groupForDate(
                $createdAt
            ),
            'read' => (bool) $notification->read_at,
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
            'featured' =>
                (bool) ($data['featured'] ?? false),
            'action_label' =>
                $data['action_label'] ?? null,
            'action_tab' =>
                $data['action_tab'] ?? null,
            'action_path' =>
                $data['action_path'] ?? null,
            'secondary_label' =>
                $data['secondary_label'] ?? null,
            'secondary_tab' =>
                $data['secondary_tab'] ?? null,
            'announcement_id' =>
                $data['announcement_id'] ?? null,
        ];
    }

    private function decodeData($data): array
    {
        if (!$data) {
            return [];
        }

        if (is_array($data)) {
            return $data;
        }

        $decoded = json_decode($data, true);

        return is_array($decoded)
            ? $decoded
            : [];
    }

    private function categoryForType(
        string $type
    ): string {
        return match ($type) {
            'course',
            'assignment',
            'grade',
            'certificate' => 'academics',

            'competition' => 'competitions',

            default => 'system',
        };
    }

    private function iconForType(
        string $type
    ): string {
        return match ($type) {
            'course' => '📘',
            'assignment' => '📝',
            'grade' => '🎓',
            'certificate' => '🏅',
            'competition' => '🏆',
            'announcement' => '📢',
            default => '🔔',
        };
    }

    private function groupForDate(
        Carbon $date
    ): string {
        if ($date->isToday()) {
            return 'Today';
        }

        if ($date->isYesterday()) {
            return 'Yesterday';
        }

        if ($date->greaterThanOrEqualTo(
            now()->subDays(7)->startOfDay()
        )) {
            return 'Last Week';
        }

        return 'Earlier';
    }
    private function notificationEnabled(
        string $type,
        $settings
    ): bool {
        if (!$settings) {
            return true;
        }

        return match ($type) {
            'assignment' =>
                (bool) $settings->assignment_notifications,
            'grade' =>
                (bool) $settings->grade_notifications,
            'course' =>
                (bool) $settings->course_notifications,
            'project' =>
                (bool) $settings->project_notifications,
            'competition' =>
                (bool) $settings->competition_notifications,
            'message' =>
                (bool) $settings->message_notifications,
            'announcement' =>
                (bool) $settings->announcement_notifications,
            'certificate',
            'achievement' =>
                (bool) $settings->achievement_notifications,
            default => true,
        };
    }

}
