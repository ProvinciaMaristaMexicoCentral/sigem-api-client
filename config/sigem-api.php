<?php

return [

    'base_url' => env('SIGEM_BASE_URL', 'http://localhost'),

    'timeout' => (int) env('SIGEM_API_TIMEOUT', 10),

    'connect_timeout' => (int) env('SIGEM_API_CONNECT_TIMEOUT', 3),

    /*
    | Tienda de cache. null = la por defecto de la app.
    | En produccion usa redis o memcached.
    */
    'cache_store' => env('SIGEM_API_CACHE_STORE'),

    'cache_prefix' => env('SIGEM_API_CACHE_PREFIX', 'sigem'),

    /*
    | TTL en segundos por tipo de recurso. Las claves operativas siempre
    | llevan el usuario, asi que el cache nunca cruza sesiones.
    */
    'ttls' => [
        'catalogos' => (int) env('SIGEM_API_TTL_CATALOGOS', 43200),
        'me' => (int) env('SIGEM_API_TTL_ME', 600),
        'alumnos' => (int) env('SIGEM_API_TTL_ALUMNOS', 120),
        'detalle' => (int) env('SIGEM_API_TTL_DETALLE', 300),
        'fiscales' => (int) env('SIGEM_API_TTL_FISCALES', 60),
    ],

    /*
    | Llaves de sesion donde la app guarda los tokens del login SSO.
    */
    'session_keys' => [
        'access_token' => env('SIGEM_API_SESSION_ACCESS_TOKEN', 'sigem.access_token'),
        'refresh_token' => env('SIGEM_API_SESSION_REFRESH_TOKEN', 'sigem.refresh_token'),
        'user_key' => env('SIGEM_API_SESSION_USER_KEY', 'sigem.user_key'),
    ],

];
