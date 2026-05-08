<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508124000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-user upload tokens for symbol and binary uploads.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD upload_token VARCHAR(128) DEFAULT NULL');
        $this->addSql('UPDATE `user` SET upload_token = LOWER(HEX(RANDOM_BYTES(32))) WHERE upload_token IS NULL OR upload_token = \'\'');
        $this->addSql('ALTER TABLE `user` CHANGE upload_token upload_token VARCHAR(128) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649B6A2DD68 ON `user` (upload_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D649B6A2DD68 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP upload_token');
    }
}
