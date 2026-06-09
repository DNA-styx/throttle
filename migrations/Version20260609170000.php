<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260609170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-field profile visibility settings for user profiles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user ADD profile_field_visibility LONGTEXT NOT NULL COMMENT '(DC2Type:json)'");
        $this->addSql("UPDATE user SET profile_field_visibility = '{}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP profile_field_visibility');
    }
}
