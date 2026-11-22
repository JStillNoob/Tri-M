<?php

namespace App\Http\Controllers;

use App\Http\Resources\PosUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends PosController
{
    public function login(Request $request): JsonResponse
    {
        abort_unless($request->hasSession(), 419, 'Initialize your session before signing in.');

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $credentials['email'] = mb_strtolower($credentials['email']);

        $guard = Auth::guard('web');
        $authenticated = $guard->attemptWhen($credentials, fn ($user) => $user->is_active && $user->hasRole('owner', 'cashier'));
        abort_unless($authenticated, 401, 'The email or password is incorrect, or the account is unavailable.');

        $request->session()->regenerate();

        return $this->result(new PosUserResource($guard->user()), 'Signed in.');
    }

    public function show(Request $request): JsonResponse
    {
        return $this->result(new PosUserResource($request->user()), 'Current user retrieved.');
    }

    public function logout(Request $request): JsonResponse
    {
        abort_unless($request->hasSession(), 419, 'The session is unavailable.');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->result(null, 'Signed out.');
    }
}
