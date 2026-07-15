# Visibility Dashboard

**SEO + GEO Sichtbarkeits-Dashboard** — misst regelmässig und automatisiert die
Sichtbarkeit eines **ganzen Unternehmens** (nicht nur der Website, sondern auch
Social Media und Newsletter) und leitet daraus konkrete Optimierungsmöglichkeiten ab:

- **Website** in **Google & Bing** (klassisches SEO: Rankings, Onsite, Backlinks)
  und in **ChatGPT, Perplexity, Gemini & AI Overviews** (GEO: wird die Marke in
  KI-Antworten erwähnt/zitiert?).
- **Social Media** (TikTok, Instagram, YouTube, LinkedIn): Follower, Engagement,
  Wachstum — eigene und Wettbewerber-Accounts. *(Roadmap)*
- **Newsletter** (Owned Media): Öffnungs-/Klickraten, Listen-Wachstum. *(Roadmap)*

Erzeugt pro Kunde einen monatlichen, ausführlichen Report auf Deutsch inkl.
Diagrammen und einer Executive Summary; der automatische Mail-Versand (Summary als
Mail-Body) ist geplant.

Eigenes Tool (kein Kundenzugang), lokal mit **DDEV** entwickelt, später evtl.
produktiv auf `visibility.openstream.ch`. PHP 8.3, MariaDB, **DataForSEO** als
zentrale Datenquelle + gratis Google/Bing-APIs. Keine fertigen SEO-Suites.

> Dieses README ist zugleich **Konzept, Recherche, Architektur und Statusplan**.
> Arbeitskonventionen für Claude Code stehen in `CLAUDE.md`.

## Schnellstart

```bash
ddev start && ddev composer install
cp .env.example .env          # API-Keys eintragen (DataForSEO, OpenAI, Anthropic ...)
ddev exec php bin/console migrate
ddev exec php bin/console onboard --client=<slug> --save   # Keywords + GEO-Prompts generieren
ddev exec php bin/console approve --client=<slug>          # freigeben
ddev exec php bin/console collect --client=<slug>          # Rankings erheben (wöchentlich)
```

## Inhalt

