<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudentCompetitionSeeder extends Seeder
{
    public function run(): void
    {
        $studentUser = DB::table('users')
            ->where('email', 'student@test.com')
            ->first();

        $trainerUser = DB::table('users')
            ->where('email', 'trainer@test.com')
            ->first();

        if (!$studentUser || !$trainerUser) {
            return;
        }

        $student = DB::table('students')
            ->where('user_id', $studentUser->id)
            ->first();

        $trainer = DB::table('trainers')
            ->where('user_id', $trainerUser->id)
            ->first();

        if (!$student || !$trainer) {
            return;
        }

        $titles = [
            'Compass AI Innovation Challenge',
            'Backend API Sprint',
            'Web Solutions Challenge',
        ];

        DB::table('competitions')
            ->whereIn('title', $titles)
            ->delete();

        $registrationCompetitionId = $this->createCompetition(
            $trainer->id,
            [
                'title' => 'Compass AI Innovation Challenge',
                'category' => 'Artificial Intelligence',
                'description' => 'Build an AI-powered solution that addresses a real student or community problem.',
                'objective' => 'Create an original and practical AI solution with a clear impact.',
                'participation_type' => 'individual_or_team',
                'max_team_members' => 4,
                'registration_start_at' => now()->subDays(2),
                'registration_end_at' => now()->addDays(7),
                'work_start_at' => now()->addDays(8),
                'work_end_at' => now()->addDays(14),
                'submission_deadline_at' => now()->addDays(15),
                'results_at' => now()->addDays(20),
                'prize' => '$2,000',
                'status' => 'registration_open',
            ],
            [
                'Student or team participation is allowed.',
                'Basic programming knowledge is required.',
                'All submitted work must be original.',
            ],
            [
                'Teams may include up to four members.',
                'The final solution must be submitted before the deadline.',
                'Plagiarized work will be disqualified.',
            ]
        );

        $submissionCompetitionId = $this->createCompetition(
            $trainer->id,
            [
                'title' => 'Backend API Sprint',
                'category' => 'Software Engineering',
                'description' => 'Design and implement a secure REST API for a real-world application.',
                'objective' => 'Demonstrate API architecture, validation, authentication, and database design skills.',
                'participation_type' => 'individual',
                'max_team_members' => 1,
                'registration_start_at' => now()->subDays(15),
                'registration_end_at' => now()->subDays(8),
                'work_start_at' => now()->subDays(3),
                'work_end_at' => now()->addDays(4),
                'submission_deadline_at' => now()->addDays(5),
                'results_at' => now()->addDays(10),
                'prize' => 'Certificate + Recognition',
                'status' => 'submissions_open',
            ],
            [
                'Use a REST API architecture.',
                'Include authentication and validation.',
                'Provide source code or a repository link.',
            ],
            [
                'The API must be your own work.',
                'The deadline is final.',
                'A working demonstration is recommended.',
            ]
        );

        $resultsCompetitionId = $this->createCompetition(
            $trainer->id,
            [
                'title' => 'Web Solutions Challenge',
                'category' => 'Web Development',
                'description' => 'Create a polished web solution for a practical academic or community need.',
                'objective' => 'Combine usability and technical quality in a complete web project.',
                'participation_type' => 'individual_or_team',
                'max_team_members' => 3,
                'registration_start_at' => now()->subDays(30),
                'registration_end_at' => now()->subDays(25),
                'work_start_at' => now()->subDays(24),
                'work_end_at' => now()->subDays(10),
                'submission_deadline_at' => now()->subDays(9),
                'results_at' => now()->subDay(),
                'prize' => '$1,000',
                'status' => 'results_published',
                'results_published_at' => now()->subDay(),
            ],
            [
                'Build a responsive web application.',
                'Submit a project description and source link.',
            ],
            [
                'The solution must be functional.',
                'Evaluation is based on quality, impact, and presentation.',
            ]
        );

        $approvedRegistrationId = DB::table(
            'competition_registrations'
        )->insertGetId([
            'competition_id' => $submissionCompetitionId,
            'team_name' => null,
            'status' => 'approved',
            'rejection_reason' => null,
            'reviewed_at' => now()->subDays(7),
            'registered_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(7),
        ]);

        $this->addLeader(
            $approvedRegistrationId,
            $student->id,
            $studentUser->name,
            $studentUser->email
        );

        $resultRegistrationId = DB::table(
            'competition_registrations'
        )->insertGetId([
            'competition_id' => $resultsCompetitionId,
            'team_name' => null,
            'status' => 'approved',
            'rejection_reason' => null,
            'reviewed_at' => now()->subDays(24),
            'registered_at' => now()->subDays(27),
            'created_at' => now()->subDays(27),
            'updated_at' => now()->subDays(24),
        ]);

        $this->addLeader(
            $resultRegistrationId,
            $student->id,
            $studentUser->name,
            $studentUser->email
        );

        $submissionId = DB::table(
            'competition_submissions'
        )->insertGetId([
            'competition_registration_id' =>
                $resultRegistrationId,
            'title' => 'Campus Services Web Portal',
            'description' => 'A responsive portal that centralizes student services and academic resources.',
            'github_url' => 'https://github.com/example/campus-portal',
            'demo_url' => 'https://example.com/campus-portal',
            'status' => 'scored',
            'feedback' => 'Strong technical implementation and clear presentation.',
            'reviewed_at' => now()->subDays(2),
            'delete_reason' => null,
            'deleted_at' => null,
            'submitted_at' => now()->subDays(8),
            'created_at' => now()->subDays(12),
            'updated_at' => now()->subDays(2),
        ]);

        DB::table('competition_results')->insert([
            'competition_registration_id' =>
                $resultRegistrationId,
            'rank' => 2,
            'final_score' => 88.5,
            'award' => 'Second Place',
            'published_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $criteria = DB::table(
            'competition_evaluation_criteria'
        )
            ->where(
                'competition_id',
                $resultsCompetitionId
            )
            ->orderBy('position')
            ->get();

        foreach ($criteria as $criterion) {
            DB::table('competition_scores')->insert([
                'competition_submission_id' => $submissionId,
                'judge_id' => $trainer->id,
                'criterion_id' => $criterion->id,
                'score' => match ($criterion->title) {
                    'Innovation' => 22,
                    'Technical Quality' => 32,
                    'Impact' => 17,
                    default => 17.5,
                },
                'feedback' => null,
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ]);
        }
    }

    private function createCompetition(
        int $trainerId,
        array $data,
        array $requirements,
        array $rules
    ): int {
        $competitionId = DB::table('competitions')
            ->insertGetId([
                'created_by' => $trainerId,
                'title' => $data['title'],
                'category' => $data['category'],
                'description' => $data['description'],
                'objective' => $data['objective'],
                'participation_type' =>
                    $data['participation_type'],
                'max_team_members' =>
                    $data['max_team_members'],
                'registration_start_at' =>
                    $data['registration_start_at'],
                'registration_end_at' =>
                    $data['registration_end_at'],
                'work_start_at' => $data['work_start_at'],
                'work_end_at' => $data['work_end_at'],
                'submission_deadline_at' =>
                    $data['submission_deadline_at'],
                'results_at' => $data['results_at'],
                'prize' => $data['prize'],
                'status' => $data['status'],
                'results_published_at' =>
                    $data['results_published_at'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach ($requirements as $index => $requirement) {
            DB::table('competition_requirements')->insert([
                'competition_id' => $competitionId,
                'requirement' => $requirement,
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($rules as $index => $rule) {
            DB::table('competition_rules')->insert([
                'competition_id' => $competitionId,
                'rule' => $rule,
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (
            [
                ['Project description', 'text'],
                ['Demo or prototype link', 'demo'],
                ['Source repository', 'github'],
                ['Supporting files', 'file'],
            ] as $index => [$title, $type]
        ) {
            DB::table(
                'competition_submission_requirements'
            )->insert([
                'competition_id' => $competitionId,
                'title' => $title,
                'type' => $type,
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (
            [
                ['Innovation', 25],
                ['Technical Quality', 35],
                ['Impact', 20],
                ['Presentation', 20],
            ] as $index => [$title, $weight]
        ) {
            DB::table(
                'competition_evaluation_criteria'
            )->insert([
                'competition_id' => $competitionId,
                'title' => $title,
                'weight' => $weight,
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $competitionId;
    }

    private function addLeader(
        int $registrationId,
        int $studentId,
        string $name,
        string $email
    ): void {
        DB::table(
            'competition_registration_members'
        )->insert([
            'competition_registration_id' =>
                $registrationId,
            'student_id' => $studentId,
            'member_name' => $name,
            'member_email' => $email,
            'role' => 'leader',
            'member_role' => 'Participant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
