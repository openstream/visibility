<?php

declare(strict_types=1);

use Openstream\Visibility\App;

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    echo 'Dependencies fehlen. Bitte: ddev composer install';
    return;
}

require $root . '/vendor/autoload.php';
App::boot($root);

// Minimaler Platzhalter. Das Web-Dashboard (Kundenliste, Charts, Report-Ansicht)
// entsteht in Phase 5. UI liest ausschliesslich aus der DB — keine Live-API-Calls.
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
