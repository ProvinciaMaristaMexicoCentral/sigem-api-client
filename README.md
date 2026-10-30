# maristas/sigem-api-client

Cliente HTTP con cache para consumir la API SSO de SIGEM (`/api/sso/v1/*`)
desde sistemas periféricos (PJM, EventosPJM, ...).

- Autenticación por usuario (Bearer del login SSO) + refresh-on-401 con un reintento.
- Cache atado al usuario: nunca cruza sesiones.
- Excepciones tipadas por status.

Docs: `docs/instalacion.md`, `docs/uso.md`, `docs/cache.md`, `docs/errores.md`.
