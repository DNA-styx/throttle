<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add share table for legacy sharing flows on v4 user ids.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE share (owner INT NOT NULL, user INT NOT NULL, accepted DATETIME DEFAULT NULL, INDEX IDX_D18B004A7E3C61F9 (owner), INDEX IDX_D18B004A8D93D649 (user), PRIMARY KEY(owner, user)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE share ADD CONSTRAINT FK_D18B004A7E3C61F9 FOREIGN KEY (owner) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE share ADD CONSTRAINT FK_D18B004A8D93D649 FOREIGN KEY (user) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE share DROP FOREIGN KEY FK_D18B004A7E3C61F9');
        $this->addSql('ALTER TABLE share DROP FOREIGN KEY FK_D18B004A8D93D649');
        $this->addSql('DROP TABLE share');
    }
}