- [Recherche: Datenquellen & Dienste](#recherche-datenquellen--dienste-juli-2026)
- [Kostenübersicht](#kostenübersicht--pro-domain--monat-wöchentliche-erhebung)
- [Keyword- & GEO-Prompt-Generierung (Onboarding)](#keyword--geo-prompt-generierung-onboarding-kern)
- [Marktverteilung Schweiz](#marktverteilung-schweiz-kontext-für-dashboard--report)
- [Diagramme & Visualisierung](#diagramme--visualisierung)
- [Phasenplan & Status](#phasenplan)

---

## Recherche: Datenquellen & Dienste (Juli 2026)

### SEO / Suchmaschinen-Sichtbarkeit

| Quelle | Modell | Kosten | Wofür | Bewertung |
|---|---|---|---|---|
| **Google Search Console API** | offiziell, OAuth/Service-Account | **kostenlos** | Echte Klicks, Impressions, CTR, Position pro Query & Seite — nur für *eigene* verifizierte Properties | **Erste Wahl**, wo GSC-Zugriff besteht. Skill `gsc-api-access` existiert bereits. |
| **GSC — Search Generative AI Report** | offiziell | **kostenlos** | Impressions/Pages/Countries/Devices in **AI Overviews & AI Mode** (Daten ab 18.05.2026, **keine Queries/Klicks/CTR**) | ⚠️ **Angekündigt 3. Juni 2026, Rollout nur an UK-Teilmenge, keine API.** Bei unseren CH-Domains (z.B. hepro.ch) **noch nicht sichtbar.** → Als „kommt später" einplanen, nicht darauf warten; sobald verfügbar für AIO-Seiten-Signale nutzen. |
| **Bing Webmaster Tools** | offiziell | **kostenlos** | Bing-Rankings/Impressions/Klicks (API ✅, umgesetzt) **und** der **AI Performance (Beta)** Report: Citations in Microsoft Copilot & Bing-AI-Summaries, „Grounding Queries", Intents/Topics/Citation-Share/Compare (seit Juni 2026). Nur für *eigene* verifizierte Properties. | Klassische WMT-Daten ✅ via API (`BingWmtClient`/`BingSerpProvider`). **AI-Report: weiterhin KEINE API** (Microsoft: „im Laufe 2026", Juli 2026 noch nicht live). **Aber CSV-Export über die UI** (bing.com/webmasters/aiperformance) → sauberer Datenpfad: manueller CSV-Import statt Scrape. **90-Tage-Fenster** → regelmässig exportieren & selbst archivieren. Daten sind eine **Stichprobe**. |
| **DataForSEO — SERP API** | pay-per-task | ~$0.0006/Query (Standard-Queue, ~5 Min) bis $0.002 (Live, ~6 Sek) | Google-Rankings für beliebige Keywords/Domains *ohne* GSC-Zugriff; Wettbewerber-Rankings. **Unterstützt Schweiz + Deutsch** (`location="Switzerland"`, `gl=ch`, `hl=de`; Labs deckt DE/FR/IT für CH ab). PHP-Beispiele in Doku. | **Zweite Wahl / Ergänzung** für SEO. Günstigster Anbieter bei Volumen, transparentes Pay-per-Query. |
| SerpApi | Abo | ab $25/Mt (1'000 Suchen), $75 (5'000) | SERP-Scraping, >100 Engines, `gl=ch`/`hl=de`/`location` | Backup zu DataForSEO. Teurer bei Volumen, aber sauberes JSON & breite Abdeckung. |
| ValueSERP / ScaleSERP, Scrapingdog, Bright Data, Oxylabs SERP | pay-per-1K | ~$0.30–1.60 / 1'000 | Reine Google-SERP-Scraper mit CH-Targeting (UULE/`gl=ch`) | Günstige SERP-only-Alternativen. Kein Keyword-/Backlink-/GEO-Mehrwert. Nur falls DataForSEO nicht reicht. |

**Bewusst NICHT genutzt (Suite-Produkte):** Sistrix, XOVI (Nicks Alt-Tool),
SE Ranking, Semrush, Ahrefs. → Entscheidung: **kein Suite-Dashboard**, wir bauen
selbst. Ihre *rohen Daten-APIs* wären erlaubt, sind aber teurer/abo-gebunden als
DataForSEO und bringen für unseren Fall keinen Mehrwert. Einzige Ausnahme, die man
später abwägen könnte: Sistrix-**OVI** als Kennzahl, falls ein Kunde ihn explizit
im Report erwartet — dann nur diese eine Zahl, nicht die Suite.

> **SE Ranking geprüft & verworfen (Juli 2026):** Beide (SE Ranking + DataForSEO)
> haben MCP-Server, aber MCP ist für ein LLM-Client-Szenario (Claude Desktop), nicht
> für unser PHP-Tool, das die REST-API direkt anspricht — kein Mehrwert. Einziger
> echter Unterschied: SE Ranking hat via Planable **Social-Media-Tracking** (9
> Plattformen, inkl. Social-Zitate in ChatGPT/Perplexity), DataForSEO nicht.
> **Update (Social ist jetzt Ziel):** Wir lösen das **nicht** über SE Ranking/Planable
> (Suite), sondern selbst — YouTube offiziell (Data API), TikTok/Instagram via Apify nur
> für die *eigenen* Kunden-Accounts (Gesamt-Views). Kein Wettbewerber-Tracking, LinkedIn
> vorerst weggelassen (Nick-Entscheidung, s. „Social-Media-Sichtbarkeit"). SE Ranking
> bleibt verworfen; die „keine Suite"-Leitplanke gilt weiter.

### Onsite / technisches SEO (Website-Audit)

Bisher via Xovi gemacht → jetzt selbst gebaut, aber **das Crawlen läuft über APIs,
NICHT über einen selbstgebauten Crawler/Scraper.** „Crawlen" = regelmässiger
Erhebungslauf gegen APIs. Kein Screaming Frog/Sitebulb (Desktop-Tools) und
**kein Eigenbau-Crawler** nötig.

| Quelle | Modell | Kosten | Wofür | Bewertung |
|---|---|---|---|---|
| **DataForSEO OnPage API** | pay-per-task | **~$0.000125/Seite** (Base), +JS-Rendering | **Crawl-Backbone (API).** 60+ technische Checks: Meta/Title/Description, Headings, Canonicals, robots.txt, Sitemap, Broken Links (4xx/5xx), Redirect-Chains, **hreflang**, strukturierte Daten, HTTPS, Mobile, **Alt-Texte**, Duplicate Content, interne Links, Core Web Vitals (aus Lighthouse). JSON, async. | **Primäre Onsite-Quelle.** ~$1.25 für 10'000 Seiten. Deckt die allermeisten Checks ab — inkl. der Punkte, die wir sonst „selbst" prüfen müssten. |
| **Google PageSpeed Insights API** | offiziell | **kostenlos** (25'000 Req/Tag) | Lighthouse-Lab + Core Web Vitals (LCP, INP, CLS), SEO-/Performance-Score je URL | **Einbauen** — gratis, präzise Performance-Daten. |
| **Chrome UX Report (CrUX) API** | offiziell | **kostenlos** | **Feld-/Real-User-Daten** der Core Web Vitals (nicht nur Lab) | **Einbauen** — ergänzt PageSpeed um echte Nutzerdaten. |
| **Google Search Console API** | offiziell | **kostenlos** | Crawl-Stats, Index-Coverage, Mobile-Usability der *eigenen* Properties | Bereits im SEO-Teil; liefert auch Onsite-Signale. |
| **Mozilla Observatory API** | offiziell | kostenlos | Security-Header, TLS-Bewertung | Kleiner Zusatz-Check per API. |

**Onsite-Strategie: rein API-basiert.** DataForSEO OnPage als Crawl-Backbone (deckt
Meta/Headings/hreflang/Broken Links/Alt-Texte/strukturierte Daten selbst ab) +
gratis Google-APIs (PageSpeed/CrUX/GSC) + Mozilla Observatory. **Kein eigener
Crawler.** Kostet real nur wenige Franken/Monat für alle 4 Domains.
Falls später ein Spezialcheck fehlt, den keine API liefert: mit Nick abklären, ob
er das Report-Ergebnis überhaupt braucht — nicht reflexartig selbst crawlen.

### Offsite SEO (Backlinks / Autorität)

| Quelle | Modell | Kosten | Wofür | Bewertung |
|---|---|---|---|---|
| **DataForSEO Backlinks API** | pay-per-task | **$0.02/Request + $0.00003/Zeile** (~$0.05 / 1'000 Backlinks) | Referring Domains, Backlink-Anzahl/-Qualität, **Domain/Page/Backlink-Rank**, **Spam-Score**, neue/verlorene Links, Anchor-Texte, Wettbewerbsvergleich. 2.8 T Live-Backlinks, .ch normal abgedeckt. JSON, PHP-tauglich. | **Klare erste Wahl.** Einziger echter pay-per-use-Anbieter mit gutem Index. ~100× günstiger als Ahrefs-API. |
| ~~Ahrefs API, Semrush API, Majestic, Moz~~ | Abo/Enterprise | $400–999+/Mt | Autoritäts-Metriken: **DR** (Domain Rating, Ahrefs), **DA** (Domain Authority, Moz), **TF** (Trust Flow) & **CF** (Citation Flow, Majestic) | ❌ **DEFINITIV NICHT NUTZEN.** Das sind Suites → verstösst gegen die „keine Suites"-Leitplanke, ausserdem unwirtschaftlich. Nur hier gelistet, um die Entscheidung zu dokumentieren. Ihre Autoritäts-Metriken werden **nicht** gebraucht — wir nutzen den DataForSEO-eigenen Domain/Backlink-Rank + Spam-Score. |
| Bing Webmaster Tools (eigene Site, gratis) | offiziell | kostenlos | Backlinks *zur eigenen* verifizierten Domain | Kostenlose Zusatzquelle für die eigenen Properties; keine Wettbewerber. |
| Common Crawl / OpenLinkProfiler | offen/gratis | kostenlos | Roh-Backlink-Daten | Für kontinuierliches Monitoring **nicht praktikabel** (zu roh/technisch bzw. zu klein). |

**Offsite-Strategie:** **DataForSEO Backlinks ist die einzige Backlink-Quelle** —
inkl. eigener Autoritäts-Metrik (Domain/Backlink-Rank) und Spam-Score. Für die
*eigenen* verifizierten Domains zusätzlich gratis via GSC/Bing. **Ahrefs, Semrush,
Majestic, Moz sind Suites und werden nicht genutzt** (auch nicht das gratis Ahrefs
Webmaster Tools — hält uns sauber Suite-frei). Bewusste Lücke: keine
Trust-Flow/Citation-Flow-Metrik (Majestic-exklusiv) — brauchen wir nicht.

> **Konsolidierung:** DataForSEO wird damit die zentrale bezahlte Datenquelle für
> **vier** Rollen — SERP-Rankings, GEO (Perplexity/Gemini/AIO), OnPage/Onsite und
> Backlinks/Offsite. Ein Account, eine Auth, ein `DataForSeoClient` im Code. Das
> vereinfacht die Architektur erheblich und hält die Kosten niedrig.

### Social-Media-Sichtbarkeit (eigene Kanäle, via OAuth)

Sichtbarkeit ist mehr als die Website: **Social Media** gehört zum Auftritt eines
Unternehmens dazu. Ziel: **echte monatliche Views/Reichweite** und Follower-Wachstum der
**eigenen** Kanäle des Kunden. Plattformen: **YouTube, Instagram, TikTok** (LinkedIn
vorerst weggelassen). Zeitreihe bauen wir selbst (wöchentlich `collect` → DB).

**Kernentscheidung (Nick, Juli 2026 — Kurskorrektur weg von Apify):** Für zuverlässige,
**exakte Monats-Views** führt kein Weg an den **offiziellen APIs mit OAuth** vorbei.
Apify/öffentliches Scraping liefert das nicht sauber (IG gibt öffentlich gar keine
Account-Views; TikTok je nach Actor; YouTube nur Lifetime-Näherung). Deshalb: **der Kunde
verbindet seine Kanäle selbst per OAuth** über eine schlanke Web-App auf
`visibility.openstream.ch` → einmal „mit YouTube/Instagram/TikTok verbinden" klicken. Das
skaliert (kein Setup-Aufwand für Nick pro Kunde) und liefert die genauesten Daten.

| Plattform | Quelle (OAuth, eigenes Konto) | Liefert |
|---|---|---|
| **YouTube** | **Analytics API** (`youtubeAnalytics.reports.query`, Scope `yt-analytics.readonly`) | echte Monats-`views`, Watchtime, Subscriber-Gains, **Shorts-vs-Video-Split** (`creatorContentType`). |
| **Instagram** | **Graph API Insights** (Business/Creator) | echte Reichweite/Impressions/Views + Follower — das, was öffentlich GAR nicht geht. Meta-App-Review nötig. |
| **TikTok** | **Display/Business API** (eigenes verbundenes Konto) | echte Video-Views/Engagement des eigenen Kontos. |

**Architektur der OAuth-Anbindung** (s. „Social via OAuth" unten für Details):
- Schlanke **Verbindungsseite** je Kunde: OAuth-Consent → **Refresh-Token verschlüsselt in
  der DB** (`social_connections`, App-Key aus `.env`).
- `collect --social` nutzt die gespeicherten Tokens (Refresh → Access-Token) und ruft die
  Analytics-APIs headless ab. Kein Kunden-Login/Portal darüber hinaus.
- **Fallback ohne OAuth:** die bereits gebaute **YouTube Data API** (nur API-Key,
  Lifetime-Views-Näherung) bleibt nutzbar, wenn ein Kunde (noch) nicht verbunden ist.

> **Kein Scraping, kein Wettbewerber-Tracking.** Nur die eigenen Kanäle des Kunden mit
> seiner Einwilligung, nur aggregierte Account-Stats (keine Personendaten). Der frühere
> Apify-Weg (heute als `TikTokProvider`/`InstagramProvider` im Code) wird durch OAuth
> ersetzt; die Scraping-Anbieter-Recherche bleibt in Memory + Git-Historie dokumentiert.
> DataForSEO deckt Social nicht ab (Pinterest-only).

### Social via OAuth — Architektur (Roadmap)

Bewusste Erweiterung um eine **minimale Web-Komponente** (bricht die „nur CLI/kein Login"-
Leitplanke gezielt für genau diesen Zweck, s. CLAUDE.md). Kein Kundenportal, keine Reports
für Kunden — nur das Verbinden der Kanäle.

- **`social_connections`** (DB): `client_id`, `platform`, `account_ref`, `refresh_token`
  (**verschlüsselt**, AES via App-Key aus `.env`), `scopes`, `connected_at`, `status`.
- **OAuth-Flow** (Web): `/connect/<platform>?client=<slug>` → Provider-Consent → Callback
  speichert den Refresh-Token verschlüsselt. Pro Plattform eine registrierte OAuth-App
  (Google Cloud / Meta / TikTok Developer) mit Callback-URL auf `visibility.openstream.ch`.
- **`OAuthTokenStore`**: entschlüsselt Refresh-Token, tauscht ihn gegen kurzlebiges
  Access-Token (gecacht), stellt es den Providern bereit.
- **Provider** (`YouTubeAnalyticsProvider`, `InstagramInsightsProvider`,
  `TikTokProvider`): rufen die Analytics-Endpunkte mit dem Access-Token → echte Monats-Views
  → `social_metrics` (bzw. eigene monatliche Tabelle, da hier echte Monatswerte statt Deltas).
- **Lokal testbar (DDEV):** OAuth-Flow gegen Nicks eigene openstream-Konten lokal
  entwickeln/testen (Callback auf die DDEV-URL registrieren), bevor `visibility.openstream.ch`
  produktiv steht.
- **Reihenfolge:** erst die gemeinsame Infrastruktur (Flow + `social_connections` +
  `OAuthTokenStore` + Interface), dann die drei Provider nacheinander andocken.

### Newsletter / E-Mail-Marketing (Owned Media)

Der **Newsletter** ist ein weiterer eigener Sichtbarkeitskanal (Owned Media) neben
Website und Social. Ziel: je Ausgabe **Öffnungsrate, Klickrate, Bounces, Abmeldungen**
und **Listen-Wachstum**, plus Trend über die Ausgaben.

**Das Tool ist pro Kunde verschieden** — openstream nutzt **Sendy**, viele Kunden
**Mailchimp**. Deshalb als **zwei Provider hinter einem `NewsletterProvider`-Interface**,
je Kunde in der Config gewählt. API-Keys je Kunde in `.env` (Suffix = Slug), damit
Secrets aus dem Repo bleiben und pro Kunde getrennte Accounts möglich sind.

| Tool | Datenzugriff | Liefert |
|---|---|---|
| **Mailchimp** | offizielle **Marketing API** (`/reports`, `/lists`), REST/JSON, API-Key (enthält Server-Prefix `-usXX`) | Opens/Clicks/Bounces/Unsubscribes je Kampagne + Listen-Wachstum, sauber. |
| **Sendy** (selbst-gehostet, Amazon SES) | schlanke API (`/api/...`, Abonnenten aktiv/abgemeldet); Kampagnen-Opens/Clicks liegen in Sendys **MySQL-DB** → read-only-Zugriff bzw. Report-Endpunkte. Beim Bau prüfen, was die Version hergibt. | dieselben Kennzahlen, teils via DB. |

- **Besonderheit:** rein **eigene, private Daten** (kein Scraping, kein
  Wettbewerber-Vergleich) → datenschutzrechtlich unkritisch, nur aggregierte Raten
  in den Report (keine Empfänger-Adressen).
- **Report:** eigener Abschnitt „Newsletter" mit Momentaufnahme (letzte Ausgabe) +
  Zeitreihe (Öffnungs-/Klickrate über die Ausgaben, Listen-Wachstum). Kunden ohne
  Newsletter: Abschnitt sauber ausblenden.

### Openstream Visibility Score (OVS) — eine plattformübergreifende Zahl

Ziel: **eine** monatliche Kennzahl, die auf einen Blick vermittelt, wie sichtbar das
Unternehmen online war — über Website, KI-Antworten, Social und Newsletter hinweg.

**Das methodische Problem (bewusst gelöst):** GSC-Impressions, YouTube-Views und Klicks
sind NICHT dasselbe. Eine Impression (Seite erschien irgendwo im Suchergebnis, oft
ungesehen) mit einem Video-View (jemand hat aktiv gestartet) zu addieren, erzeugt eine
Scheinzahl, die von den riesigen Impression-Zahlen dominiert wird und „flüchtig erschienen"
mit „aktiv konsumiert" vermischt. Willkürliche Gewichte (Impression = 0.1 o.ä.) wären
Marketing-Theater.

**Lösung — OVS misst „aktive Sichtkontakte" (angelehnt an die ETV-Logik):** jeder Kanal
wird auf dieselbe ehrliche Einheit reduziert — *ein Mensch hat den Content aktiv
konsumiert*. Keine erfundenen Gewichte; jede Komponente ist entweder eine **echte Aktion**
oder eine **CTR-fundierte Schätzung** (nicht 0.1, sondern die reale Klickrate):

```
OVS (Monat) = Google-Klicks + Bing-Klicks               (echte Besuche)
            + (Google-Impressions × Google-CTR)          (erwartete Besuche, CTR-fundiert)
            + GEO-Nennungen/Zitate (ChatGPT/Perplexity/AIO)  (aktiver KI-Sichtkontakt)
            + YouTube-Views + TikTok-Views + Instagram-Views (aktiv konsumiert)
            + Newsletter-Opens                            (aktiv geöffnet)
```

- **Warum das ehrlich ist:** Impressions werden mit der *tatsächlichen* CTR gewichtet
  (dieselbe Grösse, die ETV nutzt), nicht mit einem gegriffenen Faktor. Damit fliesst eine
  Impression exakt mit ihrem realen Erwartungswert ein (~0,003 Besuche bei 0,3 % CTR), statt
  33× überbewertet. Social-Views/Klicks/Opens sind bereits echte Aktionen (Faktor 1).
- **Report-Darstellung (transparent):** Der Report zeigt den OVS als eine Zahl PLUS die
  **offene Zusammensetzung** — welcher Kanal wie viele Kontakte beitrug + die verwendete
  Formel/CTR. Kein Blackbox-Score; der Kunde kann es nachvollziehen.
- **Bezug zu ETV:** ETV bleibt die kanalspezifische Google-Trend-Kennzahl (in „Traffic-Wert").
  OVS ist die kanalübergreifende Dach-Kennzahl (in „Kontakten"). ETV speist die
  Google-Komponente konzeptionell, ersetzt sie aber nicht.
- **Zeitreihe:** OVS je Monat → eigener Trend-Chart (steigt die Gesamt-Sichtbarkeit?).

> **Offen (beim Bau zu entscheiden):** Behandlung von GEO-Nennungen (1 Kontakt je Nennung?
> ohne Reichweitenschätzung), Newsletter-Opens vs. -Klicks, und ob Bing-Impressions
> mangels CTR-Daten mitzählen. Formel im Code zentral + dokumentiert, damit sie
> nachvollziehbar bleibt und angepasst werden kann.

---

## DataForSEO — vollständiges Fähigkeits-Inventar (für Feature-Planung)

DataForSEO ist deutlich vielseitiger als nur die 4 Kern-Rollen. Alles läuft über
**einen Account (REST API, Basic Auth: Login+Passwort), pay-per-task**. Unten das
komplette Inventar — was wir **jetzt** nutzen (Kern) und was **später** interessante
Features ermöglicht. Damit wir beim Bauen den `DataForSeoClient` gleich so schneiden,
dass neue Endpunkte einfach andockbar sind.

**Onboarding-Auswahl (im DataForSEO-Setup ankreuzen):** SERP Tracking, Backlink
Analysis, Keyword Research, Rank Tracking, Competitor Analysis, AI/LLM Mentions,
Content Optimization, Building SaaS Tool (+ Local SEO). Integration: **REST API**.

| API-Gruppe | Liefert | Nutzung bei uns |
|---|---|---|
| **SERP API** | Rankings Google/Bing/YouTube/Yahoo u.a.; organic, maps, news, images, features | ✅ **Kern** — Google/Bing-Rankings |
| **OnPage API** | Crawl + technisches Audit: Meta, Headings, Canonicals, hreflang, Broken Links, Duplicate Content, Lighthouse/CWV | ✅ **Kern** — Onsite |
| **Backlinks API** | referring domains/networks, Anchor-Texte, neu/verloren, Domain-Rank, Spam-Score, Wettbewerbsvergleich | ✅ **Kern** — Offsite |
| **AI Optimization API** | LLM-Mentions & Citations (ChatGPT*/Perplexity/Gemini/AIO), LLM-Responses je Modell, AI-Keyword-Data | ✅ **Kern** — GEO (*ChatGPT nur US/EN → via OpenAI) |
| **Keywords Data API** | Suchvolumen (Google/Bing Ads), Google Trends, Clickstream-Nachfrage | 🔜 **Onboarding** — Keyword-Ideen & Volumen für CH; „people also ask" als Prompt-Saat |
| **DataForSEO Labs API** | Wettbewerber-Research, Keyword-Ideen, Ranked-Keywords, Domain-Vergleich, Kategorie-Analyse | 🔜 **Onboarding + Wettbewerb** — Konkurrenten & deren Keywords finden |
| **Domain Analytics API** | Tech-Stack-Erkennung, WHOIS, Domain-Infos | 💡 **Später** — Tech-Kontext im Onsite-Report (z.B. CMS erkannt) |
| **Content Analysis API** | Sentiment, Rating-Verteilung, Phrase-Trends über Web-Erwähnungen | 💡 **Später** — Marken-/Reputations-Signal (Brand-Mentions, Sentiment) neben GEO |
| **Business Data API** | Google Business Profile, Bewertungen (Trustpilot/Tripadvisor), Local-Pack, Social-Mentions | 💡 **Später (relevant für CH-KMU!)** — Local SEO: GBP-Sichtbarkeit, Sterne, lokale Rankings |
| **Merchant API** | Amazon / Google Shopping: Produkte, Preise, Reviews | ➖ nur falls ein Kunde E-Commerce/Shopping trackt |
| **App Data API** | Google Play / App Store: Rankings, Reviews | ➖ nur falls ein Kunde eine App hat |

**Priorisierung fürs spätere Backlog:**
1. **Keywords Data + Labs** — direkt fürs Onboarding (Keyword-/Prompt-Generierung,
   Wettbewerber-Findung). Kommen früh dran.
2. **Business Data (Local SEO)** — für lokale CH-Kunden oft wertvoller als reine
   Web-Rankings: Google-Business-Profile-Sichtbarkeit, Sterne-Bewertungen, Local Pack.
   Guter Kandidat für einen eigenen Report-Abschnitt „Lokale Sichtbarkeit".
3. **Content Analysis** — Brand-Mentions/Sentiment als Ergänzung zur GEO-Sektion.
4. **Domain Analytics** — kleiner Tech-Kontext-Block im Onsite-Teil.

> **Architektur-Konsequenz:** `DataForSeoClient` generisch bauen (Endpoint-Pfad +
> Payload als Parameter, gemeinsames Auth/Retry/Cost-Logging), damit neue Gruppen
> ohne Umbau andockbar sind. Provider (`SerpProvider`, `OnsiteProvider`, …) nutzen
> denselben Client, nur mit anderem Endpoint.

---

## Kostenübersicht — pro Domain / Monat (wöchentliche Erhebung)

**Rhythmus:** 4 Läufe/Monat (wöchentlich). Alle Preise verifiziert (Juli 2026).
Mengen sind pro Domain anpassbare Annahmen für ein typisches CH-KMU.

**Annahmen pro Domain:** 20 Keywords (Rankings) · 50 Seiten (Onsite-Crawl) ·
2'000 Backlinks/Snapshot · 8 GEO-Prompts (5 Kategorie + 3 Marke), davon
ChatGPT via OpenAI + 3 Kanäle (Perplexity/Gemini/AI-Overview) via DataForSEO.

**Echte gemessene Kosten (Juli 2026, openstream):** Rankings wöchentlich, GEO/Onsite/
Offsite/Historie monatlich. GEO-Kanäle: ChatGPT + Perplexity (Gemini/Claude deaktiviert,
teurer) + AI Overview.

| Posten | Menge | Rhythmus | Gemessene Kosten |
|---|---|---|---|
| SERP-Rankings (GSC gratis, DataForSEO nur Zusatz) | ~20 Keywords | wöchentlich | ~$0.05/Monat |
| GEO ChatGPT (DataForSEO LLM-Responses) | 20 Prompts | monatlich | ~$0.55 (~$0.027/Call) |
| GEO Perplexity (Sonar API) | 20 Prompts | monatlich | ~$0.10 (günstig) |
| GEO AI Overview (DataForSEO SERP) | ~104 Keywords | monatlich | ~$0.40 ($0.004/Query) |
| Onsite-Audit (DataForSEO OnPage) | 25 Seiten | monatlich | ~$0.004 |
| Offsite/Backlinks (DataForSEO) | 1 Snapshot | monatlich | ~$0.024 |
| Historie-Backfill (DataForSEO Labs) | einmalig/Domain | einmalig | ~$0.13 |
| Gratis: GSC, Bing WMT, Bing-AI-CSV | — | — | $0 |
| **Laufende Summe pro Domain / Monat** | | | **≈ $1.20** |

**→ Pro Domain rund $1.20/Monat** mit ChatGPT+Perplexity+AI-Overview. GEO ist der grösste
Block; wenn Gemini/Claude aktiviert würden, käme je ~$0.55–1.30 hinzu (daher deaktiviert).
Wichtigste Stellschraube: GEO monatlich (nicht wöchentlich) und Kanäle bewusst wählen.
Die alte Schätzung ($25/1'000 OpenAI-Tool-Gebühr) ist überholt — wir nutzen kein OpenAI
direkt, alle Chat-Kanäle laufen über DataForSEO.

### Hochrechnung & Fixkosten

| Domains | API-Kosten/Monat |
|---|---|
| 1 | ~$1.50 |
| **4 (foppa, openstream, schwarzenbach, hepro)** | **~$6** |
| 10 | ~$15 |
| 25 | ~$38 |

- **Einmalig:** DataForSEO-Mindesteinzahlung **$50** (Guthaben verfällt nicht,
  reicht bei 4 Domains ~8 Monate). $1 Gratis-Guthaben zum Testen.
- **Hosting:** lokal (DDEV) $0; produktiv später vernachlässigbar (bestehender Server).
- **Optional statt OpenAI-ChatGPT:** Perplexity Sonar als zusätzlicher/alternativer
  Kanal ist günstiger (~$0.003–0.008/Anfrage statt $0.028) — falls die ChatGPT-Kosten
  stören, kann man den ChatGPT-Kanal seltener (z.B. monatlich statt wöchentlich)
  laufen lassen und drückt die $0.90 auf ~$0.22.

**Stellschrauben, falls Kosten gesenkt werden sollen:**
- ChatGPT-Grounding **monatlich** statt wöchentlich erheben (Rankings/GEO ändern
  sich in KI-Antworten langsam) → grösster Hebel.
- SERP/OnPage in Standard-Queue lassen (bereits eingerechnet, günstigste Stufe).
- Backlink-Snapshot **monatlich** statt wöchentlich (ändert sich langsam) → spart ~$0.24.

> **Fazit:** Für die 4 Start-Domains liegen die laufenden Datenkosten bei **~$6/Monat**,
> selbst mit wöchentlicher Erhebung aller Kanäle. Das Tool ist damit praktisch
> gratis im Betrieb — die Investition ist die Entwicklungszeit, nicht die APIs.

---

### GEO — Sichtbarkeit in ChatGPT / Perplexity / AI Overviews

| Quelle | Modell | Kosten | Wofür | Bewertung |
|---|---|---|---|---|
| **DataForSEO — AI Optimization API** | pay-per-task | pay-per-task (günstig) | LLM-Mentions & Citations über ChatGPT, Perplexity, Gemini, Google AI Overview. Endpunkte u.a. `/v3/ai_optimization/...`, `/v3/dataforseo_labs/ai_mentions/live`. LLM-Responses-API generiert echte Antworten je Modell. Freshness-Lag 2–7 Tage. | **⚠️ WICHTIG für unseren Fall:** **`chat_gpt`-Mentions sind laut Doku nur für „United States" + „English" verfügbar.** Für unsere **CH-Domains mit deutschen, lokalen Prompts ist das ChatGPT-Panel via DataForSEO praktisch wertlos.** Perplexity/Gemini/AI-Overview haben breitere Länder-/Sprach-Abdeckung. → Bleibt **primäre GEO-Quelle für Perplexity/Gemini/AI-Overview**, aber **NICHT für ChatGPT in der Schweiz**. CH+Deutsch-Kombination je Engine am Live-Endpoint `/v3/ai_optimization/llm_mentions/locations_and_languages` mit unserem Key **verifizieren (offener TODO)**. |
| **Perplexity Sonar API** | pay-per-token | Sonar ~$1/1M, Sonar Pro $3/$15 pro 1M In/Out; Web-Grounding inklusive; ~$5–14 / 1'000 grounded Requests | **Citation-native** — jede Antwort liefert `citations`-Array + `search_results`-Metadaten. Direkt Perplexity befragen, Sprache/Land frei wählbar (deutsche Prompts kein Problem). | **Empfohlen als direkter Perplexity-Kanal**, unabhängig von DataForSEO-Länderlimits. |
| **OpenAI API (web-search tool)** | pay-per-call | ~$20–25 / 1'000 Requests | **Eigene ChatGPT-artige Antworten mit Web-Suche selbst grounden — in Deutsch, ohne US-Limit.** | **Wird zum bevorzugten ChatGPT-Kanal für die Schweiz**, weil DataForSEO-ChatGPT auf US/EN beschränkt ist. Teurer, aber der einzige saubere Weg zu ChatGPT-Sichtbarkeit auf Deutsch. |
| **Perplexity Sonar API** (Details oben) | | | Deutsch nativ via `language_filter="de"`, CH-Prompts problemlos, Citations inklusive | **Bestätigter Best-in-Class für deutschsprachiges Selbst-Grounding.** |
| **Google Gemini API / Claude API (web search)** | pay-per-token | günstig | Eigene grounded Antworten je Modell, multilingual inkl. Deutsch, mit Quell-Links | Optionale zusätzliche GEO-Kanäle zum Selber-Grounden, falls Gemini/Claude-Sichtbarkeit relevant wird. |
| **SE Ranking (SE Visible)** | Abo | im SE-Ranking-Abo enthalten | Fertiges AI-Visibility-Dashboard mit **deutscher Konfiguration + CH**, API in allen Plänen; Perplexity/Gemini/AIO | **Einzige fertige Suite mit echtem dt./CH-Setup + API.** ChatGPT bleibt aber auch hier US/EN. Guter Fallback/Ergänzung. |
| Fertige GEO-Suiten (Otterly, Peec [Berlin/DSGVO], Profound, Nightwatch, RankScale, Semrush AI, Ahrefs Brand Radar) | Abo | ~$20–700/Mt | AI-Visibility-Dashboards out-of-the-box | Referenz für Features & Fallback. **Achtung:** fast alle tracken ChatGPT **nur US/EN**; „DACH"-Tools (z.B. prompt-monitoring.com) nutzen zwar dt. Prompts, haben aber oft **kein API**. |

### Firecrawl — geprüft & verworfen (Juli 2026)

**[Firecrawl](https://www.firecrawl.dev/)** (Scrape/Crawl/Search → LLM-ready Markdown)
war kurz als Content-Werkzeug vorgemerkt. **Nicht nötig:** Als SEO-Rank-Tracker und
GEO-Quelle ohnehin ungeeignet (liefert Seiteninhalte, keine SERP-Positionen; kann
keine ChatGPT/Perplexity-Antworten abfragen). Und der Content-Zweck (Seiten fürs
Website-Verständnis holen) wird bereits von der **DataForSEO OnPage API** abgedeckt,
die wir schon nutzen (`ContentFetcher` liefert Meta/Headings/Text/Raw-HTML). Firecrawls
„schöneres" Markdown bringt für unseren LLM-Schritt keinen relevanten Mehrwert. →
**Kein Firecrawl** — eine Quelle weniger, Stack bleibt bei DataForSEO + gratis APIs.

### Empfohlener Ausgangs-Stack (an CH-Realität angepasst)

**Leitprinzip: eigenes Dashboard, keine Suite, und Datenerhebung über APIs — kein
selbstgebauter Crawler.** Wir verdrahten die besten Einzel-APIs selbst. DataForSEO
ist die zentrale bezahlte Quelle über vier Rollen hinweg; alles andere ist gratis.

- **Google-SEO (Rankings):** GSC API (gratis) wo Property verifiziert, sonst
  DataForSEO SERP (`gl=ch, hl=de`).
- **Bing-SEO + Bing-AI:** Bing Webmaster Tools — klassische Rankings via API (✅),
  **AI-Performance-Report via CSV-Import** (noch keine API; UI-Export, 90-Tage-Fenster).
- **Onsite/technisch:** DataForSEO OnPage (Crawl-Backbone via API) + PageSpeed/CrUX/
  GSC + Mozilla Observatory (alle gratis). **Kein eigener Crawler.**
- **Offsite/Backlinks:** DataForSEO Backlinks (einzige bezahlte Quelle) + gratis
  Backlinks der eigenen Domains via GSC/Bing.
- **GEO Perplexity/Gemini/AI-Overview:** DataForSEO AI Optimization API;
  Perplexity zusätzlich/alternativ direkt via **Sonar API** (`language_filter="de"`).
- **GEO ChatGPT (Deutsch/CH):** **OpenAI-API selbst grounden** (web-search tool,
  deutsche Prompts) — NICHT DataForSEO (dessen ChatGPT-Panel ist auf US/EN
  limitiert; die OpenAI-API selbst ist sprachneutral).
- **Provider-Interface** kapselt alle Quellen, damit Wechsel/Ergänzung billig bleibt.

> **Realistischer Minimal-Stack für den Start (die 4 CH-Domains):**
> GSC (gratis) + Bing WMT (gratis) + **DataForSEO als Zentrale** (SERP + OnPage +
> Backlinks + Perplexity/Gemini/AIO, alles pay-per-task) +
> PageSpeed/CrUX/Observatory (gratis) + OpenAI-API (ChatGPT-Grounding) + Perplexity
> Sonar (optional). Alles API-basiert. Erwartete Kosten: ~$6/Monat für 4 Domains.
> **Keine Suite** — bewusste Entscheidung.

> Grobe Kostenschätzung (Solo, ~5–10 Kunden, monatlich): zweistelliger
> Franken-/Dollar-Betrag pro Monat, wenn wir Standard-Queue nutzen und
> Roh-Antworten cachen. Genaue Zahlen nach Kunden-/Keyword-Zählung in Phase 1.

**Quellen:** [DataForSEO AI Optimization API](https://dataforseo.com/apis/ai-optimization-api) · [DataForSEO Docs v3](https://docs.dataforseo.com/v3/ai_optimization-overview/) · [DataForSEO LLM Mentions Locations & Languages (ChatGPT = US/EN only)](https://docs.dataforseo.com/v3/ai_optimization-llm_mentions-locations_and_languages/) · [DataForSEO: Swiss Italian in Labs API](https://dataforseo.com/update/swiss-italian-available-in-dataforseo-labs-api) · [Perplexity API Pricing](https://docs.perplexity.ai/docs/getting-started/pricing) · [Bing WMT AI Performance (Public Preview)](https://blogs.bing.com/webmaster/February-2026/Introducing-AI-Performance-in-Bing-Webmaster-Tools-Public-Preview) · [Bing AI Insights: Intents/Topics/Citation Share/Compare (Juni 2026)](https://blogs.bing.com/search/June-2026/New-AI-Visibility-Insights-in-Bing-Webmaster-Tools-Intents-Topics-Citation-Share-Compare) · [Bing WMT AI-Report: API? (Microsoft Q&A)](https://learn.microsoft.com/en-ca/answers/questions/5780844/bing-webmaster-tools-ai-performance-report-is-ther) · [SE Ranking AI Visibility](https://seranking.com/ai-visibility-tracker.html) · [SERP API Vergleich 2026](https://apiserpent.com/blog/serp-api-rank-tracking-cost) · [Sistrix API](https://www.sistrix.com/api/) · [XOVI Rank Tracker](https://www.xovi.com/xovi-tool/rank-tracker/) · [SE Ranking API](https://seranking.com/api.html) · [Perplexity Sonar Language Filter](https://docs.perplexity.ai/guides/language-filter-guide) · [Peec AI (Berlin)](https://docs.peec.ai/api/introduction) · [Gemini API Grounding](https://ai.google.dev/gemini-api/docs/google-search) · [DataForSEO OnPage API](https://dataforseo.com/apis/on-page-api) · [DataForSEO Backlinks API](https://dataforseo.com/apis/backlinks-api) · [PageSpeed Insights API](https://developers.google.com/speed/docs/insights/v5/get-started) · [CrUX API](https://developer.chrome.com/docs/crux/guides/crux-api) · [Mozilla Observatory API](https://developer.mozilla.org/en-US/docs/Web/Security/Practical_implementation_guides)

### Abdeckungs-Check für unsere Ziel-Domains (foppa.ch, openstream.ch, schwarzenbach.ch, hepro.ch)

Alles Schweizer Domains, deutschsprachig, lokaler Fokus. Konsequenzen:

| Kanal | CH + Deutsch nutzbar? | Weg |
|---|---|---|
| Google SEO | ✅ | GSC API (verifizierte Properties) / DataForSEO SERP (CH+DE) |
| Bing SEO | ✅ | Bing WMT API (verifizierte Properties) |
| Bing AI (Copilot/Bing-Summaries) | ✅ Daten da, ⚠️ **nur UI** | Dashboard/UI-Scrape bis Bing-API 2026 kommt |
| Onsite/technisch | ✅ | DataForSEO OnPage (API) + PageSpeed/CrUX/GSC (gratis); hreflang de/fr/it-CH kommt aus OnPage |
| Offsite/Backlinks | ✅ | DataForSEO Backlinks (.ch normal abgedeckt) + gratis eigene Domains via GSC/Bing |
| ChatGPT (GEO) | ✅ | **DataForSEO LLM Responses** (`chat_gpt`, web_search, deutsche Prompts) — umgeht das US/EN-Limit; alternativ OpenAI direkt |
| Gemini (GEO) | ✅ | DataForSEO LLM Responses (`gemini`, web_search) |
| Perplexity (GEO) | ⚠️ | DataForSEO (Freischaltung offen) **oder** Perplexity Sonar direkt |
| Google AI Overview (GEO) | ✅ | DataForSEO LLM Mentions (`google` für CH+de bestätigt) |

**✅ Abdeckung verifiziert (echter API-Test, 14.07.2026):** DataForSEO-Auth OK.
- **LLM Mentions (fertig geparste Panels):** Für `Switzerland` (code 2756) gibt es
  Daten für **de/fr/it**, aber `available_platforms` = **nur `google`** (= AI Overview).
  → Perplexity/Gemini/ChatGPT-**Mentions**-Panels für CH gibt es NICHT.
- **LLM Responses (wir schicken eigene Prompts):** Modelle verfügbar für **ChatGPT**
  (GPT-5.x/4o, `web_search_supported`) und **Gemini** (3.5-flash etc.). Da wir die
  Prompts selbst auf **Deutsch** stellen, **keine Länderbeschränkung** → das ist
  unser Weg für ChatGPT- & Gemini-GEO. Perplexity/Claude-Modelle gaben noch 40104
  (Konto-Freischaltung, s.u.).

> **Strategie-Update:** GEO läuft primär über **DataForSEO LLM Responses** (ChatGPT +
> Gemini, deutsche Prompts) + **LLM Mentions `google`** (AI Overview für CH). Das
> **spart evtl. die separate OpenAI-Integration** ($0.90/Domain) — nach Freischaltung
> Kosten der LLM-Responses-Calls gegen OpenAI-direkt vergleichen und den günstigeren
> Weg wählen. Perplexity via Sonar-API als eigener Kanal bleibt Option.

**✅ Konto freigeschaltet & End-to-End getestet (14.07.2026):** SERP `task_post`
(Standard-Queue) für Google CH/de lief durch — echte CH-Rankings abgerufen
(z.B. „webentwicklung zürich": ebro.ch #4, s-pro.io #5, webagentur.ch #6),
Kosten $0.0006/Query wie kalkuliert. Auth → task_post → task_get bestätigt.
(Die 40104-Meldung war zeitverzögerte Verifizierung, jetzt erledigt.)
**Nächster Test nach Bedarf:** LLM-Responses-Call auf Deutsch (ChatGPT/Gemini) +
OnPage/Backlinks für openstream.ch.

---

## Keyword- & GEO-Prompt-Generierung (Onboarding-Kern)

Beim Onboarding einer Domain müssen **Keywords** (für Rankings) und **GEO-Prompts**
(für die KI-Sichtbarkeit) erzeugt und dem Kunden **zur Prüfung/Freigabe vorgelegt**
werden. Qualität hier = Qualität des ganzen Reports. Ansatz: **erst die Website
verstehen**, dann aus echten Signalen generieren, per LLM ausformulieren, dann
Mensch (Nick + Kunde) kuratieren.

### Schritt 0: Die Website verstehen (Innensicht — Grundlage für alles Weitere)

GSC-Queries & Keyword-Tools sind **Aussensicht** (wonach schon gesucht wird). Das
allein reicht nicht — wir müssen zuerst verstehen, **was die Website IST und was ihre
Absicht ist**, sonst gehen Kategorie-Zuordnung und v.a. Marken-Prompts an der
tatsächlichen Positionierung vorbei. Dieser Schritt ist die Grundlage, aus der alles
Weitere abgeleitet wird.

**Vorgehen (API-basiert, kein eigener Crawler):**
- **Inhalt holen:** Startseite + wichtigste Unterseiten (Leistungen/Produkte, Über
  uns, Kontakt) via **DataForSEO OnPage** (liefert Meta/Headings/Text/Raw-HTML). Über
  `key_pages` in der Kunden-Config die relevanten Seiten wählen (nicht die ganze Site).
- **Verstehen (LLM/Claude):** aus dem Inhalt ein strukturiertes **Website-Profil**
  ableiten:
  - **Was wird angeboten?** (Leistungen/Produkte, konkret)
  - **Absicht/Ziel der Seite?** (verkaufen, Leads, informieren, Marke aufbauen …)
  - **Zielgruppe & Region** (B2B/B2C, Branche, CH-Fokus/Kanton, Sprache/n)
  - **Positionierung / USP / Tonalität** (wie beschreibt sich die Marke selbst?)
  - **Marken-/Entitätsname(n)** und wie sie genannt werden
  - **Content-Themen & wichtige Seiten** (woraus Kategorien werden)
- **Ergebnis:** ein `website_profile` (JSON) pro Kunde, das in die Keyword-/Prompt-
  Generierung einfliesst und im Onboarding-Report dem Kunden **auch zur Bestätigung
  vorgelegt wird** („Haben wir eure Seite richtig verstanden?"). Wird in der DB/Config
  gespeichert; ist selbst **lebende Config** (bei Relaunch/Neuausrichtung aktualisieren).

> Ohne dieses Verständnis sind die Prompts geraten. Mit ihm sind Kategorie-Prompts
> („bester Anbieter für *das, was die Seite wirklich tut*") und Marken-Prompts
> („kennt die KI *diese Marke* korrekt?") trennscharf und aussagekräftig.

### Woher die Rohsignale kommen (datenbasiert, nicht geraten)

| Quelle | Liefert | Für | Status |
|---|---|---|---|
| **Google Search Console** (eigene Property) | echte Suchanfragen (Query, Klicks, Impressions, Position) | **beste Keyword-Quelle**; auch Prompt-Saatgut | ✅ via API verfügbar |
| **Bing Webmaster Tools** | Suchanfragen (API) **+ AI Performance „Grounding Queries"** (welche Fragen KI-Antworten mit Zitat der Seite auslösten) | **Prompt-Saatgut aus echten KI-Anfragen** | Klassik via API (✅); **AI-Report via CSV-Export** (keine API) → Grounding-Queries aus CSV einlesen |
| **Google Search Console — Search Generative AI Report** | Impressions/Pages/Countries/Devices in AI Overviews & AI Mode (**keine Queries!**) | zeigt, *welche Seiten* in AIO auftauchen → daraus Themen ableiten | ⚠️ **angekündigt 3. Juni 2026, Rollout nur an UK-Teilmenge, Daten ab 18.05.2026, keine API.** Bei hepro.ch & Co. **noch nicht sichtbar** → als „kommt später" einplanen, nicht darauf warten. |
| **DataForSEO** (Keyword-Ideen, „people also ask", verwandte Suchen, AI-Keyword-Data) | Keyword-Vorschläge & Volumen für CH, Frageformulierungen | Keyword- **und** Prompt-Ideen für Domains ohne/mit wenig GSC-Daten | ✅ via API |
| **Website-Inhalt → Website-Profil** (via DataForSEO OnPage + LLM) | Was die Seite IST, Absicht/Ziel, Angebot, Zielgruppe, Positionierung, Marke | **Grundlage (Schritt 0)** für Kategorie- & Marken-Prompts — Innensicht | ✅ API + LLM |
| **Wettbewerber** (vom Kunden genannt / aus SERP) | Konkurrenznamen | für „alternatives to X"- und Vergleichs-Prompts | ✅ |

### Methodik für realistische GEO-Prompts (Best Practice 2026)

- **Echte Nutzer prompten kurz & keyword-nah**, oft mit persönlichem Kontext
  (Ort, Budget, Beruf). Also **keine** elaborierten Marketing-Prompt-Templates,
  sondern natürliche, kurze Fragen auf **Deutsch/CH**.
- **Drei bewährte Buckets** pro Domain kombinieren:
  1. **„best/bester X"** — Kategorie-/Kaufabsicht („Bester Anbieter für <Leistung>
     in <Region CH>?") → misst Sichtbarkeit vs. Wettbewerb.
  2. **„X für <Branche/Zielgruppe>"** — verengt, spiegelt Personalisierung.
  3. **„Alternativen zu <Wettbewerber>"** — Vergleichs-/Verdrängungs-Prompts.
  Plus die schon beschlossenen **Marken-Prompts** („Was ist <Marke>?").
- **CH-Lokalisierung ist Pflicht:** Region/Kanton/Stadt und ggf. Sprache (de/fr/it)
  in die Prompts einbauen — KI-Antworten variieren stark nach Ort.
- **Spezifität > Menge.** Häufigste Fehler: vage Prompts, fehlender Marken-Kontext,
  einmalig statt gepflegt. Prompts sind **lebende Config**, quartalsweise prüfen.

### Generierungs-Pipeline (halbautomatisch, mit Kundenfreigabe)

0. **Website verstehen (Innensicht):** relevante Seiten via API holen → per LLM ein
   **`website_profile`** ableiten (Angebot, Absicht, Zielgruppe, Region, Positionierung,
   Marke). Grundlage für alle folgenden Schritte.
1. **Sammeln (Aussensicht):** GSC-Queries + Bing-Grounding-Queries +
   DataForSEO-Keyword-Ideen + genannte Wettbewerber zusammenführen.
2. **Clustern & Vorschlagen (LLM):** Claude kombiniert **Website-Profil (0)** mit den
   Rohsignalen (1), bündelt zu Themen und formuliert **Keyword-Liste** + **8
   GEO-Prompt-Vorschläge** (5 Kategorie / 3 Marke) auf Deutsch, je mit Begründung und
   Quell-Signal. Das Profil sorgt für Trennschärfe & korrekten Marken-Bezug.
3. **Kuratieren (Nick):** durchsehen, schärfen, CH-Bezug prüfen.
4. **Kundenfreigabe:** Onboarding-Report `.md` mit **(a) Website-Profil zur
   Bestätigung** („richtig verstanden?") **+ (b) Keyword-/Prompt-Vorschlägen** →
   Kunde bestätigt/ergänzt/streicht.
5. **Festschreiben:** freigegebenes Profil + Keywords/Prompts → DB, Status `approved`,
   mit Freigabedatum. Erst dann startet `collect`.

> **Wichtig:** Die Prompts sind das Herz der GEO-Messung. Sie einmal sauber mit
> echten Daten + Kundenwissen aufzusetzen, entscheidet über die Aussagekraft aller
> Folge-Reports. Deshalb eigener Onboarding-Schritt (siehe Phase 1.5).

---

## Marktverteilung Schweiz (Kontext für Dashboard & Report)

Damit Rankings/Mentions richtig gewichtet werden, zeigen Dashboard und Report die
**CH-Marktanteile** als Kontext-Block (z.B. Donut-Diagramme). So ist sofort klar,
warum Google/ChatGPT stärker gewichtet werden als Bing/Perplexity.

**Quelle:** StatCounter Global Stats, **Stand Juni 2026**. Diese Werte als
Startwerte in einer Config/Referenztabelle hinterlegen und ~quartalsweise
aktualisieren (StatCounter-Seiten unten). Ideal später halbautomatisch nachziehen.

### Suchmaschinen — Schweiz (Juni 2026)

| Suchmaschine | Anteil CH |
|---|---|
| Google | **81.6 %** |
| Bing | 10.17 % |
| DuckDuckGo | 2.31 % |
| Yahoo! | 2.27 % |
| Yandex | 1.79 % |
| Ecosia | 1.39 % |

→ Google dominiert klar; Bing ist mit ~10 % aber relevanter als weltweit (~4 %)
— rechtfertigt, Bing (inkl. Bing-AI/Copilot) im Tool mitzuführen.

### AI-Assistenten / Chatbots — Schweiz (Juni 2026)

| Assistent | Anteil CH | zum Vergleich weltweit |
|---|---|---|
| ChatGPT | **71.88 %** | 76.87 % |
| Google Gemini | 8.04 % | 7.94 % |
| **Claude** | **7.11 %** | 3.74 % |
| Microsoft Copilot | 6.38 % | 3.49 % |
| Perplexity | 6.21 % | 7.91 % |
| Phind | 0.26 % | — |

→ **CH-Besonderheit:** Claude (7.11 %) liegt in der Schweiz **vor Perplexity**
(6.21 %) und deutlich über dem Weltschnitt; Copilot ist in CH ebenfalls stärker.
Das stützt die GEO-Kanalwahl: ChatGPT (Pflicht, klar #1), Gemini, dann Claude &
Copilot ernster nehmen als global. Perplexity bleibt relevant, aber nicht #2.
Google AI Overviews laufen innerhalb der Google-Suche und sind separat zu tracken.

**StatCounter-Quellen** (regelmässig nachschauen):
[Suchmaschinen CH](https://gs.statcounter.com/search-engine-market-share/all/switzerland) ·
[AI-Chatbots CH](https://gs.statcounter.com/ai-chatbot-market-share/all/switzerland) ·
[AI-Chatbots weltweit](https://gs.statcounter.com/ai-chatbot-market-share)

---

## Diagramme & Visualisierung

Dashboard **und** `.md`-Report enthalten aussagekräftige, übersichtliche Diagramme.
Immer zwei Perspektiven pro Kennzahl: **Momentaufnahme** (aktueller Stand) und
**historische Entwicklung** (Zeitreihe aus den wöchentlichen Datenpunkten).

### Technische Umsetzung (wichtig: `.md` ≠ interaktive Charts)

- **Dashboard (Web/HTML):** interaktive Charts mit **Chart.js** (schlank, kein
  Framework nötig, gut zu PHP/Twig). Alternativ ApexCharts. Daten aus der DB.
- **`.md`-Report:** Markdown kann keine JS-Charts. Zwei gangbare Wege, wir nutzen
  **beide je nach Diagrammtyp**:
  1. **Statische Bilder (PNG/SVG)** serverseitig rendern und im `.md` einbetten
     (`![...](charts/....svg)`). Rendering via **QuickChart** (Chart.js-kompatibel,
     liefert PNG/SVG per URL/API) oder headless Chart.js. Beste Qualität für
     Linien-/Balken-/Donut-Diagramme im Report/Anhang.
  2. **Mermaid** direkt im Markdown (` ```mermaid `) für einfache Verläufe/Anteile
     — wird auf GitHub/vielen Viewern nativ gerendert, aber nicht in jedem
     Mail-Client. Für den versendeten Report daher eher (1) verwenden.
- Charts als Dateien unter `storage/reports/<kunde>/charts/` ablegen, damit der
  `.md`-Report portabel bleibt (Bilder mitliefern / einbetten).

### Diagramme, die rein sollen (Momentaufnahme + Verlauf)

- **Suchmaschinen-Rankings:** Verlauf der Durchschnittsposition/Sichtbarkeit über
  die Wochen (Linie); Top-Keywords als Balken; Verteilung Top-3/Top-10/dahinter (Donut).
- **Onsite:** Core-Web-Vitals-Verlauf (Linie, LCP/INP/CLS); technische Fehler
  aktuell vs. Vormonat (Balken); Score-Gauge als Momentaufnahme.
- **Offsite:** referring domains & Backlinks über Zeit (Linie); neue vs. verlorene
  Links pro Woche (gestapelte Balken); Autoritäts-Trend.
- **GEO:** Mention-Rate je LLM über Zeit (Linien, ein Strang pro Engine); „in wie
  vielen Prompts erwähnt" aktuell (Balken); Share-of-Voice vs. Wettbewerber (Donut).
- **Markt-Kontext:** CH-Marktanteile Suchmaschinen & AI-Assistenten (Donut,
  s. Abschnitt oben) — als fixer Kontext-Block.

---

## Phasenplan

### Phase 0 — Setup & Konzept ✅/🟡
- [x] `CLAUDE.md` + `ROADMAP.md` + API-Recherche
- [x] Kern-Entscheidungen geklärt: keine Suites, kein eigener Crawler (nur APIs),
      wöchentlich crawlen/monatlich auswerten, Diagramme Pflicht, Onboarding mit
      Kundenfreigabe, plain PHP + Composer, YAML pro Kunde + DB, Gerüst zuerst.
- [x] DataForSEO-Account angelegt, verifiziert, API funktioniert (echter CH-SERP-Test).
- [x] **DataForSEO-Länder/Sprach-Check** erledigt (s.o.: Mentions=nur `google` für CH;
      GEO via LLM-Responses ChatGPT+Gemini auf Deutsch).
- [x] **GSC-Zugriff für openstream.ch eingerichtet** (Service-Account
      gsc-reader@openstream-apis, read-only, via Skill gsc-api-access). Verifiziert:
      136 Klicks / 47'312 Impr. / Ø-Pos 22.5 (28 T). URL-Prefix-Property, deckt alles ab.
      Key liegt ausserhalb des Repos; Token-Helper `gsc_token.sh`. **hepro.ch ebenfalls
      schon am selben SA angebunden.**
- [x] Bing Webmaster Tools: API-Key vorhanden, 14 Properties verifiziert
      (u.a. openstream/foppa/hepro). **Offen:** schwarzenbach.ch verifizieren,
      AI-Performance-Report für die Domains real sichten (was liefert er?).
- [x] 🟡 Ziel-Kunden + Keywords + GEO-Prompts pro Kunde: **openstream vollständig
      onboardet** (104 Keywords + 20 GEO-Prompts approved). foppa/hepro/schwarzenbach
      als Config angelegt, aber noch nicht durchs Onboarding geschickt.

### Phase 1 — Gerüst & lokale Umgebung  ✅ (Grundgerüst steht)
- [x] DDEV-Projekt (PHP 8.3, MariaDB 10.11, docroot `public`), `ddev start` läuft
      → https://visibility-openstream.ddev.site (HTTP 200)
- [x] Composer-Setup (schlank, plain PHP): Guzzle, Twig, phpdotenv, Symfony
      Console/Yaml/Mailer, league/commonmark. *Chart-Libs kommen in Phase 3.*
- [x] Projektstruktur: `bin/console`, `src/{App,Command,Database,Provider,Report,
      Chart,Mail,Onboarding}`, `public/index.php`, `storage/{raw,reports}`,
      `config/{clients,market}/`, `.env.example`, `.gitignore`
- [x] **Marktdaten-Referenz** `config/market/switzerland.yaml` (StatCounter Juni 2026)
- [x] DB-Schema **als Zeitreihen** (`measured_at`): `clients`, `website_profiles`,
      `competitors`, `keywords`, `geo_prompts`, `measurements`, `ai_mentions`,
      `onsite_audits`, `backlinks`, `visibility_history`, `reports` — via `migrate`
      angewendet (11 Tabellen).
- [x] Kunden-Konfiguration als YAML (`config/clients/_example.yaml` als Vorlage) +
      Messwerte in DB.
- [x] CLI-Gerüst: alle Kommandos funktionsfähig — `migrate`, `onboard`, `approve`,
      `backfill`, `collect`, `report`, `send`.
- [x] DataForSEO-Account angelegt, Key in `.env` eingetragen (verifiziert).
- [x] git-Repo initialisiert (34 Commits auf `main`).

### Phase 1.5 — Onboarding einer Domain (Keywords & GEO-Prompts)  ✅ (End-to-end)
Eigener Schritt **vor** der ersten Datenerhebung. Vollständig durchlaufen auf openstream.ch
(vom Website-Verständnis über Vorschläge bis Freigabe in der DB).
- [x] **Schritt 0 — Website verstehen:** `WebsiteAnalyzer` holt Seiten via DataForSEO
      OnPage (`ContentFetcher`) → `ClaudeClient.structuredJson()` leitet `website_profile`
      ab (Angebot, Absicht, Zielgruppe, Region, Positionierung, Marke). Auf openstream.ch
      korrekt erkannt (Analyse-Plattform, B2B-DACH, Zürich, „Orientierung statt Hype").
- [x] `bin/console onboard --client=<slug>`: sammelt GSC-Queries (80 für openstream) +
      Wettbewerber aus Config. (Bing-Grounding/DataForSEO-Keyword-Ideen: später ergänzbar.)
- [x] LLM-Schritt (`PromptGenerator`): **Website-Profil + GSC-Queries** → ~18 Keywords +
      8 GEO-Prompts (5 Kategorie / 3 Marke), Deutsch, CH-lokalisiert, je mit Begründung +
      Quell-Signal. GSC-Signale heben die Qualität sichtbar (echte Nachfrage statt geraten).
- [x] **Onboarding-Report `.md`** (Deutsch): Website-Profil zur Bestätigung +
      Keyword-/Prompt-Tabellen → `storage/reports/<slug>/onboarding.md`. Kosten ~$0.0003/Lauf.
- [x] **Freigabe → DB:** `onboard --save` schreibt Profil/Keywords/GEO-Prompts als
      `pending` in die DB (`ClientRepository`), `approve --client=<slug>` setzt sie auf
      `approved` (+ Datum). `collect` prüft die Freigabe und läuft erst danach. Für
      openstream durchlaufen: 104 Keywords + 20 GEO-Prompts + Profil approved.
- [x] 🟡 Bing-Grounding-Queries als GEO-Prompt-Saatgut: umgesetzt (`onboard --bing-ai=<csv>`
      → `BingAiImporter`). **Offen:** DataForSEO-Keyword-Ideen als weiteres Signal.
- [x] **Social-Profile in der Config (neu):** `social:` Block (youtube/tiktok/instagram)
      + `newsletter:` (provider + env_suffix) je Kunde in der YAML (`_example.yaml` +
      openstream). Optional; leer = Kanal wird im Report ausgeblendet. *(Interaktive
      Abfrage im `onboard`-Flow später; Struktur steht.)*
- **Setup-Notiz:** GSC-Key-Verzeichnis wird via `.ddev/docker-compose.gsc-keys.yaml`
      read-only in den Container gemountet (`/mnt/gcloud-keys`); `.env` zeigt dorthin.
- [ ] Re-Onboarding/Review quartalsweise möglich (Prompts sind lebende Config).
- [x] **Historie-Backfill (umgesetzt):** `backfill --client=<slug>` lädt via
      `HistoricalProvider` (`dataforseo_labs/google/historical_rank_overview/live`)
      die aggregierte monatliche Google-Sichtbarkeit rückwirkend → `visibility_history`
      (~$0.13/Domain, einmalig). Der erste Report zeigt so sofort eine Verlaufsgrafik.
      - **Offen (optional):** echte monatliche Keyword-Positionen via
        `historical_serps/live` → `measurements` (`source=dataforseo_historical`).
      - **Grenze:** Bing hat KEINE historischen Rankings (nur live). GSC liefert
        zusätzlich 16 Monate echte eigene Daten (falls Property alt genug).

### Phase 2 — Datenerhebung (das Herzstück)  🟡 (Rankings + Onsite/Offsite + GEO fertig)
- [x] `SerpProvider`-Interface + `Measurement`-DTO.
- [x] **GSC-Implementierung** (`GscSerpProvider`): echte Position/Klicks/Impressionen/
      CTR pro Query, Zuordnung zu approved Keywords. Auf openstream getestet (10 Messwerte).
- [x] **DataForSEO-SERP-Implementierung** (`DataForSeoSerpProvider`): organische Position
      der Kundendomain je Keyword via task_post→tasks_ready→task_get (Tag-Zuordnung).
      Getestet: openstream Pos 4 für „ki anbieter schweiz", $0.0024/2 Keywords.
- [x] `collect`-Kommando: GSC (gratis) immer, DataForSEO-SERP hinter `--serp` (kostet,
      ~5 Min). Schreibt Zeitreihe → `measurements` (idempotent pro Tag/Quelle).
- [x] **Plattform-Keyword-Kombinationen** (`KeywordCombiner`): WordPress/WooCommerce/
      Shopify/Magento × Rolle × Region deterministisch → 104 approved Keywords openstream.
- [x] **`BingSerpProvider` + `BingWmtClient`**: klassische Bing-Rankings via WMT-API
      (GetQueryStats, pro Query aggregiert, engine=bing/source=bing_wmt). In collect
      eingebunden (`bing.site_url`). Getestet auf openstream: 9 Messwerte inkl.
      Plattform-Kombos. Key hat 14 verifizierte Properties (u.a. openstream/foppa/hepro;
      schwarzenbach.ch fehlt noch).
- [ ] **Bing AI Performance Report (CSV-Import)** — eigener Datenpfad, da KEINE API:
      - Report exportieren: bing.com/webmasters/aiperformance → CSV (Citations,
        Grounding Queries, seit Juni 2026 Intents/Topics/Citation-Share).
      - **90-Tage-Fenster** → regelmässig (monatlich) exportieren, damit Historie
        erhalten bleibt. CSV nach `storage/raw/<kunde>/bing_ai/<YYYY-MM>.csv` ablegen.
      - `BingAiImporter`: CSV parsen → `ai_mentions` (engine=`bing_ai`, source=`bing_ui`,
        mentioned/cited/Citations). Als **Stichprobe** im Report kennzeichnen.
      - **Grounding Queries zusätzlich als GEO-Prompt-Saatgut** ans Onboarding geben.
      - Kommando: `bin/console import-bing-ai --client=<slug> --file=<csv>`.
      - Sobald Microsoft die API liefert (angekündigt „im Laufe 2026"): auf API umstellen,
        Importer als Fallback behalten.
- [x] 🟡 `OnsiteProvider` (**rein API-basiert, umgesetzt**) via DataForSEO OnPage
      (`on_page/instant_pages`): technische Checks je Seite (Title/Description-Länge,
      H1/H2, interne/externe Links, 17 Problem-Checks wie Title zu lang, fehlende
      Alt-Texte, Broken/4xx/5xx, Duplicate Meta …) → `onsite_audits`. In `collect --onsite`
      eingebunden, im Report als Abschnitt 2 aufbereitet. **Kein eigener Crawler.**
      **Seitenauswahl (umgesetzt):** `key_pages` aus der Config + Top-Seiten aus GSC
      (nach Klicks), dedupliziert auf max. 25 Seiten (`onsiteUrls()`).
      **Offen (optional):** PageSpeed/CrUX (Core Web Vitals) + Mozilla Observatory
      (Security-Header), beide gratis — als zusätzliche Signale.
- [x] `OffsiteProvider` (**umgesetzt**) via DataForSEO `backlinks/summary/live`:
      Domain Rank (0–1000), Backlinks gesamt, referring domains, broken/neu/verloren
      → `backlinks`. In `collect --offsite` eingebunden, im Report als Abschnitt 3.
      **Offen (optional):** Anchor-Texte + Wettbewerbs-Backlink-Vergleich.
- [x] **`GeoProvider`-Interface + `MentionAnalyzer` + `DataForSeoGeoProvider`:**
      **Wichtige Vereinfachung (15.07.2026):** ChatGPT, Gemini **und Claude** laufen
      alle über **DataForSEO LLM-Responses** (deutsche Prompts, web_search) — eine
      Auth, ein Antwortformat, serverseitige Web-Suche. Der ursprüngliche Plan
      (ChatGPT via OpenAI, Claude via Anthropic) ist überholt: DataForSEO kann seit
      Kontofreischaltung alle drei auf Deutsch (kein US/EN-Limit, weil wir die Prompts
      selbst stellen). `MentionAnalyzer` (rein, getestet) prüft je Antwort: erwähnt/
      zitiert/Position/Wettbewerber → `ai_mentions`. In `collect --geo` eingebunden.
      Getestet openstream: Marke bei Marken-Prompts sichtbar (Pos 1), bei Kategorie-
      Prompts (Wettbewerb) noch nicht — je Kanal unterschiedlich.
      **Kanal-Marktanteile CH:** ChatGPT 72 %, Gemini 8 %, **Claude 7 % (vor Perplexity!)**.
- [x] **Perplexity** via Sonar-API (`PerplexityGeoProvider`, citation-native, deutsch):
      Antwort + Citations → `MentionAnalyzer` → `ai_mentions`. In `collect --geo`
      eingebunden (config `geo.channels.perplexity`).
- [x] **Google AI Overview** (`AiOverviewProvider`): prüft je Keyword mit Suchvolumen,
      ob die Domain in der AI-Zusammenfassung der Google-SERP zitiert wird
      → `ai_mentions` engine=`ai_overview`. In `collect --geo` eingebunden.
- [ ] **Copilot** = Bing-AI aus CSV (`BingAiImporter` liest Grounding Queries/Citations,
      → `ai_mentions` engine=bing_ai). Onboarding-Seeding fertig; **Mess-Import-Kommando
      (`import-bing-ai`) offen.**
- [x] `bin/console collect --client=<slug>` (läuft **wöchentlich**) schreibt
      normalisiert → DB **mit `measured_at`**, idempotent pro Tag/Quelle. Flags:
      `--serp`/`--geo`/`--onsite`/`--offsite`, `--date` zum Nachtragen.
      **Offen (optional):** Roh-Antworten zusätzlich nach `storage/raw` cachen.
- [ ] Fehler-/Kostenlogging pro Lauf (aktuell: Kosten werden je Lauf ausgegeben,
      aber nicht persistiert; kein zentrales Log).

### Phase 2.5 — Social Media + Newsletter (Owned Media erweitern)
Sichtbarkeit = ganzes Unternehmen, nicht nur die Website. Neue Kanäle, jeweils als
eigener `Provider` hinter einem Interface (tauschbar), Zeitreihe via `collect` → DB.
Details + Anbieter-Bewertung s. „Social-Media-Sichtbarkeit" und „Newsletter".
**Grundsatz (entschieden):** nur **eigene Kanäle**, Fokus auf **öffentliche Views**.
YouTube offiziell (API-Key); TikTok/IG nur eigene Accounts via Apify. Kein
Wettbewerber-Tracking. Details s. „Social-Media-Sichtbarkeit".
- [x] **`SocialProvider`-Interface** + `SocialMetric`-DTO + DB-Tabelle `social_metrics`
      (Zeitreihe: client, platform, account, followers, views_total, posts_total,
      measured_at). Config je Kunde (`social:` Block, optional — Kunden ohne Kanal:
      Abschnitt ausgeblendet). In `collect --social` eingebunden.
- [x] **YouTube** (`YouTubeProvider`) via **Data API v3** (`channels.list?part=statistics`,
      nur API-Key, kein OAuth) — `viewCount` (Lifetime inkl. Shorts), Subscriber,
      Video-Anzahl. Löst Kanal-ID/URL/@handle auf (`resolve()`, getestet). **Live-Test
      offen bis YOUTUBE_API_KEY gesetzt.** *(Optional später: Analytics API mit OAuth für
      echte Monats-Views + Shorts-Split.)*
- [x] 🟡 **TikTok + Instagram** (`TikTokProvider`/`InstagramProvider` via `ApifyClient`):
      gebaut, aber **durch OAuth ersetzt** (Apify liefert Monats-Views nicht zuverlässig;
      IG öffentlich gar keine). Code bleibt vorerst als Fallback/Referenz, wird durch die
      OAuth-Provider (s. Phase 2.6) abgelöst. Kein Apify-Token nötig.
- [x] **Monats-Views je Kanal** aus wöchentlichen `views_total`-Ständen (Differenz,
      clamp ≥0) — `ClientRepository::socialMonthly()`. Report-getestet. *(Bei OAuth-
      Providern später durch echte Monatswerte ersetzbar.)*
- [ ] LinkedIn: **vorerst weggelassen** (kein öffentlicher View-Zugang ohne Admin-OAuth).
- [ ] **`NewsletterProvider`-Interface** + `newsletter_stats` (Zeitreihe): je Ausgabe
      Öffnungs-/Klickrate, Bounces, Abmeldungen, Listen-Wachstum. Tool je Kunde in der
      Config gewählt, API-Key je Kunde aus `.env` (Suffix = Slug). Nur aggregierte Raten,
      keine Empfänger-Adressen.
  - [ ] **`MailchimpProvider`** via offizielle Marketing API (`/reports`, `/lists`).
  - [ ] **`SendyProvider`** via Sendy-API bzw. read-only aus Sendys MySQL-DB (beim Bau
        prüfen, was die installierte Version hergibt). openstream nutzt Sendy.
- [ ] **Openstream Visibility Score (OVS):** monatliche „aktive Sichtkontakte"
      plattformübergreifend berechnen (Formel s. „Openstream Visibility Score") → als
      Zeitreihe speichern. Formel zentral im Code + dokumentiert.
- [ ] **Grundsatz:** nur aggregierte Account-/Kampagnen-Stats des **eigenen** Kunden-Kanals,
      keine Personen-/Follower-/Empfänger-Listen, kein Wettbewerber-Tracking (DSG/DSGVO).

### Phase 2.6 — Social via OAuth (exakte Monats-Views, minimale Web-Komponente)
Kurskorrektur: Apify liefert Monats-Views nicht zuverlässig → **offizielle APIs mit OAuth**,
der Kunde verbindet seine Kanäle selbst. Bewusste, minimale Web-Erweiterung (kein
Kundenportal). Details s. „Social via OAuth — Architektur". **Erst Infrastruktur, dann Provider.**
- [ ] **OAuth-Apps registrieren:** Google Cloud (YouTube Analytics, Scope
      `yt-analytics.readonly`), Meta (Instagram Graph + App-Review), TikTok Developer.
      Callback-URLs auf `visibility.openstream.ch` (+ DDEV-URL fürs lokale Testen).
- [ ] **DB `social_connections`** (client_id, platform, account_ref, refresh_token
      **verschlüsselt**, scopes, connected_at, status) + App-Verschlüsselungs-Key in `.env`.
- [ ] **`OAuthTokenStore`**: Refresh-Token ver-/entschlüsseln, gegen Access-Token tauschen
      (gecacht), Providern bereitstellen.
- [ ] **Verbindungsseite (Web):** `/connect/<platform>?client=<slug>` → Consent → Callback
      speichert verschlüsselten Refresh-Token. Schlank, kein Login/Session darüber hinaus.
- [ ] **`YouTubeAnalyticsProvider`** (`youtubeAnalytics.reports.query`): echte Monats-`views`
      + Shorts-vs-Video-Split. Baut auf dem bestehenden `YouTubeProvider` auf.
- [ ] **`InstagramInsightsProvider`** (Graph API Insights): echte Reichweite/Impressions/Views.
- [ ] **`TikTokProvider` (OAuth)** (Display/Business API): echte Video-Views des eigenen Kontos.
- [ ] **Lokal testen (DDEV):** OAuth-Flow gegen Nicks openstream-Konten, bevor
      `visibility.openstream.ch` produktiv steht (s. Phase 6).
- [ ] Echte Monatswerte → `social_metrics` (bzw. eigene Monatstabelle), ersetzen die
      Apify-Deltas. Report-Abschnitt „Social Media" nutzt dann die exakten Zahlen.

### Phase 3 — Report-Generierung (inkl. Diagramme)
- [ ] Report-Datenmodell: aktueller Monat vs. Vormonat (Deltas!) **plus Zeitreihe
      aus den wöchentlichen Datenpunkten** für die Verlaufs-Diagramme.
- [x] `src/Chart`: Chart-Generator (`SvgChart` + `ReportCharts`), der aus den
      Zeitreihen/Momentaufnahmen **eigenständiges SVG** erzeugt — kein externer
      Dienst (kein QuickChart), keine Netzwerk-Calls, voll reproduzierbar. Vier
      Diagrammtypen umgesetzt: **Linie** (ETV-Verlauf, Zeitreihe), **Balken**
      (Keyword-Positionsverteilung + GEO-Erwähnungsrate, Momentaufnahme), **Donut**
      (CH-Marktanteile Suchmaschinen + KI-Assistenten). Deutsche Zahlenformatierung.
      Ausgabe → `storage/reports/<kunde>/charts/*.svg`, relativ im `.md` eingebettet.
      Text-Sparkline bleibt als Fallback, wenn ohne Charts gebaut wird. Web-Dashboard-
      JSON (Chart.js) folgt bei der UI.
- [x] **„Worum es geht" + Executive Summary um Social erweitert:** Einleitung nennt Social
      Media, wenn Kanäle vorhanden (datenabhängig); Summary-Fakten + System-Prompt beziehen
      die monatlichen Social-Views ein. Report-getestet (Summary griff Social eigenständig auf).
      *(Newsletter analog, sobald `NewsletterProvider` gebaut.)*
- [x] **Report-Abschnitt „5. Social Media"** (`socialSection`): je Plattform Views (Monat) +
      Follower, plus **Views-Gesamt-Total** über alle Plattformen. Blendet sich ohne Daten aus.
- [ ] **Ausführlicher `.md`-Report (Deutsch)** mit klaren Abschnitten:
      0. **Openstream Visibility Score (OVS)** — die eine Dach-Zahl (aktive Sichtkontakte,
         Monatswert + Trend) mit **offener Zusammensetzung** (Beitrag je Kanal + Formel).
      1. **Markt-Kontext CH** (Donut Suchmaschinen + AI-Assistenten).
      2. **Suchmaschinen-Rankings** (Google + Bing): Änderungen, Sichtbarkeitsindex.
      3. **Onsite/technisch:** Core Web Vitals, technische Fehler, hreflang, Broken Links.
      4. **Offsite/Backlinks:** referring domains, neue/verlorene Links, Spam-Score.
      5. **GEO:** Mentions/Citations je LLM (ChatGPT/Perplexity/Gemini/AI-Overview) + Bing-AI.
      6. **Social Media (neu):** je Plattform (YouTube/TikTok/Instagram) eine Zeile mit
         **monatlichen Views** + Follower + Trend; darunter das **Views-Gesamt-Total** über
         alle Plattformen. Kunden ohne Kanal: Abschnitt ausblenden.
      7. **Newsletter (neu):** Öffnungs-/Klickrate, Listen-Wachstum je Ausgabe + Trend.
      8. **Handlungsempfehlungen** über alle Bereiche.
      Jeder Datenabschnitt enthält **Momentaufnahme + Verlaufs-Diagramm** (Bilder aus
      `charts/`). Twig-/PHP-Template → Markdown. Datenlücken transparent kennzeichnen.
- [ ] **Executive Summary (Deutsch, kurz):** das Wichtigste + Trend + eine Empfehlung.
      **Um Social/Newsletter + OVS erweitern** (nicht nur SEO/GEO): den OVS-Monatswert +
      Trend und die stärksten Social-/Newsletter-Signale einbeziehen. Für Mail-Body.
- [ ] `bin/console report --client=<slug> --month=YYYY-MM` → schreibt beide.

### Phase 4 — Versand
- [ ] Symfony Mailer / SMTP-Config in `.env` (welcher Mailserver? → offene Frage)
- [ ] `bin/console send --client=<slug> --month=YYYY-MM [--dry-run]`:
      Executive Summary als Body, `.md` (bzw. als PDF/HTML gerendert?) als Anhang
- [ ] Freigabe-Flow: manuell (Standard) vs. automatisch — Report erst als Draft,
      Nick gibt frei. (Gmail-MCP für Draft-Erstellung ist verfügbar.)

### Phase 5 — Automatisierung & Web-UI
- [ ] **Wöchentlicher Cron:** `collect` je Kunde (füllt die Zeitreihe).
- [ ] **Monatlicher Cron:** `report` → Charts erzeugen → Gmail-Draft →
      Benachrichtigung an Nick zur Freigabe.
- [ ] Minimal-Web-UI (nur lokal/Nick): Kundenliste, letzte Reports ansehen,
      **interaktive Chart.js-Diagramme** (Momentaufnahme + Verlauf), Report manuell
      auslösen/versenden. Liest aus DB, keine Live-API-Calls.
- [ ] Historie über Monate/Wochen im Dashboard durchklickbar.

### Phase 6 — Produktion (optional/später)
- [ ] Deploy auf `visibility.openstream.ch`, Server-Cron, Secrets-Handling
- [ ] Backups von `storage/` + DB

---

## Getroffene Entscheidungen (verbindlich)

1. **GEO-Messmethode: Beides.** Pro Kunde ein Mix aus
   - **3–5 Kategorie-/Kaufabsicht-Prompts** (z.B. „Bester Anbieter für X in der
     Schweiz?") → misst Sichtbarkeit *gegenüber Wettbewerbern*: Wird der Kunde
     erwähnt, an welcher Stelle, welche Quelle wird zitiert, welche Konkurrenten
     tauchen auf?
   - **2–3 Marken-Prompts** (z.B. „Was ist Firma X und was bietet sie an?") →
     misst *Marken-Wissen & Faktentreue*: Kennt der LLM die Marke, sind die
     Fakten korrekt/aktuell, welche Quellen zitiert er?

   → Beide Perspektiven kommen in den Report. Datenmodell `geo_prompts` braucht
   ein Feld `type` (`category` | `brand`). Prompts pro Kunde in der Config.

2. **Executive Summary: per LLM.** Claude formuliert aus den Rohdaten eine
   flüssige, deutsche Summary *mit Einordnung* („Sichtbarkeit gestiegen, weil …";
   „grösstes Potenzial bei Perplexity"). Der ausführliche `.md`-Report wird
   template-basiert erzeugt (Zahlen/Tabellen), die Summary obendrauf per LLM.
   → Braucht Claude-API-Zugriff im Versand-/Report-Schritt (siehe `claude-api`).

3. **Versand: Gmail-Draft zur Freigabe.** `send` erstellt einen fertigen
   Mail-**Entwurf** in Nicks Gmail (Executive Summary als Body, `.md`-Report als
   Anhang) via **Gmail-MCP** — Nick prüft und sendet manuell. Kein automatischer
   Direktversand. Monatlicher Cron läuft bis „Draft erstellt" und benachrichtigt
   Nick zur Freigabe.

## Noch offen (später, nicht blockierend)

4. **Report-Anhang-Format:** vorerst reines `.md`. Später evtl. zusätzlich
   HTML/PDF fürs Kunden-Auge — nach erstem echten Report entscheiden.
5. **Konkrete GEO-Prompts & Keyword-Listen pro Kunde** — in Phase 0/1 pro Kunde
   erfassen.
