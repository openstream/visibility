# CLAUDE.md — Visibility Dashboard

Kontext und Konventionen für Claude Code in diesem Projekt. Kurz halten, aktuell halten.

## Was das ist

Ein **Visibility Dashboard** für Nick (openstream.ch): prüft regelmässig und
automatisiert die Sichtbarkeit **eines ganzen Unternehmens** — nicht nur der Website,
sondern auch Social Media und Newsletter.

**Website** in
- **Google** (klassisches SEO — Rankings, Impressions, Klicks, CTR, Position),
- **Bing** (SEO via Webmaster Tools **plus** dessen neuer **AI Performance
  (Beta)** Report — Citations in Copilot & Bing-AI-Summaries),
- **ChatGPT** und **Perplexity** (GEO — werden Marke/Domain in KI-Antworten
  erwähnt und zitiert?),
- optional **Google AI Overviews / AI Mode**, **Gemini**,

und misst zusätzlich den **Zustand der Website selbst**:
- **Onsite/technisches SEO** (Audit der Kundenseite),
- **Offsite SEO** (Backlink-/Autoritätsprofil).

**Social Media** (Roadmap, s. README): YouTube, TikTok, Instagram (LinkedIn vorerst weg).
Fokus **monatliche Views/Follower der EIGENEN Kunden-Kanäle**. **YouTube** offiziell via
Data API (API-Key, kein OAuth, Views inkl. Shorts). **TikTok/Instagram:** Gesamt-Views nur
des **eigenen** Kunden-Accounts via **Apify** (kein OAuth). **Kein Wettbewerber-Tracking**
(Nick-Entscheidung Juli 2026). Ethische Linie: eigene Accounts scrapen ≠ fremde scrapen.

**Newsletter** (Roadmap, s. README, Owned Media): Öffnungs-/Klickraten, Bounces,
Abmeldungen, Listen-Wachstum. Tool je Kunde verschieden — **Mailchimp** (viele Kunden,
offizielle Marketing API) und **Sendy** (openstream, selbst-gehostet). Zwei Provider
hinter einem Interface, API-Key je Kunde aus `.env` (Suffix = Slug). Eigene, private
Daten; nur aggregierte Raten, keine Empfänger-Adressen.

Es ist ein **SEO + GEO Dashboard** mit Social- und Newsletter-Kanälen. Ersetzt Xovi
(nicht mehr im Einsatz).

**SEO umfasst dabei bewusst beide Seiten:**
- **Onsite / technisches SEO** (bisher via Xovi gemacht): Meta/Titles/Descriptions,
  Heading-Struktur, Core Web Vitals/PageSpeed, Mobile, strukturierte Daten,
  Canonicals, robots/sitemap, Indexierbarkeit, hreflang (CH oft mehrsprachig!),
  Broken Links, Security-Header, Alt-Texte, Duplicate Content.
- **Offsite SEO**: Backlinks, referring domains, Autoritäts-Metriken — DR (Domain
  Rating, Ahrefs), DA (Domain Authority, Moz), TF (Trust Flow, Majestic) —
  neue/verlorene Links, Anchor-Texte, Wettbewerber-Backlink-Vergleich.

**Output pro Kunde und Monat:**
1. Ein ausführlicher **Visibility-Report als `.md`** (auf Deutsch).
2. Eine **Executive Summary** (kurz, deutsch) → wird als Body-Text der Mail an
   den Kunden geschickt. Der ausführliche `.md`-Report ist Anhang/Verweis.

Der Report kann **automatisch (monatlich) oder manuell** per Mail versendet werden.

## Wichtige Rahmenbedingungen (nicht aufweichen)

- **Eigenes Dashboard, KEINE fertigen SEO/GEO-Suites.** Wir bauen das Dashboard
  selbst auf Basis der am besten geeigneten **einzelnen Daten-/APIs**. Also NICHT
  Xovi/Sistrix/Semrush/Ahrefs/SE-Ranking *als Produkt/Dashboard* einbinden.
  Deren *rohe Daten-APIs* (z.B. eine Backlink-API) sind erlaubt, wo sie die beste
  Datenquelle sind — aber die Aufbereitung, das Dashboard und der Report sind
  komplett unser eigener Code. Wenn eine Aufgabe nur über ein Suite-Produkt
  lösbar scheint: mit Nick abklären, nicht heimlich eine Suite einführen.
