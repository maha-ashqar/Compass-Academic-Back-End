<?php

namespace Database\Seeders;

use App\Services\NotificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TrainerAnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $trainer = DB::table('trainers')
            ->orderBy('id')
            ->first();

        if (!$trainer) {
            $this->command->error('No trainer found.');
            return;
        }

        $trainerUser = DB::table('users')
            ->where('id', $trainer->user_id)
            ->first();

        $studentIds = DB::table('enrollments as e')
            ->join(
                'courses as c',
                'c.id',
                '=',
                'e.course_id'
            )
            ->where(
                'c.trainer_id',
                $trainer->id
            )
            ->whereIn(
                'e.status',
                ['active', 'completed']
            )
            ->pluck('e.student_id')
            ->unique()
            ->values();

        if ($studentIds->isEmpty()) {
            $this->command->error(
                'No students found in this trainer courses.'
            );

            return;
        }

        $announcementId = DB::table('announcements')
            ->insertGetId([
                'created_by' => $trainer->id,
                'title' => 'Test Announcement',
                'type' => 'general',
                'content' =>
                    'Hello students, this is a test announcement from your trainer.',
                'related_link' => null,
                'attachment_path' => null,
                'attachment_name' => null,
                'attachment_size' => null,
                'attachment_mime' => null,
                'status' => 'published',
                'scheduled_at' => null,
                'published_at' => now(),
                'archived_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('announcement_audiences')
            ->insert([
                'announcement_id' => $announcementId,
                'audience_type' => 'all_students',
                'audience_id' => null,
                'audience_value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $students = DB::table('students')
            ->whereIn(
                'id',
                $studentIds
            )
            ->get();

        foreach ($students as $student) {
            DB::table('announcement_recipients')
                ->updateOrInsert(
                    [
                        'announcement_id' =>
                            $announcementId,

                        'user_id' =>
                            $student->user_id,
                    ],
                    [
                        'notified_at' => now(),
                        'read_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

            NotificationService::createForStudent(
                (int) $student->id,
                'announcement',
                'Test Announcement',
                'Hello students, this is a test announcement from your trainer.',
                [
                    'icon' => '📢',
                    'category' => 'system',

                    'action_label' =>
                        'Read announcement',

                    'action_path' =>
                        '/student-dashboard/announcements/' .
                        $announcementId,

                    'announcement_id' =>
                        $announcementId,
                ]
            );
        }

        $this->command->info(
            'Announcement created successfully.'
        );

        $this->command->info(
            'Trainer: ' .
            ($trainerUser->name ?? $trainer->id)
        );

        $this->command->info(
            'Announcement ID: ' .
            $announcementId
        );

        $this->command->info(
            'Recipients: ' .
            $students->count()
        );
    }
}