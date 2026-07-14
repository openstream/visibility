# ROADMAP — Visibility Dashboard

SEO + GEO Sichtbarkeits-Dashboard für openstream.ch. Ziel: monatlicher,
ausführlicher Visibility-Report (`.md`, Deutsch) pro Kunde + Executive Summary
als Mail-Body. Nur für Nick, kein Kundenzugang. Lokal mit DDEV, später evtl.
`visibility.openstream.ch`.

Siehe `CLAUDE.md` für Rahmenbedingungen und Stack.

---

## Recherche: Datenquellen & Dienste (Juli 2026)

### SEO / Suchmaschinen-Sichtbarkeit

| Quelle | Modell | Kosten | Wofür | Bewertung |
|---|---|---|---|---|
| **Google Search Console API** | offiziell, OAuth/Service-Account | **kostenlos** | Echte Klicks, Impressions, CTR, Position pro Query & Seite — nur für *eigene* verifizierte Properties | **Erste Wahl**, wo GSC-Zugriff besteht. Skill `gsc-api-access` existiert bereits. |
| **GSC — Search Generative AI Report** | offiziell | **kostenlos** | Impressions/Pages/Countries/Devices in **AI Overviews & AI Mode** (Daten ab 18.05.2026, **keine Queries/Klicks/CTR**) | ⚠️ **Angekündigt 3. Juni 2026, Rollout nur an UK-Teilmenge, keine API.** Bei unseren CH-Domains (z.B. hepro.ch) **noch nicht sichtbar.** → Als „kommt später" einplanen, nicht darauf warten; sobald verfügbar für AIO-Seiten-Signale nutzen. |
| **Bing Webmaster Tools** | offiziell | **kostenlos** | Bing-Rankings/Impressions/Klicks **und** der neue **AI Performance (Beta)** Report: Citations in Microsoft Copilot & Bing-AI-Summaries, „Grounding Queries", Citation-Share, Seiten-Level. Nur für *eigene* verifizierte Properties. | **Einbauen.** Klassische WMT-Daten haben eine API. **Achtung AI-Report: aktuell NUR Dashboard-UI, noch KEINE API** — Microsoft hat API-Zugang „im Laufe 2026" versprochen (Stand Juli 2026 noch nicht live). Bis dahin AI-Zahlen manuell/halbautomatisch (Scraping der eingeloggten UI) übernehmen. Daten sind ausserdem eine **Stichprobe**, keine Vollerhebung. |
| **DataForSEO — SERP API** | pay-per-task | ~$0.0006/Query (Standard-Queue, ~5 Min) bis $0.002 (Live, ~6 Sek) | Google-Rankings für beliebige Keywords/Domains *ohne* GSC-Zugriff; Wettbewerber-Rankings. **Unterstützt Schweiz + Deutsch** (`location="Switzerland"`, `gl=ch`, `hl=de`; Labs deckt DE/FR/IT für CH ab). PHP-Beispiele in Doku. | **Zweite Wahl / Ergänzung** für SEO. Günstigster Anbieter bei Volumen, transparentes Pay-per-Query. |
| SerpApi | Abo | ab $25/Mt (1'000 Suchen), $75 (5'000) | SERP-Scraping, >100 Engines, `gl=ch`/`hl=de`/`location` | Backup zu DataForSEO. Teurer bei Volumen, aber sauberes JSON & breite Abdeckung. |
| ValueSERP / ScaleSERP, Scrapingdog, Bright Data, Oxylabs SERP | pay-per-1K | ~$0.30–1.60 / 1'000 | Reine Google-SERP-Scraper mit CH-Targeting (UULE/`gl=ch`) | Günstige SERP-only-Alternativen. Kein Keyword-/Backlink-/GEO-Mehrwert. Nur falls DataForSEO nicht reicht. |

**Bewusst NICHT genutzt (Suite-Produkte):** Sistrix, XOVI (Nicks Alt-Tool),
SE Ranking, Semrush, Ahrefs. → Entscheidung: **kein Suite-Dashboard**, wir bauen
selbst. Ihre *rohen Daten-APIs* wären erlaubt, sind aber teurer/abo-gebunden als
DataForSEO und bringen für unseren Fall keinen Mehrwert. Einzige Ausnahme, die man
später abwägen könnte: Sistrix-**OVI** als Kennzahl, falls ein Kunde ihn explizit
im Report erwartet — dann nur diese eine Zahl, nicht die Suite.

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

