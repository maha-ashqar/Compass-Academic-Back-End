<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TrainerLoginSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'trainer@test.com'],
            [
                'name' => 'Ahmad Trainer',
                'password' => Hash::make('password123'),
                'role' => 'trainer',
            ]
        );

        DB::table('trainers')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'job_title' => 'Software Engineering Instructor',
                'status' => 'active',
                'is_verified' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
