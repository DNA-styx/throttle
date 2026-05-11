<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511192600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pinned timestamp to crash signature notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crash_signature_note ADD pinned_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crash_signature_note DROP pinned_at');
    }
}
