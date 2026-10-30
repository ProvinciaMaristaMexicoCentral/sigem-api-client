# Instalación

## 1. Requiere el paquete

Ruta B (repo GitHub privado + VCS, gratis): en la app agrega el repo y requiere:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:maristas/sigem-api-client.git" }
]
```

```bash
composer require maristas/sigem-api-client:^0.1
```

Necesitas una credencial con lectura del repo (SSH deploy key o
`COMPOSER_AUTH_JSON` con token en servidor/CI).

Ruta A (Packagist.com privado): sigue su guía de repo privado; el
`composer require` es el mismo.

## 2. Publica la config (opcional)

```bash
php artisan vendor:publish --tag=sigem-api
```

Lee `SIGEM_BASE_URL` (ya existe en PJM) y expone TTLs y llaves de sesión:

| Env | Default | Uso |
| --- | --- | --- |
| `SIGEM_BASE_URL` | `http://localhost` | base de SIGEM |
| `SIGEM_API_TIMEOUT` / `SIGEM_API_CONNECT_TIMEOUT` | `10` / `3` | segundos |
| `SIGEM_API_CACHE_STORE` | (default app) | en prod: `redis` |
| `SIGEM_API_CACHE_PREFIX` | `sigem` | prefijo de claves |
| `SIGEM_API_TTL_{CATALOGOS,ME,ALUMNOS,DETALLE,FISCALES}` | `43200/600/120/300/60` | TTL en segundos |

## 3. Guarda los tokens en el login SSO

En tu `SsoController::callback`, tras el intercambio de código:

```php
$request->session()->put([
    'sigem.access_token' => $tokenResponse->json('access_token'),
    'sigem.refresh_token' => $tokenResponse->json('refresh_token'),
    'sigem.user_key' => (string) $profile['id'],
]);
```

El provider registra `SigemApiClient` como singleton que lee la sesión y
renueva el token solo (vía `refresh_token` + `services.sigem` client/secret).
