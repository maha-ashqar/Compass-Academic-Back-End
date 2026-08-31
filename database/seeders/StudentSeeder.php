<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            [
                'email' => 'student@test.com',
            ],
            [
                'name' => 'Mohammed Ahmad',
                'password' => Hash::make('12345678'),
                'role' => 'student',
                'avatar' => null,
            ]
        );

        $student = Student::updateOrCreate(
            [
                'user_id' => $user->id,
            ],
            [
                'student_code' => 'STU001',
                'phone' => '+970599123456',
                'gender' => 'male',
                'date_of_birth' => '2002-05-15',
                'nationality' => 'Palestinian',
                'professional_summary' => 'Software Engineering student interested in backend development, web technologies, databases, and building practical software projects.',
                'github_url' => 'https://github.com/student-test',
                'linkedin_url' => 'https://www.linkedin.com/in/student-test',
                'portfolio_code' => 'PORT001',
                'is_verified' => true,
            ]
        );

        DB::table('student_educations')
            ->where('student_id', $student->id)
            ->delete();

        DB::table('student_educations')->insert([
            'student_id' => $student->id,
            'degree' => 'Bachelor',
            'major' => 'Software Engineering',
            'university' => 'Palestine Technical University',
            'faculty' => 'Faculty of Engineering and Information Technology',
            'department' => 'Software Engineering',
            'academic_year' => 'Fourth Year',
            'start_year' => 2023,
            'expected_graduation_date' => '2027-06-30',
            'location' => 'Palestine',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_skills')
            ->where('student_id', $student->id)
            ->delete();

        $skills = [
            ['name' => 'PHP', 'category' => 'Backend'],
            ['name' => 'Laravel', 'category' => 'Backend'],
            ['name' => 'MySQL', 'category' => 'Database'],
            ['name' => 'JavaScript', 'category' => 'Frontend'],
            ['name' => 'React', 'category' => 'Frontend'],
            ['name' => 'Git', 'category' => 'Tools'],
        ];

        foreach ($skills as $skillData) {
            $skill = DB::table('skills')
                ->where('name', $skillData['name'])
                ->first();

            if (!$skill) {
                $skillId = DB::table('skills')->insertGetId([
                    'name' => $skillData['name'],
                    'category' => $skillData['category'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $skillId = $skill->id;
            }

            DB::table('student_skills')->insert([
                'student_id' => $student->id,
                'skill_id' => $skillId,
                'is_verified' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
