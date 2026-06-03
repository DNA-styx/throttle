<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260516014500 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Persist source lookup provider traces and local index metadata';
    }

    public function up(Schema $schema): void
    {
		$this->addSql('ALTER TABLE source_lookup_cache ADD COLUMN provider_trace LONGTEXT DEFAULT NULL');
		$this->addSql('ALTER TABLE source_lookup_cache ADD COLUMN rejection_reasons LONGTEXT DEFAULT NULL');
		$this->addSql('CREATE TABLE IF NOT EXISTS source_lookup_local_index (
			source_root_hash CHAR(40) NOT NULL,
			source_root VARCHAR(1024) NOT NULL,
			fingerprint VARCHAR(64) NOT NULL,
			index_type VARCHAR(32) NOT NULL,
			cache_path VARCHAR(2048) NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY(source_root_hash, fingerprint)
		)');
    }

    public function down(Schema $schema): void
    {
		$this->addSql('DROP TABLE IF EXISTS source_lookup_local_index');
		$this->addSql('ALTER TABLE source_lookup_cache DROP COLUMN rejection_reasons');
		$this->addSql('ALTER TABLE source_lookup_cache DROP COLUMN provider_trace');
    }
}
