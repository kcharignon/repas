<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Garde de séquence d'ING-014 (amendement du 2026-10-20 de l'ADR 0028) : aucun changement de schéma, aucune écriture. Le code sans
 * ingrédient commun (visibilité « créateur = moi », `Ingredient::load()` qui exige un créateur) rendrait le propriétaire du catalogue
 * aveugle sur une base où il reste des ingrédients sans créateur : tant que la passation n'a pas eu lieu, ce code ne doit pas démarrer.
 *
 * En PRODUCTION, la migration s'arrête (`abortIf`) s'il en reste un ; `deploy.sh` revient alors seul à la version précédente
 * (ADR 0017 règle 5) et la passation (`docs/ingredients/sans-commun.md`) reste à faire. Ailleurs (développement, test), elle avertit
 * seulement : le développement n'est pas bloqué, il recharge ses fixtures (`make fixtures-load-dev` puis `make fixtures-demo`).
 */
final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Garde de séquence : refuse de démarrer en production tant qu\'un ingrédient n\'a pas de créateur (ING-014)';
    }

    public function up(Schema $schema): void
    {
        $withoutCreator = (int) $this->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL');
        $production = self::environment() === 'prod';

        $this->abortIf(
            $production && $withoutCreator > 0,
            sprintf('%d ingrédient(s) sans créateur : lancer la passation des anciens communs avant cette version (docs/ingredients/sans-commun.md).', $withoutCreator),
        );
        $this->warnIf(
            !$production && $withoutCreator > 0,
            sprintf('%d ingrédient(s) sans créateur : recharger les fixtures de développement (make fixtures-load-dev, make fixtures-demo).', $withoutCreator),
        );

        // Aucun changement de schéma : une instruction neutre évite l'avertissement « aucune instruction SQL »
        $this->addSql('SELECT 1');
    }

    public function down(Schema $schema): void
    {
        // Rien n'a été écrit : rien à défaire
    }

    /**
     * L'absence de variable compte comme la production : dans le doute, la garde s'applique.
     */
    private static function environment(): string
    {
        return (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? (getenv('APP_ENV') ?: 'prod'));
    }
}
