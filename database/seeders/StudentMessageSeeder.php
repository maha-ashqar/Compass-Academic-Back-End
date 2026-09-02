<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudentMessageSeeder extends Seeder
{
    public function run(): void
    {
        $studentUser = DB::table('users')
            ->where('email', 'student@test.com')
            ->where('role', 'student')
            ->first();

        if (!$studentUser) {
            return;
        }

        $student = DB::table('students')
            ->where('user_id', $studentUser->id)
            ->first();

        if (!$student) {
            return;
        }

        $course = DB::table('enrollments as e')
            ->join('courses as c', 'c.id', '=', 'e.course_id')
            ->join('trainers as t', 't.id', '=', 'c.trainer_id')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('e.student_id', $student->id)
            ->whereIn('e.status', ['active', 'completed'])
            ->where('c.status', 'published')
            ->where('t.status', 'active')
            ->orderBy('c.id')
            ->first([
                'c.id as course_id',
                'c.title as course_title',
                't.id as trainer_id',
                'u.id as trainer_user_id',
                'u.name as trainer_name',
            ]);

        if (!$course) {
            return;
        }

        $conversationId = DB::table(
            'conversation_participants as a'
        )
            ->join(
                'conversation_participants as b',
                'b.conversation_id',
                '=',
                'a.conversation_id'
            )
            ->where('a.user_id', $studentUser->id)
            ->where('b.user_id', $course->trainer_user_id)
            ->value('a.conversation_id');

        if (!$conversationId) {
            $conversationId = DB::table(
                'conversations'
            )->insertGetId([
                'topic' => $course->course_title,
                'accepted_at' => now()->subDays(3),
                'declined_at' => null,
                'blocked_by_user_id' => null,
                'created_at' => now()->subDays(4),
                'updated_at' => now()->subMinutes(20),
            ]);

            DB::table(
                'conversation_participants'
            )->insert([
                [
                    'conversation_id' => $conversationId,
                    'user_id' => $studentUser->id,
                    'last_read_at' => now()->subHours(2),
                    'cleared_at' => null,
                    'created_at' => now()->subDays(4),
                    'updated_at' => now()->subHours(2),
                ],
                [
                    'conversation_id' => $conversationId,
                    'user_id' => $course->trainer_user_id,
                    'last_read_at' => now()->subHours(3),
                    'cleared_at' => null,
                    'created_at' => now()->subDays(4),
                    'updated_at' => now()->subHours(3),
                ],
            ]);
        } else {
            DB::table('conversations')
                ->where('id', $conversationId)
                ->update([
                    'topic' => $course->course_title,
                    'accepted_at' =>
                        DB::raw(
                            'COALESCE(accepted_at, CURRENT_TIMESTAMP)'
                        ),
                    'declined_at' => null,
                    'blocked_by_user_id' => null,
                    'updated_at' => now(),
                ]);
        }

        $hasMessages = DB::table('messages')
            ->where(
                'conversation_id',
                $conversationId
            )
            ->exists();

        if ($hasMessages) {
            return;
        }

        DB::table('messages')->insert([
            [
                'conversation_id' => $conversationId,
                'sender_id' => $course->trainer_user_id,
                'message' =>
                    'Welcome! If you need help with the course, send me a message here.',
                'attachment_path' => null,
                'read_at' => now()->subDays(2),
                'edited_at' => null,
                'deleted_at' => null,
                'created_at' => now()->subDays(3),
                'updated_at' => now()->subDays(3),
            ],
            [
                'conversation_id' => $conversationId,
                'sender_id' => $studentUser->id,
                'message' =>
                    'Thank you. I will use this chat if I have any questions.',
                'attachment_path' => null,
                'read_at' => now()->subDays(1),
                'edited_at' => null,
                'deleted_at' => null,
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ],
            [
                'conversation_id' => $conversationId,
                'sender_id' => $course->trainer_user_id,
                'message' =>
                    'Perfect. I also added a few notes for the next lesson.',
                'attachment_path' => null,
                'read_at' => null,
                'edited_at' => null,
                'deleted_at' => null,
                'created_at' => now()->subMinutes(20),
                'updated_at' => now()->subMinutes(20),
            ],
        ]);
    }
}
