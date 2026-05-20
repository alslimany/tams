<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    /**
     * Create a new scoped API token for the authenticated user.
     *
     * This endpoint is used by the tenant Settings > API Tokens UI.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['string', 'in:'.implode(',', AuthController::ABILITIES)],
        ]);

        $abilities = $validated['abilities'] ?? ['*'];
        $token = $request->user()->createToken($validated['name'], $abilities);

        return $this->success([
            'id' => $token->accessToken->id,
            'name' => $token->accessToken->name,
            'abilities' => $abilities,
            'token' => $token->plainTextToken,
            'created_at' => $token->accessToken->created_at->toISOString(),
        ], 'Token created. Store it securely — it will not be shown again.', 201);
    }

    /**
     * Revoke a specific token by ID.
     *
     * Users can only revoke their own tokens.
     */
    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        $deleted = $request->user()
            ->tokens()
            ->where('id', $tokenId)
            ->delete();

        if (! $deleted) {
            return $this->error('Token not found.', 404);
        }

        return $this->success(null, 'Token revoked.');
    }
}
