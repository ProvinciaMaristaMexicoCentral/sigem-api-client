<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Maristas\SigemApi\Exceptions\SigemAuthException;
use Maristas\SigemApi\Exceptions\SigemConfigurationException;
use Maristas\SigemApi\Exceptions\SigemForbiddenException;
use Maristas\SigemApi\Exceptions\SigemServerException;
use Maristas\SigemApi\Exceptions\SigemValidationException;
use Maristas\SigemApi\SigemApiClient;

function testConfig(): array
{
    return [
        'base_url' => 'http://sigem.test',
        'timeout' => 5,
        'connect_timeout' => 2,
        'cache_store' => null,
        'cache_prefix' => 'sigem-test',
        'ttls' => [
            'catalogos' => 43200,
            'me' => 600,
            'alumnos' => 120,
            'detalle' => 300,
            'fiscales' => 60,
        ],
    ];
}

function testClient(?Factory $http = null): SigemApiClient
{
    return new SigemApiClient(
        testConfig(),
        $http ?? tap(new Factory(), fn (Factory $f): mixed => $f->fake()),
        new Repository(new ArrayStore())
    );
}

it('envia el bearer y decodifica me', function () {
    $http = new Factory();
    $http->fake(['*' => $http->response(['tipo' => 'familiar', 'hijos_ids' => [7]], 200)]);

    $me = testClient($http)->forUser('token-abc', 'padre@example.com')->me();

    expect($me['tipo'])->toBe('familiar')
        ->and($http->recorded(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer token-abc')))->toHaveCount(1);
});

it('cachea catalogos y evita el segundo request', function () {
    $http = new Factory();
    $http->fake(['*' => $http->response(['data' => []], 200)]);

    $client = testClient($http)->forUser('t');
    $client->instituciones(['per_page' => 10]);
    $client->instituciones(['per_page' => 10]);

    expect($http->recorded())->toHaveCount(1);
});

it('exige userKey en datos operativos pero no en catalogos', function () {
    $http = new Factory();
    $http->fake(['*' => $http->response(['data' => []], 200)]);

    $client = testClient($http)->forUser('t');
    $client->instituciones();

    expect(fn () => $client->alumnos(['institucion_id' => 1]))
        ->toThrow(SigemConfigurationException::class);
});

it('exige institucion_id en alumnos', function () {
    $client = testClient()->forUser('t', 'u');

    expect(fn () => $client->alumnos([]))->toThrow(InvalidArgumentException::class);
});

it('mapea 403, 422 y 500 a excepciones tipadas', function () {
    $forbidden = new Factory();
    $forbidden->fake(['*' => $forbidden->response([], 403)]);

    $unprocessable = new Factory();
    $unprocessable->fake(['*' => $unprocessable->response(['message' => 'Mal.', 'errors' => ['q' => ['x']]], 422)]);

    $serverError = new Factory();
    $serverError->fake(['*' => $serverError->response([], 500)]);

    expect(fn () => testClient($forbidden)->forUser('t', 'u')->alumno(1))
        ->toThrow(SigemForbiddenException::class);

    try {
        testClient($unprocessable)->forUser('t', 'u')->alumno(1);
        $this->fail('No lanzo SigemValidationException.');
    } catch (SigemValidationException $e) {
        expect($e->errors())->toBe(['q' => ['x']]);
    }

    expect(fn () => testClient($serverError)->forUser('t', 'u')->alumno(1))
        ->toThrow(SigemServerException::class);
});

it('reintenta una vez con el token renovado ante 401', function () {
    $http = new Factory();
    $http->fake([
        '*' => $http->sequence()
            ->push([], 401)
            ->push(['id' => 9, 'matricula' => 'A1'], 200),
    ]);

    $alumno = testClient($http)
        ->forUser('expirado', 'u', fn (): string => 'renovado')
        ->alumno(9);

    expect($alumno['matricula'])->toBe('A1')
        ->and($http->recorded(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer renovado')))->toHaveCount(1);
});

it('lanza auth sin refresh handler ante 401', function () {
    $http = new Factory();
    $http->fake(['*' => $http->response([], 401)]);

    expect(fn () => testClient($http)->forUser('t', 'u')->me())
        ->toThrow(SigemAuthException::class);
});

it('forgetUserCache invalida el cache del usuario', function () {
    $http = new Factory();
    $http->fake(['*' => $http->response(['tipo' => 'alumno'], 200)]);

    $client = testClient($http)->forUser('t', 'u');
    $client->me();
    $client->me();

    expect($http->recorded())->toHaveCount(1);

    $client->forgetUserCache();
    $client->me();

    expect($http->recorded())->toHaveCount(2);
});

it('pagina alumnos con eachAlumnos hasta last_page', function () {
    $http = new Factory();
    $http->fake([
        '*' => $http->sequence()
            ->push(['data' => [['id' => 1]], 'meta' => ['last_page' => 2]], 200)
            ->push(['data' => [['id' => 2]], 'meta' => ['last_page' => 2]], 200),
    ]);

    $vistos = [];
    testClient($http)->forUser('t', 'u')->eachAlumnos(
        ['institucion_id' => 3],
        function (array $alumno) use (&$vistos): void {
            $vistos[] = $alumno['id'];
        }
    );

    expect($vistos)->toBe([1, 2]);
});
