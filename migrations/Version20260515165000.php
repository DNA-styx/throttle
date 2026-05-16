<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515165000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add module source mappings for raw native source lookup';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE module_source_mapping (
            module VARCHAR(255) NOT NULL,
            source_type VARCHAR(16) NOT NULL,
            github_repo_url VARCHAR(255) DEFAULT NULL,
            github_ref VARCHAR(255) DEFAULT NULL,
            local_root VARCHAR(1024) DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(module)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE module_source_mapping');
    }
}
