<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Provider\MailchimpProvider;
use PHPUnit\Framework\TestCase;

final class MailchimpProviderTest extends TestCase
{
    public function testDatacenterFromApiKey(): void
    {
        $this->assertSame('us21', MailchimpProvider::datacenter('abc123def456-us21'));
        $this->assertSame('us6', MailchimpProvider::datacenter('key-us6'));
    }

    public function testDatacenterRejectsKeyWithoutSuffix(): void
    {
        $this->expectException(\RuntimeException::class);
        MailchimpProvider::datacenter('nodatacenter');
    }

    public function testParseCampaigns(): void
    {
        $data = ['campaigns' => [[
            'id' => 'camp1',
            'emails_sent' => 1000,
            'send_time' => '2026-07-03T09:15:00+00:00',
            'settings' => ['subject_line' => 'Juli-Ausgabe'],
            'report_summary' => ['unique_opens' => 420, 'subscriber_clicks' => 85],
            'recipients' => ['recipient_count' => 1050],
        ]]];
        $stats = MailchimpProvider::parseCampaigns($data);
        $this->assertCount(1, $stats);
        $s = $stats[0];
        $this->assertSame('camp1', $s->campaignRef);
        $this->assertSame('Juli-Ausgabe', $s->subject);
        $this->assertSame('2026-07-03', $s->sentAt);
        $this->assertSame(1000, $s->recipients);
        $this->assertSame(420, $s->opens);
        $this->assertSame(85, $s->clicks);
        $this->assertSame(1050, $s->listSize);
        $this->assertSame(42.0, $s->openRate());
        $this->assertSame(8.5, $s->clickRate());
        $this->assertSame('mailchimp', $s->provider);
    }

    public function testParseEmptyCampaigns(): void
    {
        $this->assertSame([], MailchimpProvider::parseCampaigns([]));
        $this->assertSame([], MailchimpProvider::parseCampaigns(['campaigns' => []]));
    }
}
