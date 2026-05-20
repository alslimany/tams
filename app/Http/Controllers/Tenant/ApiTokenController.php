<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    /**
     * Display the API tokens management page.
     */
    public function index(Request $request): Response
    {
        $tokens = $request->user()->tokens->map(fn ($token) => [
            'id' => $token->id,
            'name' => $token->name,
            'abilities' => $token->abilities,
            'last_used_at' => $token->last_used_at?->toISOString(),
            'expires_at' => $token->expires_at?->toISOString(),
            'created_at' => $token->created_at->toISOString(),
        ]);

        return Inertia::render('Tenant/Settings/ApiTokens', [
            'tokens' => $tokens,
            'availableAbilities' => AuthController::ABILITIES,
        ]);
    }

    /**
     * Create a new API token.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:'.implode(',', AuthController::ABILITIES)],
        ]);

        $token = $request->user()->createToken($validated['name'], $validated['abilities']);

        return back()->with('newToken', $token->plainTextToken);
    }

    /**
     * Revoke an API token.
     */
    public function destroy(Request $request, int $tokenId): RedirectResponse
    {
        $request->user()
            ->tokens()
            ->where('id', $tokenId)
            ->delete();

        return back()->with('success', 'Token revoked.');
    }
}
