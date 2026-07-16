<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Openstream\Visibility\OAuth\OAuthTokenStore;
use Openstream\Visibility\Provider\TikTokProvider;
use PHPUnit\Framework\TestCase;

final class TikTokProviderTest extends TestCase
{
    public function testViewCountToleratesFieldNames(): void
    {
        $this->assertSame(42, TikTokProvider::viewCount(['view_count' => 42]));
        $this->assertSame(7, TikTokProvider::viewCount(['play_count' => 7]));
        $this->assertSame(0, TikTokProvider::viewCount(['id' => 'x'])); // kein View-Feld
    }

    /** @param array<int,Response> $responses */
    private function providerWith(array $responses): TikTokProvider
    {
        $mock = new MockHandler($responses);
        $http = new Client(['handler' => HandlerStack::create($mock)]);

        $store = $this->createMock(OAuthTokenStore::class);
        $store->method('accessTokenFor')->willReturn('fake-access-token');

        return new TikTokProvider($store, $http);
    }

    public function testAggregatesViewsAcrossPages(): void
    {
        $provider = $this->providerWith([
            // user/info/
            new Response(200, [], json_encode(['data' => ['user' => [
                'display_name' => 'Openstream', 'follower_count' => 500,
                'likes_count' => 9000, 'video_count' => 3,
            ]]])),
            // video/list/ Seite 1 (has_more=true)
            new Response(200, [], json_encode(['data' => [
                'videos' => [['id' => '1', 'view_count' => 100], ['id' => '2', 'view_count' => 250]],
                'cursor' => 123456, 'has_more' => true,
            ]])),
            // video/list/ Seite 2 (has_more=false)
            new Response(200, [], json_encode(['data' => [
                'videos' => [['id' => '3', 'view_count' => 400]],
                'cursor' => 999, 'has_more' => false,
            ]])),
        ]);

        $out = $provider->collectConnected(['id' => 1, 'platform' => 'tiktok', 'account_ref' => '@openstreamch'], '2026-07-16');

        $this->assertCount(1, $out);
        $m = $out[0];
        $this->assertSame('tiktok', $m->platform);
        $this->assertSame('@openstreamch', $m->account);
        $this->assertSame(500, $m->followers);
        $this->assertSame(750, $m->viewsTotal); // 100 + 250 + 400
        $this->assertNull($m->monthlyViews);    // TikTok: Monats-Views via Delta, nicht direkt
        $this->assertSame('tiktok_api', $m->source);
    }

    public function testStopsWhenNoMorePages(): void
    {
        $provider = $this->providerWith([
            new Response(200, [], json_encode(['data' => ['user' => ['follower_count' => 10]]])),
            new Response(200, [], json_encode(['data' => [
                'videos' => [['id' => '1', 'view_count' => 5]],
                'has_more' => false,
            ]])),
        ]);

        $out = $provider->collectConnected(['id' => 1, 'platform' => 'tiktok'], '2026-07-16');
        $this->assertSame(5, $out[0]->viewsTotal);
        $this->assertSame(10, $out[0]->followers);
    }
}
