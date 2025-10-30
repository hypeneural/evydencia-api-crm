<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20251206000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria estrutura inicial do módulo de escolas, eventos e sincronização offline.';
    }

    public function up(Schema $schema): void
    {
        $file = __DIR__ . '/20251206_create_escolas_module.sql';
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Nao foi possivel ler o arquivo de migration: %s', $file));
        }

        $statements = preg_split('/;\s*\R/', $contents);
        if ($statements === false) {
            throw new RuntimeException('Falha ao fracionar a migration SQL.');
        }

        foreach ($statements as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '') {
                continue;
            }

            $this->addSql($trimmed);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS v_escolas_panfletagem_recente');
        $this->addSql('DROP TABLE IF EXISTS sync_mutations');
        $this->addSql('DROP TABLE IF EXISTS evento_logs');
        $this->addSql('DROP TABLE IF EXISTS eventos');
        $this->addSql('DROP TABLE IF EXISTS escola_panfletagem_logs');
        $this->addSql('DROP TABLE IF EXISTS escola_observacao_logs');
        $this->addSql('DROP TABLE IF EXISTS escola_observacoes');
        $this->addSql('DROP TABLE IF EXISTS escola_etapas');
        $this->addSql('DROP TABLE IF EXISTS escola_periodos');
        $this->addSql('DROP TABLE IF EXISTS escolas');
        $this->addSql('DROP TABLE IF EXISTS bairros');
        $this->addSql('DROP TABLE IF EXISTS cidades');
        $this->addSql('DROP TABLE IF EXISTS usuarios');
    }
}
