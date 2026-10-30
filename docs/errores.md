# Errores

| Status | Excepción | Acción recomendada |
| --- | --- | --- |
| 401 | `SigemAuthException` | el cliente ya reintentó con refresh una vez; manda a re-login SSO y limpia sesión |
| 403 | `SigemForbiddenException` | falta scope o recurso ajeno; no reintentar, mostrar "sin permiso" |
| 422 | `SigemValidationException` | revisa `$e->errors()` (ej. falta `institucion_id`) |
| 429 | `SigemRateLimitException` | backoff y reintenta (fiscales: 30/min en SIGEM) |
| 5xx / no JSON | `SigemServerException` | log + mensaje genérico |
| sin token / sin userKey | `SigemConfigurationException` / `InvalidArgumentException` | bug de integración, no de SIGEM |

Ejemplo:

```php
use Maristas\SigemApi\Exceptions\SigemAuthException;
use Maristas\SigemApi\Exceptions\SigemForbiddenException;

try {
    $alumnos = $sigem->alumnos(['institucion_id' => $id]);
} catch (SigemAuthException) {
    return redirect()->route('sso.redirect');
} catch (SigemForbiddenException) {
    abort(403);
}
```
