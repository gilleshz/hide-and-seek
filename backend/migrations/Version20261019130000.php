<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the identity token epoch, normalize legacy names and drop legacy password hashes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.identities ADD token_epoch integer DEFAULT 1 NOT NULL;');
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE collision text;
            BEGIN
                SELECT btrim(name) INTO collision
                FROM public.identities
                GROUP BY btrim(name)
                HAVING count(*) > 1
                LIMIT 1;

                IF collision IS NOT NULL THEN
                    RAISE EXCEPTION 'Identity names collide after trimming whitespace: %', collision;
                END IF;
            END $$;
        SQL);
        $this->addSql('UPDATE public.identities SET name = btrim(name) WHERE name <> btrim(name);');
        $this->addSql(
            'UPDATE public.identities SET credential_hash = md5(random()::text) || md5(random()::text) '
            . 'WHERE credential_reset_requested = true',
        );
    }

    public function down(Schema $schema): void
    {
        // Name normalization and the hash wipe are irreversible; only the epoch column rolls back.
        $this->addSql('ALTER TABLE public.identities DROP COLUMN token_epoch;');
    }
}
