<?php

declare(strict_types=1);

use Openstream\Visibility\App;
use Openstream\Visibility\Database\ClientRepository;
use Openstream\Visibility\Web\OAuthController;

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    echo 'Dependencies fehlen. Bitte: ddev composer install';
    return;
}

require $root . '/vendor/autoload.php';
App::boot($root);

$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

// --- OAuth-Verbindungsflow (die einzige Kundeninteraktion, s. CLAUDE.md) ---
// GET /connect/<platform>?client=<slug>   → Redirect zum Provider
// GET /connect/<platform>/callback         → Token speichern
if (preg_match('#^/connect/([a-z]+)(/callback)?$#', $path, $m)) {
    $platform = $m[1];
    $isCallback = isset($m[2]);
    $controller = new OAuthController(new ClientRepository());

    $result = $isCallback
        ? $controller->callback($platform, $_GET)
        : $controller->start($platform, $_GET['client'] ?? null);

    if (isset($result['redirect'])) {
        header('Location: ' . $result['redirect'], true, 302);
        return;
    }
    http_response_code($result['status']);
    header('Content-Type: text/html; charset=utf-8');
    echo $result['html'];
    return;
}

// --- Platzhalter-Startseite (Web-Dashboard folgt in Phase 5) ---
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><title>Visibility Dashboard</title></head>
<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">
  <h1>Visibility Dashboard</h1>
  <p>SEO + GEO Sichtbarkeit — Gerüst läuft. Das Web-Dashboard folgt in Phase 5.</p>
  <p>CLI: <code>ddev exec php bin/console list</code></p>
</body>
</html>
