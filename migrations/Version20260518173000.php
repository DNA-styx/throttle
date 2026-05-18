<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add AI config extra options and persisted crash AI history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_ai_config ADD extra_options_json LONGTEXT DEFAULT NULL');

        $this->addSql('CREATE TABLE crash_ai_analysis_history (
            id INT AUTO_INCREMENT NOT NULL,
            crash CHAR(12) NOT NULL,
            user_id INT NOT NULL,
            provider VARCHAR(32) NOT NULL,
            model VARCHAR(255) NOT NULL,
            context VARCHAR(32) NOT NULL,
            prompt LONGTEXT NOT NULL,
            request_sections_json LONGTEXT NOT NULL,
            response_text LONGTEXT NOT NULL,
            usage_json LONGTEXT DEFAULT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX IDX_D698FE4DD7E8F0DF (crash),
            INDEX IDX_D698FE4DA76ED395 (user_id),
            INDEX IDX_D698FE4D5526C5C5 (is_public),
            INDEX IDX_D698FE4DB31BAF03 (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE crash_ai_analysis_history ADD CONSTRAINT FK_D698FE4DD7E8F0DF FOREIGN KEY (crash) REFERENCES crash (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crash_ai_analysis_history ADD CONSTRAINT FK_D698FE4DA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crash_ai_analysis_history DROP FOREIGN KEY FK_D698FE4DD7E8F0DF');
        $this->addSql('ALTER TABLE crash_ai_analysis_history DROP FOREIGN KEY FK_D698FE4DA76ED395');
        $this->addSql('DROP TABLE crash_ai_analysis_history');
        $this->addSql('ALTER TABLE user_ai_config DROP extra_options_json');
    }
}
