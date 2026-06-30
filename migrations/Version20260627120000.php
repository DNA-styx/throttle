<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin-sensitive crash privacy flag and global AI analysis cache.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD allow_admin_sensitive_crash_access TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('CREATE TABLE crash_ai_global_analysis (
            id INT AUTO_INCREMENT NOT NULL,
            stackhash VARCHAR(255) NOT NULL,
            status VARCHAR(32) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            model VARCHAR(255) NOT NULL,
            input_hash VARCHAR(64) NOT NULL,
            prompt_snapshot LONGTEXT NOT NULL,
            response_text LONGTEXT DEFAULT NULL,
            response_html LONGTEXT DEFAULT NULL,
            usage_json LONGTEXT DEFAULT NULL,
            error_summary LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_CRASH_AI_GLOBAL_ANALYSIS_STACKHASH (stackhash),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE crash_ai_global_analysis');
        $this->addSql('ALTER TABLE user DROP allow_admin_sensitive_crash_access');
    }
}
