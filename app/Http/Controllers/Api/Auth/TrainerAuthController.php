<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TrainerAuthController extends Controller
{
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
}
