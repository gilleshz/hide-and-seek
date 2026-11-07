<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catalog-sourced GTFS-RT feeds may lack a service-alerts row, so derive the sibling
 * service-alert URL from the trip-updates URL (MAP-12/MAP-13).
 */
final class Version20261107213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill GTFS-RT service-alerts URLs derived from trip-updates URLs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE public.gtfs_source "
            . "SET realtime_alerts_url = regexp_replace(realtime_url, 'trip[-_]?updates?', 'service-alert', 'gi') "
            . "WHERE realtime_provider = 'gtfs_rt_url' "
            . "AND realtime_alerts_url IS NULL "
            . "AND realtime_url IS NOT NULL "
            . "AND realtime_url ~* 'trip[-_]?updates?' "
            . "AND regexp_replace(realtime_url, 'trip[-_]?updates?', 'service-alert', 'gi') <> realtime_url;",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE public.gtfs_source SET realtime_alerts_url = NULL "
            . "WHERE realtime_provider = 'gtfs_rt_url' "
            . "AND realtime_url IS NOT NULL "
            . "AND realtime_url ~* 'trip[-_]?updates?' "
            . "AND realtime_alerts_url = regexp_replace(realtime_url, 'trip[-_]?updates?', 'service-alert', 'gi');",
        );
    }
}
