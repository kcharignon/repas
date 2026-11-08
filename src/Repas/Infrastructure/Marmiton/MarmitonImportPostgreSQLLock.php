<?php

namespace Repas\Repas\Infrastructure\Marmiton;


use Doctrine\DBAL\Connection;
use Repas\Repas\Domain\Interface\MarmitonImportLock;

/**
 * Le verrou d'un import Marmiton par identifiant de recette (ADR 0037 décision 14) : un verrou consultatif de TRANSACTION de PostgreSQL
 * (`pg_advisory_xact_lock`), relâché au `COMMIT` ou au `ROLLBACK` de la commande d'import, comme `IngredientAdoptionBulkPostgreSQLRepository::lock()`.
 * La clé est le haché du texte `marmiton-import:{identifiant}` : un identifiant ne gêne jamais un autre, et aucun autre verrou de l'application
 * ne partage cette clé.
 */
final readonly class MarmitonImportPostgreSQLLock implements MarmitonImportLock
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function acquire(string $marmitonId): void
    {
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['marmiton-import:' . $marmitonId])->free();
    }
}
