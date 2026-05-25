<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user moderation flags for bans and upload token blocking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD is_banned TINYINT(1) NOT NULL DEFAULT 0, ADD uploads_blocked TINYINT(1) NOT NULL DEFAULT 0, ADD banned_at DATETIME DEFAULT NULL, ADD banned_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649D4F0B35B ON `user` (is_banned)');
        $this->addSql('CREATE INDEX IDX_8D93D6491F5B3DA1 ON `user` (uploads_blocked)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_8D93D649D4F0B35B ON `user`');
        $this->addSql('DROP INDEX IDX_8D93D6491F5B3DA1 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP is_banned, DROP uploads_blocked, DROP banned_at, DROP banned_reason');
    }
}
