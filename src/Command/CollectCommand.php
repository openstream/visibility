<?php

declare(strict_types=1);

namespace Openstream\Visibility\Command;

use Openstream\Visibility\App;
use Openstream\Visibility\Database\ClientRepository;
use Openstream\Visibility\Provider\BingSerpProvider;
use Openstream\Visibility\Provider\BingWmtClient;
use Openstream\Visibility\Provider\DataForSeoClient;
use Openstream\Visibility\Provider\DataForSeoSerpProvider;
use Openstream\Visibility\Provider\GscClient;
use Openstream\Visibility\Provider\GscSerpProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 2 — Wöchentliche Datenerhebung (Rankings). Liest approved Keywords, erhebt
 * Google-Rankings über GSC (gratis, eigene Property) und optional DataForSEO SERP
 * (pay-per-task, für Keywords ohne GSC-Daten). Schreibt Zeitreihe in `measurements`.
 */
#[AsCommand(name: 'collect', description: 'Wöchentliche Ranking-Erhebung für einen Kunden (Zeitreihe)')]
final class CollectCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug');
        $this->addOption('serp', null, InputOption::VALUE_NONE, 'Zusätzlich DataForSEO-SERP erheben (kostet, ~5 Min)');
        $this->addOption('geo', null, InputOption::VALUE_NONE, 'GEO-Sichtbarkeit erheben (ChatGPT/Gemini/Claude, kostet pro Prompt)');
        $this->addOption('onsite', null, InputOption::VALUE_NONE, 'Onsite-Audit der wichtigsten Seiten (DataForSEO OnPage)');
        $this->addOption('offsite', null, InputOption::VALUE_NONE, 'Offsite/Backlinks-Snapshot (DataForSEO)');
        $this->addOption('social', null, InputOption::VALUE_NONE, 'Social-Kennzahlen der eigenen Kanäle (YouTube + OAuth-Verbindungen)');
        $this->addOption('newsletter', null, InputOption::VALUE_NONE, 'Newsletter-Kennzahlen (Sendy/Mailchimp je Kunden-Config)');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'measured_at überschreiben (Y-m-d, Default heute)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $app = App::get();

        $slug = $input->getOption('client');
        if (!$slug) {
            $io->error('--client=<slug> erforderlich.');
            return Command::INVALID;
        }

        $cfgFile = $app->configPath("clients/{$slug}.yaml");
        if (!is_file($cfgFile)) {
            $io->error("Konfiguration nicht gefunden: {$cfgFile}");
            return Command::FAILURE;
        }
        $cfg = Yaml::parseFile($cfgFile);
        $domain = $cfg['domain'] ?? $slug;
        $measuredAt = $input->getOption('date') ?: date('Y-m-d');

        $repo = new ClientRepository();
        try {
            $clientId = $repo->clientIdBySlug($slug);
        } catch (\Throwable $e) {
            $io->error($e->getMessage() . ' — zuerst `onboard --save` und `approve` laufen lassen.');
            return Command::FAILURE;
        }

        $keywords = $repo->approvedKeywords($clientId);
        if (!$keywords) {
            $io->error('Keine freigegebenen Keywords. Zuerst `approve --client=' . $slug . '`.');
            return Command::FAILURE;
        }
        $io->title("Collect: {$slug} — {$measuredAt}");
        $io->text(count($keywords) . ' freigegebene Keywords.');

        $total = 0;

        // --- GSC (gratis, eigene Property) ---
        $siteUrl = $cfg['gsc']['site_url'] ?? null;
        if ($siteUrl) {
            try {
                $provider = new GscSerpProvider(GscClient::fromEnv(), $siteUrl);
                $measurements = $provider->collect($keywords);
                $n = $repo->saveMeasurements($clientId, $measurements, $measuredAt);
                $io->success("GSC: {$n} Messwerte erhoben und gespeichert.");
                $total += $n;
            } catch (\Throwable $e) {
                $io->warning('GSC-Erhebung fehlgeschlagen: ' . $e->getMessage());
            }
        } else {
            $io->text('Keine GSC-Property — überspringe GSC.');
        }

        // --- Bing Webmaster Tools (gratis, eigene Property) ---
        $bingSite = $cfg['bing']['site_url'] ?? null;
        if ($bingSite) {
            try {
                $provider = new BingSerpProvider(BingWmtClient::fromEnv(), $bingSite);
                $measurements = $provider->collect($keywords);
                $n = $repo->saveMeasurements($clientId, $measurements, $measuredAt);
                $io->success("Bing: {$n} Messwerte erhoben und gespeichert.");
                $total += $n;
            } catch (\Throwable $e) {
                $io->warning('Bing-Erhebung fehlgeschlagen: ' . $e->getMessage());
            }
        } else {
            $io->text('Keine Bing-Property — überspringe Bing.');
        }

        // --- DataForSEO SERP (optional, kostet) ---
        if ($input->getOption('serp')) {
            $io->text('DataForSEO-SERP läuft (Standard-Queue, ~5 Min Wartezeit) …');
            $dfs = new DataForSeoClient();
            try {
                $provider = new DataForSeoSerpProvider($dfs, $domain);
                $measurements = $provider->collect($keywords);
                $n = $repo->saveMeasurements($clientId, $measurements, $measuredAt);
                $io->success("DataForSEO-SERP: {$n} Messwerte gespeichert.");
                $io->note(sprintf('DataForSEO-Kosten: $%.4f', $dfs->spent()));
                $total += $n;
            } catch (\Throwable $e) {
                $io->warning('DataForSEO-SERP fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // --- GEO: KI-Sichtbarkeit (optional, kostet pro Prompt) ---
        if ($input->getOption('geo')) {
            $prompts = $repo->approvedGeoPrompts($clientId);
            if (!$prompts) {
                $io->warning('Keine freigegebenen GEO-Prompts — überspringe GEO.');
            } else {
                $io->section('GEO — KI-Sichtbarkeit');
                $analyzer = new \Openstream\Visibility\Provider\MentionAnalyzer(
                    $domain, $repo->brandNames($clientId), $cfg['competitors'] ?? []
                );
                // Aktive GEO-Kanäle aus der Config (kostenbewusst wählbar).
                // ChatGPT/Gemini/Claude via DataForSEO; Perplexity via Sonar (citation-native).
                $channels = $cfg['geo']['channels'] ?? ['chatgpt' => true, 'perplexity' => true];
                $active = array_keys(array_filter($channels));
                $io->text(count($prompts) . ' GEO-Prompts über: ' . implode(', ', $active) . '.');
                $dfsGeo = new DataForSeoClient();
                $providers = [];
                foreach (['chatgpt', 'gemini', 'claude'] as $ch) {
                    if (!empty($channels[$ch])) {
                        $providers[] = new \Openstream\Visibility\Provider\DataForSeoGeoProvider($dfsGeo, $analyzer, $ch);
                    }
                }
                if (!empty($channels['perplexity'])) {
                    $providers[] = new \Openstream\Visibility\Provider\PerplexityGeoProvider($analyzer);
                }
                if (!$providers) {
                    $io->warning('Keine GEO-Kanäle aktiviert (config geo.channels).');
                }
                foreach ($providers as $p) {
                    try {
                        $mentions = $p->collect($prompts);
                        $n = $repo->saveGeoMentions($clientId, $mentions, $measuredAt);
                        $mentionedCount = count(array_filter($mentions, static fn($m) => $m->mentioned));
                        $io->success(sprintf('%s: %d Prompts erhoben, in %d erwähnt.', $p->name(), $n, $mentionedCount));
                        $total += $n;
                    } catch (\Throwable $e) {
                        $io->warning($p->name() . ' fehlgeschlagen: ' . $e->getMessage());
                    }
                }

                // Google AI Overview: arbeitet mit KEYWORDS (nicht Prompts) — prüft, ob die
                // Domain in der AI-Zusammenfassung oben in den Google-Ergebnissen zitiert wird.
                if (!empty($channels['ai_overview'])) {
                    // Nur Keywords mit echtem Suchvolumen prüfen (Impressionen) — sonst zu
                    // langsam/teuer bei vielen Keywords. Fallback: alle approved Keywords.
                    $kw = $repo->keywordsWithImpressions($clientId, 30);
                    if (!$kw) {
                        $kw = $repo->approvedKeywords($clientId);
                    }
                    if ($kw) {
                        try {
                            $aio = new \Openstream\Visibility\Provider\AiOverviewProvider($dfsGeo, $domain);
                            $mentions = $aio->collect($kw);
                            $n = $repo->saveGeoMentions($clientId, $mentions, $measuredAt);
                            $citedCount = count(array_filter($mentions, static fn($m) => $m->cited));
                            $io->success(sprintf('ai_overview: %d Keywords mit AI Overview, Domain in %d zitiert.', $n, $citedCount));
                            $total += $n;
                        } catch (\Throwable $e) {
                            $io->warning('ai_overview fehlgeschlagen: ' . $e->getMessage());
                        }
                    }
                }

                if ($dfsGeo->spent() > 0) {
                    $io->note(sprintf('DataForSEO-GEO-Kosten: $%.4f (Perplexity separat via Sonar)', $dfsGeo->spent()));
                }
            }
        }

        // --- Onsite-Audit (wichtigste Seiten: key_pages + Top-Seiten aus GSC) ---
        if ($input->getOption('onsite')) {
            $urls = $this->onsiteUrls($cfg);
            $io->section('Onsite / Technisches SEO');
            $io->text(count($urls) . ' Seiten prüfen …');
            $dfsOn = new DataForSeoClient();
            try {
                $audit = (new \Openstream\Visibility\Provider\OnsiteProvider($dfsOn))->audit($urls);
                $n = $repo->saveOnsiteAudit($clientId, $audit, $measuredAt);
                $probs = array_sum(array_map(static fn($a) => count($a['problems']), $audit));
                $io->success("Onsite: {$n} Seiten geprüft, {$probs} Auffälligkeiten.");
                $io->note(sprintf('DataForSEO-OnPage-Kosten: $%.4f', $dfsOn->spent()));
            } catch (\Throwable $e) {
                $io->warning('Onsite-Audit fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // --- Offsite/Backlinks-Snapshot ---
        if ($input->getOption('offsite')) {
            $io->section('Offsite / Backlinks');
            $dfsOff = new DataForSeoClient();
            try {
                $summary = (new \Openstream\Visibility\Provider\OffsiteProvider($dfsOff, $domain))->summary();
                $repo->saveBacklinks($clientId, $summary, $measuredAt);
                $io->success(sprintf('Offsite: Domain Rank %s, %s Backlinks, %s Referring Domains.',
                    $summary['domain_rank'] ?? '?', $summary['backlinks'] ?? '?', $summary['referring_domains'] ?? '?'));
                $io->note(sprintf('DataForSEO-Backlinks-Kosten: $%.4f', $dfsOff->spent()));
            } catch (\Throwable $e) {
                $io->warning('Offsite fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // --- Social Media (eigene Kanäle: YouTube Data API + OAuth-Verbindungen) ---
        if ($input->getOption('social')) {
            $total += $this->collectSocial($io, $repo, $clientId, $cfg, $measuredAt);
        }

        // --- Newsletter (Owned Media: Sendy/Mailchimp je Config) ---
        if ($input->getOption('newsletter')) {
            $total += $this->collectNewsletter($io, $repo, $clientId, $cfg, $measuredAt);
        }

        $io->success("Fertig. {$total} Messwerte für {$measuredAt} in der DB.");
        return Command::SUCCESS;
    }

    /**
     * Erhebt Social-Kennzahlen der EIGENEN Kunden-Kanäle. YouTube via offizielle Data API
     * (API-Key), TikTok/Instagram via Apify (nur wenn Token gesetzt). Nur eigene Accounts.
     *
     * @param array<string,mixed> $cfg
     * @return int Anzahl geschriebener Zeilen
     */
    private function collectSocial(SymfonyStyle $io, ClientRepository $repo, int $clientId, array $cfg, string $measuredAt): int
    {
        $social = $cfg['social'] ?? [];
        $yt = array_values(array_filter((array) ($social['youtube'] ?? [])));

        $io->section('Social Media (eigene Kanäle)');
        $written = 0;

        // YouTube (öffentlich, Data API, nur API-Key) — Fallback ohne OAuth für Kunden,
        // die (noch) nicht per OAuth verbunden sind. Liefert Lifetime-viewCount inkl. Shorts.
        if ($yt) {
            try {
                $provider = \Openstream\Visibility\Provider\YouTubeProvider::fromEnv();
                $metrics = $provider->collect($yt);
                $n = $repo->saveSocialMetrics($clientId, $metrics, $measuredAt);
                $io->success("YouTube (Data API): {$n} Kanal/Kanäle erhoben.");
                $written += $n;
            } catch (\Throwable $e) {
                $io->warning('YouTube (Data API) fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // Verbundene Kanäle (YouTube Analytics / Instagram / TikTok) via OAuth: echte
        // Monats-Views. Nutzt die in `social_connections` gespeicherten Tokens. Der erste
        // Config-Handle je Plattform dient als Account-Label, damit OAuth- und Data-API-
        // Zeile denselben Kanal betreffen (eine Zeile im Report).
        $handles = [
            'youtube'   => $yt[0] ?? null,
            'instagram' => (array_values(array_filter((array) ($social['instagram'] ?? [])))[0] ?? null),
            'tiktok'    => (array_values(array_filter((array) ($social['tiktok'] ?? [])))[0] ?? null),
        ];
        $written += $this->collectOauthSocial($io, $repo, $clientId, $measuredAt, $handles);

        if ($written === 0) {
            $io->text('Keine Social-Daten erhoben (keine Kanäle in Config, keine OAuth-Verbindungen).');
        }
        return $written;
    }

    /**
     * Erhebt Social-Kennzahlen der per OAuth verbundenen Kanäle (echte Monats-Views).
     * Läuft nur, wenn Verbindungen existieren; überspringt sonst geräuschlos.
     * @return int Anzahl geschriebener Zeilen
     */
    /** @param array<string,?string> $handles Config-Handle je Plattform (für einheitliche Account-Labels) */
    private function collectOauthSocial(SymfonyStyle $io, ClientRepository $repo, int $clientId, string $measuredAt, array $handles = []): int
    {
        $connections = $repo->socialConnections($clientId);
        if (!$connections) {
            return 0;
        }
        // Config-Handle je Verbindung als account_ref setzen, damit OAuth-Metriken unter
        // demselben Account-Namen wie die Data-API-Zeile laufen (eine Report-Zeile).
        foreach ($connections as &$conn) {
            $h = $handles[$conn['platform']] ?? null;
            if ($h && empty($conn['account_ref'])) {
                $conn['account_ref'] = $h;
                $conn['account_label'] = $h;
            }
        }
        unset($conn);

        try {
            $store = new \Openstream\Visibility\OAuth\OAuthTokenStore($repo);
        } catch (\Throwable $e) {
            $io->warning('OAuth-Token-Store nicht verfügbar: ' . $e->getMessage());
            return 0;
        }

        $written = 0;
        foreach ($connections as $conn) {
            $provider = $this->oauthProviderFor($conn['platform'], $store);
            if ($provider === null) {
                continue; // Plattform-Provider noch nicht implementiert (z.B. IG/TikTok)
            }
            try {
                $metrics = $provider->collectConnected($conn, $measuredAt);
                $n = $repo->saveSocialMetrics($clientId, $metrics, $measuredAt);
                $io->success(sprintf('%s (OAuth): %d Kanal/Kanäle erhoben.', $conn['platform'], $n));
                $written += $n;
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s (OAuth) fehlgeschlagen: %s', $conn['platform'], $e->getMessage()));
            }
        }
        return $written;
    }

    /**
     * Wählt den OAuth-Provider für eine Plattform. Null, wenn noch nicht implementiert.
     * @param array<string,mixed> $store nicht genutzt hier, an Provider durchgereicht
     */
    private function oauthProviderFor(string $platform, \Openstream\Visibility\OAuth\OAuthTokenStore $store): ?\Openstream\Visibility\Provider\ConnectedSocialProvider
    {
        return match ($platform) {
            'youtube'   => new \Openstream\Visibility\Provider\YouTubeAnalyticsProvider($store),
            'tiktok'    => new \Openstream\Visibility\Provider\TikTokProvider($store),
            'instagram' => new \Openstream\Visibility\Provider\InstagramInsightsProvider($store),
            default     => null,
        };
    }

    /**
     * Erhebt Newsletter-Kennzahlen (Sendy/Mailchimp je Kunden-Config). Keys aus .env
     * (Suffix = env_suffix). @param array<string,mixed> $cfg
     * @return int Anzahl geschriebener Zeilen
     */
    private function collectNewsletter(SymfonyStyle $io, ClientRepository $repo, int $clientId, array $cfg, string $measuredAt): int
    {
        $nl = $cfg['newsletter'] ?? null;
        if (!$nl || empty($nl['provider'])) {
            $io->text('Kein Newsletter in der Config — überspringe.');
            return 0;
        }
        $io->section('Newsletter');
        $app = App::get();
        $suffix = strtoupper((string) ($nl['env_suffix'] ?? ''));
        $provider = strtolower((string) $nl['provider']);

        try {
            $p = match ($provider) {
                'mailchimp' => $this->mailchimpProvider($app, $suffix),
                'sendy'     => $this->sendyProvider($app, $suffix, $nl),
                default     => throw new \RuntimeException("Unbekannter Newsletter-Provider: {$provider}"),
            };
            $stats = $p->recentCampaigns(12);
            $n = $repo->saveNewsletterStats($clientId, $stats, $measuredAt);
            $io->success(sprintf('%s: %d Kampagne(n)/Snapshot(s) erhoben.', $provider, $n));
            return $n;
        } catch (\Throwable $e) {
            $io->warning("Newsletter ({$provider}) fehlgeschlagen: " . $e->getMessage());
            return 0;
        }
    }

    private function mailchimpProvider(App $app, string $suffix): \Openstream\Visibility\Provider\MailchimpProvider
    {
        $key = $app->env("MAILCHIMP_API_KEY_{$suffix}");
        if (!$key) {
            throw new \RuntimeException("MAILCHIMP_API_KEY_{$suffix} fehlt in .env");
        }
        return new \Openstream\Visibility\Provider\MailchimpProvider($key);
    }

    /** @param array<string,mixed> $nl */
    private function sendyProvider(App $app, string $suffix, array $nl): \Openstream\Visibility\Provider\SendyProvider
    {
        $key = $app->env("SENDY_API_KEY_{$suffix}");
        $url = $app->env("SENDY_URL_{$suffix}");
        if (!$key || !$url) {
            throw new \RuntimeException("SENDY_API_KEY_{$suffix} / SENDY_URL_{$suffix} fehlen in .env");
        }
        return new \Openstream\Visibility\Provider\SendyProvider($key, $url, $nl['list_id'] ?? null);
    }

    /**
     * URLs fürs Onsite-Audit: key_pages aus der Config + Top-Seiten aus GSC (nach Klicks).
     * @param array<string,mixed> $cfg
     * @return array<int,string>
     */
    private function onsiteUrls(array $cfg): array
    {
        $urls = $cfg['key_pages'] ?? ['https://' . ($cfg['domain'] ?? '') . '/'];

        // Top-Seiten aus GSC ergänzen (nach Klicks), falls Property vorhanden.
        $siteUrl = $cfg['gsc']['site_url'] ?? null;
        if ($siteUrl) {
            try {
                $gsc = GscClient::fromEnv();
                $end = date('Y-m-d', strtotime('-3 days'));
                $start = date('Y-m-d', strtotime('-28 days'));
                $rows = $gsc->searchAnalytics($siteUrl, $start, $end, ['page'], 20);
                foreach ($rows as $r) {
                    $urls[] = $r['keys'][0];
                }
            } catch (\Throwable) {
                // ohne GSC nur key_pages
            }
        }

        // Deduplizieren, auf sinnvolle Anzahl begrenzen (Kosten/Zeit).
        return array_slice(array_values(array_unique($urls)), 0, 25);
    }
}
