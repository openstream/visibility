<?php

declare(strict_types=1);

namespace Openstream\Visibility\OAuth;

use Openstream\Visibility\App;

/**
 * OAuth-Endpunkte + Credentials je Plattform. Credentials aus .env. Redirect-URI wird aus
 * APP_BASE_URL abgeleitet (lokal DDEV, prod visibility.openstream.ch), damit derselbe Code
 * in beiden Umgebungen läuft.
 *
 * @phpstan-type ProviderCfg array{
 *   auth_url:string, token_url:string, client_id:string, client_secret:string,
 *   scopes:array<int,string>, extra_auth:array<string,string>
 * }
 */
final class OAuthProviderConfig
{
    /** @return array<string,mixed>|null Config oder null, wenn Credentials fehlen */
    public static function for(string $platform): ?array
    {
        $app = App::get();
        return match ($platform) {
            'youtube' => self::google($app),
            'instagram' => self::meta($app),
            'tiktok' => self::tiktok($app),
            default => null,
        };
    }

    public static function redirectUri(string $platform): string
    {
        $base = rtrim(App::get()->env('APP_BASE_URL', 'https://visibility-openstream.ddev.site') ?? '', '/');
        return "{$base}/connect/{$platform}/callback";
    }

    /** @return array<string,mixed>|null */
    private static function google(App $app): ?array
    {
        $id = $app->env('GOOGLE_OAUTH_CLIENT_ID');
        $secret = $app->env('GOOGLE_OAUTH_CLIENT_SECRET');
        if (!$id || !$secret) {
            return null;
        }
        return [
            'auth_url'      => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'     => 'https://oauth2.googleapis.com/token',
            'client_id'     => $id,
            'client_secret' => $secret,
            'scopes'        => ['https://www.googleapis.com/auth/yt-analytics.readonly'],
            // offline + consent erzwingt einen Refresh-Token (sonst nur beim ersten Consent).
            'extra_auth'    => ['access_type' => 'offline', 'prompt' => 'consent'],
            // Standard-OAuth2: refresh_token-Grant mit client_id/client_secret.
            'token_style'   => 'oauth2',
        ];
    }

    /** @return array<string,mixed>|null */
    private static function meta(App $app): ?array
    {
        $id = $app->env('META_OAUTH_CLIENT_ID');
        $secret = $app->env('META_OAUTH_CLIENT_SECRET');
        if (!$id || !$secret) {
            return null;
        }
        return [
            'auth_url'      => 'https://www.facebook.com/v21.0/dialog/oauth',
            'token_url'     => 'https://graph.facebook.com/v21.0/oauth/access_token',
            'client_id'     => $id,
            'client_secret' => $secret,
            'scopes'        => ['instagram_basic', 'instagram_manage_insights', 'pages_read_engagement'],
            'extra_auth'    => [],
            // Meta kennt keinen refresh_token-Grant: Das langlebige Token (60 Tage) wird per
            // fb_exchange_token verlängert. Wir speichern das Long-Lived-Token als „refresh_token"
            // und tauschen es bei jedem Lauf gegen ein frisches Long-Lived-Token (rollierend).
            'token_style'   => 'meta_longlived',
        ];
    }

    /** @return array<string,mixed>|null */
    private static function tiktok(App $app): ?array
    {
        $id = $app->env('TIKTOK_OAUTH_CLIENT_KEY');
        $secret = $app->env('TIKTOK_OAUTH_CLIENT_SECRET');
        if (!$id || !$secret) {
            return null;
        }
        return [
            'auth_url'      => 'https://www.tiktok.com/v2/auth/authorize/',
            'token_url'     => 'https://open.tiktokapis.com/v2/oauth/token/',
            'client_id'     => $id,
            'client_secret' => $secret,
            'scopes'        => ['user.info.basic', 'user.info.stats', 'video.list'],
            // TikTok nennt den Client-Parameter in der Auth-URL client_key (nicht client_id).
            'extra_auth'    => ['client_key' => $id],
            // refresh_token-Grant, aber der Client-Parameter heisst client_key.
            'token_style'   => 'tiktok',
        ];
    }
}
