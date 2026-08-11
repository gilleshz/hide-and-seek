<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bind players to accounts: backfill one account per distinct name (oldest seat\'s credential wins, other same-name seats bind to it), add account_id FK, drop per-game display_name + password_hash.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO public.accounts (uuid, name, password_hash, created_at)
            SELECT gen_random_uuid()::varchar, display_name, password_hash, created_at
            FROM (
                SELECT DISTINCT ON (display_name) display_name, password_hash, created_at
                FROM public.players
                ORDER BY display_name, created_at, id
            ) first_seat
            ON CONFLICT (name) DO NOTHING
        SQL);
        $this->addSql('ALTER TABLE public.players ADD account_id integer;');
        $this->addSql(<<<'SQL'
            UPDATE public.players p
            SET account_id = a.id
            FROM public.accounts a
            WHERE a.name = p.display_name
        SQL);
        $this->addSql('ALTER TABLE public.players ALTER COLUMN account_id SET NOT NULL;');
        $this->addSql('ALTER TABLE ONLY public.players
    ADD CONSTRAINT fk_players_account FOREIGN KEY (account_id) REFERENCES public.accounts(id) ON DELETE CASCADE;');
        $this->addSql('CREATE UNIQUE INDEX uniq_player_game_account ON public.players USING btree (game_id, account_id);');
        $this->addSql('DROP INDEX uniq_player_game_name;');
        $this->addSql('ALTER TABLE public.players DROP COLUMN display_name;');
        $this->addSql('ALTER TABLE public.players DROP COLUMN password_hash;');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public.players ADD display_name character varying(80) NOT NULL;');
        $this->addSql('ALTER TABLE public.players ADD password_hash character varying(255) NOT NULL;');
        $this->addSql('CREATE UNIQUE INDEX uniq_player_game_name ON public.players USING btree (game_id, display_name);');
        $this->addSql('ALTER TABLE public.players DROP CONSTRAINT fk_players_account;');
        $this->addSql('DROP INDEX uniq_player_game_account;');
        $this->addSql('ALTER TABLE public.players DROP COLUMN account_id;');
    }
}
