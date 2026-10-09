<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261009150703 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE checkpoint (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, event_seq INTEGER NOT NULL, state CLOB NOT NULL, simulation_id INTEGER NOT NULL, CONSTRAINT FK_F00F7BEFEC09103 FOREIGN KEY (simulation_id) REFERENCES simulation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_sim_checkpoint ON checkpoint (simulation_id, event_seq)');
        $this->addSql('CREATE INDEX IDX_F00F7BEFEC09103 ON checkpoint (simulation_id)');
        $this->addSql('CREATE TABLE game_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, seq INTEGER NOT NULL, type VARCHAR(48) NOT NULL, payload CLOB NOT NULL, verbose BOOLEAN NOT NULL, simulation_id INTEGER NOT NULL, CONSTRAINT FK_99D7328FEC09103 FOREIGN KEY (simulation_id) REFERENCES simulation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_sim_seq ON game_event (simulation_id, seq)');
        $this->addSql('CREATE INDEX IDX_99D7328FEC09103 ON game_event (simulation_id)');
        $this->addSql('CREATE TABLE simulation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, fork_event_seq INTEGER DEFAULT NULL, status VARCHAR(16) NOT NULL, state CLOB NOT NULL, pending CLOB DEFAULT NULL, created_at DATETIME NOT NULL, seed INTEGER NOT NULL, parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_CBDA467B727ACA70 FOREIGN KEY (parent_id) REFERENCES simulation (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_CBDA467B727ACA70 ON simulation (parent_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE checkpoint');
        $this->addSql('DROP TABLE game_event');
        $this->addSql('DROP TABLE simulation');
    }
}
