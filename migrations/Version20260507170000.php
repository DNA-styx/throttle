<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add crash processing tables adapted for v4 server owners.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE crash (id CHAR(12) NOT NULL, owner_id INT DEFAULT NULL, server_id INT DEFAULT NULL, timestamp DATETIME NOT NULL, ip VARCHAR(45) NOT NULL, metadata LONGTEXT DEFAULT NULL, cmdline LONGTEXT DEFAULT NULL, thread INT DEFAULT NULL, processed TINYINT(1) NOT NULL DEFAULT 0, failed TINYINT(1) NOT NULL DEFAULT 0, stackhash VARCHAR(80) DEFAULT NULL, lastview DATETIME DEFAULT NULL, crashmodule VARCHAR(255) DEFAULT NULL, crashfunction VARCHAR(255) DEFAULT NULL, INDEX IDX_F94745267E3C61F9 (owner_id), INDEX IDX_F9474527183E88CB (server_id), INDEX IDX_F9474526D06A6DFB (processed), INDEX IDX_F9474526D5EF7FA9 (failed), INDEX IDX_F9474526D7087D28 (timestamp), INDEX IDX_F9474526D1F7E2D6 (lastview), INDEX IDX_F9474526B11213DD (stackhash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE frame (crash CHAR(12) NOT NULL, thread INT NOT NULL, frame INT NOT NULL, module VARCHAR(255) NOT NULL, function VARCHAR(512) NOT NULL, file VARCHAR(1024) NOT NULL, line VARCHAR(255) NOT NULL, frame_offset VARCHAR(255) NOT NULL, rendered VARCHAR(512) DEFAULT NULL, url VARCHAR(1024) DEFAULT NULL, INDEX IDX_DA18B7C5D7E8F0DF (crash), INDEX IDX_DA18B7C5D7E8F0DF31204C83 (crash, thread), PRIMARY KEY(crash, thread, frame)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE module (crash CHAR(12) NOT NULL, name VARCHAR(255) NOT NULL, identifier VARCHAR(255) NOT NULL, processed TINYINT(1) NOT NULL DEFAULT 0, present TINYINT(1) NOT NULL DEFAULT 0, base BIGINT UNSIGNED DEFAULT NULL, INDEX IDX_C242628DD7E8F0DF (crash), INDEX IDX_C242628DDB635FA6D7087D28 (processed, present), INDEX IDX_C242628DD06A6DFB (processed), INDEX IDX_C242628D1E27F6BF (present), PRIMARY KEY(crash, name, identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE notice (id INT AUTO_INCREMENT NOT NULL, severity VARCHAR(32) NOT NULL, text LONGTEXT NOT NULL, rule LONGTEXT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE crashnotice (crash CHAR(12) NOT NULL, notice INT NOT NULL, INDEX IDX_4B22F056D7E8F0DF (crash), INDEX IDX_4B22F0563E7C61F9 (notice), PRIMARY KEY(crash, notice)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE crash ADD CONSTRAINT FK_F94745267E3C61F9 FOREIGN KEY (owner_id) REFERENCES server_owner (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE crash ADD CONSTRAINT FK_F9474527183E88CB FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE frame ADD CONSTRAINT FK_DA18B7C5D7E8F0DF FOREIGN KEY (crash) REFERENCES crash (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE module ADD CONSTRAINT FK_C242628DD7E8F0DF FOREIGN KEY (crash) REFERENCES crash (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crashnotice ADD CONSTRAINT FK_4B22F056D7E8F0DF FOREIGN KEY (crash) REFERENCES crash (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crashnotice ADD CONSTRAINT FK_4B22F0563E7C61F9 FOREIGN KEY (notice) REFERENCES notice (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crash DROP FOREIGN KEY FK_F94745267E3C61F9');
        $this->addSql('ALTER TABLE crash DROP FOREIGN KEY FK_F9474527183E88CB');
        $this->addSql('ALTER TABLE frame DROP FOREIGN KEY FK_DA18B7C5D7E8F0DF');
        $this->addSql('ALTER TABLE module DROP FOREIGN KEY FK_C242628DD7E8F0DF');
        $this->addSql('ALTER TABLE crashnotice DROP FOREIGN KEY FK_4B22F056D7E8F0DF');
        $this->addSql('ALTER TABLE crashnotice DROP FOREIGN KEY FK_4B22F0563E7C61F9');
        $this->addSql('DROP TABLE crashnotice');
        $this->addSql('DROP TABLE notice');
        $this->addSql('DROP TABLE module');
        $this->addSql('DROP TABLE frame');
        $this->addSql('DROP TABLE crash');
    }
}
