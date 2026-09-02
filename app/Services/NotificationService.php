<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class NotificationService
{
    public static function create(
        int $userId,
        string $type,
        string $title,
        ?string $message = null,
        array $data = []
    ): int {
        return DB::table('notifications')->insertGetId([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data
                ? json_encode(
                    $data,
                    JSON_UNESCAPED_UNICODE
                )
                : null,
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function createForStudent(
        int $studentId,
        string $type,
        string $title,
        ?string $message = null,
        array $data = []
    ): ?int {
        $userId = DB::table('students')
            ->where('id', $studentId)
            ->value('user_id');

        if (!$userId) {
            return null;
        }

        return self::create(
            (int) $userId,
            $type,
            $title,
            $message,
            $data
        );
    }
}
