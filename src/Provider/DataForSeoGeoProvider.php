<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * GEO-Sichtbarkeit über DataForSEO LLM-Responses (ChatGPT, Gemini, Claude, Perplexity).
 * Schickt jeden GEO-Prompt auf Deutsch mit Web-Suche an das Modell, analysiert Text +
 * Citations (via MentionAnalyzer) → erwähnt/zitiert/Position/Wettbewerber. Kein US/EN-Limit,
 * da wir die Prompts selbst deutsch stellen. Perplexity läuft seit 18.07.2026 ebenfalls hier
 * (statt separater Sonar-API) → alle GEO-Kanäle unter einer Auth/einem Antwortformat.
 */
final class DataForSeoGeoProvider implements GeoProvider
{
    /** engine → [dfs-provider-slug, modell, ai_mentions-engine, source] */
    private const CHANNELS = [
        'chatgpt'    => ['chat_gpt',  'gpt-4o-mini', 'chatgpt', 'dataforseo'],
        'gemini'     => ['gemini',    'gemini-2.5-flash', 'gemini', 'dataforseo'],
        'claude'     => ['claude',    'claude-sonnet-4-6', 'claude', 'dataforseo'],
        'perplexity' => ['perplexity', 'sonar', 'perplexity', 'dataforseo'],
    ];

    public function __construct(
        private readonly DataForSeoClient $dfs,
        private readonly MentionAnalyzer $analyzer,
        private readonly string $engine,   // 'chatgpt' | 'gemini'
    ) {
        if (!isset(self::CHANNELS[$engine])) {
            throw new \InvalidArgumentException("Unbekannter GEO-Kanal: {$engine}");
        }
    }

    public function name(): string
    {
        return self::CHANNELS[$this->engine][2];
    }

    public function collect(array $prompts): array
    {
        [$slug, $model, $aiEngine, $source] = self::CHANNELS[$this->engine];
        $out = [];

        foreach ($prompts as $promptId => $promptText) {
            try {
                $res = $this->dfs->post("ai_optimization/{$slug}/llm_responses/live", [[
                    'user_prompt' => $promptText,
                    'model_name'  => $model,
                    'web_search'  => true,
                ]]);
            } catch (\Throwable $e) {
                continue; // einzelnen Prompt überspringen, Lauf nicht abbrechen
            }

            [$text, $citations] = $this->extract($res);
            $a = $this->analyzer->analyze($text, $citations);

            $out[] = new GeoMention(
                engine:      $aiEngine,
                promptId:    (int) $promptId,
                mentioned:   $a['mentioned'],
                cited:       $a['cited'],
                position:    $a['position'],
                citations:   $citations,
                competitors: $a['competitors'],
                source:      $source,
            );
        }
        return $out;
    }

    /**
     * Zieht Fliesstext + zitierte URLs aus der LLM-Responses-Antwort.
     * @return array{0:string,1:array<int,string>}
     */
    private function extract(array $res): array
    {
        $item = $res['tasks'][0]['result'][0]['items'][0] ?? [];
        $text = '';
        $citations = [];
        foreach ($item['sections'] ?? [] as $s) {
            $text .= ($s['text'] ?? '') . "\n";
            foreach ($s['annotations'] ?? [] as $an) {
                if (!empty($an['url'])) {
                    $citations[] = (string) $an['url'];
                }
            }
        }
        return [$text, array_values(array_unique($citations))];
    }
}
