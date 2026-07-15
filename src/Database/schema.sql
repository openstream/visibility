-- Visibility Dashboard — DB-Schema (MariaDB / utf8mb4).
-- Zeitreihen-orientiert: jeder Messwert trägt ein Datum, damit Verlaufs-Diagramme
-- (Momentaufnahme + historische Entwicklung) möglich sind.
-- Erhebung wöchentlich (collect), Auswertung monatlich (report).

CREATE TABLE IF NOT EXISTS clients (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug         VARCHAR(64)  NOT NULL,
    name         VARCHAR(255) NOT NULL,
    domain       VARCHAR(255) NOT NULL,
    country      VARCHAR(64)  NOT NULL DEFAULT 'Switzerland',
    gl           VARCHAR(8)   NOT NULL DEFAULT 'ch',
    languages    VARCHAR(64)  NOT NULL DEFAULT 'de',    -- CSV: de[,fr,it]
    region       VARCHAR(128) NULL,
    recipient    VARCHAR(255) NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clients_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Website-Profil (Innensicht): was die Seite IST und will. LLM-abgeleitet aus
-- dem Website-Inhalt, vom Kunden bestätigt. Grundlage der Keyword-/Prompt-Generierung.
-- Versioniert (lebende Config; bei Relaunch neu ableiten).
CREATE TABLE IF NOT EXISTS website_profiles (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id     INT UNSIGNED NOT NULL,
    summary       TEXT         NULL,           -- Kurzbeschreibung: was die Seite ist/tut
    intent        VARCHAR(255) NULL,           -- Absicht: verkaufen | leads | informieren | brand ...
    offerings     JSON         NULL,           -- Leistungen/Produkte
    audience      TEXT         NULL,           -- Zielgruppe (B2B/B2C, Branche)
    region        VARCHAR(128) NULL,           -- geografischer Fokus (CH/Kanton/Stadt)
    positioning   TEXT         NULL,           -- USP / Positionierung / Tonalität
    brand_names   JSON         NULL,           -- Marken-/Entitätsnamen
    topics        JSON         NULL,           -- Content-Themen / wichtige Seiten
    source_urls   JSON         NULL,           -- welche Seiten analysiert wurden
    raw           JSON         NULL,           -- vollständiges LLM-Profil
    approved      TINYINT(1)   NOT NULL DEFAULT 0,
    approved_at   DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_website_profiles_client (client_id),
    CONSTRAINT fk_website_profiles_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS competitors (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id  INT UNSIGNED NOT NULL,
    domain     VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_competitors_client (client_id),
    CONSTRAINT fk_competitors_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Zu trackende Keywords (nach Onboarding-Freigabe: approved = 1).
CREATE TABLE IF NOT EXISTS keywords (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id   INT UNSIGNED NOT NULL,
    keyword     VARCHAR(255) NOT NULL,
    approved    TINYINT(1)   NOT NULL DEFAULT 0,
    approved_at DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_keywords_client (client_id),
    CONSTRAINT fk_keywords_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- GEO-Prompts (type: category = Wettbewerb, brand = Marken-Wissen).
CREATE TABLE IF NOT EXISTS geo_prompts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id   INT UNSIGNED NOT NULL,
    type        ENUM('category','brand') NOT NULL,
    prompt      TEXT         NOT NULL,
    source      VARCHAR(64)  NULL,           -- woraus generiert (gsc, bing_grounding, dataforseo, manual ...)
    approved    TINYINT(1)   NOT NULL DEFAULT 0,
    approved_at DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_geo_prompts_client (client_id),
    CONSTRAINT fk_geo_prompts_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SEO-Ranking-Messwerte (Google/Bing), wöchentlich.
CREATE TABLE IF NOT EXISTS measurements (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id    INT UNSIGNED NOT NULL,
    keyword_id   INT UNSIGNED NULL,
    engine       ENUM('google','bing') NOT NULL,
    position     DECIMAL(6,2) NULL,
    url          VARCHAR(768) NULL,
    impressions  INT UNSIGNED NULL,
    clicks       INT UNSIGNED NULL,
    ctr          DECIMAL(6,3) NULL,
    source       VARCHAR(64)  NOT NULL,       -- gsc | bing_wmt | dataforseo_serp
    measured_at  DATE         NOT NULL,
    PRIMARY KEY (id),
    KEY idx_measurements_client_date (client_id, measured_at),
    KEY idx_measurements_keyword (keyword_id),
    CONSTRAINT fk_measurements_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- GEO-Sichtbarkeit je Prompt/Engine, wöchentlich.
CREATE TABLE IF NOT EXISTS ai_mentions (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id    INT UNSIGNED NOT NULL,
    prompt_id    INT UNSIGNED NULL,
    engine       ENUM('chatgpt','perplexity','gemini','claude','ai_overview','bing_ai') NOT NULL,
    mentioned    TINYINT(1)   NOT NULL DEFAULT 0,
    position     INT          NULL,           -- Rang in der Antwort, falls ermittelbar
    cited        TINYINT(1)   NOT NULL DEFAULT 0,
    citations    JSON         NULL,           -- zitierte URLs/Quellen
    competitors  JSON         NULL,           -- welche Wettbewerber genannt wurden
    source       VARCHAR(64)  NOT NULL,       -- openai | perplexity | dataforseo | bing_ui
    measured_at  DATE         NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ai_mentions_client_date (client_id, measured_at),
    KEY idx_ai_mentions_prompt (prompt_id),
    CONSTRAINT fk_ai_mentions_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Onsite/technische Audits je URL/Lauf.
CREATE TABLE IF NOT EXISTS onsite_audits (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id     INT UNSIGNED NOT NULL,
    url           VARCHAR(768) NULL,           -- NULL = domain-weit aggregiert
    lcp_ms        INT          NULL,
    inp_ms        INT          NULL,
    cls           DECIMAL(6,3) NULL,
    performance   INT          NULL,           -- Lighthouse-Score 0-100
    issues        JSON         NULL,           -- technische Fehler (Meta, hreflang, broken links, ...)
    source        VARCHAR(64)  NOT NULL,       -- dataforseo_onpage | pagespeed | crux | observatory
    measured_at   DATE         NOT NULL,
    PRIMARY KEY (id),
    KEY idx_onsite_client_date (client_id, measured_at),
    CONSTRAINT fk_onsite_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Offsite/Backlink-Snapshots, wöchentlich (oder monatlich, s. Kostenstellschrauben).
CREATE TABLE IF NOT EXISTS backlinks (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id         INT UNSIGNED NOT NULL,
    referring_domains INT UNSIGNED NULL,
    backlinks_total   INT UNSIGNED NULL,
    domain_rank       INT          NULL,       -- DataForSEO-eigener Rank (kein DR/DA/TF)
    spam_score        INT          NULL,
    new_last_period   INT          NULL,
    lost_last_period  INT          NULL,
    top_anchors       JSON         NULL,
    source            VARCHAR(64)  NOT NULL DEFAULT 'dataforseo_backlinks',
    measured_at       DATE         NOT NULL,
    PRIMARY KEY (id),
    KEY idx_backlinks_client_date (client_id, measured_at),
    CONSTRAINT fk_backlinks_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Aggregierte monatliche Sichtbarkeits-Historie (DataForSEO Labs historical_rank_overview).
-- Rückwirkend beim Onboarding befüllt → sofortiger Verlauf im Report, ohne bei null zu starten.
-- Ranking-Verteilung + geschätzter Traffic-Wert (etv) pro Monat.
CREATE TABLE IF NOT EXISTS visibility_history (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id     INT UNSIGNED NOT NULL,
    engine        ENUM('google','bing') NOT NULL DEFAULT 'google',
    period        CHAR(7)      NOT NULL,          -- YYYY-MM
    keywords_total INT         NULL,              -- Anzahl rankender Keywords
    pos_1         INT          NULL,
    pos_2_3       INT          NULL,
    pos_4_10      INT          NULL,
    pos_11_20     INT          NULL,
    pos_21_50     INT          NULL,
    pos_51_100    INT          NULL,
    etv           DECIMAL(12,2) NULL,             -- estimated traffic value (Sichtbarkeits-Proxy)
    is_new        INT          NULL,
    is_lost       INT          NULL,
    source        VARCHAR(64)  NOT NULL DEFAULT 'dataforseo_historical',
    PRIMARY KEY (id),
    UNIQUE KEY uq_vishist (client_id, engine, period, source),
    KEY idx_vishist_client (client_id, period),
    CONSTRAINT fk_vishist_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Social-Media-Kennzahlen der EIGENEN Kunden-Kanäle, wöchentlich (Zeitreihe).
-- views_total ist der kumulierte Lifetime-Zähler der Plattform (YouTube: channel viewCount
-- inkl. Shorts; TikTok/Instagram: Gesamt-Views des Accounts). Monats-Views werden im
-- Report aus der Differenz zweier Stände abgeleitet (nicht hier gespeichert).
CREATE TABLE IF NOT EXISTS social_metrics (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id    INT UNSIGNED NOT NULL,
    platform     ENUM('youtube','tiktok','instagram') NOT NULL,
    account      VARCHAR(255) NOT NULL,           -- Kanal-ID/Handle/URL (Anzeige + Zuordnung)
    followers    BIGINT UNSIGNED NULL,            -- Subscriber/Follower
    views_total  BIGINT UNSIGNED NULL,            -- kumulierte Lifetime-Views (Basis für Monats-Delta)
    posts_total  INT UNSIGNED NULL,               -- Anzahl Videos/Posts (falls verfügbar)
    source       VARCHAR(64)  NOT NULL,           -- youtube_data_api | apify
    measured_at  DATE         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_social (client_id, platform, account, measured_at),
    KEY idx_social_client_date (client_id, measured_at),
    CONSTRAINT fk_social_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Erzeugte Monatsberichte (Metadaten; die .md/Charts liegen im Dateisystem).
CREATE TABLE IF NOT EXISTS reports (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id    INT UNSIGNED NOT NULL,
    period       CHAR(7)      NOT NULL,        -- YYYY-MM
    md_path      VARCHAR(768) NULL,
    summary      TEXT         NULL,            -- Executive Summary (Mail-Body)
    status       ENUM('draft','sent') NOT NULL DEFAULT 'draft',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reports_client_period (client_id, period),
    CONSTRAINT fk_reports_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
