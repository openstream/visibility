<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Erhebt echte Kennzahlen eines per OAuth VERBUNDENEN Kanals (der Kunde hat seinen eigenen
 * Account autorisiert). Im Gegensatz zu SocialProvider (öffentlich, ohne OAuth) liefern
 * diese Provider die exakten Monats-Views/Reichweite über die Analytics-APIs.
 */
interface ConnectedSocialProvider
{
    /**
     * @param array<string,mixed> $connection Zeile aus social_connections
     * @param string $measuredAt Y-m-d des Erhebungslaufs
     * @return array<int,SocialMetric>
     */
    public function collectConnected(array $connection, string $measuredAt): array;

    /** Plattform-Name (youtube|instagram|tiktok). */
    public function name(): string;
}
