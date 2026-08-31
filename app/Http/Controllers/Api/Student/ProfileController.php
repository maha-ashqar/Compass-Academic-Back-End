<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $student = $user->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.'
            ], 404);
        }

        $education = DB::table('student_educations')
            ->where('student_id', $student->id)
            ->orderByDesc('is_current')
            ->orderByDesc('id')
            ->first();

        $skills = DB::table('student_skills')
            ->join('skills', 'student_skills.skill_id', '=', 'skills.id')
            ->where('student_skills.student_id', $student->id)
            ->select(
                'skills.id',
                'skills.name',
                'skills.category',
                'student_skills.is_verified'
            )
            ->get();

        return response()->json([
            'profile' => [
                'id' => $student->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar
                    ? asset('storage/' . $user->avatar)
                    : null,
                'student_code' => $student->student_code,
                'phone' => $student->phone,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth,
                'nationality' => $student->nationality,
                'professional_summary' => $student->professional_summary,
                'github_url' => $student->github_url,
                'linkedin_url' => $student->linkedin_url,
                'portfolio_code' => $student->portfolio_code,
                'is_verified' => $student->is_verified,
                'education' => $education,
                'skills' => $skills,
            ],
        ]);
    }
    public function update(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $student = $user->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.'
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email,' . $user->id],

            'phone' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date'],
            'nationality' => ['nullable', 'string', 'max:191'],
            'professional_summary' => ['nullable', 'string'],
            'github_url' => ['nullable', 'url', 'max:2048'],
            'linkedin_url' => ['nullable', 'url', 'max:2048'],

            'education.degree' => ['nullable', 'string', 'max:191'],
            'education.major' => ['nullable', 'string', 'max:191'],
            'education.university' => ['nullable', 'string', 'max:191'],
            'education.faculty' => ['nullable', 'string', 'max:191'],
            'education.department' => ['nullable', 'string', 'max:191'],
            'education.academic_year' => ['nullable', 'string', 'max:191'],
            'education.start_year' => ['nullable', 'integer'],
            'education.expected_graduation_date' => ['nullable', 'date'],
            'education.location' => ['nullable', 'string', 'max:191'],

            'skills' => ['nullable', 'array'],
            'skills.*' => ['string', 'max:191'],
        ]);

        DB::transaction(function () use ($validated, $user, $student) {
            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->save();

            if (array_key_exists('phone', $validated)) {
                $student->phone = $validated['phone'];
            }

            if (array_key_exists('gender', $validated)) {
                $student->gender = $validated['gender'];
            }

            if (array_key_exists('date_of_birth', $validated)) {
                $student->date_of_birth = $validated['date_of_birth'];
            }

            if (array_key_exists('nationality', $validated)) {
                $student->nationality = $validated['nationality'];
            }

            if (array_key_exists('professional_summary', $validated)) {
                $student->professional_summary = $validated['professional_summary'];
            }

            if (array_key_exists('github_url', $validated)) {
                $student->github_url = $validated['github_url'];
            }

            if (array_key_exists('linkedin_url', $validated)) {
                $student->linkedin_url = $validated['linkedin_url'];
            }

            $student->save();

            if (isset($validated['education'])) {
                $education = $validated['education'];

                $existingEducation = DB::table('student_educations')
                    ->where('student_id', $student->id)
                    ->where('is_current', true)
                    ->first();

                $educationData = [
                    'degree' => $education['degree'] ?? $existingEducation?->degree,
                    'major' => $education['major'] ?? $existingEducation?->major,
                    'university' => $education['university'] ?? $existingEducation?->university,
                    'faculty' => $education['faculty'] ?? $existingEducation?->faculty,
                    'department' => $education['department'] ?? $existingEducation?->department,
                    'academic_year' => $education['academic_year'] ?? $existingEducation?->academic_year,
                    'start_year' => $education['start_year'] ?? $existingEducation?->start_year,
                    'expected_graduation_date' =>
                    $education['expected_graduation_date'] ??
                        $existingEducation?->expected_graduation_date,
                    'location' => $education['location'] ?? $existingEducation?->location,
                    'is_current' => true,
                    'updated_at' => now(),
                ];

                if ($existingEducation) {
                    DB::table('student_educations')
                        ->where('id', $existingEducation->id)
                        ->update($educationData);
                } else {
                    DB::table('student_educations')->insert([
                        'student_id' => $student->id,
                        ...$educationData,
                        'created_at' => now(),
                    ]);
                }
            }
            if (array_key_exists('skills', $validated)) {
                DB::table('student_skills')
                    ->where('student_id', $student->id)
                    ->delete();

                foreach ($validated['skills'] as $skillName) {
                    $skillName = trim($skillName);

                    if ($skillName === '') {
                        continue;
                    }

                    $skill = DB::table('skills')
                        ->where('name', $skillName)
                        ->first();

                    if ($skill) {
                        $skillId = $skill->id;
                    } else {
                        $skillId = DB::table('skills')->insertGetId([
                            'name' => $skillName,
                            'category' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('student_skills')->insert([
                        'student_id' => $student->id,
                        'skill_id' => $skillId,
                        'is_verified' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        return $this->show($request);
    }
    public function updateAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $request->validate([
            'avatar' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        if (
            $user->avatar &&
            Storage::disk('public')->exists($user->avatar)
        ) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store(
            'avatars',
            'public'
        );

        $user->avatar = $path;
        $user->save();

        return response()->json([
            'message' => 'Avatar updated successfully.',
            'avatar' => asset('storage/' . $path),
        ]);
    }
}
