<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
}
