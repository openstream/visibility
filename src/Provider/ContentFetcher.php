<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Holt Seiteninhalte für die Website-Analyse (Onboarding Schritt 0).
 * API-basiert — kein eigener Crawler. Primär via DataForSEO OnPage instant_pages
 * (liefert geparsten Seiteninhalt inkl. Meta/Headings/Plaintext in einem Call).
 */
final class ContentFetcher
{
    public function __construct(private readonly DataForSeoClient $dfs) {}

    /**
     * Holt eine einzelne URL und gibt Titel, Description, Headings und Plaintext zurück.
     *
     * @return array{url:string,title:?string,description:?string,headings:array<int,string>,text:string}
     */
    public function fetch(string $url): array
    {
        $data = $this->dfs->post('on_page/instant_pages', [[
            'url'                    => $url,
            'enable_javascript'      => false,
            'load_resources'         => false,
        ]]);

        $item = $data['tasks'][0]['result'][0]['items'][0] ?? null;
        if ($item === null) {
            return ['url' => $url, 'title' => null, 'description' => null, 'headings' => [], 'text' => ''];
        }

        $meta = $item['meta'] ?? [];
        $headings = [];
        foreach (($meta['htags'] ?? []) as $tag => $values) {
            foreach ((array) $values as $v) {
                $headings[] = strtoupper((string) $tag) . ': ' . trim((string) $v);
            }
        }

        // Plaintext: DataForSEO liefert bei content-parsing ein plain_text-Feld.
        $text = (string) ($item['page_content']['main_topic'][0]['primary_content'] ?? '');
        if ($text === '') {
            $text = (string) ($meta['content']['plain_text_rate'] ?? '');
        }

        return [
            'url'         => $url,
            'title'       => $meta['title'] ?? null,
            'description' => $meta['description'] ?? null,
            'headings'    => $headings,
            'text'        => $text,
        ];
    }

    /**
     * Holt mehrere URLs und fasst ihren Inhalt zu einem kompakten Text-Korpus zusammen,
     * der einem LLM zum Verstehen der Website übergeben werden kann.
     *
     * @param array<int,string> $urls
     * @return array{corpus:string,pages:array<int,array<string,mixed>>}
     */
    public function corpus(array $urls, int $maxCharsPerPage = 3000): array
    {
        $pages = [];
        $parts = [];
        foreach ($urls as $url) {
            $p = $this->fetch($url);
            $pages[] = $p;
            $block = "URL: {$p['url']}\n";
            if ($p['title']) {
                $block .= "Titel: {$p['title']}\n";
            }
            if ($p['description']) {
                $block .= "Description: {$p['description']}\n";
            }
            if ($p['headings']) {
                $block .= "Überschriften:\n- " . implode("\n- ", array_slice($p['headings'], 0, 25)) . "\n";
            }
            if ($p['text']) {
                $block .= "Inhalt:\n" . mb_substr($p['text'], 0, $maxCharsPerPage) . "\n";
            }
            $parts[] = $block;
        }
        return ['corpus' => implode("\n\n---\n\n", $parts), 'pages' => $pages];
    }
}
