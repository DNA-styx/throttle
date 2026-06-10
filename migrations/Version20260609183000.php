<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260609183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Correct profile visibility storage table for user profiles.';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist(['user'])) {
            $userColumns = $schemaManager->listTableColumns('user');
            if (!array_key_exists('profile_field_visibility', $userColumns)) {
                $this->addSql("ALTER TABLE user ADD profile_field_visibility LONGTEXT NOT NULL COMMENT '(DC2Type:json)'");
                $this->addSql("UPDATE user SET profile_field_visibility = '{}'");
            }
        }

        if ($schemaManager->tablesExist(['server_owner'])) {
            $serverOwnerColumns = $schemaManager->listTableColumns('server_owner');
            if (array_key_exists('profile_field_visibility', $serverOwnerColumns)) {
                $this->addSql('ALTER TABLE server_owner DROP profile_field_visibility');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist(['server_owner'])) {
            $serverOwnerColumns = $schemaManager->listTableColumns('server_owner');
            if (!array_key_exists('profile_field_visibility', $serverOwnerColumns)) {
                $this->addSql("ALTER TABLE server_owner ADD profile_field_visibility LONGTEXT NOT NULL COMMENT '(DC2Type:json)'");
                $this->addSql("UPDATE server_owner SET profile_field_visibility = '{}'");
            }
        }

        if ($schemaManager->tablesExist(['user'])) {
            $userColumns = $schemaManager->listTableColumns('user');
            if (array_key_exists('profile_field_visibility', $userColumns)) {
                $this->addSql('ALTER TABLE user DROP profile_field_visibility');
            }
        }
    }
}
