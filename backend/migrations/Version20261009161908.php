<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261009161908 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE checkpoint CHANGE state state LONGTEXT NOT NULL');
        $this->addSql('ALTER TABLE game_event CHANGE payload payload LONGTEXT NOT NULL');
        $this->addSql('ALTER TABLE simulation CHANGE state state LONGTEXT NOT NULL, CHANGE pending pending LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE checkpoint CHANGE state state JSON NOT NULL');
        $this->addSql('ALTER TABLE game_event CHANGE payload payload JSON NOT NULL');
        $this->addSql('ALTER TABLE simulation CHANGE state state JSON NOT NULL, CHANGE pending pending JSON DEFAULT NULL');
    }
}
