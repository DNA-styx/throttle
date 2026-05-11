<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add votes for crash signature notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE crash_signature_note_vote (id INT AUTO_INCREMENT NOT NULL, note_id INT NOT NULL, voter_id INT NOT NULL, value TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_65508B7A9180C57A (note_id), INDEX IDX_65508B7A6A29F6BF (voter_id), UNIQUE INDEX UNIQ_65508B7A9180C57A6A29F6BF (note_id, voter_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE crash_signature_note_vote ADD CONSTRAINT FK_65508B7A9180C57A FOREIGN KEY (note_id) REFERENCES crash_signature_note (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crash_signature_note_vote ADD CONSTRAINT FK_65508B7A6A29F6BF FOREIGN KEY (voter_id) REFERENCES server_owner (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE crash_signature_note_vote');
    }
}
