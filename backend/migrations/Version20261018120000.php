<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the optional GTFS-RT trip-update URL to a GTFS source.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.gtfs_source ADD realtime_url character varying(1000) DEFAULT NULL;');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.gtfs_source DROP COLUMN realtime_url;');
    }
}
