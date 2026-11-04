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
            // The previous SPA flow issued a persistent token on every
            // login. Those tokens keep working on their own, so opening
            // a session revokes them: the session is the credential now.
            $user->tokens()->delete();

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
     *
     * The branch follows HOW the request was authenticated, not how the
     * request looks: a bearer token authenticated client revokes its
     * token, a browser session ends the session. Deciding by origin
     * instead would leave the token of a first-party request alive.
     */
    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            // API client: revoke the token it authenticated with.
            $token->delete();

            return response()->json(['message' => 'Logged out']);
        }

        // Browser session: end it and rotate the CSRF token.
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
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