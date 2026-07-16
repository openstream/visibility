<?php

declare(strict_types=1);

namespace Openstream\Visibility\OAuth;

use GuzzleHttp\Client;
use Openstream\Visibility\App;
use Openstream\Visibility\Database\ClientRepository;

/**
 * Verwaltet OAuth-Refresh-Tokens der Kunden: speichert sie verschlüsselt (via Crypto) und
 * tauscht sie bei Bedarf gegen kurzlebige Access-Tokens (je Plattform-Token-Endpoint).
 * Access-Tokens werden pro Lauf im Speicher gecacht (nicht persistiert — sie sind kurzlebig).
 *
 * Der Store ist die einzige Stelle, die Klartext-Tokens sieht. Provider bekommen nur das
 * fertige Access-Token.
 */
// Nicht final: in Provider-Tests wird accessTokenFor() gemockt (kein echter Token-Tausch).
class OAuthTokenStore
{
    private Crypto $crypto;
    private Client $http;
    /** @var array<string,string> connectionId => access_token (Lauf-Cache) */
    private array $accessCache = [];

    public function __construct(private readonly ClientRepository $repo, ?Crypto $crypto = null)
    {
        if ($crypto === null) {
            $key = App::get()->env('APP_ENCRYPTION_KEY');
            if (!$key) {
                throw new \RuntimeException('APP_ENCRYPTION_KEY fehlt in .env (openssl rand -base64 32).');
            }
            $crypto = new Crypto($key);
        }
        $this->crypto = $crypto;
        $this->http = new Client(['timeout' => 30]);
    }

    /** Verschlüsselt einen Refresh-Token für die Speicherung. */
    public function encryptRefreshToken(string $refreshToken): string
    {
        return $this->crypto->encrypt($refreshToken);
    }

    /**
     * Liefert ein gültiges Access-Token für eine Verbindung (Refresh → Access, gecacht).
     * @param array<string,mixed> $connection Zeile aus social_connections
     */
    public function accessTokenFor(array $connection): string
    {
        $id = (string) $connection['id'];
        if (isset($this->accessCache[$id])) {
            return $this->accessCache[$id];
        }

        $platform = (string) $connection['platform'];
        $cfg = OAuthProviderConfig::for($platform);
        if ($cfg === null) {
            throw new \RuntimeException("Keine OAuth-Config für Plattform {$platform} (Credentials in .env?).");
        }

        $stored = $this->crypto->decrypt((string) $connection['refresh_token_enc']);
        $access = match ($cfg['token_style'] ?? 'oauth2') {
            'tiktok'           => $this->refreshTikTok($cfg, $stored),
            'instagram_login'  => $this->refreshInstagramLogin($stored, $connection),
            default            => $this->refreshOauth2($cfg, $stored),
        };

        return $this->accessCache[$id] = $access;
    }

    /**
     * Standard-OAuth2-Refresh (Google/YouTube): refresh_token-Grant, client_id/client_secret.
     * @param array<string,mixed> $cfg
     */
    private function refreshOauth2(array $cfg, string $refreshToken): string
    {
        $data = $this->postToken($cfg['token_url'], [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
        ]);
        return $this->requireAccessToken($data, 'oauth2');
    }

    /**
     * TikTok-Refresh: wie OAuth2, aber der Client-Parameter heisst client_key.
     * @param array<string,mixed> $cfg
     */
    private function refreshTikTok(array $cfg, string $refreshToken): string
    {
        $data = $this->postToken($cfg['token_url'], [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_key'    => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
        ]);
        return $this->requireAccessToken($data, 'tiktok');
    }

    /**
     * Instagram-Login: kein refresh_token-Grant. Das gespeicherte Long-Lived-Token (60 Tage)
     * wird via ig_refresh_token auf graph.instagram.com verlängert (frisches 60-Tage-Token)
     * und rollierend zurückgespeichert. Das Token ist zugleich das Access-Token für die
     * graph.instagram.com-Calls. Instagram erlaubt Refresh erst ab Token-Alter 24 h; solange
     * das aktuelle Token noch gültig ist, taugt es ohnehin direkt als Access-Token, daher
     * fällt der Refresh bei Fehlern still auf das gespeicherte Token zurück.
     * @param array<string,mixed> $connection
     */
    private function refreshInstagramLogin(string $longLived, array $connection): string
    {
        try {
            $data = $this->postToken('https://graph.instagram.com/refresh_access_token', [
                'grant_type'   => 'ig_refresh_token',
                'access_token' => $longLived,
            ], 'GET');
            $access = $this->requireAccessToken($data, 'instagram');
        } catch (\Throwable) {
            return $longLived; // Refresh (noch) nicht möglich → gespeichertes Token nutzen
        }
        if (isset($connection['id'])) {
            $this->repo->updateSocialConnectionToken(
                (int) $connection['id'],
                $this->crypto->encrypt($access),
            );
        }
        return $access;
    }

