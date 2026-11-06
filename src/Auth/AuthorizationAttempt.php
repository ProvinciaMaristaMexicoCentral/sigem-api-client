<?php

namespace Maristas\SigemApi\Auth;

use Maristas\SigemApi\Exceptions\SigemAuthorizationException;

/**
 * Intento de autorización OAuth2 (Authorization Code + PKCE).
 *
 * Es un dato de protocolo, sin dependencia de sesión HTTP ni de la app.
 * La aplicación lo conserva (p. ej. en sesión) y lo devuelve en el callback
 * para su validación y consumo único.
 */
final class AuthorizationAttempt
{
    public const DEFAULT_TTL = 600;

    public function __construct(
        public readonly string $state,
        public readonly string $codeVerifier,
        public readonly int $createdAt,
        public readonly int $expiresAt,
    ) {}

    public static function create(string $state, string $codeVerifier, ?int $now = null, int $ttl = self::DEFAULT_TTL): self
    {
        $createdAt = $now ?? time();

        return new self($state, $codeVerifier, $createdAt, $createdAt + $ttl);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $state = $data['state'] ?? null;
        $verifier = $data['code_verifier'] ?? null;
        $createdAt = $data['created_at'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;

        if (! is_string($state) || $state === '') {
            throw new SigemAuthorizationException('Intento de autorización inválido.');
        }

        if (! is_string($verifier) || ! self::isValidVerifier($verifier)) {
            throw new SigemAuthorizationException('Intento de autorización inválido.');
        }

        if (! is_int($createdAt) || ! is_int($expiresAt) || $expiresAt <= $createdAt) {
            throw new SigemAuthorizationException('Intento de autorización inválido.');
        }

        return new self($state, $verifier, $createdAt, $expiresAt);
    }

    /**
     * @return array{state: string, code_verifier: string, created_at: int, expires_at: int}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'code_verifier' => $this->codeVerifier,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    public function isExpired(?int $now = null): bool
    {
        return ($now ?? time()) >= $this->expiresAt;
    }

    public static function isValidVerifier(string $verifier): bool
    {
        $length = strlen($verifier);

        if ($length < 43 || $length > 128) {
            return false;
        }

        return preg_match('/\A[A-Za-z0-9\-._~]+\z/', $verifier) === 1;
    }
}
