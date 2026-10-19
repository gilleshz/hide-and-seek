<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename accounts to identities and bind them to a device credential instead of a password.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.accounts RENAME TO identities;');
        $this->addSql('ALTER TABLE public.identities RENAME CONSTRAINT accounts_pkey TO identities_pkey;');
        $this->addSql('ALTER SEQUENCE public.accounts_id_seq RENAME TO identities_id_seq;');
        $this->addSql('ALTER INDEX public.uniq_accounts_uuid RENAME TO uniq_identities_uuid;');
        $this->addSql('ALTER INDEX public.uniq_accounts_name RENAME TO uniq_identities_name;');
        $this->addSql('ALTER TABLE public.identities RENAME COLUMN password_hash TO credential_hash;');
        $this->addSql('ALTER TABLE public.identities ADD credential_reset_requested boolean DEFAULT true NOT NULL;');
        $this->addSql('ALTER TABLE public.identities ALTER COLUMN credential_reset_requested SET DEFAULT false;');
        $this->addSql('ALTER TABLE public.players RENAME COLUMN account_id TO identity_id;');
        $this->addSql('ALTER TABLE public.players RENAME CONSTRAINT fk_players_account TO fk_players_identity;');
        $this->addSql('ALTER INDEX public.uniq_player_game_account RENAME TO uniq_player_game_identity;');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.identities DROP COLUMN credential_reset_requested;');
        $this->addSql('ALTER TABLE public.identities RENAME COLUMN credential_hash TO password_hash;');
        $this->addSql('ALTER INDEX public.uniq_identities_name RENAME TO uniq_accounts_name;');
        $this->addSql('ALTER INDEX public.uniq_identities_uuid RENAME TO uniq_accounts_uuid;');
        $this->addSql('ALTER TABLE public.identities RENAME CONSTRAINT identities_pkey TO accounts_pkey;');
        $this->addSql('ALTER SEQUENCE public.identities_id_seq RENAME TO accounts_id_seq;');
        $this->addSql('ALTER TABLE public.identities RENAME TO accounts;');
        $this->addSql('ALTER TABLE public.players RENAME COLUMN identity_id TO account_id;');
        $this->addSql('ALTER TABLE public.players RENAME CONSTRAINT fk_players_identity TO fk_players_account;');
        $this->addSql('ALTER INDEX public.uniq_player_game_identity RENAME TO uniq_player_game_account;');
    }
}