- **Datenerhebung über APIs, KEIN eigener Crawler/Scraper.** „Crawlen" meint hier
  den regelmässigen Erhebungslauf gegen APIs — nicht das Bauen eines eigenen
  Website-Crawlers. Onsite-/technische Checks kommen über die DataForSEO OnPage API
  (+ gratis Google-APIs/Observatory), Content-Kontext beim Onboarding ebenfalls über
  die DataForSEO OnPage API. Guzzle ist nur der HTTP-Client für diese API-Calls,
  kein Scraper. Falls ein Check über keine API verfügbar ist: mit Nick abklären,
  nicht eigenmächtig einen Crawler bauen.
  - **Social-Media-Daten:** **YouTube** über die offizielle Data API (API-Key). **TikTok/
    Instagram** über **Apify**, aber NUR für die **eigenen** öffentlichen Kunden-Accounts
    (Gesamt-Views, kein OAuth). **KEIN Scraping fremder/Wettbewerber-Accounts** und keine
    Personendaten (Nick-Entscheidung Juli 2026). Ethische Linie: eigene Accounts eines
    Kunden auslesen ≠ fremde Accounts scrapen. Nur aggregierte Account-Stats des Kunden.
- **Nur für Nick.** Kein Kundenzugang, kein Login, kein Rollen-/Rechte-System,
  kein Multi-User. Single-User-Tool. Keine Auth-Komplexität einbauen.
- **Sprache der Reports: Deutsch.** Code, Kommentare, Commits: Englisch ok.
- **Erhebungsrhythmus: wöchentlich crawlen, monatlich auswerten.** Die Erhebung
  (`collect`) läuft **wöchentlich** je Kunde → jeder Messwert wird mit Datum in
  die DB geschrieben (Zeitreihe!). Der **Report wird monatlich** aus diesen
  wöchentlichen Datenpunkten erzeugt (`report`). So bekommen wir historische
  Entwicklung *innerhalb* des Monats für die Diagramme, ohne die Kosten von
  Daily-Tracking. Kein Daily nötig.
- **Diagramme sind Pflicht** — im Dashboard und im `.md`-Report. Immer beides:
  **Momentaufnahme** (aktueller Stand) *und* **historische Entwicklung** (Zeitreihe
  über die Wochen/Monate). Siehe README → „Diagramme & Visualisierung".
- **Lokal zuerst mit DDEV**, später evtl. produktiv auf `visibility.openstream.ch`.
  Code so schreiben, dass der Sprung lokal→prod klein ist (keine hartcodierten
  Pfade/URLs, Config über `.env`).

## Tech-Stack

- **PHP** (Ziel: 8.3+). Kein grosses Framework nötig; schlank halten.
  Empfehlung: **Slim** oder plain PHP + Composer. Twig für Views.
- **DDEV** für lokale Entwicklung (`ddev start`, `ddev launch`).
- **MySQL/MariaDB** (kommt mit DDEV) für Kunden, Keywords, historische Messwerte.
- **Composer** für Dependencies (HTTP-Client Guzzle, Twig, phpdotenv, ggf.
  `league/commonmark` für MD-Rendering, PHPMailer/Symfony Mailer für Versand).
- **Cron** (DDEV bzw. später Server-Cron) für die monatliche Automatisierung.

## Datenquellen / APIs (Stand Recherche Juli 2026)

> Konkrete Auswahl + Kosten stehen in `README.md`. Kurzfassung:

- **Google Search Console API** — kostenlos, für Nicks *eigene* verifizierte
  Properties. Liefert echte Klicks/Impressions/CTR/Position pro Query & Seite.
  Erste Wahl für den Google-SEO-Teil. Skill `gsc-api-access` existiert bereits.
