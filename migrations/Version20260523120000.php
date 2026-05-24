<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add local auth fields to users and auth flow tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD login VARCHAR(64) DEFAULT NULL, ADD password_hash VARCHAR(255) DEFAULT NULL, ADD email_verified_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649AA08CB10 ON `user` (login)');

        $this->addSql('CREATE TABLE auth_flow_token (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT DEFAULT NULL,
            purpose VARCHAR(32) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_4F3A8B50A76ED395 (user_id),
            INDEX IDX_4F3A8B50A6A24A64 (purpose),
            INDEX IDX_4F3A8B50A2A1B46F (expires_at),
            INDEX IDX_4F3A8B50B267E7A7 (consumed_at),
            UNIQUE INDEX UNIQ_4F3A8B5098D5854D (token_hash),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE auth_flow_token ADD CONSTRAINT FK_4F3A8B50A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auth_flow_token DROP FOREIGN KEY FK_4F3A8B50A76ED395');
        $this->addSql('DROP TABLE auth_flow_token');
        $this->addSql('DROP INDEX UNIQ_8D93D649AA08CB10 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP login, DROP password_hash, DROP email_verified_at');
    }
}
