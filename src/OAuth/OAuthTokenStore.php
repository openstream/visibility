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
            'tiktok'         => $this->refreshTikTok($cfg, $stored),
            'meta_longlived' => $this->refreshMetaLongLived($cfg, $stored, $connection),
            default          => $this->refreshOauth2($cfg, $stored),
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
     * Meta/Instagram: kein refresh_token-Grant. Das gespeicherte Long-Lived-Token (60 Tage)
     * wird per fb_exchange_token gegen ein frisches Long-Lived-Token getauscht und rollierend
     * zurückgespeichert (verlängert die 60-Tage-Frist bei jedem Lauf). Das getauschte Token
     * ist zugleich das Access-Token für die Graph-API-Calls.
     * @param array<string,mixed> $cfg
     * @param array<string,mixed> $connection
     */
    private function refreshMetaLongLived(array $cfg, string $longLived, array $connection): string
    {
        $data = $this->postToken($cfg['token_url'], [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $cfg['client_id'],
            'client_secret'     => $cfg['client_secret'],
            'fb_exchange_token' => $longLived,
        ], 'GET');
        $access = $this->requireAccessToken($data, 'meta');
        // Rollierend zurückspeichern, damit die 60-Tage-Frist nicht abläuft.
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
     * @return array{access_token:string,refresh_token:?string,scope:?string}
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

        // Meta liefert kein refresh_token: das (kurzlebige) Access-Token muss zunächst gegen
        // ein Long-Lived-Token getauscht und DAS als „refresh_token" gespeichert werden.
        if (($cfg['token_style'] ?? 'oauth2') === 'meta_longlived') {
            $refresh = $this->metaLongLivedFromShort($cfg, (string) $access);
        }

        return [
            'access_token'  => (string) $access,
            'refresh_token' => $refresh !== null ? (string) $refresh : null,
            'scope'         => $scope !== null ? (string) $scope : null,
        ];
    }

    /**
     * Tauscht ein kurzlebiges Meta-Access-Token (1 h) gegen ein Long-Lived-Token (60 Tage).
     * Dieses langlebige Token speichern wir verschlüsselt und verlängern es rollierend.
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
