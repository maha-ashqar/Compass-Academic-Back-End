<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainerNotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'trainer') {
            return response()->json([
                'message' => 'Trainer access required.',
            ], 403);
        }

        $notifications = DB::table('notifications')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
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

        if (!$user || $user->role !== 'trainer') {
            return response()->json([
                'message' => 'Trainer access required.',
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
            'message' =>
                'Notification marked as read.',

            'notification' =>
                $this->notificationData(
                    $notification
                ),
        ]);
    }

    public function markAllAsRead(
        Request $request
    ) {
        $user = $request->user();

        if (!$user || $user->role !== 'trainer') {
            return response()->json([
                'message' => 'Trainer access required.',
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
            'message' =>
                'All notifications marked as read.',

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
            ? Carbon::parse(
                $notification->created_at
            )
            : now();

        return [
            'id' => (int) $notification->id,

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

            'time' =>
                $createdAt->diffForHumans(),

            'group' =>
                $this->groupForDate(
                    $createdAt
                ),

            'read' =>
                (bool) $notification->read_at,

            'read_at' =>
                $notification->read_at,

            'created_at' =>
                $notification->created_at,

            'featured' =>
                (bool) (
                    $data['featured'] ??
                    false
                ),

            'action_label' =>
                $data['action_label'] ??
                null,

            'action_tab' =>
                $data['action_tab'] ??
                null,

            'action_path' =>
                $data['action_path'] ??
                null,

            'secondary_label' =>
                $data['secondary_label'] ??
                null,

            'secondary_tab' =>
                $data['secondary_tab'] ??
                null,

            'course_id' =>
                $data['course_id'] ??
                null,

            'assignment_id' =>
                $data['assignment_id'] ??
                null,

            'project_id' =>
                $data['project_id'] ??
                null,

            'competition_id' =>
                $data['competition_id'] ??
                null,

            'conversation_id' =>
                $data['conversation_id'] ??
                null,
        ];
    }

    private function decodeData(
        $data
    ): array {
        if (!$data) {
            return [];
        }

        if (is_array($data)) {
            return $data;
        }

        $decoded = json_decode(
            $data,
            true
        );

        return is_array($decoded)
            ? $decoded
            : [];
    }

    private function categoryForType(
        string $type
    ): string {
        return match ($type) {
            'assignment',
            'submission',
            'grade',
            'course' => 'academics',

            'project' => 'projects',

            'competition' =>
                'competitions',

            'message' => 'messages',

            default => 'system',
        };
    }

    private function iconForType(
        string $type
    ): string {
        return match ($type) {
            'assignment' => '📝',
            'submission' => '📥',
            'grade' => '🎓',
            'course' => '📘',
            'project' => '💼',
            'competition' => '🏆',
            'message' => '💬',
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

        if (
            $date->greaterThanOrEqualTo(
                now()
                    ->subDays(7)
                    ->startOfDay()
            )
        ) {
            return 'Last Week';
        }

        return 'Earlier';
    }
}