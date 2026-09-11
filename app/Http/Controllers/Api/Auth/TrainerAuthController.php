<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TrainerAuthController extends Controller
{
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
                'role' => 'trainer',
            ]);

            $trainerId = DB::table('trainers')->insertGetId([
                'user_id' => $user->id,
                'status' => 'active',
                'is_verified' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $token = $user
                ->createToken('trainer-auth')
                ->plainTextToken;

            return [
                'user' => $user,
                'trainer_id' => $trainerId,
                'token' => $token,
            ];
        });

        return response()->json([
            'message' => 'Trainer account created successfully.',
            'token' => $result['token'],
            'user' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'role' => $result['user']->role,
                'avatar' => null,
                'trainer' => [
                    'id' => $result['trainer_id'],
                    'status' => 'active',
                    'is_verified' => false,
                ],
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($validated['email']));

        $user = User::where('email', $email)
            ->where('role', 'trainer')
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user->last_active_at = now();
        $user->save();

        $token = $user->createToken('trainer-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'avatar' => $user->avatar
                    ? asset('storage/' . $user->avatar)
                    : null,
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'trainer') {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $user->last_active_at = now();
        $user->save();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'avatar' => $user->avatar
                    ? asset('storage/' . $user->avatar)
                    : null,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'trainer') {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = Str::lower(trim($validated['email']));

        $user = User::where('email', $email)
            ->where('role', 'trainer')
            ->first();

        $response = [
            'message' => 'If this email belongs to a trainer account, a reset code has been sent.',
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

        $frontendUrl = rtrim(
            env('FRONTEND_URL', 'http://localhost:5173'),
            '/'
        );

        $resetUrl = $frontendUrl
            . '/forgot-password?email=' . urlencode($email)
            . '&code=' . urlencode($code)
            . '&role=trainer';

        $html = view('emails.student-password-reset', [
            'code' => $code,
            'name' => $user->name,
            'resetUrl' => $resetUrl,
        ])->render();

        Http::withHeaders([
            'api-key' => config('services.brevo.key'),
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])->post('https://api.brevo.com/v3/smtp/email', [
            'sender' => [
                'name' => config('services.brevo.sender_name'),
                'email' => config('services.brevo.sender_email'),
            ],
            'to' => [
                [
                    'email' => $email,
                    'name' => $user->name,
                ],
            ],
            'subject' => 'Your Compass Academy verification code',
            'htmlContent' => $html,
        ])->throw();

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
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $email = Str::lower(trim($validated['email']));

        $this->checkResetCode(
            $email,
            $validated['code']
        );

        $user = User::where('email', $email)
            ->where('role', 'trainer')
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'Trainer account not found.',
            ], 404);
        }

        $user->password = Hash::make(
            $validated['password']
        );

        $user->save();

        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->delete();

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    private function checkResetCode(
        string $email,
        string $code
    ): void {
        $reset = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$reset || !$reset->created_at) {
            abort(
                422,
                'The verification code is invalid or expired.'
            );
        }

        if (
            Carbon::parse($reset->created_at)
                ->lt(now()->subMinutes(15))
        ) {
            DB::table('password_reset_tokens')
                ->where('email', $email)
                ->delete();

            abort(
                422,
                'The verification code has expired.'
            );
        }

        if (!Hash::check($code, $reset->token)) {
            abort(
                422,
                'The verification code is incorrect.'
            );
        }
    }
}
