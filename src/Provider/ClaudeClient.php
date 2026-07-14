<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use Openstream\Visibility\App;

/**
 * Minimaler Anthropic-Messages-API-Client (raw HTTP via Guzzle — kein SDK, passt
 * zum schlanken Stack). Für Website-Verständnis, Prompt-Generierung, Executive Summary.
 *
 * Endpoint: POST https://api.anthropic.com/v1/messages
 * Header: x-api-key, anthropic-version: 2023-06-01
 * Modell-Default: claude-opus-4-8 (aktueller Opus-Tier, Stand 2026).
 */
final class ClaudeClient
{
    private const VERSION = '2023-06-01';
    private const MODEL   = 'claude-opus-4-8';

    private Client $http;

    public function __construct(?string $apiKey = null)
    {
        $apiKey ??= App::get()->env('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            throw new \RuntimeException('ANTHROPIC_API_KEY fehlt in .env');
        }
        $this->http = new Client([
            'base_uri' => 'https://api.anthropic.com/',
            'timeout'  => 180,
            'headers'  => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::VERSION,
                'content-type'      => 'application/json',
            ],
        ]);
    }

    /**
     * Freitext-Antwort auf einen Prompt (mit optionalem System-Prompt).
     */
    public function text(string $prompt, ?string $system = null, int $maxTokens = 4000): string
    {
        $body = [
            'model'      => self::MODEL,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($system !== null) {
            $body['system'] = $system;
        }
        $data = $this->request($body);
        return $this->firstText($data);
    }

    /**
     * Erzwingt strukturiertes JSON gemäss $schema (json_schema) und gibt es dekodiert zurück.
     * Zuverlässiger Weg laut Anthropic: output_config.format mit json_schema.
     *
     * @param array<string,mixed> $schema  JSON-Schema (object mit properties, required, additionalProperties:false)
     * @return array<string,mixed>
     */
    public function structuredJson(string $prompt, array $schema, ?string $system = null, int $maxTokens = 4000): array
    {
        $body = [
            'model'         => self::MODEL,
            'max_tokens'    => $maxTokens,
            'messages'      => [['role' => 'user', 'content' => $prompt]],
            'output_config' => [
                'format' => [
                    'type'   => 'json_schema',
                    'schema' => $schema,
                ],
            ],
        ];
        if ($system !== null) {
            $body['system'] = $system;
        }
        $data = $this->request($body);
        $text = $this->firstText($data);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Claude lieferte kein gültiges JSON: ' . mb_substr($text, 0, 300));
        }
        return $decoded;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function request(array $body): array
    {
        $res = $this->http->post('v1/messages', ['json' => $body]);
        $data = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);

        // Refusal-Stop-Reason vor dem Lesen des Contents prüfen (leerer/partieller content möglich).
        if (($data['stop_reason'] ?? null) === 'refusal') {
            throw new \RuntimeException('Claude hat die Anfrage abgelehnt (refusal).');
        }
        return $data;
    }

    /** Ersten Text-Block aus der Antwort ziehen (content ist ein Block-Array). */
    private function firstText(array $data): string
    {
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                return (string) $block['text'];
            }
        }
        throw new \RuntimeException('Keine Text-Antwort von Claude erhalten.');
    }
}
