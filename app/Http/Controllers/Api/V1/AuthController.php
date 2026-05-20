<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Valid token abilities that can be requested.
     *
     * @var array<string>
     */
    public const ABILITIES = ['read', 'write', 'issue', 'report'];

    /**
     * Issue an API token from email + password.
     *
     * Pass an optional `abilities` array to scope the token.
     * Valid values: read, write, issue, report.
     * Omitting abilities (or passing ['*']) grants full access.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['string', 'in:'.implode(',', self::ABILITIES)],
        ]);

        $user = config('auth.providers.users.model')::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return $this->error('Your account has been deactivated.', 403);
        }

        $abilities = $validated['abilities'] ?? ['*'];
        $token = $user->createToken($validated['device_name'], $abilities);

        return $this->success([
            'token' => $token->plainTextToken,
            'abilities' => $abilities,
            'user' => $this->formatUser($user),
        ], 'Token created successfully.');
    }

    /**
     * Revoke the current token (logout).
     *
     * Uses bearerToken() directly to ensure we delete the real PersonalAccessToken,
     * not a TransientToken that Sanctum creates during SPA/cookie authentication.
     */
    public function revoke(Request $request): JsonResponse
    {
        $bearerToken = $request->bearerToken();

        if ($bearerToken === null) {
            return $this->error('No Bearer token provided. Use DELETE /v1/auth/token with Authorization: Bearer {token}.', 400);
        }

        $tokenModel = config('sanctum.personal_access_token_model', \Laravel\Sanctum\PersonalAccessToken::class);
        $accessToken = $tokenModel::findToken($bearerToken);

        if ($accessToken === null) {
            return $this->error('Token not found or already revoked.', 404);
        }

        $accessToken->delete();

        return $this->success(null, 'Token revoked.');
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success($this->formatUser($request->user()));
    }

    /**
     * List all active tokens for the authenticated user.
     */
    public function tokens(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens->map(fn ($token) => [
            'id' => $token->id,
            'name' => $token->name,
            'abilities' => $token->abilities,
            'last_used_at' => $token->last_used_at?->toISOString(),
            'expires_at' => $token->expires_at?->toISOString(),
            'created_at' => $token->created_at->toISOString(),
        ]);

        return $this->success($tokens);
    }

    private function formatUser(mixed $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'initials' => $user->initials(),
            'last_login_at' => $user->last_login_at?->toISOString(),
        ];
    }
}
