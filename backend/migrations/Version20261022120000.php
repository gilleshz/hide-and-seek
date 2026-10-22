<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the optional avatar reference to an identity.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.identities ADD avatar_ref VARCHAR(64) DEFAULT NULL;');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.identities DROP avatar_ref;');
    }
}
