# Cache

## Claves

```
{sig_prefix}:{usuario}:{vN}:{recurso}:{md5(params)}
```

- Operativos: usuario = `u:{userKey}` (de `forUser()`).
- Catálogos: usuario = `u:{userKey}` o `shared` (no son PII, se comparte).
- `vN`: versión por usuario; `forgetUserCache()` la incrementa e invalida todo.

## TTLs (segundos, `config/sigem-api.php`)

| Recurso | Default | Motivo |
| --- | --- | --- |
| catálogos | 43200 | cambian poco |
| `me` | 600 | vínculos estables en sesión |
| alumnos (listado) | 120 | operativo, cambia con inscripciones |
| detalle/familiares/registros | 300 | lectura frecuente |
| fiscales | 60 | sensible + `throttle:30,1` en SIGEM |

Solo se cachean GET 200. Las excepciones no se cachean.

## Producción

- Usa `SIGEM_API_CACHE_STORE=redis` (o memcached). El driver `file`
  funciona pero sin invalidación distribuida.
- Nunca pongas `shared` en recursos operativos: el cliente lo impide
  (`userKey` requerido) a nivel código.
