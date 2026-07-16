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

    public function testCollectsRealMonthlyViewsWithCachedIgId(): void
    {
        // account_ref gesetzt → kein /me/accounts-Call; Reihenfolge: views, reach, followers.
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

    public function testResolvesIgUserIdWhenNotCached(): void
    {
        // Ohne account_ref: erst /me/accounts, dann views, reach, followers.
        $provider = $this->providerWith([
            new Response(200, [], json_encode(['data' => [
                ['instagram_business_account' => ['id' => '17841499999999999']],
            ]])),
            new Response(200, [], json_encode(['data' => [['name' => 'views', 'total_value' => ['value' => 300]]]])),
            new Response(200, [], json_encode(['data' => [['name' => 'reach', 'total_value' => ['value' => 250]]]])),
            new Response(200, [], json_encode(['followers_count' => 90])),
        ]);

        $out = $provider->collectConnected(['id' => 1, 'platform' => 'instagram'], '2026-07-16');
        $this->assertSame(300, $out[0]->monthlyViews);
        $this->assertSame(90, $out[0]->followers);
    }

    public function testReturnsEmptyWhenNoLinkedIgAccount(): void
    {
        $provider = $this->providerWith([
            new Response(200, [], json_encode(['data' => []])), // keine Seite mit IG-Konto
        ]);
        $out = $provider->collectConnected(['id' => 1, 'platform' => 'instagram'], '2026-07-16');
        $this->assertSame([], $out);
    }
}
