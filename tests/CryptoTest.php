<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\OAuth\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    private function key(): string
    {
        // Deterministischer 32-Byte-Key (base64) für die Tests.
        return base64_encode(str_repeat("\x11", 32));
    }

    public function testRoundTrip(): void
    {
        $c = new Crypto($this->key());
        $plain = 'refresh-token-1//abc.DEF-ghi_jkl';
        $enc = $c->encrypt($plain);
        $this->assertNotSame($plain, $enc);
        $this->assertSame($plain, $c->decrypt($enc));
    }

    public function testCiphertextIsRandomizedPerEncryption(): void
    {
        // Gleicher Klartext → unterschiedliche Ciphertexts (zufälliger IV).
        $c = new Crypto($this->key());
        $this->assertNotSame($c->encrypt('x'), $c->encrypt('x'));
    }

    public function testTamperedCiphertextFails(): void
    {
        $c = new Crypto($this->key());
        $enc = $c->encrypt('secret');
        $raw = base64_decode($enc, true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === "\x00" ? "\x01" : "\x00"; // letztes Byte kippen
        $this->expectException(\RuntimeException::class);
        $c->decrypt(base64_encode($raw));
    }

    public function testWrongKeyFails(): void
    {
        $enc = (new Crypto($this->key()))->encrypt('secret');
        $other = new Crypto(base64_encode(str_repeat("\x22", 32)));
        $this->expectException(\RuntimeException::class);
        $other->decrypt($enc);
    }

    public function testRejectsBadKeyLength(): void
    {
        $this->expectException(\RuntimeException::class);
        new Crypto(base64_encode('too-short'));
    }
}
