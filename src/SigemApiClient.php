<?php

namespace Maristas\SigemApi;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Maristas\SigemApi\Exceptions\SigemAuthException;
use Maristas\SigemApi\Exceptions\SigemConfigurationException;
use Maristas\SigemApi\Exceptions\SigemForbiddenException;
use Maristas\SigemApi\Exceptions\SigemRateLimitException;
use Maristas\SigemApi\Exceptions\SigemServerException;
use Maristas\SigemApi\Exceptions\SigemValidationException;

/**
 * Cliente HTTP con cache para la API SSO de SIGEM (`/api/sso/v1/*`).
 *
 * El token es por usuario: toda respuesta operativa se cachea con una clave
 * atada al usuario para no filtrar datos entre sesiones.
 */
class SigemApiClient
{
    protected ?string $accessToken = null;

    protected ?string $userKey = null;

    /** @var callable|null */
    protected $refreshHandler = null;

    protected bool $refreshAttempted = false;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = [],
        protected ?HttpFactory $http = null,
        protected ?CacheRepository $cache = null,
    ) {}

    /**
     * Clona el cliente autenticado como un usuario.
     *
     * @param  callable|null  $refreshHandler  fn(): ?string — retorna el access token renovado o null.
     */
    public function forUser(string $accessToken, ?string $userKey = null, ?callable $refreshHandler = null): static
    {
        $clone = clone $this;
        $clone->accessToken = $accessToken;
        $clone->userKey = $userKey;
        $clone->refreshHandler = $refreshHandler;
        $clone->refreshAttempted = false;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->cachedGet('/api/sso/v1/me/relaciones', [], 'me', $this->ttl('me'));
    }

    /**
     * @return array<string, mixed>
     */
    public function instituciones(array $params = []): array
    {
        return $this->cachedGet('/api/sso/v1/instituciones', $params, 'instituciones', $this->ttl('catalogos'), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function institucion(int $id): array
    {
        return $this->cachedGet("/api/sso/v1/instituciones/{$id}", [], "institucion:{$id}", $this->ttl('catalogos'), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function ciclos(array $params = []): array
    {
        return $this->cachedGet('/api/sso/v1/ciclos-escolares', $params, 'ciclos', $this->ttl('catalogos'), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function ciclo(int $id): array
    {
        return $this->cachedGet("/api/sso/v1/ciclos-escolares/{$id}", [], "ciclo:{$id}", $this->ttl('catalogos'), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function programas(int $institucionId, array $params = []): array
    {
        return $this->cachedGet(
            '/api/sso/v1/programas',
            ['institucion_id' => $institucionId] + $params,
            'programas',
            $this->ttl('catalogos'),
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function programa(int $id): array
    {
        return $this->cachedGet("/api/sso/v1/programas/{$id}", [], "programa:{$id}", $this->ttl('catalogos'), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function alumnos(array $params): array
    {
        if (! isset($params['institucion_id'])) {
            throw new InvalidArgumentException('alumnos() requiere institucion_id.');
        }

        return $this->cachedGet('/api/sso/v1/alumnos', $params, 'alumnos', $this->ttl('alumnos'));
    }

    /**
     * Itera todos los alumnos paginando en SIGEM.
     *
     * @param  callable(array<string, mixed>): (bool|void)  $each  Retorna false para detener.
     */
    public function eachAlumnos(array $params, callable $each): void
    {
        $page = 1;

        do {
            $response = $this->alumnos($params + ['page' => $page]);
            $lastPage = (int) ($response['meta']['last_page'] ?? 1);

            foreach ($response['data'] ?? [] as $alumno) {
                if ($each($alumno) === false) {
                    return;
                }
            }

            $page++;
        } while ($page <= $lastPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function alumno(int $id): array
    {
        return $this->cachedGet("/api/sso/v1/alumnos/{$id}", [], "alumno:{$id}", $this->ttl('detalle'));
    }

    /**
     * @return array<string, mixed>
     */
    public function familiaresDeAlumno(int $id): array
    {
        return $this->cachedGet("/api/sso/v1/alumnos/{$id}/familiares", [], "alumno:{$id}:familiares", $this->ttl('detalle'));
    }

    /**
     * @return array<string, mixed>
     */
    public function familiar(int $id): array
    {
        return $this->cachedGet("/api/sso/v1/familiares/{$id}", [], "familiar:{$id}", $this->ttl('detalle'));
    }

    /**
     * @return array<string, mixed>
     */
    public function registrosDeAlumno(int $id): array
    {
        return $this->cachedGet("/api/sso/v1/alumnos/{$id}/registros-academicos", [], "alumno:{$id}:registros", $this->ttl('detalle'));
    }

    /**
     * @return array<string, mixed>
     */
    public function datosFiscalesDeAlumno(int $id): array
    {
        return $this->cachedGet("/api/sso/v1/alumnos/{$id}/datos-fiscales", [], "alumno:{$id}:fiscales", $this->ttl('fiscales'));
    }

    /**
     * Invalida todo el cache del usuario actual (bump de versión de claves).
     */
    public function forgetUserCache(): void
    {
        $key = $this->versionKey($this->userKeyFor(false));
        $version = (int) $this->cache()->get($key, 1);
        $this->cache()->forever($key, $version + 1);
    }

    /**
     * Renueva un access token con el refresh token (grant_type=refresh_token).
     *
     * @return array<string, mixed>  Respuesta de /oauth/token.
     */
    public static function refreshToken(
        string $baseUrl,
        string $clientId,
        string $clientSecret,
        string $refreshToken,
        int $timeout = 10,
    ): array {
        $response = Http::asForm()->timeout($timeout)->post(
            rtrim($baseUrl, '/').'/oauth/token',
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]
        );

        if (! $response->successful()) {
            throw new SigemAuthException('No se pudo renovar el token de SIGEM.');
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    protected function cachedGet(string $uri, array $params, string $resource, int $ttl, bool $shared = false): array
    {
        $key = $this->cacheKey($resource, $params, $shared);

        return $this->cache()->remember($key, $ttl, fn (): array => $this->request('GET', $uri, $params));
    }

    /**
     * @return array<string, mixed>
     */
    protected function request(string $method, string $uri, array $params = []): array
    {
        $response = $this->pendingRequest()->get($uri, $params);

        if ($response->status() === 401 && $this->refreshHandler !== null && ! $this->refreshAttempted) {
            $this->refreshAttempted = true;
            $renewed = ($this->refreshHandler)();

            if (is_string($renewed) && $renewed !== '') {
                $this->accessToken = $renewed;
                $response = $this->pendingRequest()->get($uri, $params);
            }
        }

        return $this->mapResponse($response);
    }

    protected function pendingRequest(): \Illuminate\Http\Client\PendingRequest
    {
        if ($this->accessToken === null || $this->accessToken === '') {
            throw new SigemConfigurationException('Falta el access token: usa forUser() antes de llamar a la API.');
        }

        return $this->http()
            ->baseUrl(rtrim((string) ($this->config['base_url'] ?? ''), '/'))
            ->withToken($this->accessToken)
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 10))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 3))
            ->retry(2, 100, function (\Throwable $e): bool {
                // Solo reintenta fallos de conexion y errores 5xx.
                // Los 401/403/422/429 los maneja el cliente (refresh o excepcion tipada).
                return ! $e instanceof RequestException
                    || $e->response === null
                    || $e->response->serverError();
            }, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapResponse(Response $response): array
    {
        if ($response->successful()) {
            $data = $response->json();

            if (! is_array($data)) {
                throw new SigemServerException('SIGEM devolvio una respuesta no JSON.');
            }

            return $data;
        }

        throw match ($response->status()) {
            401 => new SigemAuthException('No autorizado en SIGEM (token invalido o expirado).'),
            403 => new SigemForbiddenException('Sin permiso en SIGEM (scope o recurso ajeno).'),
            422 => new SigemValidationException(
                (string) ($response->json('message') ?? 'Datos invalidos para la API de SIGEM.'),
                (array) ($response->json('errors') ?? [])
            ),
            429 => new SigemRateLimitException('Limite de peticiones a SIGEM excedido.'),
            default => new SigemServerException("SIGEM respondio {$response->status()}."),
        };
    }

    protected function cacheKey(string $resource, array $params, bool $shared): string
    {
        $userKey = $this->userKeyFor($shared);
        $version = $this->cache()->get($this->versionKey($userKey), 1);
        $fingerprint = md5(json_encode($params) ?: '');

        return "{$this->config['cache_prefix']}:{$userKey}:v{$version}:{$resource}:{$fingerprint}";
    }

    protected function userKeyFor(bool $shared): string
    {
        if ($shared) {
            return $this->userKey ?? 'shared';
        }

        if ($this->userKey === null || $this->userKey === '') {
            throw new SigemConfigurationException('Los datos operativos requieren userKey: usa forUser().');
        }

        return 'u:'.$this->userKey;
    }

    protected function versionKey(string $userKey): string
    {
        return "{$this->config['cache_prefix']}:version:{$userKey}";
    }

    protected function ttl(string $resource): int
    {
        return (int) ($this->config['ttls'][$resource] ?? 60);
    }

    protected function http(): HttpFactory
    {
        return $this->http ?? Http::getFacadeRoot();
    }

    protected function cache(): CacheRepository
    {
        return $this->cache ?? Cache::store($this->config['cache_store'] ?? null);
    }
}
