<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAgentTokenRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AgentAccessController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/agent-access', [
            'tokens' => $request->user()->tokens()->latest('id')
                ->get(['id', 'name', 'created_at', 'last_used_at'])
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                ]),
            'endpoint' => url('/mcp'),
        ]);
    }

    public function rotate(Request $request, int $token): JsonResponse
    {
        $credential = $request->user()->tokens()->findOrFail($token);
        $secret = $request->user()->generateTokenString();
        $credential->forceFill(['token' => hash('sha256', $secret), 'last_used_at' => null])->save();

        return response()->json([
            'token' => ['id' => $credential->id, 'name' => $credential->name],
            'plain_text_token' => $credential->id.'|'.$secret,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, int $token): HttpResponse
    {
        $request->user()->tokens()->findOrFail($token)->delete();

        return response()->noContent();
    }

    public function store(StoreAgentTokenRequest $request): JsonResponse
    {
        $token = $request->user()->createToken($request->validated('name'), ['financial-data:read']);

        return response()->json([
            'token' => ['id' => $token->accessToken->id, 'name' => $token->accessToken->name],
            'plain_text_token' => $token->plainTextToken,
        ], 201)->header('Cache-Control', 'no-store, private');
    }
}
