<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add profile privacy setting for user profiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD profile_private TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP profile_private');
    }
}
