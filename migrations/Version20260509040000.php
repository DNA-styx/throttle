<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add crash signature notes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE crash_signature_note (id INT AUTO_INCREMENT NOT NULL, stackhash VARCHAR(80) NOT NULL, author_id INT NOT NULL, title VARCHAR(100) NOT NULL, body VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_A5176024B11213DD (stackhash), INDEX IDX_A5176024F675F31B (author_id), INDEX IDX_A5176024B31BAF03 (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE crash_signature_note ADD CONSTRAINT FK_A5176024F675F31B FOREIGN KEY (author_id) REFERENCES server_owner (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crash_signature_note DROP FOREIGN KEY FK_A5176024F675F31B');
        $this->addSql('DROP TABLE crash_signature_note');
    }
}
