<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Erhebt Newsletter-Kennzahlen (Öffnungen/Klicks/Abmeldungen/Listengrösse) eines Kunden.
 * Implementierungen: Mailchimp (offizielle Marketing API), Sendy (API/DB).
 */
interface NewsletterProvider
{
    /**
     * @param int $limit maximale Anzahl jüngster Kampagnen
     * @return array<int,NewsletterStat>
     */
    public function recentCampaigns(int $limit = 12): array;

    /** Provider-Name (mailchimp | sendy). */
    public function name(): string;
}
