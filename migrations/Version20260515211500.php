<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515211500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add module identifier keyed source lookup mapping and cache tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS source_lookup_mapping (
            module_basename VARCHAR(255) NOT NULL,
            module_identifier VARCHAR(64) NOT NULL,
            source_type VARCHAR(16) NOT NULL,
            github_repo_url VARCHAR(255) DEFAULT NULL,
            github_ref VARCHAR(255) DEFAULT NULL,
            local_root VARCHAR(1024) DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(module_basename, module_identifier)
        )');

        $this->addSql('CREATE TABLE IF NOT EXISTS source_lookup_cache (
            module_basename VARCHAR(255) NOT NULL,
            module_identifier VARCHAR(64) NOT NULL,
            source_type VARCHAR(16) NOT NULL,
            github_repo_url VARCHAR(255) DEFAULT NULL,
            github_ref VARCHAR(255) DEFAULT NULL,
            resolved_file VARCHAR(1024) NOT NULL,
            line_start INT NOT NULL,
            line_end INT NOT NULL,
            snippet TEXT NOT NULL,
            match_quality VARCHAR(16) NOT NULL,
            github_url VARCHAR(2048) DEFAULT NULL,
            warning TEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(module_basename, module_identifier)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS source_lookup_cache');
        $this->addSql('DROP TABLE IF EXISTS source_lookup_mapping');
    }
}
