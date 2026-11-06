<?php

namespace Maristas\SigemApi\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Maristas\SigemApi\Exceptions\SigemAuthorizationException;
use Maristas\SigemApi\Exceptions\SigemConfigurationException;
use Maristas\SigemApi\Exceptions\SigemProfileException;
use Maristas\SigemApi\Exceptions\SigemTokenException;

/**
 * Protocolo OAuth2 Authorization Code + PKCE (S256) contra SIGEM.
 *
 * Solo protocolo: genera el intento, construye la URL, canjea el código y
 * consulta el perfil. No conoce sesión, Request, Auth ni modelos locales.
 *
 * @param  array<string, mixed>  $config  base_url, client_id, client_secret,
 *   redirect_uri, authorize_endpoint, token_endpoint, userinfo_endpoint,
 *   scopes, timeout, connect_timeout, attempt_ttl.
 * @param  callable(): int|null  $clock
 */
final class SigemOAuthClient
{
    /**
     * @param  array<string, mixed>  $config
     * @param  callable(): int|null  $clock
     */
    public function __construct(
        protected array $config = [],
        protected ?HttpFactory $http = null,
        protected $clock = null,
    ) {}

    public function start(?string $scopes = null): AuthorizationStart
    {
        $baseUrl = $this->stringConfig('base_url');
        $clientId = $this->stringConfig('client_id');
        $redirectUri = $this->stringConfig('redirect_uri');
        $authorizeEndpoint = $this->stringConfig('authorize_endpoint', '/oauth/authorize');

        if ($baseUrl === null || $clientId === null || $redirectUri === null || $authorizeEndpoint === null) {
            throw new SigemConfigurationException('Configuración OAuth de SIGEM incompleta.');
        }

        $requestedScopes = $scopes ?? $this->stringConfig('scopes');
        $state = self::generateState();
        $verifier = self::generateCodeVerifier();
        $challenge = self::deriveChallenge($verifier);

        $attempt = AuthorizationAttempt::create($state, $verifier, $this->now(), $this->attemptTtl());

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        if (is_string($requestedScopes) && trim($requestedScopes) !== '') {
            $params['scope'] = $requestedScopes;
        }

        $url = rtrim($baseUrl, '/').$authorizeEndpoint.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return new AuthorizationStart($url, $attempt);
    }

    /**
     * Valida el callback y canjea el código por tokens.
     *
     * @param  mixed  $receivedState  valor de ?state del callback.
     * @param  mixed  $code  valor de ?code del callback.
     * @param  mixed  $error  valor de ?error del callback.
     */
    public function exchange(AuthorizationAttempt $attempt, mixed $receivedState, mixed $code, mixed $error = null, ?int $now = null): TokenSet
    {
        if ($error !== null && $error !== '') {
            throw new SigemAuthorizationException('Autorización denegada por SIGEM.');
        }

        if ($attempt->isExpired($now ?? $this->now())) {
            throw new SigemAuthorizationException('Intento de autorización expirado.');
        }

        if (! is_string($receivedState) || $receivedState === '') {
            throw new SigemAuthorizationException('Estado de autorización inválido.');
        }

        if (! hash_equals($attempt->state, $receivedState)) {
            throw new SigemAuthorizationException('Estado de autorización inválido.');
        }

        if (! is_string($code) || $code === '') {
            throw new SigemAuthorizationException('Código de autorización inválido.');
        }

        $baseUrl = $this->stringConfig('base_url');
        $clientId = $this->stringConfig('client_id');
        $clientSecret = $this->stringConfig('client_secret');
        $redirectUri = $this->stringConfig('redirect_uri');
        $tokenEndpoint = $this->stringConfig('token_endpoint', '/oauth/token');

        if ($baseUrl === null || $clientId === null || $clientSecret === null || $redirectUri === null || $tokenEndpoint === null) {
            throw new SigemConfigurationException('Configuración OAuth de SIGEM incompleta.');
        }

        $url = rtrim($baseUrl, '/').$tokenEndpoint;

        try {
            $response = $this->http()
                ->asForm()
                ->timeout($this->timeout())
                ->connectTimeout($this->connectTimeout())
                ->post($url, [
                    'grant_type' => 'authorization_code',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                    'code' => $code,
                    'code_verifier' => $attempt->codeVerifier,
                ]);
        } catch (ConnectionException $e) {
            throw new SigemTokenException('No se pudo contactar a SIGEM.', 0, $e);
        }

        if (! $response->successful()) {
            throw new SigemTokenException('No se pudo completar el intercambio de código.');
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new SigemTokenException('Respuesta de tokens inválida.');
        }

        /** @var array<string, mixed> $data */
        return TokenSet::fromArray($data);
    }

    public function fetchProfile(string $accessToken): SigemUserProfile
    {
        if ($accessToken === '') {
            throw new SigemConfigurationException('Falta el access token para consultar el perfil.');
        }

        $baseUrl = $this->stringConfig('base_url');
        $userinfoEndpoint = $this->stringConfig('userinfo_endpoint', '/api/sso/user');

        if ($baseUrl === null || $userinfoEndpoint === null) {
            throw new SigemConfigurationException('Configuración OAuth de SIGEM incompleta.');
        }

        try {
            $response = $this->http()
                ->withToken($accessToken)
                ->acceptJson()
                ->timeout($this->timeout())
                ->connectTimeout($this->connectTimeout())
                ->get(rtrim($baseUrl, '/').$userinfoEndpoint);
        } catch (ConnectionException $e) {
            throw new SigemProfileException('No se pudo contactar a SIGEM.', 0, $e);
        }

        if (! $response->successful()) {
            throw new SigemProfileException('No se pudo obtener el perfil desde SIGEM.');
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new SigemProfileException('Perfil de SIGEM inválido.');
        }

        /** @var array<string, mixed> $data */
        return SigemUserProfile::fromArray($data);
    }

    public static function generateState(): string
    {
        return self::base64Url(random_bytes(32));
    }

    public static function generateCodeVerifier(): string
    {
        return self::base64Url(random_bytes(64));
    }

    public static function deriveChallenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    protected static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    protected function stringConfig(string $key, ?string $default = null): ?string
    {
        $value = $this->config[$key] ?? $default;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    protected function timeout(): int
    {
        $timeout = $this->config['timeout'] ?? 10;

        return is_int($timeout) ? $timeout : (int) $timeout;
    }

    protected function connectTimeout(): int
    {
        $connectTimeout = $this->config['connect_timeout'] ?? 3;

        return is_int($connectTimeout) ? $connectTimeout : (int) $connectTimeout;
    }

    protected function attemptTtl(): int
    {
        $ttl = $this->config['attempt_ttl'] ?? AuthorizationAttempt::DEFAULT_TTL;

        return is_int($ttl) ? $ttl : (int) $ttl;
    }

    protected function now(): int
    {
        if (is_callable($this->clock)) {
            $now = ($this->clock)();

            if (is_int($now)) {
                return $now;
            }
        }

        return time();
    }

    protected function http(): HttpFactory
    {
        return $this->http ?? Http::getFacadeRoot();
    }
}
