<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Kennzahlen einer Newsletter-Kampagne/Ausgabe (eine Zeile in `newsletter_stats`).
 * Nur aggregierte Raten, keine Empfänger-Adressen.
 */
final class NewsletterStat
{
    public function __construct(
        public readonly string $campaignRef,
        public readonly ?string $subject,
        public readonly ?string $sentAt,      // Y-m-d
        public readonly ?int $recipients,
        public readonly ?int $opens,          // eindeutige Öffnungen
        public readonly ?int $clicks,         // eindeutige Klicks
        public readonly ?int $bounces,
        public readonly ?int $unsubscribes,
        public readonly ?int $listSize,
        public readonly string $provider,     // sendy | mailchimp
    ) {}

    /** Öffnungsrate in Prozent (0..100), null wenn nicht berechenbar. */
    public function openRate(): ?float
    {
        return $this->recipients ? round(($this->opens ?? 0) / $this->recipients * 100, 1) : null;
    }

    /** Klickrate in Prozent (0..100), null wenn nicht berechenbar. */
    public function clickRate(): ?float
    {
        return $this->recipients ? round(($this->clicks ?? 0) / $this->recipients * 100, 1) : null;
    }
}
