<?php

declare(strict_types=1);

namespace Openstream\Visibility\Web;

use Openstream\Visibility\Database\ClientRepository;
use Openstream\Visibility\OAuth\OAuthProviderConfig;
use Openstream\Visibility\OAuth\OAuthTokenStore;

/**
 * Web-Endpunkte für den OAuth-Verbindungsflow (die EINZIGE Kundeninteraktion, s. CLAUDE.md).
 * Der Kunde verbindet seinen eigenen Social-Kanal:
 *   GET /connect/<platform>?client=<slug>   → Redirect zum Provider-Consent
 *   GET /connect/<platform>/callback         → Code gegen Token tauschen, verschlüsselt speichern
 *
 * Kein Login/keine Session darüber hinaus. State-Token (CSRF) in oauth_states.
 * Rückgabe je Methode: ['status'=>int, 'html'=>string] oder ['redirect'=>url].
 */
final class OAuthController
{
    private const PLATFORMS = ['youtube', 'instagram', 'tiktok'];

    public function __construct(private readonly ClientRepository $repo) {}

    /**
     * Startet den Flow: validiert Plattform + Kunde, erzeugt State, leitet zum Provider.
     * @return array{redirect:string}|array{status:int,html:string}
     */
    public function start(string $platform, ?string $clientSlug): array
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            return $this->error(404, 'Unbekannte Plattform.');
        }
        if (!$clientSlug) {
            return $this->error(400, 'Parameter client fehlt.');
        }
        try {
            $clientId = $this->repo->clientIdBySlug($clientSlug);
        } catch (\Throwable) {
            return $this->error(404, 'Kunde nicht gefunden.');
        }
        $cfg = OAuthProviderConfig::for($platform);
        if ($cfg === null) {
            return $this->error(503, 'Diese Plattform ist noch nicht konfiguriert (OAuth-Credentials fehlen).');
        }

        $state = bin2hex(random_bytes(32));
        $this->repo->createOAuthState($state, $clientId, $platform);

        // Scope-Trennzeichen: OAuth2-Standard ist Leerzeichen; TikTok verlangt Kommas.
        $sep = $cfg['scope_separator'] ?? ' ';
        $base = [
            'redirect_uri'  => OAuthProviderConfig::redirectUri($platform),
            'response_type' => 'code',
            'scope'         => implode($sep, $cfg['scopes']),
            'state'         => $state,
        ];
        // TikTok identifiziert die App über client_key (in extra_auth). Sonst: client_id.
        if (!isset($cfg['extra_auth']['client_key'])) {
            $base['client_id'] = $cfg['client_id'];
        }
        $params = array_merge($base, $cfg['extra_auth']);

        return ['redirect' => $cfg['auth_url'] . '?' . http_build_query($params)];
    }

    /**
     * Callback: prüft State, tauscht Code gegen Tokens, speichert Refresh-Token verschlüsselt.
     * @param array<string,string> $query $_GET des Callbacks
     * @return array{status:int,html:string}
     */
    public function callback(string $platform, array $query): array
    {
        if (isset($query['error'])) {
            return $this->page(400, 'Verbindung abgebrochen', 'Der Zugriff wurde nicht erteilt.');
        }
        $state = $query['state'] ?? '';
        $code = $query['code'] ?? '';
        if ($state === '' || $code === '') {
            return $this->page(400, 'Ungültige Anfrage', 'State oder Code fehlt.');
        }
        $ctx = $this->repo->consumeOAuthState($state);
        if ($ctx === null || $ctx['platform'] !== $platform) {
            return $this->page(400, 'Ungültiger State', 'Bitte den Verbindungsvorgang neu starten.');
        }

        try {
            $store = new OAuthTokenStore($this->repo);
            $tokens = $store->exchangeCode($platform, $code);
        } catch (\Throwable $e) {
            return $this->page(502, 'Token-Tausch fehlgeschlagen', $this->esc($e->getMessage()));
        }

        if (!$tokens['refresh_token']) {
            // Kein Refresh-Token (z.B. Nutzer war schon verbunden) → sauber melden.
            return $this->page(400, 'Kein Refresh-Token erhalten',
                'Bitte die App-Verbindung beim Anbieter entfernen und erneut verbinden '
                . '(damit ein Refresh-Token ausgestellt wird).');
        }

        $this->repo->saveSocialConnection(
            $ctx['client_id'],
            $platform,
            null,
            null,
            $store->encryptRefreshToken($tokens['refresh_token']),
            $tokens['scope'],
        );

        return $this->page(200, 'Verbunden',
            'Ihr ' . ucfirst($platform) . '-Kanal ist jetzt verbunden. Vielen Dank - Sie können '
            . 'dieses Fenster schliessen.');
    }

    /** @return array{status:int,html:string} */
    private function error(int $status, string $msg): array
    {
        return $this->page($status, 'Fehler', $this->esc($msg));
    }

    /** @return array{status:int,html:string} */
    private function page(int $status, string $title, string $body): array
    {
        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->esc($title) . ' — Visibility</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:34rem;margin:4rem auto;padding:0 1rem">'
            . '<h1>' . $this->esc($title) . '</h1><p>' . $body . '</p></body></html>';
        return ['status' => $status, 'html' => $html];
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
