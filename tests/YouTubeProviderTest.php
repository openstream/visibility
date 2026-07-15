<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Provider\YouTubeProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeProviderTest extends TestCase
{
    public function testResolvesRawChannelId(): void
    {
        [$param, $value] = YouTubeProvider::resolve('UC_x5XG1OV2P6uZZ5FSM9Ttw');
        $this->assertSame('id', $param);
        $this->assertSame('UC_x5XG1OV2P6uZZ5FSM9Ttw', $value);
    }

    public function testResolvesChannelUrl(): void
    {
        [$param, $value] = YouTubeProvider::resolve('https://www.youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw');
        $this->assertSame('id', $param);
        $this->assertSame('UC_x5XG1OV2P6uZZ5FSM9Ttw', $value);
    }

    public function testResolvesHandleWithAt(): void
    {
        [$param, $value] = YouTubeProvider::resolve('@openstream');
        $this->assertSame('forHandle', $param);
        $this->assertSame('@openstream', $value);
    }

    public function testResolvesHandleUrl(): void
    {
        [$param, $value] = YouTubeProvider::resolve('https://www.youtube.com/@openstream');
        $this->assertSame('forHandle', $param);
        $this->assertSame('@openstream', $value);
    }

    public function testResolvesBareNameAsHandle(): void
    {
        [$param, $value] = YouTubeProvider::resolve('openstream');
        $this->assertSame('forHandle', $param);
        $this->assertSame('@openstream', $value);
    }
}
