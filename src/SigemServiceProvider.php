<?php

namespace Maristas\SigemApi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Maristas\SigemApi\Auth\SigemOAuthClient;

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

        $this->app->singleton(SigemOAuthClient::class, function ($app): SigemOAuthClient {
            $oauth = $app['config']->get('services.sigem', []);
            $api = $app['config']->get('sigem-api', []);

            return new SigemOAuthClient([
                'base_url' => $oauth['base_url'] ?? $api['base_url'] ?? null,
                'client_id' => $oauth['client_id'] ?? null,
                'client_secret' => $oauth['client_secret'] ?? null,
                'redirect_uri' => $oauth['redirect_uri'] ?? null,
                'authorize_endpoint' => $oauth['authorize_endpoint'] ?? '/oauth/authorize',
                'token_endpoint' => $oauth['token_endpoint'] ?? '/oauth/token',
                'userinfo_endpoint' => $oauth['userinfo_endpoint'] ?? '/api/sso/user',
                'scopes' => $oauth['scopes'] ?? null,
                'timeout' => $api['timeout'] ?? 10,
                'connect_timeout' => $api['connect_timeout'] ?? 3,
                'attempt_ttl' => $api['oauth']['attempt_ttl'] ?? 600,
            ]);
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
