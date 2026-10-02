<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add games.rules_variant (NOT NULL, official by default): opt-in per-game rule set whose '
            . 'compact option shortens the Small thermometer ladder.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE public.games ADD rules_variant character varying(16) DEFAULT 'official' NOT NULL;");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.games DROP COLUMN rules_variant;');
    }
}
