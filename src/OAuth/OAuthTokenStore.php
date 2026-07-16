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
final class OAuthTokenStore
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

        $refresh = $this->crypto->decrypt((string) $connection['refresh_token_enc']);
        $res = $this->http->post($cfg['token_url'], ['form_params' => [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
        ]]);
        $data = json_decode((string) $res->getBody(), true);
        $access = $data['access_token'] ?? null;
        if (!is_string($access) || $access === '') {
            throw new \RuntimeException("Access-Token-Tausch für {$platform} fehlgeschlagen.");
        }

        return $this->accessCache[$id] = $access;
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
        $res = $this->http->post($cfg['token_url'], ['form_params' => [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => OAuthProviderConfig::redirectUri($platform),
        ]]);
        $data = json_decode((string) $res->getBody(), true);
        if (empty($data['access_token'])) {
            throw new \RuntimeException("Code-Tausch für {$platform} fehlgeschlagen.");
        }
        return [
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'scope'         => isset($data['scope']) ? (string) $data['scope'] : null,
        ];
    }
}