| Posten | Menge/Woche | ×4 Wo. | Stückpreis | **$/Monat** |
|---|---|---|---|---|
| SERP-Rankings (DataForSEO SERP, Standard-Queue) | 20 | 80 Queries | $0.60 / 1'000 | **$0.05** |
| Onsite-Crawl (DataForSEO OnPage) | 50 | 200 Seiten | $0.000125 / Seite | **$0.03** |
| Backlinks (DataForSEO Backlinks) | 1 Req à 2'000 | 4 Req + 8'000 Zeilen | $0.02/Req + $0.00003/Zeile | **$0.32** |
| GEO Perplexity+Gemini+AI-Overview (DataForSEO AI Opt.) | 8×3 = 24 | 96 Abfragen | ~$0.002 / Abfrage | **$0.19** |
| GEO ChatGPT (OpenAI web-search: $25/1'000 Calls + Token) | 8 | 32 Calls | ~$0.028 / Call (inkl. Token) | **$0.90** |
| Gratis: GSC, Bing WMT, PageSpeed, CrUX, Mozilla Observatory | — | — | $0 | **$0.00** |
| **Summe API-Kosten pro Domain / Monat** | | | | **≈ $1.50** |

**→ Pro Domain rund $1.50/Monat.** Der mit Abstand grösste Posten ist die
ChatGPT-Sichtbarkeit über OpenAI ($0.90) — wegen der $25/1'000-Tool-Gebühr für
Web-Suche. Alles andere zusammen kostet ~$0.60.

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

### Firecrawl — geprüft, Rolle geklärt

**[Firecrawl](https://www.firecrawl.dev/)** ist eine Scrape/Crawl/Map/**Search**/Extract-API, die
Webseiten in LLM-ready Markdown/JSON umwandelt. Geprüft für unseren Use-Case:

- **Als SEO-Rank-Tracker: NEIN.** Der Search-Endpoint (`location="Switzerland"`
  wird unterstützt, 2 Credits / 10 Ergebnisse) liefert **Seiteninhalte, keine
  SERP-Positionen** — er ist nicht für Rank-Tracking gebaut. Für Google-Rankings
  bleiben DataForSEO/SerpApi/GSC die richtigen Werkzeuge.
- **Als GEO-Messquelle: NEIN.** Kann ChatGPT/Perplexity-Antworten nicht abfragen.
- **Als Content-Werkzeug: JA, sinnvoll optional.** Gut geeignet, um
  **Kundenseiten & Wettbewerber-Seiten sauber zu crawlen** (→ Grundlage für
  Content-/Onpage-Hinweise, oder um zitierte Quellen aus Perplexity/Gemini
  faktisch zu prüfen). Nur einbauen, wenn wir Content-Analyse ins Dashboard
  aufnehmen — nicht für die Kern-Sichtbarkeitsmessung nötig.
- **Preis 2026:** Abo-only, Free-Tier 1'000 Credits/Mt, danach Hobby/Standard/Growth.

→ **Fazit: Firecrawl ist kein Ersatz für DataForSEO/SerpApi und keine GEO-Quelle.**
Optional als Content-Crawler in einer späteren Phase, kein Teil des Kern-Stacks.

### Empfohlener Ausgangs-Stack (an CH-Realität angepasst)

**Leitprinzip: eigenes Dashboard, keine Suite, und Datenerhebung über APIs — kein
selbstgebauter Crawler.** Wir verdrahten die besten Einzel-APIs selbst. DataForSEO
ist die zentrale bezahlte Quelle über vier Rollen hinweg; alles andere ist gratis.

- **Google-SEO (Rankings):** GSC API (gratis) wo Property verifiziert, sonst
  DataForSEO SERP (`gl=ch, hl=de`).
- **Bing-SEO + Bing-AI:** Bing Webmaster Tools — klassische Daten via API,
  **AI-Performance-Report vorerst manuell/UI-Scrape** (noch keine API).
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
- **Firecrawl:** nicht im Kern-Stack; optional später als **Content-API** (nicht
  „Crawler" im Sinne von Eigenbau — eine fertige Scrape-API) für Onboarding-Kontext.

> **Realistischer Minimal-Stack für den Start (die 4 CH-Domains):**
> GSC (gratis) + Bing WMT (gratis) + **DataForSEO als Zentrale** (SERP + OnPage +
> Backlinks + Perplexity/Gemini/AIO, alles pay-per-task) +
> PageSpeed/CrUX/Observatory (gratis) + OpenAI-API (ChatGPT-Grounding) + Perplexity
> Sonar (optional). Alles API-basiert. Erwartete Kosten: ~$6/Monat für 4 Domains.
> **Keine Suite** — bewusste Entscheidung.

> Grobe Kostenschätzung (Solo, ~5–10 Kunden, monatlich): zweistelliger
> Franken-/Dollar-Betrag pro Monat, wenn wir Standard-Queue nutzen und
> Roh-Antworten cachen. Genaue Zahlen nach Kunden-/Keyword-Zählung in Phase 1.

**Quellen:** [DataForSEO AI Optimization API](https://dataforseo.com/apis/ai-optimization-api) · [DataForSEO Docs v3](https://docs.dataforseo.com/v3/ai_optimization-overview/) · [DataForSEO LLM Mentions Locations & Languages (ChatGPT = US/EN only)](https://docs.dataforseo.com/v3/ai_optimization-llm_mentions-locations_and_languages/) · [DataForSEO: Swiss Italian in Labs API](https://dataforseo.com/update/swiss-italian-available-in-dataforseo-labs-api) · [Perplexity API Pricing](https://docs.perplexity.ai/docs/getting-started/pricing) · [Bing WMT AI Performance (Public Preview)](https://blogs.bing.com/webmaster/February-2026/Introducing-AI-Performance-in-Bing-Webmaster-Tools-Public-Preview) · [Bing AI Insights: Intents/Topics/Citation Share/Compare (Juni 2026)](https://blogs.bing.com/search/June-2026/New-AI-Visibility-Insights-in-Bing-Webmaster-Tools-Intents-Topics-Citation-Share-Compare) · [Bing WMT AI-Report: API? (Microsoft Q&A)](https://learn.microsoft.com/en-ca/answers/questions/5780844/bing-webmaster-tools-ai-performance-report-is-ther) · [SE Ranking AI Visibility](https://seranking.com/ai-visibility-tracker.html) · [SERP API Vergleich 2026](https://apiserpent.com/blog/serp-api-rank-tracking-cost) · [Firecrawl](https://www.firecrawl.dev/) · [Firecrawl Search-Endpoint](https://www.firecrawl.dev/blog/mastering-firecrawl-search-endpoint) · [Firecrawl Pricing](https://www.firecrawl.dev/pricing) · [Sistrix API](https://www.sistrix.com/api/) · [XOVI Rank Tracker](https://www.xovi.com/xovi-tool/rank-tracker/) · [SE Ranking API](https://seranking.com/api.html) · [Perplexity Sonar Language Filter](https://docs.perplexity.ai/guides/language-filter-guide) · [Peec AI (Berlin)](https://docs.peec.ai/api/introduction) · [Gemini API Grounding](https://ai.google.dev/gemini-api/docs/google-search) · [DataForSEO OnPage API](https://dataforseo.com/apis/on-page-api) · [DataForSEO Backlinks API](https://dataforseo.com/apis/backlinks-api) · [PageSpeed Insights API](https://developers.google.com/speed/docs/insights/v5/get-started) · [CrUX API](https://developer.chrome.com/docs/crux/guides/crux-api) · [Mozilla Observatory API](https://developer.mozilla.org/en-US/docs/Web/Security/Practical_implementation_guides)

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
  uns, Kontakt) via **DataForSEO OnPage** (liefert Inhalt/Struktur ohnehin) bzw.
  **Firecrawl** (sauberes LLM-ready Markdown). Sitemap/Navigation nutzen, um die
  relevanten Seiten zu wählen (nicht die ganze Site).
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
| **Bing Webmaster Tools** | Suchanfragen **+ AI Performance „Grounding Queries"** (welche Fragen KI-Antworten mit Zitat der Seite auslösten) | **Prompt-Saatgut aus echten KI-Anfragen** | Klassik via API; **AI-Report nur UI** → manuell/Scrape übernehmen |
| **Google Search Console — Search Generative AI Report** | Impressions/Pages/Countries/Devices in AI Overviews & AI Mode (**keine Queries!**) | zeigt, *welche Seiten* in AIO auftauchen → daraus Themen ableiten | ⚠️ **angekündigt 3. Juni 2026, Rollout nur an UK-Teilmenge, Daten ab 18.05.2026, keine API.** Bei hepro.ch & Co. **noch nicht sichtbar** → als „kommt später" einplanen, nicht darauf warten. |
| **DataForSEO** (Keyword-Ideen, „people also ask", verwandte Suchen, AI-Keyword-Data) | Keyword-Vorschläge & Volumen für CH, Frageformulierungen | Keyword- **und** Prompt-Ideen für Domains ohne/mit wenig GSC-Daten | ✅ via API |
| **Website-Inhalt → Website-Profil** (via DataForSEO OnPage / Firecrawl API + LLM) | Was die Seite IST, Absicht/Ziel, Angebot, Zielgruppe, Positionierung, Marke | **Grundlage (Schritt 0)** für Kategorie- & Marken-Prompts — Innensicht | ✅ API + LLM |
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
- [ ] Bing Webmaster Tools: Ziel-Domains verifizieren, API-Key holen,
      AI-Performance-Report für die 4 Domains sichten (was liefert er real?)
- [ ] Ziel-Kunden + Keywords + GEO-Prompts pro Kunde grob festlegen
      (Start-Domains: foppa.ch, openstream.ch, schwarzenbach.ch, hepro.ch)

### Phase 1 — Gerüst & lokale Umgebung  ✅ (Grundgerüst steht)
- [x] DDEV-Projekt (PHP 8.3, MariaDB 10.11, docroot `public`), `ddev start` läuft
      → https://visibility-openstream.ddev.site (HTTP 200)
- [x] Composer-Setup (schlank, plain PHP): Guzzle, Twig, phpdotenv, Symfony
      Console/Yaml/Mailer, league/commonmark. *Chart-Libs kommen in Phase 3.*
- [x] Projektstruktur: `bin/console`, `src/{App,Command,Database,Provider,Report,
      Chart,Mail,Onboarding}`, `public/index.php`, `storage/{raw,reports}`,
      `config/{clients,market}/`, `.env.example`, `.gitignore`
- [x] **Marktdaten-Referenz** `config/market/switzerland.yaml` (StatCounter Juni 2026)
- [x] DB-Schema **als Zeitreihen** (`measured_at`): `clients`, `keywords`,
      `geo_prompts`, `competitors`, `measurements`, `ai_mentions`, `onsite_audits`,
      `backlinks`, `reports` — via `migrate` angewendet (9 Tabellen).
- [x] Kunden-Konfiguration als YAML (`config/clients/_example.yaml` als Vorlage) +
      Messwerte in DB.
- [x] CLI-Gerüst: `migrate` (funktionsfähig), `onboard`/`collect`/`report`/`send`
      (Signatur steht, Logik folgt in den jeweiligen Phasen).
- [ ] **Offen (du):** DataForSEO-Account anlegen, Key in `.env` eintragen.
- [ ] git-Repo initialisieren + erster Commit (auf Wunsch).

### Phase 1.5 — Onboarding einer Domain (Keywords & GEO-Prompts)  ✅ (Grundfunktion)
Eigener Schritt **vor** der ersten Datenerhebung. End-to-end getestet auf openstream.ch.
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
- [ ] **Offen:** freigegebene Listen → DB (`keywords`/`geo_prompts`/`website_profiles`,
      Status `approved`) schreiben — aktuell nur als Report. Danach darf `collect` laufen.
- [ ] **Offen:** Bing-Grounding-Queries + DataForSEO-Keyword-Ideen als weitere Signale.
- **Setup-Notiz:** GSC-Key-Verzeichnis wird via `.ddev/docker-compose.gsc-keys.yaml`
      read-only in den Container gemountet (`/mnt/gcloud-keys`); `.env` zeigt dorthin.
- [ ] Re-Onboarding/Review quartalsweise möglich (Prompts sind lebende Config).

### Phase 2 — Datenerhebung (das Herzstück)  🟡 (Rankings fertig)
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
      schwarzenbach.ch fehlt noch). **AI-Performance-Report weiterhin ohne API** →
      separat/manuell (später), noch nicht implementiert.
- [ ] `OnsiteProvider`-Interface + Implementierungen (**rein API-basiert**):
      - DataForSEO OnPage (Crawl-Backbone via API, 60+ technische Checks inkl.
        hreflang de/fr/it-CH, Alt-Texte, strukturierte Daten, Broken Links)
      - PageSpeed Insights + CrUX (Core Web Vitals, gratis)
      - Mozilla Observatory (Security-Header, gratis)
      Ergebnisse normalisiert → `onsite_audits`. **Kein eigener Crawler.**
- [ ] `OffsiteProvider`-Interface + DataForSEO-Backlinks-Implementierung
      (referring domains, ranks, spam-score, neu/verloren, Anchor-Texte,
      Wettbewerbsvergleich) → `backlinks`.
- [ ] `GeoProvider`-Interface + Implementierungen je Kanal:
      - DataForSEO AI Optimization → **Perplexity, Gemini, AI-Overview**
      - **OpenAI web-search → ChatGPT (deutsche Prompts, CH)** — NICHT DataForSEO
      - optional Perplexity Sonar direkt (citation-native)
      Ergebnisse (Mention ja/nein, Position, Citations, Wettbewerber) pro
      GEO-Prompt normalisieren, Quelle je Kanal im Datensatz vermerken.
- [ ] `bin/console collect --client=<slug>` (läuft **wöchentlich**) schreibt
      Roh-Antworten → `storage/raw`, normalisiert → DB **mit `measured_at`**.
      Idempotent pro Woche, mit Caching/Rate-Limit-Handling.
- [ ] Fehler-/Kostenlogging pro Lauf

### Phase 3 — Report-Generierung (inkl. Diagramme)
- [ ] Report-Datenmodell: aktueller Monat vs. Vormonat (Deltas!) **plus Zeitreihe
      aus den wöchentlichen Datenpunkten** für die Verlaufs-Diagramme.
- [ ] `src/Chart`: Chart-Generator, der aus den Zeitreihen Diagramme erzeugt —
      statische PNG/SVG für den `.md`-Report (QuickChart/headless Chart.js) und
      dieselben Daten als JSON fürs Web-Dashboard (Chart.js). Diagrammtypen
      s. „Diagramme & Visualisierung". Ausgabe → `storage/reports/<kunde>/charts/`.
- [ ] **Ausführlicher `.md`-Report (Deutsch)** mit klaren Abschnitten:
      0. **Markt-Kontext CH** (Donut Suchmaschinen + AI-Assistenten) — kurzer
         Einordnungs-Block, warum welche Kanäle zählen.
      1. **Suchmaschinen-Rankings** (Google + Bing): Änderungen, Sichtbarkeitsindex.
      2. **Onsite/technisch:** Core Web Vitals, technische Fehler, hreflang, Broken
         Links — mit Delta zum Vormonat und priorisierten Fixes.
      3. **Offsite/Backlinks:** referring domains, neue/verlorene Links, Spam-Score,
         Autoritäts-Trend, Wettbewerbsvergleich.
      4. **GEO:** Bing-AI-Citations + Mentions je LLM (ChatGPT/Perplexity/Gemini/
         AI-Overview), Citations, Wettbewerber.
      5. **Handlungsempfehlungen** über alle Bereiche.
      Jeder Abschnitt (1–4) enthält **Momentaufnahme + Verlaufs-Diagramm** (Bilder
      aus `charts/` eingebettet). Twig-/PHP-Template → Markdown. Datenlücken
      transparent kennzeichnen („Bing-AI nur Stichprobe", „ChatGPT via
      OpenAI-Grounding, kein Live-Panel").
- [ ] **Executive Summary (Deutsch, kurz):** 3–6 Sätze/Bullets, das Wichtigste
      + Trend + eine Empfehlung. Für Mail-Body. (Ggf. per Claude/LLM aus den
      Rohdaten generiert — Entscheidung offen.)
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
