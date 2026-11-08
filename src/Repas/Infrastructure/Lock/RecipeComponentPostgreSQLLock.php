<?php

namespace Repas\Repas\Infrastructure\Lock;


use Doctrine\DBAL\Connection;
use Repas\Repas\Domain\Interface\RecipeComponentLock;

/**
 * Le verrou des recettes composées par auteur (ADR 0040 décision 27) : un verrou consultatif de TRANSACTION de PostgreSQL (`pg_advisory_xact_lock`),
 * relâché au `COMMIT` ou au `ROLLBACK` de la commande, comme `MarmitonImportPostgreSQLLock`. La clé est le haché du texte `recipe-components:{auteur}` :
 * un auteur ne gêne jamais un autre, et aucun autre verrou de l'application ne partage cette clé.
 */
final readonly class RecipeComponentPostgreSQLLock implements RecipeComponentLock
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function acquire(string $authorId): void
    {
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['recipe-components:' . $authorId])->free();
    }
}
