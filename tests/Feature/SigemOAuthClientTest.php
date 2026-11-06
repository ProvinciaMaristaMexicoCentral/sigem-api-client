<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Maristas\SigemApi\Auth\AuthorizationAttempt;
use Maristas\SigemApi\Auth\SigemOAuthClient;
use Maristas\SigemApi\Exceptions\SigemAuthorizationException;
use Maristas\SigemApi\Exceptions\SigemConfigurationException;
use Maristas\SigemApi\Exceptions\SigemProfileException;
use Maristas\SigemApi\Exceptions\SigemTokenException;

function oauthConfig(array $overrides = []): array
{
    return array_merge([
        'base_url' => 'http://sigem.test',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'http://pjm.test/sso/callback',
        'authorize_endpoint' => '/oauth/authorize',
        'token_endpoint' => '/oauth/token',
        'userinfo_endpoint' => '/api/sso/user',
        'scopes' => 'profile acceso:read',
        'timeout' => 5,
        'connect_timeout' => 2,
        'attempt_ttl' => 600,
    ], $overrides);
}

function oauthAttempt(?int $now = null): AuthorizationAttempt
{
    $now ??= time();

    return AuthorizationAttempt::create('state-abc', str_repeat('a', 64), $now, 600);
}

it('construye la url de autorización con endpoints y valores configurados', function () {
    $start = (new SigemOAuthClient(oauthConfig(), new Factory()))->start();

    expect($start->authorizationUrl)->toStartWith('http://sigem.test/oauth/authorize?')
        ->and($start->authorizationUrl)->toContain('client_id=client-id')
        ->and($start->authorizationUrl)->toContain('redirect_uri='.rawurlencode('http://pjm.test/sso/callback'))
        ->and($start->authorizationUrl)->toContain('response_type=code')
        ->and($start->authorizationUrl)->toContain('scope='.rawurlencode('profile acceso:read'))
        ->and($start->authorizationUrl)->toContain('code_challenge_method=S256')
        ->and($start->authorizationUrl)->not->toContain($start->attempt->codeVerifier);
});

it('respeta endpoints personalizados y no agrega scopes', function () {
    $http = new Factory();
    $client = new SigemOAuthClient(oauthConfig([
        'authorize_endpoint' => '/custom/authorize',
        'token_endpoint' => '/custom/token',
        'userinfo_endpoint' => '/custom/me',
    ]), $http);

    $start = $client->start('solo:uno');

    expect($start->authorizationUrl)->toStartWith('http://sigem.test/custom/authorize?')
        ->and($start->authorizationUrl)->toContain('scope='.rawurlencode('solo:uno'))
        ->and($start->authorizationUrl)->not->toContain('catalogos');
});

