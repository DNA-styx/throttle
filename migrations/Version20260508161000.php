<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track per-user upload token usage.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE upload_token_usage (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, created_at DATETIME NOT NULL, endpoint VARCHAR(32) NOT NULL, remote_addr VARCHAR(45) DEFAULT NULL, account VARCHAR(32) DEFAULT NULL, module VARCHAR(255) DEFAULT NULL, identifier VARCHAR(128) DEFAULT NULL, bytes INT DEFAULT 0 NOT NULL, status_code INT DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, INDEX IDX_879F3F917E3C61F9 (owner_id), INDEX IDX_879F3F91B31BAF03 (created_at), INDEX IDX_879F3F91811C9B0D (remote_addr), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE upload_token_usage ADD CONSTRAINT FK_879F3F917E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE upload_token_usage DROP FOREIGN KEY FK_879F3F917E3C61F9');
        $this->addSql('DROP TABLE upload_token_usage');
    }
}
