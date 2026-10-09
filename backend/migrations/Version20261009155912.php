<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261009155912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE checkpoint (id INT AUTO_INCREMENT NOT NULL, event_seq INT NOT NULL, state JSON NOT NULL, simulation_id INT NOT NULL, UNIQUE INDEX uniq_sim_checkpoint (simulation_id, event_seq), INDEX IDX_F00F7BEFEC09103 (simulation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_event (id INT AUTO_INCREMENT NOT NULL, seq INT NOT NULL, type VARCHAR(48) NOT NULL, payload JSON NOT NULL, verbose TINYINT NOT NULL, simulation_id INT NOT NULL, UNIQUE INDEX uniq_sim_seq (simulation_id, seq), INDEX IDX_99D7328FEC09103 (simulation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE simulation (id INT AUTO_INCREMENT NOT NULL, fork_event_seq INT DEFAULT NULL, status VARCHAR(16) NOT NULL, state JSON NOT NULL, pending JSON DEFAULT NULL, created_at DATETIME NOT NULL, seed INT NOT NULL, parent_id INT DEFAULT NULL, INDEX IDX_CBDA467B727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE checkpoint ADD CONSTRAINT FK_F00F7BEFEC09103 FOREIGN KEY (simulation_id) REFERENCES simulation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_event ADD CONSTRAINT FK_99D7328FEC09103 FOREIGN KEY (simulation_id) REFERENCES simulation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE simulation ADD CONSTRAINT FK_CBDA467B727ACA70 FOREIGN KEY (parent_id) REFERENCES simulation (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE checkpoint DROP FOREIGN KEY FK_F00F7BEFEC09103');
        $this->addSql('ALTER TABLE game_event DROP FOREIGN KEY FK_99D7328FEC09103');
        $this->addSql('ALTER TABLE simulation DROP FOREIGN KEY FK_CBDA467B727ACA70');
        $this->addSql('DROP TABLE checkpoint');
        $this->addSql('DROP TABLE game_event');
        $this->addSql('DROP TABLE simulation');
    }
}