it('genera state y verifier distintos por intento y deriva S256 con vector conocido', function () {
    $client = new SigemOAuthClient(oauthConfig(), new Factory());

    $first = $client->start();
    $second = $client->start();

    expect($first->attempt->state)->not->toBe($second->attempt->state)
        ->and($first->attempt->codeVerifier)->not->toBe($second->attempt->codeVerifier);

    // Vector RFC 7636 Apéndice B (verificado con openssl: mismo digest).
    expect(SigemOAuthClient::deriveChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))
        ->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

it('rechaza state ausente, inválido o diferente sin llamar al token endpoint', function () {
    $http = new Factory();
    $http->fake(['*' => $http->response(['access_token' => 'x'], 200)]);
    $client = new SigemOAuthClient(oauthConfig(), $http);
    $attempt = oauthAttempt();

    expect(fn () => $client->exchange($attempt, null, 'code'))->toThrow(SigemAuthorizationException::class);
    expect(fn () => $client->exchange($attempt, '', 'code'))->toThrow(SigemAuthorizationException::class);
    expect(fn () => $client->exchange($attempt, ['x'], 'code'))->toThrow(SigemAuthorizationException::class);
    expect(fn () => $client->exchange($attempt, 'otro-state', 'code'))->toThrow(SigemAuthorizationException::class);
    expect($http->recorded())->toHaveCount(0);
});

it('rechaza intentos expirados', function () {
    $http = new Factory();
    $http->fake(['*' => $http->response(['access_token' => 'x'], 200)]);
    $client = new SigemOAuthClient(oauthConfig(), $http);

    $expired = AuthorizationAttempt::create('s', str_repeat('b', 64), 1000, 600);

    expect(fn () => $client->exchange($expired, 's', 'code', null, 2000))
        ->toThrow(SigemAuthorizationException::class);
    expect($http->recorded())->toHaveCount(0);
});

it('rechaza la denegación del proveedor sin continuar', function () {
    $http = new Factory();
    $http->fake(['*' => $http->response(['access_token' => 'x'], 200)]);
    $client = new SigemOAuthClient(oauthConfig(), $http);

    expect(fn () => $client->exchange(oauthAttempt(), 'state-abc', 'code', 'access_denied'))
        ->toThrow(SigemAuthorizationException::class);
    expect($http->recorded())->toHaveCount(0);
});

it('rechaza callback sin código válido', function () {
    $http = new Factory();
    $http->fake(['*' => $http->response(['access_token' => 'x'], 200)]);
    $client = new SigemOAuthClient(oauthConfig(), $http);

    expect(fn () => $client->exchange(oauthAttempt(), 'state-abc', null))->toThrow(SigemAuthorizationException::class);
    expect(fn () => $client->exchange(oauthAttempt(), 'state-abc', ''))->toThrow(SigemAuthorizationException::class);
    expect($http->recorded())->toHaveCount(0);
});

it('canjea con POST de formulario y campos correctos', function () {
    $http = new Factory();
    $http->fake(['*' => $http->response([
        'access_token' => 'tok',
        'refresh_token' => 'ref',
        'expires_in' => 3600,
        'token_type' => 'Bearer',
        'scope' => 'profile acceso:read',
    ], 200)]);

    $client = new SigemOAuthClient(oauthConfig(), $http);
    $tokens = $client->exchange(oauthAttempt(), 'state-abc', 'auth-code');

    expect($tokens->accessToken)->toBe('tok')
        ->and($tokens->refreshToken)->toBe('ref');

    $http->assertSent(function (Request $r): bool {
        return $r->method() === 'POST'
            && str_ends_with($r->url(), '/oauth/token')
            && $r['grant_type'] === 'authorization_code'
            && $r['client_id'] === 'client-id'
            && $r['client_secret'] === 'client-secret'
            && $r['redirect_uri'] === 'http://pjm.test/sso/callback'
            && $r['code'] === 'auth-code'
            && $r['code_verifier'] === str_repeat('a', 64)
            && str_contains($r->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded');
    });
});

it('mapea error del token endpoint, respuesta malformada y fallo de conexión', function () {
    $badStatus = new Factory();
    $badStatus->fake(['*' => $badStatus->response(['error' => 'invalid_grant'], 400)]);

    expect(fn () => (new SigemOAuthClient(oauthConfig(), $badStatus))->exchange(oauthAttempt(), 'state-abc', 'code'))
        ->toThrow(SigemTokenException::class);

    $malformed = new Factory();
    $malformed->fake(['*' => $malformed->response(['sin_access_token' => true], 200)]);

    expect(fn () => (new SigemOAuthClient(oauthConfig(), $malformed))->exchange(oauthAttempt(), 'state-abc', 'code'))
        ->toThrow(SigemTokenException::class);

    $offline = new Factory();
    $offline->fake(['*' => $offline->failedConnection()]);

    expect(fn () => (new SigemOAuthClient(oauthConfig(), $offline))->exchange(oauthAttempt(), 'state-abc', 'code'))
        ->toThrow(SigemTokenException::class);
});

it('consulta el perfil con Bearer y valida la respuesta', function () {
    $http = new Factory();
    $http->fake(['*' => $http->response([
        'id' => 123,
        'name' => 'Test User',
        'email' => 'test@maristas.edu.mx',
        'username' => 'test@maristas.edu.mx',
        'email_verified_at' => '2026-01-01 00:00:00',
        'extra' => 'tolerado',
    ], 200)]);

    $profile = (new SigemOAuthClient(oauthConfig(), $http))->fetchProfile('tok');

    expect($profile->id)->toBe('123')
        ->and($profile->email)->toBe('test@maristas.edu.mx');

    $http->assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer tok')
        && str_ends_with($r->url(), '/api/sso/user'));

    $badProfile = new Factory();
    $badProfile->fake(['*' => $badProfile->response(['id' => null, 'email' => 'no-es-email'], 200)]);

    expect(fn () => (new SigemOAuthClient(oauthConfig(), $badProfile))->fetchProfile('tok'))
        ->toThrow(SigemProfileException::class);

    $denied = new Factory();
    $denied->fake(['*' => $denied->response([], 401)]);

    expect(fn () => (new SigemOAuthClient(oauthConfig(), $denied))->fetchProfile('tok'))
        ->toThrow(SigemProfileException::class);
});

it('valida configuración al usar OAuth sin impedir otros usos', function () {
    $client = new SigemOAuthClient([], new Factory());

    expect(fn () => $client->start())->toThrow(SigemConfigurationException::class);
    expect(fn () => $client->fetchProfile('tok'))->toThrow(SigemConfigurationException::class);
});
