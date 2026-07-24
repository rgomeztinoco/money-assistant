<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateOpenClaw
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $keyId = $request->header('X-Money-Assistant-Key-Id');
        $timestamp = $request->header('X-Money-Assistant-Timestamp');
        $nonce = $request->header('X-Money-Assistant-Nonce');
        $encodedSignature = $request->header('X-Money-Assistant-Signature');

        if (! is_string($keyId)
            || ! is_string($timestamp)
            || ! is_string($nonce)
            || ! is_string($encodedSignature)
            || ! hash_equals((string) config('services.openclaw.capability.key_id'), $keyId)) {
            return $this->unauthorized();
        }

        $signature = base64_decode($encodedSignature, true);
        $publicKey = $this->publicKey();

        if ($signature === false
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || $publicKey === null
            || ! sodium_crypto_sign_verify_detached(
                $signature,
                $this->signedMessage($request, $timestamp, $nonce),
                $publicKey,
            )) {
            return $this->unauthorized();
        }

        $request->attributes->set('openclaw.key_id', $keyId);
        $request->attributes->set('openclaw.timestamp', $timestamp);
        $request->attributes->set('openclaw.nonce', $nonce);

        return $next($request);
    }

    /**
     * @return non-empty-string|null
     */
    private function publicKey(): ?string
    {
        $encodedPublicKey = config('services.openclaw.capability.public_key');

        if (! is_string($encodedPublicKey)) {
            return null;
        }

        $publicKey = base64_decode(trim($encodedPublicKey), true);

        if (! is_string($publicKey)
            || $publicKey === ''
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        return $publicKey;
    }

    private function signedMessage(Request $request, string $timestamp, string $nonce): string
    {
        return implode("\n", [
            $timestamp,
            $nonce,
            $request->getMethod(),
            '/'.$request->path(),
            hash('sha256', $request->getContent()),
        ]);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
    }
}
