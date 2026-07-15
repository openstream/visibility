<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use Openstream\Visibility\App;

/**
 * GEO-Sichtbarkeit im Perplexity-Kanal via Sonar API. Citation-native: jede Antwort
 * liefert ein `citations`-Array. Deutsche Prompts problemlos. Günstig und schneller
 * als LLM-Responses. Wir analysieren Text + Citations via MentionAnalyzer.
 */
final class PerplexityGeoProvider implements GeoProvider
{
    private Client $http;

    public function __construct(
        private readonly MentionAnalyzer $analyzer,
        ?string $apiKey = null,
        private readonly string $model = 'sonar',
    ) {
        $apiKey ??= App::get()->env('PERPLEXITY_API_KEY');
        if (!$apiKey) {
            throw new \RuntimeException('PERPLEXITY_API_KEY fehlt in .env');
        }
        $this->http = new Client([
            'base_uri' => 'https://api.perplexity.ai/',
            'timeout'  => 60,
            'headers'  => ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
        ]);
    }

    public function name(): string
    {
        return 'perplexity';
    }

    public function collect(array $prompts): array
    {
        $out = [];
        foreach ($prompts as $promptId => $promptText) {
            try {
                $res = $this->http->post('chat/completions', ['json' => [
                    'model'    => $this->model,
                    'messages' => [['role' => 'user', 'content' => $promptText]],
                ]]);
                $d = json_decode((string) $res->getBody(), true);
            } catch (\Throwable $e) {
                continue; // einzelnen Prompt überspringen
            }

            $text = (string) ($d['choices'][0]['message']['content'] ?? '');
            $citations = $this->citations($d);
            $a = $this->analyzer->analyze($text, $citations);

            $out[] = new GeoMention(
                engine:      'perplexity',
                promptId:    (int) $promptId,
                mentioned:   $a['mentioned'],
                cited:       $a['cited'],
                position:    $a['position'],
                citations:   $citations,
                competitors: $a['competitors'],
                source:      'perplexity',
            );
        }
        return $out;
    }

    /** Citations aus `citations` oder `search_results`. @return array<int,string> */
    private function citations(array $d): array
    {
        if (!empty($d['citations'])) {
            return array_values(array_filter(array_map('strval', $d['citations'])));
        }
        $urls = [];
        foreach ($d['search_results'] ?? [] as $s) {
            if (!empty($s['url'])) {
                $urls[] = (string) $s['url'];
            }
        }
        return array_values(array_unique($urls));
    }
}
