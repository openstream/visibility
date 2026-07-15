<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Misst die GEO-Sichtbarkeit (Erwähnung/Zitat in KI-Antworten) für eine Liste von
 * GEO-Prompts auf einem KI-Kanal. Implementierungen: DataForSEO (ChatGPT/Gemini),
 * Anthropic (Claude), Perplexity (Sonar), Bing-AI (CSV-Import).
 */
interface GeoProvider
{
    /**
     * @param array<int,string> $prompts approved GEO-Prompts (id => prompt-text)
     * @return array<int,GeoMention>
     */
    public function collect(array $prompts): array;

    /** Kanal-Name (chatgpt|gemini|claude|perplexity|bing_ai) für Logging. */
    public function name(): string;
}
