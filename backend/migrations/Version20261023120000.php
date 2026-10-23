<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Identities become device-keyed: the display name loses its uniqueness, a secret-keyed lookup
 * hash locates the row, and the operator-driven credential reset goes away. Accepted data loss:
 * every existing seat is device-unbound, so the table starts empty.
 */
final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Key identities by a device lookup hash and turn the unique name into a display name.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('TRUNCATE TABLE public.identities CASCADE;');
        $this->addSql('DROP INDEX public.uniq_identities_name;');
        $this->addSql('ALTER TABLE public.identities RENAME COLUMN name TO display_name;');
        $this->addSql('ALTER TABLE public.identities ADD lookup_hash VARCHAR(64) NOT NULL;');
        $this->addSql('CREATE UNIQUE INDEX uniq_identities_lookup_hash ON public.identities (lookup_hash);');
        $this->addSql('ALTER TABLE public.identities DROP COLUMN credential_reset_requested;');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('TRUNCATE TABLE public.identities CASCADE;');
        $this->addSql('DROP INDEX public.uniq_identities_lookup_hash;');
        $this->addSql('ALTER TABLE public.identities DROP COLUMN lookup_hash;');
        $this->addSql('ALTER TABLE public.identities RENAME COLUMN display_name TO name;');
        $this->addSql('CREATE UNIQUE INDEX uniq_identities_name ON public.identities (name);');
        $this->addSql(
            'ALTER TABLE public.identities ADD credential_reset_requested BOOLEAN DEFAULT false NOT NULL;',
        );
    }
}
