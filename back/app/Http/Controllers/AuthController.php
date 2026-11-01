<?php
// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2024 dowardev <dowardev@gmail.com>

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle a login request.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:255',
        ]);

        $user = \App\Models\User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Detect first-party SPA request via Sanctum's 'sanctum' request attribute
        $isFirstParty = $request->attributes->get('sanctum') === true;

        if ($isFirstParty) {
            // SPA login: use session guard, regenerate session ID
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return response()->json([
                'user' => $user,
                'token' => null,
            ]);
        }

        // API client login: create personal access token
        $token = $user->createToken(
            $validated['device_name'] ?? 'api-token'
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Handle a logout request.
     */
    public function logout(Request $request)
    {
        // Detect first-party SPA request via Sanctum's 'sanctum' request attribute
        $isFirstParty = $request->attributes->get('sanctum') === true;

        if ($isFirstParty) {
            // SPA logout: invalidate session, regenerate CSRF token
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Logged out']);
        }

        // API client logout: delete current access token. A session
        // authenticated user has no token to delete, so the token is
        // checked before it is removed.
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
};