    /**
     * POST (oder GET) an einen Token-Endpoint, JSON-Antwort dekodiert.
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function postToken(string $url, array $params, string $method = 'POST'): array
    {
        $res = $method === 'GET'
            ? $this->http->get($url, ['query' => $params])
            : $this->http->post($url, ['form_params' => $params]);
        return json_decode((string) $res->getBody(), true) ?: [];
    }

    /**
     * Zieht das access_token aus einer Token-Antwort oder wirft. TikTok verschachtelt es
     * teils unter „data"; wir prüfen beide Ebenen.
     * @param array<string,mixed> $data
     */
    private function requireAccessToken(array $data, string $label): string
    {
        $access = $data['access_token'] ?? ($data['data']['access_token'] ?? null);
        if (!is_string($access) || $access === '') {
            throw new \RuntimeException("Access-Token-Tausch für {$label} fehlgeschlagen.");
        }
        return $access;
    }

    /**
     * Tauscht einen Authorization-Code gegen Tokens (im OAuth-Callback). Gibt access + refresh
     * im Klartext zurück; der Aufrufer speichert den Refresh-Token via encryptRefreshToken().
     * account_ref trägt (falls die Plattform sie liefert) die eindeutige Account-ID mit —
     * bei Instagram-Login die user_id, damit spätere Läufe /me nicht auflösen müssen.
     * @return array{access_token:string,refresh_token:?string,scope:?string,account_ref:?string}
     */
    public function exchangeCode(string $platform, string $code): array
    {
        $cfg = OAuthProviderConfig::for($platform);
        if ($cfg === null) {
            throw new \RuntimeException("Keine OAuth-Config für Plattform {$platform}.");
        }
        $params = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => OAuthProviderConfig::redirectUri($platform),
        ];
        // TikTok erwartet client_key statt client_id (auch beim Code-Tausch).
        if (($cfg['token_style'] ?? 'oauth2') === 'tiktok') {
            $params['client_key'] = $cfg['client_id'];
        } else {
            $params['client_id'] = $cfg['client_id'];
        }

        $data = $this->postToken($cfg['token_url'], $params);
        $access = $data['access_token'] ?? ($data['data']['access_token'] ?? null);
        if (!is_string($access) || $access === '') {
            throw new \RuntimeException("Code-Tausch für {$platform} fehlgeschlagen.");
        }
        $refresh = $data['refresh_token'] ?? ($data['data']['refresh_token'] ?? null);
        $scope = $data['scope'] ?? ($data['data']['scope'] ?? null);
        $accountRef = null;

        // Instagram-Login: das kurzlebige Token (1 h) zuerst gegen ein Long-Lived-Token
        // (60 Tage) tauschen und DAS als „refresh_token" speichern. Die user_id aus der
        // Code-Antwort ist die IG-Account-ID → als account_ref merken.
        if (($cfg['token_style'] ?? 'oauth2') === 'instagram_login') {
            $refresh = $this->instagramLongLivedFromShort($cfg, (string) $access);
            $access = $refresh; // Long-Lived-Token ist auch das Access-Token für graph.instagram.com
            $uid = $data['user_id'] ?? ($data['data']['user_id'] ?? null);
            $accountRef = $uid !== null ? (string) $uid : null;
        }

        return [
            'access_token'  => (string) $access,
            'refresh_token' => $refresh !== null ? (string) $refresh : null,
            'scope'         => $scope !== null ? (string) $scope : null,
            'account_ref'   => $accountRef,
        ];
    }

    /**
     * Tauscht ein kurzlebiges Instagram-Login-Token (1 h) gegen ein Long-Lived-Token (60 Tage)
     * via ig_exchange_token auf graph.instagram.com. Wird verschlüsselt gespeichert und
     * rollierend erneuert.
     * @param array<string,mixed> $cfg
     */
    private function instagramLongLivedFromShort(array $cfg, string $shortToken): string
    {
        $data = $this->postToken('https://graph.instagram.com/access_token', [
            'grant_type'    => 'ig_exchange_token',
            'client_secret' => $cfg['client_secret'],
            'access_token'  => $shortToken,
        ], 'GET');
        return $this->requireAccessToken($data, 'instagram long-lived');
    }

    /**
     * (Ungenutzt seit Umstellung auf Instagram-Login; bleibt für den FB-Login-Weg dokumentiert.)
     * Tauscht ein kurzlebiges Meta-Access-Token (1 h) gegen ein Long-Lived-Token (60 Tage).
     * @param array<string,mixed> $cfg
     */
    private function metaLongLivedFromShort(array $cfg, string $shortToken): string
    {
        $data = $this->postToken($cfg['token_url'], [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $cfg['client_id'],
            'client_secret'     => $cfg['client_secret'],
            'fb_exchange_token' => $shortToken,
        ], 'GET');
        return $this->requireAccessToken($data, 'meta long-lived');
    }
}
