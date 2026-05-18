<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-user AI analysis configs and crash AI audit log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_ai_config (
            id INT AUTO_INCREMENT NOT NULL,
            owner_id INT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            display_name VARCHAR(100) NOT NULL,
            provider VARCHAR(32) NOT NULL,
            model VARCHAR(255) NOT NULL,
            api_key_encrypted LONGTEXT NOT NULL,
            base_url VARCHAR(1024) DEFAULT NULL,
            temperature NUMERIC(4, 2) DEFAULT NULL,
            max_tokens INT DEFAULT NULL,
            default_prompt LONGTEXT NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX IDX_85C2B9EE7E3C61F9 (owner_id),
            INDEX IDX_85C2B9EE5526C5C5 (is_default),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_ai_config ADD CONSTRAINT FK_85C2B9EE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE crash_ai_analysis_audit (
            id INT AUTO_INCREMENT NOT NULL,
            crash CHAR(12) NOT NULL,
            user_id INT NOT NULL,
            provider VARCHAR(32) NOT NULL,
            model VARCHAR(255) NOT NULL,
            context VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL,
            duration_ms INT NOT NULL,
            request_bytes INT NOT NULL,
            error_summary VARCHAR(1024) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_5407D4B4D7E8F0DF (crash),
            INDEX IDX_5407D4B4A76ED395 (user_id),
            INDEX IDX_5407D4B4B31BAF03 (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE crash_ai_analysis_audit ADD CONSTRAINT FK_5407D4B4D7E8F0DF FOREIGN KEY (crash) REFERENCES crash (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crash_ai_analysis_audit ADD CONSTRAINT FK_5407D4B4A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crash_ai_analysis_audit DROP FOREIGN KEY FK_5407D4B4D7E8F0DF');
        $this->addSql('ALTER TABLE crash_ai_analysis_audit DROP FOREIGN KEY FK_5407D4B4A76ED395');
        $this->addSql('ALTER TABLE user_ai_config DROP FOREIGN KEY FK_85C2B9EE7E3C61F9');
        $this->addSql('DROP TABLE crash_ai_analysis_audit');
        $this->addSql('DROP TABLE user_ai_config');
    }
}
