<?php

namespace Maristas\SigemApi\Auth;

use Maristas\SigemApi\Exceptions\SigemTokenException;

/**
 * Respuesta de tokens validada del endpoint /oauth/token.
 */
final class TokenSet
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?int $expiresIn,
        public readonly ?string $tokenType,
        public readonly ?string $scope,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $accessToken = $data['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new SigemTokenException('Respuesta de tokens inválida.');
        }

        $refreshToken = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;
        $tokenType = $data['token_type'] ?? null;
        $scope = $data['scope'] ?? null;

        if ($refreshToken !== null && (! is_string($refreshToken) || $refreshToken === '')) {
            throw new SigemTokenException('Respuesta de tokens inválida.');
        }

        if ($expiresIn !== null && ! is_int($expiresIn)) {
            if (is_numeric($expiresIn)) {
                $expiresIn = (int) $expiresIn;
            } else {
                throw new SigemTokenException('Respuesta de tokens inválida.');
            }
        }

        if ($tokenType !== null && ! is_string($tokenType)) {
            throw new SigemTokenException('Respuesta de tokens inválida.');
        }

        if ($scope !== null && ! is_string($scope)) {
            throw new SigemTokenException('Respuesta de tokens inválida.');
        }

        return new self(
            $accessToken,
            $refreshToken,
            $expiresIn,
            $tokenType,
            $scope,
            $data,
        );
    }
}
