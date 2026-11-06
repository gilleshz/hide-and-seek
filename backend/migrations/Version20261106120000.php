<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A source can carry live data from a GTFS-RT URL or from a SIRI Lite operator profile.
 * Existing rows that have a realtime URL are GTFS-RT.
 */
final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a realtime provider and SIRI profile to GTFS sources.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE public.gtfs_source ADD realtime_provider VARCHAR(16) DEFAULT 'none' NOT NULL;");
        $this->addSql('ALTER TABLE public.gtfs_source ADD realtime_profile VARCHAR(32) DEFAULT NULL;');
        $this->addSql(
            "UPDATE public.gtfs_source SET realtime_provider = 'gtfs_rt_url' "
            . "WHERE realtime_url IS NOT NULL AND realtime_url <> '';",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.gtfs_source DROP COLUMN realtime_profile;');
        $this->addSql('ALTER TABLE public.gtfs_source DROP COLUMN realtime_provider;');
    }
}
