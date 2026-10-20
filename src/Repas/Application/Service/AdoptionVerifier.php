<?php

namespace Repas\Repas\Application\Service;


use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Repas\Domain\Model\AggregateSnapshot;
use Repas\Shared\Application\Dto\DriveChoiceFingerprint;

/**
 * Compare l'empreinte d'un utilisateur avant et après un repointage d'ingrédients (migration d'adoption ING-003 ou son retour arrière,
 * `docs/ingredients/migration.md` § 8) et dit tout ce qui diffère de ce qui était attendu. Pur : deux empreintes dedans, des phrases dehors.
 *
 * Attendu : mêmes lignes (nombre et somme des quantités par table), même contenu de chaque recette et de chaque liste (vu par le NOM et
 * le rayon des ingrédients, pas par le slug), même situation de chaque liste (statut, date d'envoi), mêmes statistiques ; une version qui
 * monte de 1 pour chaque recette et chaque liste qui référençait un ingrédient surveillé, et pour celles-là seulement ; côté drive, mêmes
 * choix, mêmes produits, mêmes contenances et recherches mémorisées, et chaque choix sur l'ingrédient attendu.
 *
 * Les phrases ne contiennent jamais d'identifiant : des tables, des nombres.
 */
final readonly class AdoptionVerifier
{
    /**
     * @param array<string, string> $driveSlugMap l'ingrédient attendu après le repointage, par ingrédient d'avant ; un choix dont le slug
     *        n'y est pas doit garder le sien
     *
     * @return list<string>
     */
    public function discrepancies(
        AdoptionSnapshot $before,
        AdoptionSnapshot $after,
        DriveChoiceFingerprint $driveBefore,
        DriveChoiceFingerprint $driveAfter,
        array $driveSlugMap,
    ): array {
        $problems = [];

        foreach ([AdoptionSnapshot::RECIPE_ROW, AdoptionSnapshot::SHOPPING_LIST_ROW, AdoptionSnapshot::SHOPPING_LIST_INGREDIENT] as $table) {
            if (($before->rowCounts[$table] ?? 0) !== ($after->rowCounts[$table] ?? 0)) {
                $problems[] = sprintf('%s : %d ligne(s) avant, %d après', $table, $before->rowCounts[$table] ?? 0, $after->rowCounts[$table] ?? 0);
            }
            if (($before->quantitySums[$table] ?? '0') !== ($after->quantitySums[$table] ?? '0')) {
                $problems[] = sprintf('%s : la somme des quantités a changé (%s avant, %s après)', $table, $before->quantitySums[$table] ?? '0', $after->quantitySums[$table] ?? '0');
            }
        }

        if (($remaining = $after->watchedRows()) > 0) {
            $problems[] = sprintf("%d ligne(s) référencent encore un ingrédient qui devait être repointé", $remaining);
        }

        array_push($problems, ...$this->aggregates('recettes', $before->recipes, $after->recipes, false));
        array_push($problems, ...$this->aggregates('listes', $before->shoppingLists, $after->shoppingLists, true));

        if ($before->statistics !== $after->statistics) {
            $problems[] = 'les statistiques du compte ont changé';
        }

        array_push($problems, ...$this->drive($driveBefore, $driveAfter, $driveSlugMap));

        return $problems;
    }

    /**
     * @param array<string, AggregateSnapshot> $before
     * @param array<string, AggregateSnapshot> $after
     *
     * @return list<string>
     */
    private function aggregates(string $label, array $before, array $after, bool $withState): array
    {
        $problems = [];
        if (array_keys($before) !== array_keys($after) && array_diff_key($before, $after) + array_diff_key($after, $before) !== []) {
            $problems[] = sprintf('%s : %d avant, %d après (une a disparu ou est apparue)', $label, count($before), count($after));
        }

        $contentChanged = $stateChanged = $versionWrong = 0;
        foreach ($before as $id => $was) {
            $now = $after[$id] ?? null;
            if ($now === null) {
                continue;
            }
            $contentChanged += $was->contentHash === $now->contentHash ? 0 : 1;
            $stateChanged += $withState && $was->stateHash !== $now->stateHash ? 1 : 0;
            $versionWrong += $now->version === $was->version + ($was->referencesWatched ? 1 : 0) ? 0 : 1;
        }
        if ($contentChanged > 0) {
            $problems[] = sprintf('%s : le contenu de %d a changé', $label, $contentChanged);
        }
        if ($stateChanged > 0) {
            $problems[] = sprintf('%s : le statut ou la date d\'envoi de %d a changé', $label, $stateChanged);
        }
        if ($versionWrong > 0) {
            $problems[] = sprintf('%s : la version de %d n\'est pas celle attendue (+1 pour celles dont les lignes ont été repointées, inchangée pour les autres)', $label, $versionWrong);
        }

        return $problems;
    }

    /**
     * @param array<string, string> $slugMap
     *
     * @return list<string>
     */
    private function drive(DriveChoiceFingerprint $before, DriveChoiceFingerprint $after, array $slugMap): array
    {
        $problems = [];
        if ($before->choices !== $after->choices) {
            $problems[] = sprintf('choix du drive : %d avant, %d après', $before->choices, $after->choices);
        }
        if ($before->products !== $after->products) {
            $problems[] = sprintf('produits des choix du drive : %d avant, %d après', $before->products, $after->products);
        }
        if ($before->packSizeSum !== $after->packSizeSum) {
            $problems[] = sprintf('contenances mémorisées du drive : somme %s avant, %s après', $before->packSizeSum, $after->packSizeSum);
        }
        if ($before->contentHash !== $after->contentHash) {
            $problems[] = 'le contenu des choix du drive (enseigne, magasin, recherche mémorisée, produits, rangs, contenances) a changé';
        }

        $wrongSlug = $wrongProducts = 0;
        foreach ($before->ingredientSlugByChoice as $choiceId => $slug) {
            $expected = $slugMap[$slug] ?? $slug;
            $wrongSlug += ($after->ingredientSlugByChoice[$choiceId] ?? null) === $expected ? 0 : 1;
            $wrongProducts += ($after->productCountByChoice[$choiceId] ?? null) === ($before->productCountByChoice[$choiceId] ?? null) ? 0 : 1;
        }
        if ($wrongSlug > 0) {
            $problems[] = sprintf("choix du drive : %d ne portent pas l'ingrédient attendu", $wrongSlug);
        }
        if ($wrongProducts > 0) {
            $problems[] = sprintf('choix du drive : %d ont perdu ou gagné des produits', $wrongProducts);
        }

        return $problems;
    }
}
