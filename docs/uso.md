# Uso

```php
use Maristas\SigemApi\SigemApiClient;

$sigem = app(SigemApiClient::class); // ya viene con el token de sesion
```

## Bootstrap del usuario

```php
$yo = $sigem->me();
// ['tipo' => 'familiar', 'familiar_id' => 5, 'hijos_ids' => [12], 'propios_ids' => []]
```

## Catálogos (scope `catalogos:read`, cache compartido 12 h)

```php
$sigem->instituciones(['per_page' => 50]);
$sigem->ciclos(['institucion_id' => 2]);
$sigem->programas(2); // institucion_id requerido
```

## Alumnos del padre (scopes `alumnos|familiares|registros|fiscales:read`)

```php
$alumnos = $sigem->alumnos(['institucion_id' => 2, 'q' => 'gonzalez']);

foreach ($alumnos['data'] as $a) {
    $detalle = $sigem->alumno($a['id']);
    $familiares = $sigem->familiaresDeAlumno($a['id']);
    $registros = $sigem->registrosDeAlumno($a['id']);
    $fiscales = $sigem->datosFiscalesDeAlumno($a['id']); // scope fiscales:read
}

// O itera todo sin paginar a mano:
$sigem->eachAlumnos(['institucion_id' => 2], function (array $a): void {
    // ...
});
```

## Cliente manual (jobs, comandos)

```php
$cliente = (new SigemApiClient(config('sigem-api')))
    ->forUser($accessToken, 'padre@example.com', fn () => $nuevoToken);
```

## Invalidar cache de un usuario (soporte)

```php
$sigem->forgetUserCache();
```
