<?php

declare(strict_types=1);

namespace Openstream\Visibility\OAuth;

/**
 * Authentifizierte Verschlüsselung (AES-256-GCM) für sensible Werte wie OAuth-Refresh-Tokens.
 * Der Schlüssel kommt aus APP_ENCRYPTION_KEY (32 Byte, base64). GCM liefert Vertraulichkeit
 * UND Integrität (Auth-Tag) — ein manipuliertes Ciphertext scheitert bei der Entschlüsselung.
 *
 * Format des Ausgabestrings: base64( iv(12) || tag(16) || ciphertext ).
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;   // GCM-Standard
    private const TAG_LEN = 16;

    private string $key;

    /** @param string $keyBase64 32-Byte-Schlüssel als base64 (openssl rand -base64 32) */
    public function __construct(string $keyBase64)
    {
        $key = base64_decode($keyBase64, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('APP_ENCRYPTION_KEY muss 32 Byte base64 sein (openssl rand -base64 32).');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($cipher === false) {
            throw new \RuntimeException('Verschlüsselung fehlgeschlagen.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Ungültiger Ciphertext.');
        }
        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::IV_LEN + self::TAG_LEN);
        $plain = openssl_decrypt($cipher, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen (falscher Key oder manipulierte Daten).');
        }
        return $plain;
    }
}
