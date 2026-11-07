<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A GTFS source can additionally carry a GTFS-RT service-alerts feed URL (MAP-12/MAP-13).
 */
final class Version20261107165753 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an optional GTFS-RT service-alerts URL to GTFS sources.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.gtfs_source ADD realtime_alerts_url VARCHAR(1000) DEFAULT NULL;');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.gtfs_source DROP COLUMN realtime_alerts_url;');
    }
}
