<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TrainerProfileController extends Controller
{
    public function show(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        return response()->json([
            'profile' => $this->profileData($trainer->id),
        ]);
    }

    public function update(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $user = $request->user();

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:191',
            ],

            'email' => [
                'sometimes',
                'required',
                'email',
                'max:191',
                Rule::unique('users', 'email')
                    ->ignore($user->id),
            ],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'job_title' => [
                'sometimes',
                'required',
                'string',
                'max:191',
            ],

            'bio' => [
                'sometimes',
                'nullable',
                'string',
                'max:10000',
            ],

            'university' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'faculty' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'department' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'office' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'office_hours' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'extension' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'github_url' => [
                'sometimes',
                'nullable',
                'url',
                'max:191',
            ],

            'linkedin_url' => [
                'sometimes',
                'nullable',
                'url',
                'max:191',
            ],

            'employment_status' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'years_of_experience' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'academic_degree' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'degree_specialization' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'graduation_year' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1900',
                'max:' . ((int) date('Y') + 10),
            ],

            'degree_certificate_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            'specializations' => [
                'sometimes',
                'array',
                'max:30',
            ],

            'specializations.*.name' => [
                'required',
                'string',
                'max:191',
            ],

            'experiences' => [
                'sometimes',
                'array',
                'max:30',
            ],

            'experiences.*.id' => [
                'nullable',
                'integer',
            ],

            'experiences.*.job_title' => [
                'required',
                'string',
                'max:191',
            ],

            'experiences.*.organization' => [
                'required',
                'string',
                'max:191',
            ],

            'experiences.*.start_year' => [
                'nullable',
                'integer',
                'min:1900',
                'max:' . ((int) date('Y') + 10),
            ],

            'experiences.*.end_year' => [
                'nullable',
                'integer',
                'min:1900',
                'max:' . ((int) date('Y') + 10),
            ],

            'experiences.*.is_current' => [
                'nullable',
                'boolean',
            ],

            'experiences.*.description' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'certificates' => [
                'sometimes',
                'array',
                'max:50',
            ],

            'certificates.*.id' => [
                'nullable',
                'integer',
            ],

            'certificates.*.name' => [
                'required',
                'string',
                'max:191',
            ],

            'certificates.*.issuer' => [
                'nullable',
                'string',
                'max:191',
            ],

            'certificates.*.issue_year' => [
                'nullable',
                'integer',
                'min:1900',
                'max:' . ((int) date('Y') + 10),
            ],

            'certificates.*.credential_url' => [
                'nullable',
                'url',
                'max:191',
            ],
        ]);

        DB::transaction(function () use (
            $request,
            $validated,
            $trainer,
            $user
        ) {
            $userUpdates = [];

            if (array_key_exists('name', $validated)) {
                $userUpdates['name'] = $validated['name'];
            }

            if (array_key_exists('email', $validated)) {
                $userUpdates['email'] = $validated['email'];
            }

            if ($userUpdates) {
                $userUpdates['updated_at'] = now();

                DB::table('users')
                    ->where('id', $user->id)
                    ->update($userUpdates);
            }

            $trainerFields = [
                'phone',
                'job_title',
                'bio',
                'university',
                'faculty',
                'department',
                'office',
                'office_hours',
                'extension',
                'github_url',
                'linkedin_url',
                'employment_status',
                'years_of_experience',
                'academic_degree',
                'degree_specialization',
                'graduation_year',
                'degree_certificate_number',
            ];

            $trainerUpdates = [];

            foreach ($trainerFields as $field) {
                if (array_key_exists($field, $validated)) {
                    $trainerUpdates[$field] = $validated[$field];
                }
            }

            if ($trainerUpdates) {
                $trainerUpdates['updated_at'] = now();

                DB::table('trainers')
                    ->where('id', $trainer->id)
                    ->update($trainerUpdates);
            }

            if ($request->has('specializations')) {
                $this->syncSpecializations(
                    $trainer->id,
                    $validated['specializations'] ?? []
                );
            }

            if ($request->has('experiences')) {
                $this->syncExperiences(
                    $trainer->id,
                    $validated['experiences'] ?? []
                );
            }

            if ($request->has('certificates')) {
                $this->syncCertificates(
                    $trainer->id,
                    $validated['certificates'] ?? []
                );
            }
        });

        return response()->json([
            'message' => 'Trainer profile updated successfully.',
            'profile' => $this->profileData($trainer->id),
        ]);
    }

    public function uploadAvatar(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $validated = $request->validate([
            'avatar' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $user = $request->user();

        $currentAvatar = DB::table('users')
            ->where('id', $user->id)
            ->value('avatar');

        $this->deleteStoredFile($currentAvatar);

        $path = $validated['avatar']->store(
            'trainer-avatars/' . $trainer->id,
            'public'
        );

        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'avatar' => $path,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Profile photo updated successfully.',
            'profile' => $this->profileData($trainer->id),
        ]);
    }

    public function deleteAvatar(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $user = $request->user();

        $currentAvatar = DB::table('users')
            ->where('id', $user->id)
            ->value('avatar');

        $this->deleteStoredFile($currentAvatar);

        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'avatar' => null,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Profile photo removed successfully.',
            'profile' => $this->profileData($trainer->id),
        ]);
    }

    public function uploadDegreeCertificate(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $validated = $request->validate([
            'certificate' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:20480',
            ],
        ]);

        $current = DB::table('trainers')
            ->where('id', $trainer->id)
            ->first();

        $this->deleteStoredFile(
            $current->degree_certificate_path
        );

        $file = $validated['certificate'];

        $path = $file->store(
            'trainer-degree-certificates/' . $trainer->id,
            'public'
        );

        DB::table('trainers')
            ->where('id', $trainer->id)
            ->update([
                'degree_certificate_path' => $path,
                'degree_certificate_original_name' =>
                    $file->getClientOriginalName(),
                'degree_certificate_size' => $file->getSize(),
                'degree_certificate_verified' => false,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Degree certificate uploaded successfully.',
            'profile' => $this->profileData($trainer->id),
        ]);
    }

    public function deleteDegreeCertificate(Request $request)
    {
        $trainer = $this->trainerFromRequest($request);

        if (!$trainer) {
            return response()->json([
                'message' => 'Trainer profile not found.',
            ], 404);
        }

        $current = DB::table('trainers')
            ->where('id', $trainer->id)
            ->first();

        $this->deleteStoredFile(
            $current->degree_certificate_path
        );

        DB::table('trainers')
            ->where('id', $trainer->id)
            ->update([
                'degree_certificate_path' => null,
                'degree_certificate_original_name' => null,
                'degree_certificate_size' => null,
                'degree_certificate_verified' => false,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Degree certificate removed successfully.',
            'profile' => $this->profileData($trainer->id),
        ]);
    }

    private function syncSpecializations(
        int $trainerId,
        array $specializations
    ): void {
        DB::table('trainer_specializations')
            ->where('trainer_id', $trainerId)
            ->delete();

        foreach ($specializations as $index => $specialization) {
            $name = trim($specialization['name']);

            if (!$name) {
                continue;
            }

            DB::table('trainer_specializations')
                ->insert([
                    'trainer_id' => $trainerId,
                    'name' => $name,
                    'position' => $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    private function syncExperiences(
        int $trainerId,
        array $experiences
    ): void {
        $existingIds = DB::table('trainer_experiences')
            ->where('trainer_id', $trainerId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $keepIds = [];

        foreach ($experiences as $experience) {
            $isCurrent = (bool) (
                $experience['is_current'] ?? false
            );

            $data = [
                'job_title' => $experience['job_title'],
                'organization' => $experience['organization'],
                'start_year' => $experience['start_year'] ?? null,
                'end_year' => $isCurrent
                    ? null
                    : ($experience['end_year'] ?? null),
                'is_current' => $isCurrent,
                'description' => $experience['description'] ?? null,
                'updated_at' => now(),
            ];

            $experienceId = isset($experience['id'])
                ? (int) $experience['id']
                : null;

            if (
                $experienceId &&
                in_array($experienceId, $existingIds, true)
            ) {
                DB::table('trainer_experiences')
                    ->where('id', $experienceId)
                    ->where('trainer_id', $trainerId)
                    ->update($data);

                $keepIds[] = $experienceId;

                continue;
            }

            $data['trainer_id'] = $trainerId;
            $data['created_at'] = now();

            $keepIds[] = DB::table('trainer_experiences')
                ->insertGetId($data);
        }

        $query = DB::table('trainer_experiences')
            ->where('trainer_id', $trainerId);

        if ($keepIds) {
            $query->whereNotIn('id', $keepIds);
        }

        $query->delete();
    }

    private function syncCertificates(
        int $trainerId,
        array $certificates
    ): void {
        $existingIds = DB::table('trainer_certificates')
            ->where('trainer_id', $trainerId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $keepIds = [];

        foreach ($certificates as $certificate) {
            $data = [
                'name' => $certificate['name'],
                'issuer' => $certificate['issuer'] ?? null,
                'issue_year' => $certificate['issue_year'] ?? null,
                'credential_url' =>
                    $certificate['credential_url'] ?? null,
                'updated_at' => now(),
            ];

            $certificateId = isset($certificate['id'])
                ? (int) $certificate['id']
                : null;

            if (
                $certificateId &&
                in_array($certificateId, $existingIds, true)
            ) {
                DB::table('trainer_certificates')
                    ->where('id', $certificateId)
                    ->where('trainer_id', $trainerId)
                    ->update($data);

                $keepIds[] = $certificateId;

                continue;
            }

            $data['trainer_id'] = $trainerId;
            $data['is_verified'] = false;
            $data['created_at'] = now();

            $keepIds[] = DB::table('trainer_certificates')
                ->insertGetId($data);
        }

        $query = DB::table('trainer_certificates')
            ->where('trainer_id', $trainerId);

        if ($keepIds) {
            $query->whereNotIn('id', $keepIds);
        }

        $query->delete();
    }

    private function profileData(int $trainerId): array
    {
        $profile = DB::table('trainers as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.id', $trainerId)
            ->select(
                't.*',
                'u.name',
                'u.email',
                'u.avatar',
                'u.last_active_at'
            )
            ->first();

        $specializations = DB::table('trainer_specializations')
            ->where('trainer_id', $trainerId)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
            ])
            ->values();

        $experiences = DB::table('trainer_experiences')
            ->where('trainer_id', $trainerId)
            ->orderByDesc('is_current')
            ->orderByDesc('start_year')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'job_title' => $item->job_title,
                'organization' => $item->organization,
                'start_year' => $item->start_year,
                'end_year' => $item->end_year,
                'is_current' => (bool) $item->is_current,
                'description' => $item->description,
            ])
            ->values();

        $certificates = DB::table('trainer_certificates')
            ->where('trainer_id', $trainerId)
            ->orderByDesc('issue_year')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'issuer' => $item->issuer,
                'issue_year' => $item->issue_year,
                'credential_url' => $item->credential_url,
                'is_verified' => (bool) $item->is_verified,
            ])
            ->values();

        $degreeCertificate = null;

        if ($profile->degree_certificate_path) {
            $degreeCertificate = [
                'name' => $profile->degree_certificate_original_name,
                'size' => $profile->degree_certificate_size,
                'size_label' => $this->formatBytes(
                    $profile->degree_certificate_size
                ),
                'verified' =>
                    (bool) $profile->degree_certificate_verified,
                'url' => $this->fileUrl(
                    $profile->degree_certificate_path
                ),
            ];
        }

        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,

            'name' => $profile->name,
            'email' => $profile->email,
            'avatar' => $this->fileUrl($profile->avatar),

            'phone' => $profile->phone,
            'job_title' => $profile->job_title,
            'bio' => $profile->bio,

            'university' => $profile->university,
            'faculty' => $profile->faculty,
            'department' => $profile->department,
            'office' => $profile->office,
            'office_hours' => $profile->office_hours,
            'extension' => $profile->extension,

            'github_url' => $profile->github_url,
            'linkedin_url' => $profile->linkedin_url,

            'status' => $profile->status,
            'is_verified' => (bool) $profile->is_verified,

            'employment_status' => $profile->employment_status,
            'years_of_experience' => $profile->years_of_experience,

            'employee_id' => $profile->employee_id,
            'national_id' => $profile->national_id,

            'academic_degree' => $profile->academic_degree,
            'degree_specialization' => $profile->degree_specialization,
            'graduation_year' => $profile->graduation_year,
            'degree_certificate_number' =>
                $profile->degree_certificate_number,

            'degree_certificate' => $degreeCertificate,

            'specializations' => $specializations,
            'experiences' => $experiences,
            'certificates' => $certificates,

            'last_active_at' => $profile->last_active_at,
        ];
    }

    private function trainerFromRequest(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'trainer') {
            return null;
        }

        return DB::table('trainers')
            ->where('user_id', $user->id)
            ->first();
    }

    private function fileUrl(?string $path): string
    {
        if (!$path) {
            return '';
        }

        if (
            str_starts_with($path, 'http://') ||
            str_starts_with($path, 'https://')
        ) {
            return $path;
        }

        return url(
            Storage::url($path)
        );
    }

    private function deleteStoredFile(?string $path): void
    {
        if (!$path) {
            return;
        }

        if (
            str_starts_with($path, 'http://') ||
            str_starts_with($path, 'https://')
        ) {
            return;
        }

        if (
            Storage::disk('public')->exists($path)
        ) {
            Storage::disk('public')->delete($path);
        }
    }

    private function formatBytes(?int $bytes): string
    {
        if (!$bytes) {
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
}
