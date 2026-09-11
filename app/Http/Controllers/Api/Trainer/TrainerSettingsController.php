<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TrainerSettingsController extends Controller
{
    public function changePassword(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'trainer') {
            return response()->json([
                'message' => 'Trainer access required.',
            ], 403);
        }

        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'confirmed',
            ],
            'logout_others' => [
                'sometimes',
                'boolean',
            ],
        ]);

        if (!Hash::check(
            $validated['current_password'],
            $user->password
        )) {
            return response()->json([
                'message' =>
                    'The current password is incorrect.',
            ], 422);
        }

        if (Hash::check(
            $validated['password'],
            $user->password
        )) {
            return response()->json([
                'message' =>
                    'The new password must be different from the current password.',
            ], 422);
        }

        $user->password = Hash::make(
            $validated['password']
        );

        $user->save();

        $otherSessionsRevoked = false;

        if (
            (bool) (
                $validated['logout_others'] ??
                false
            )
        ) {
            $currentToken =
                $user->currentAccessToken();

            if (
                $currentToken &&
                isset($currentToken->id)
            ) {
                $user->tokens()
                    ->where(
                        'id',
                        '!=',
                        $currentToken->id
                    )
                    ->delete();

                $otherSessionsRevoked = true;
            }
        }

        return response()->json([
            'message' =>
                'Password updated successfully.',
            'other_sessions_revoked' =>
                $otherSessionsRevoked,
        ]);
    }
}