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
        $id = $app->env('INSTAGRAM_OAUTH_CLIENT_ID');
        $secret = $app->env('INSTAGRAM_OAUTH_CLIENT_SECRET');
        if (!$id || !$secret) {
            return null;
        }
        // „Instagram API with Instagram Login" (graph.instagram.com) — der Kunde meldet sich
        // direkt mit seinem Instagram-Business/Creator-Konto an, KEINE Facebook-Seite nötig.
        // Auth/Token laufen über api.instagram.com, Daten über graph.instagram.com.
        return [
            'auth_url'      => 'https://api.instagram.com/oauth/authorize',
            'token_url'     => 'https://api.instagram.com/oauth/access_token',
            'client_id'     => $id,
            'client_secret' => $secret,
            'scopes'        => ['instagram_business_basic', 'instagram_business_manage_insights'],
            // Instagram verlangt komma-separierte Scopes (wie TikTok).
            'scope_separator' => ',',
            'extra_auth'    => [],
            // Instagram-Login: kein refresh_token-Grant. Das kurzlebige Token wird gegen ein
            // Long-Lived-Token (60 Tage) getauscht (ig_exchange_token) und rollierend via
            // ig_refresh_token erneuert. Endpoints auf graph.instagram.com.
            'token_style'   => 'instagram_login',
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
            // TikTok verlangt komma-separierte Scopes (nicht Leerzeichen wie OAuth2-Standard).
            'scope_separator' => ',',
            // refresh_token-Grant, aber der Client-Parameter heisst client_key.
            'token_style'   => 'tiktok',
        ];
    }
}
