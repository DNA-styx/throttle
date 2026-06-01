<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-user Discord webhook notifications for processed crashes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_discord_webhook (
            id INT AUTO_INCREMENT NOT NULL,
            owner_id INT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            display_name VARCHAR(100) NOT NULL,
            webhook_url_encrypted LONGTEXT NOT NULL,
            message_template LONGTEXT DEFAULT NULL,
            console_line_limit INT NOT NULL DEFAULT 20,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX IDX_76F76E487E3C61F9 (owner_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_discord_webhook ADD CONSTRAINT FK_76F76E487E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE crash_discord_webhook_delivery (
            id INT AUTO_INCREMENT NOT NULL,
            crash CHAR(12) NOT NULL,
            webhook_id INT NOT NULL,
            status_code INT NOT NULL,
            delivered_at DATETIME NOT NULL,
            INDEX IDX_BB74B44ED7E8F0DF (crash),
            INDEX IDX_BB74B44E2A3613CE (webhook_id),
            UNIQUE INDEX UNIQ_BB74B44ED7E8F0DF2A3613CE (crash, webhook_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE crash_discord_webhook_delivery ADD CONSTRAINT FK_BB74B44ED7E8F0DF FOREIGN KEY (crash) REFERENCES crash (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crash_discord_webhook_delivery ADD CONSTRAINT FK_BB74B44E2A3613CE FOREIGN KEY (webhook_id) REFERENCES user_discord_webhook (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crash_discord_webhook_delivery DROP FOREIGN KEY FK_BB74B44ED7E8F0DF');
        $this->addSql('ALTER TABLE crash_discord_webhook_delivery DROP FOREIGN KEY FK_BB74B44E2A3613CE');
        $this->addSql('ALTER TABLE user_discord_webhook DROP FOREIGN KEY FK_76F76E487E3C61F9');
        $this->addSql('DROP TABLE crash_discord_webhook_delivery');
        $this->addSql('DROP TABLE user_discord_webhook');
    }
}
