<?php

namespace Maristas\SigemApi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class SigemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sigem-api.php', 'sigem-api');

        $this->app->singleton(SigemApiClient::class, function ($app): SigemApiClient {
            $config = $app['config']->get('sigem-api');
            $keys = $config['session_keys'];

            $client = new SigemApiClient($config);

            $token = $app['session']->get($keys['access_token']);
            $userKey = $app['session']->get($keys['user_key'], 'anon');

            if (! is_string($token) || $token === '') {
                return $client;
            }

            return $client->forUser($token, (string) $userKey, function () use ($app, $config, $keys): ?string {
                $refreshToken = $app['session']->get($keys['refresh_token']);

                if (! is_string($refreshToken) || $refreshToken === '') {
                    return null;
                }

                $oauth = $app['config']->get('services.sigem');

                $tokens = SigemApiClient::refreshToken(
                    (string) $config['base_url'],
                    (string) ($oauth['client_id'] ?? ''),
                    (string) ($oauth['client_secret'] ?? ''),
                    $refreshToken,
                    (int) ($config['timeout'] ?? 10)
                );

                $app['session']->put($keys['access_token'], $tokens['access_token']);
                $app['session']->put($keys['refresh_token'], $tokens['refresh_token'] ?? $refreshToken);

                return $tokens['access_token'] ?? null;
            });
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sigem-api.php' => config_path('sigem-api.php'),
            ], 'sigem-api');
        }

        Http::macro('sigem', fn (): SigemApiClient => app(SigemApiClient::class));
    }
}
