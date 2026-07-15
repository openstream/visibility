<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Provider\AiOverviewProvider;
use PHPUnit\Framework\TestCase;

final class AiOverviewProviderTest extends TestCase
{
    public function testFindsDomainInReferencesWithPosition(): void
    {
        $aio = ['references' => [
            ['domain' => 'alpineai.swiss', 'url' => 'https://alpineai.swiss/'],
            ['domain' => 'www.openstream.ch', 'url' => 'https://www.openstream.ch/vergleich/'],
            ['domain' => 'swisscom.ch', 'url' => 'https://swisscom.ch/'],
        ]];
        [$found, $pos, $urls] = AiOverviewProvider::findInReferences($aio, 'openstream.ch');
        $this->assertTrue($found);
        $this->assertSame(2, $pos); // zweite Quelle
        $this->assertCount(3, $urls);
    }

    public function testNotFoundWhenAbsent(): void
    {
        $aio = ['references' => [
            ['domain' => 'konkurrent.ch', 'url' => 'https://konkurrent.ch/'],
        ]];
        [$found, $pos, $urls] = AiOverviewProvider::findInReferences($aio, 'openstream.ch');
        $this->assertFalse($found);
        $this->assertNull($pos);
        $this->assertCount(1, $urls);
    }

    public function testHandlesMissingReferences(): void
    {
        [$found, $pos, $urls] = AiOverviewProvider::findInReferences([], 'openstream.ch');
        $this->assertFalse($found);
        $this->assertNull($pos);
        $this->assertSame([], $urls);
    }

    public function testMatchesWwwPrefixedDomain(): void
    {
        $aio = ['references' => [['domain' => 'www.openstream.ch', 'url' => 'x']]];
        [$found] = AiOverviewProvider::findInReferences($aio, 'openstream.ch');
        $this->assertTrue($found);
    }
}
