<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marks ignored-signature crashes as summary-only and stores the matched signature.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crash ADD signature_ignored TINYINT(1) NOT NULL DEFAULT 0, ADD signature_ignored_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_F947452671665B0A ON crash (signature_ignored)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_F947452671665B0A ON crash');
        $this->addSql('ALTER TABLE crash DROP signature_ignored, DROP signature_ignored_reason');
    }
}