- **Bing Webmaster Tools** — kostenlos, für verifizierte Properties. Klassische
  Bing-Daten haben eine API. Der **AI Performance (Beta)** Report hat
  **noch KEINE API** (Microsoft plant sie „im Laufe 2026") → vorerst über die
  eingeloggte UI (Scrape) oder manuellen Import; als eigener Datenpfad kapseln.
  Bing-AI-Daten sind laut Microsoft nur eine **Stichprobe**.
- **DataForSEO** — pay-per-task, **zentrale Datenquelle & sehr vielseitig** (REST,
  Basic Auth Login+Passwort). Kern-Nutzung: SERP (Rankings), OnPage (Onsite),
  Backlinks (Offsite), AI Optimization (GEO). Weitere Gruppen für spätere Features:
  Keywords Data & Labs (Onboarding/Keyword-Ideen/Wettbewerber), Business Data
  (Local SEO — für CH-KMU relevant), Content Analysis (Sentiment/Brand-Mentions),
  Domain Analytics. **Vollständiges Inventar + Priorisierung in README.**
  → **`DataForSeoClient` generisch bauen** (Endpoint + Payload als Parameter,
  gemeinsames Auth/Retry/Cost-Logging), damit neue Gruppen ohne Umbau andockbar sind.
  - SERP API: Google-Rankings für Keywords ohne GSC (CH+Deutsch unterstützt).
  - AI Optimization API: LLM-Mentions/Citations. **Aber: `chat_gpt` nur
    US/Englisch!** → für unsere CH-Domains **nur Perplexity/Gemini/AI-Overview**
    hierüber, NICHT ChatGPT. (CH+DE je Engine am Endpoint verifizieren.)
- **ChatGPT-Sichtbarkeit (Deutsch/CH):** über **OpenAI web-search selbst
  grounden**, da DataForSEO-ChatGPT auf US/EN limitiert ist. Teurer, aber der
  saubere Weg zu deutschsprachiger ChatGPT-Sichtbarkeit.
- **Perplexity Sonar API** (optional/direkt) — citation-native, günstig,
  deutsche Prompts problemlos; Alternative zum DataForSEO-Perplexity-Kanal.
- **SerpApi** — Backup zu DataForSEO SERP. Nicht Default.

**Architektur-Prinzip:** Provider hinter einem Interface kapseln
(`Provider\SerpProvider`, `Provider\GeoProvider`), damit wir Anbieter tauschen
können, ohne den Report-Code anzufassen. API-Keys nur aus `.env`.

## Konventionen

- **Secrets** ausschliesslich in `.env` (nie committen; `.env.example` pflegen).
- **Kunden-Konfiguration** deklarativ: pro Kunde Domain, zu trackende Keywords,
  GEO-Prompts (die Fragen, mit denen wir ChatGPT/Perplexity testen),
  Wettbewerber, Empfänger-Mail. Als DB-Einträge oder YAML pro Kunde.
- **Onboarding vor Erhebung — beginnt mit dem Verstehen der Website:** Zuerst wird
  der Website-Inhalt (via API, kein eigener Crawler) analysiert und per LLM ein
  **`website_profile`** abgeleitet (was die Seite IST, ihre Absicht/Ziel, Angebot,
  Zielgruppe, Region, Positionierung, Marke). Das ist die **Innensicht** und die
  Grundlage für alles Weitere — ohne sie sind Kategorie-/Marken-Prompts geraten.
  Erst darauf aufbauend werden Keywords & GEO-Prompts aus echten Signalen
  (GSC/Bing/DataForSEO) generiert, per LLM vorgeschlagen, von Nick kuratiert und
  **vom Kunden freigegeben** (Status `approved`) — inkl. Bestätigung des Profils
  („richtig verstanden?"). `collect` läuft erst mit freigegebenen Listen.
  Profil & Prompts sind CH-lokalisiert und **lebende Config** (quartalsweise/bei
  Relaunch prüfen). Siehe README → „Keyword- & GEO-Prompt-Generierung".
- **Reports** landen unter `storage/reports/<kunde>/<YYYY-MM>.md`. Roh-API-Antworten
  unter `storage/raw/...` cachen (Kosten + Reproduzierbarkeit).
- **Kein Live-API-Call in der Web-UI** ohne Not — teuer/langsam. UI liest aus DB;
  Erhebung läuft über CLI-Kommandos/Cron.
- Zeitzone Europe/Zurich. Beträge/Zahlen im Report deutsch formatiert.

## Häufige Kommandos (Zielbild, siehe README)

```bash
ddev start
ddev composer install
ddev exec php bin/console onboard --client=<slug>   # einmalig: Keywords + GEO-Prompts generieren → Kundenfreigabe
ddev exec php bin/console collect --client=<slug>   # wöchentlich: Daten erheben (Zeitreihe)
ddev exec php bin/console report  --client=<slug> --month=2026-07  # monatlich: Report + Charts
ddev exec php bin/console send    --client=<slug> --month=2026-07 [--dry-run]
```

## Offene Punkte

Siehe `README.md` → „Offene Entscheidungen". Vor dem Coden mit Nick klären.
