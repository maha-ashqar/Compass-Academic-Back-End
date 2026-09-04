<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'This account is not a student account.'
            ], 403);
        }

        $token = $user->createToken('student_auth_token')->plainTextToken;

        $user->update([
            'last_active_at' => now(),
        ]);

        return response()->json([
            'message' => 'Student login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'avatar' => $user->avatar,
            ],
        ]);
    }
    public function me(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $user->load('student');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'avatar' => $user->avatar,
                'last_active_at' => $user->last_active_at,
                'student' => $user->student,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Student logout successful'
        ]);
    }
    // ==============================
    // Student Registration
    // ==============================

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = Str::lower(trim($validated['email']));

        $result = DB::transaction(function () use ($validated, $email) {

            $user = User::create([
                'name' => trim($validated['name']),
                'email' => $email,
                'password' => Hash::make($validated['password']),
                'role' => 'student',
            ]);

            $studentId = DB::table('students')->insertGetId([
                'user_id' => $user->id,
                'student_code' => $this->generateStudentCode(),
                'portfolio_code' => $this->generatePortfolioCode(),
                'is_verified' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $student = DB::table('students')
                ->where('id', $studentId)
                ->first();

            $token = $user
                ->createToken('student-auth')
                ->plainTextToken;

            return [
                'user' => $user,
                'student' => $student,
                'token' => $token,
            ];
        });

        return response()->json([
            'message' => 'Student account created successfully.',
            'token' => $result['token'],
            'user' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'role' => $result['user']->role,
                'avatar' => $result['user']->avatar,
                'student' => [
                    'id' => $result['student']->id,
                    'student_code' => $result['student']->student_code,
                    'portfolio_code' => $result['student']->portfolio_code,
                    'professional_summary' => $result['student']->professional_summary,
                    'is_verified' => (bool) $result['student']->is_verified,
                ],
            ],
        ], 201);
    }



    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = Str::lower(trim($validated['email']));

        $user = User::where('email', $email)
            ->where('role', 'student')
            ->first();

        $response = [
            'message' => 'If this email belongs to a student account, a reset code has been sent.',
        ];

        if (!$user) {
            return response()->json($response);
        }

        $code = (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($code),
                'created_at' => now(),
            ]
        );

        Mail::raw(
            "Your Compass Academy password reset code is: {$code}\n\nThis code expires in 15 minutes.",
            function ($message) use ($email) {
                $message
                    ->to($email)
                    ->subject('Compass Academy Password Reset');
            }
        );

        // For local testing only
        if (app()->environment('local')) {
            $response['debug_code'] = $code;
        }

        return response()->json($response);
    }



    public function verifyResetCode(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $this->checkResetCode(
            Str::lower(trim($validated['email'])),
            $validated['code']
        );

        return response()->json([
            'message' => 'Verification code accepted.',
            'verified' => true,
        ]);
    }



    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = Str::lower(trim($validated['email']));

        $this->checkResetCode($email, $validated['code']);

        $user = User::where('email', $email)
            ->where('role', 'student')
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'Student account not found.',
            ], 404);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->delete();

        // Logout all old sessions
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }



    private function checkResetCode(string $email, string $code): void
    {
        $reset = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$reset || !$reset->created_at) {
            abort(422, 'The verification code is invalid or expired.');
        }

        if (Carbon::parse($reset->created_at)->lt(now()->subMinutes(15))) {
            DB::table('password_reset_tokens')
                ->where('email', $email)
                ->delete();

            abort(422, 'The verification code has expired.');
        }

        if (!Hash::check($code, $reset->token)) {
            abort(422, 'The verification code is incorrect.');
        }
    }

    private function generateStudentCode(): string
    {
        do {
            $code = 'STD-' . Str::upper(Str::random(8));
        } while (
            DB::table('students')
            ->where('student_code', $code)
            ->exists()
        );

        return $code;
    }

    private function generatePortfolioCode(): string
    {
        do {
            $code = 'PF-' . Str::upper(Str::random(10));
        } while (
            DB::table('students')
            ->where('portfolio_code', $code)
            ->exists()
        );

        return $code;
    }
}
