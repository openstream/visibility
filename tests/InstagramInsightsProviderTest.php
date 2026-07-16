<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Openstream\Visibility\OAuth\OAuthTokenStore;
use Openstream\Visibility\Provider\InstagramInsightsProvider;
use PHPUnit\Framework\TestCase;

final class InstagramInsightsProviderTest extends TestCase
{
    public function testMonthUnixRangeCoversWholeMonth(): void
    {
        [$start, $end] = InstagramInsightsProvider::monthUnixRange('2026-07-16');
        $this->assertSame('2026-07-01', date('Y-m-d', $start));
        $this->assertSame('2026-07-31', date('Y-m-d', $end));
    }

    /** @param array<int,Response> $responses */
    private function providerWith(array $responses): InstagramInsightsProvider
    {
        $mock = new MockHandler($responses);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $store = $this->createMock(OAuthTokenStore::class);
        $store->method('accessTokenFor')->willReturn('fake-token');
        return new InstagramInsightsProvider($store, $http);
    }

    public function testCollectsRealMonthlyViews(): void
    {
        // Instagram-Login-Weg: me/insights views, me/insights reach, me?fields=followers_count.
        $provider = $this->providerWith([
            new Response(200, [], json_encode(['data' => [
                ['name' => 'views', 'total_value' => ['value' => 12000]],
            ]])),
            new Response(200, [], json_encode(['data' => [
                ['name' => 'reach', 'total_value' => ['value' => 8000]],
            ]])),
            new Response(200, [], json_encode(['followers_count' => 1500])),
        ]);

        $out = $provider->collectConnected(
            ['id' => 1, 'platform' => 'instagram', 'account_ref' => '17841400000000000', 'account_label' => '@openstream'],
            '2026-07-16'
        );

        $this->assertCount(1, $out);
        $m = $out[0];
        $this->assertSame('instagram', $m->platform);
        $this->assertSame('@openstream', $m->account);
        $this->assertSame(12000, $m->monthlyViews); // echte Monats-Views, kein Delta
        $this->assertSame(1500, $m->followers);
        $this->assertNull($m->viewsTotal);
        $this->assertSame('instagram_graph', $m->source);
    }

    public function testFallsBackToAccountRefAsLabel(): void
    {
        $provider = $this->providerWith([
            new Response(200, [], json_encode(['data' => [['name' => 'views', 'total_value' => ['value' => 300]]]])),
            new Response(200, [], json_encode(['data' => [['name' => 'reach', 'total_value' => ['value' => 250]]]])),
            new Response(200, [], json_encode(['followers_count' => 90])),
        ]);

        // Kein account_label → account_ref (die user_id) dient als Label.
        $out = $provider->collectConnected(['id' => 1, 'platform' => 'instagram', 'account_ref' => '17841499999999999'], '2026-07-16');
        $this->assertSame('17841499999999999', $out[0]->account);
        $this->assertSame(300, $out[0]->monthlyViews);
        $this->assertSame(90, $out[0]->followers);
    }

    public function testHandlesMissingInsightGracefully(): void
    {
        // views fehlt in der Antwort → monthlyViews null, aber Follower trotzdem gelesen.
        $provider = $this->providerWith([
            new Response(200, [], json_encode(['data' => []])),
            new Response(200, [], json_encode(['data' => []])),
            new Response(200, [], json_encode(['followers_count' => 42])),
        ]);
        $out = $provider->collectConnected(['id' => 1, 'platform' => 'instagram', 'account_ref' => 'x'], '2026-07-16');
        $this->assertNull($out[0]->monthlyViews);
        $this->assertSame(42, $out[0]->followers);
    }
}
