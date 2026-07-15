<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Ein normalisiertes GEO-Ergebnis für einen Prompt auf einem KI-Kanal
 * (eine Zeile in `ai_mentions`).
 */
final class GeoMention
{
    /**
     * @param array<int,string> $citations   zitierte URLs
     * @param array<int,string> $competitors genannte Wettbewerber (Namen/Domains)
     */
    public function __construct(
        public readonly string $engine,          // chatgpt | perplexity | gemini | ai_overview | bing_ai
        public readonly ?int $promptId,
        public readonly bool $mentioned,
        public readonly bool $cited,
        public readonly ?int $position,
        public readonly array $citations,
        public readonly array $competitors,
        public readonly string $source,         // openai | perplexity | dataforseo | anthropic | bing_ui
    ) {}
}
