<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601231500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stack trace line limit to user Discord webhooks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_discord_webhook ADD stack_trace_line_limit INT NOT NULL DEFAULT 18');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_discord_webhook DROP stack_trace_line_limit');
    }
}
