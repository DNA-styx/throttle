<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508172000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store crash processing logs and token audit events.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE crash_processing_log (id INT AUTO_INCREMENT NOT NULL, crash CHAR(12) NOT NULL, created_at DATETIME NOT NULL, status VARCHAR(32) NOT NULL, duration_ms INT DEFAULT NULL, message VARCHAR(512) DEFAULT NULL, log LONGTEXT DEFAULT NULL, INDEX IDX_D5E3B016D7E8F0DF (crash), INDEX IDX_D5E3B016B31BAF03 (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE crash_processing_log ADD CONSTRAINT FK_D5E3B016D7E8F0DF FOREIGN KEY (crash) REFERENCES crash (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE upload_token_audit (id INT AUTO_INCREMENT NOT NULL, owner_id INT DEFAULT NULL, created_at DATETIME NOT NULL, endpoint VARCHAR(32) NOT NULL, remote_addr VARCHAR(64) DEFAULT NULL, account VARCHAR(32) DEFAULT NULL, module VARCHAR(255) DEFAULT NULL, identifier VARCHAR(128) DEFAULT NULL, bytes INT DEFAULT 0 NOT NULL, status_code INT DEFAULT NULL, result VARCHAR(32) NOT NULL, reason VARCHAR(255) DEFAULT NULL, token_suffix VARCHAR(16) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, INDEX IDX_2AFD81AA7E3C61F9 (owner_id), INDEX IDX_2AFD81AAB31BAF03 (created_at), INDEX IDX_2AFD81AA6C3F90F4 (result), INDEX IDX_2AFD81AA811C9B0D (remote_addr), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE upload_token_audit ADD CONSTRAINT FK_2AFD81AA7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE upload_token_audit DROP FOREIGN KEY FK_2AFD81AA7E3C61F9');
        $this->addSql('DROP TABLE upload_token_audit');

        $this->addSql('ALTER TABLE crash_processing_log DROP FOREIGN KEY FK_D5E3B016D7E8F0DF');
        $this->addSql('DROP TABLE crash_processing_log');
    }
}
