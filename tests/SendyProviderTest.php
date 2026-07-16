<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Provider\SendyProvider;
use PHPUnit\Framework\TestCase;

final class SendyProviderTest extends TestCase
{
    public function testToStatMapsListRowAndSummary(): void
    {
        $listRow = [
            'campaign_id' => 31,
            'label' => 'The Openstream #76 – Juli/August 2026',
            'sent_iso' => '2026-07-02T20:00:03+02:00',
            'recipients' => 841,
        ];
        $summary = ['sent' => 841, 'unique_opens' => 310, 'clicks' => 117, 'unsubscribes' => 1];

        $s = SendyProvider::toStat($listRow, $summary, 841);
        $this->assertSame('31', $s->campaignRef);
        $this->assertSame('The Openstream #76 – Juli/August 2026', $s->subject);
        $this->assertSame('2026-07-02', $s->sentAt);
        $this->assertSame(841, $s->recipients);
        $this->assertSame(310, $s->opens);
        $this->assertSame(117, $s->clicks);
        $this->assertSame(1, $s->unsubscribes);
        $this->assertSame(841, $s->listSize);
        $this->assertSame('sendy', $s->provider);
        // Raten aus den echten Zahlen: 310/841 = 36.9 %, 117/841 = 13.9 %
        $this->assertSame(36.9, $s->openRate());
        $this->assertSame(13.9, $s->clickRate());
    }

    public function testCleanLabelStripsTrailingTimestamp(): void
    {
        $this->assertSame(
            'The Openstream #76 –Juli/August 2026',
            SendyProvider::cleanLabel('The Openstream #76 –Juli/August 2026 — 2026-07-02 15:09')
        );
        // Ohne Timestamp bleibt das Label unverändert.
        $this->assertSame('Normaler Betreff', SendyProvider::cleanLabel('Normaler Betreff'));
        $this->assertNull(SendyProvider::cleanLabel(null));
    }

    public function testToStatFallsBackToUnixSentAndToSend(): void
    {
        $listRow = ['campaign_id' => 28, 'title' => 'Betreff', 'sent' => 1779989403, 'to_send' => 847];
        $s = SendyProvider::toStat($listRow, [], null);
        $this->assertSame('Betreff', $s->subject);          // title, wenn kein label
        $this->assertSame('2026-05-28', $s->sentAt);        // aus Unix-sent
        $this->assertSame(847, $s->recipients);             // to_send, wenn keine summary.sent
        $this->assertNull($s->opens);                        // ohne Summary keine Opens
        $this->assertNull($s->listSize);
    }
}
