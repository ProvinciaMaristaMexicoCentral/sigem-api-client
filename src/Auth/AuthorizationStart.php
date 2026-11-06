<?php

namespace Maristas\SigemApi\Auth;

/**
 * Resultado de iniciar la autorización: URL + intento que la app debe conservar.
 */
final class AuthorizationStart
{
    public function __construct(
        public readonly string $authorizationUrl,
        public readonly AuthorizationAttempt $attempt,
    ) {}
}
