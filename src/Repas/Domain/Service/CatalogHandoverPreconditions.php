<?php

namespace Repas\Repas\Domain\Service;


use Repas\Repas\Domain\Model\CatalogHandoverState;
use Repas\Repas\Domain\Model\HandoverPrecondition;

/**
 * Les préconditions de la passation du catalogue (ING-009, `docs/ingredients/sans-commun.md` § 6), jugées sur un état lu : pur, aucune
 * dépendance. La commande les relit DANS la transaction de la passation ; le rapport à blanc les évalue sur l'état actuel et sait lesquelles
 * l'amorçage (phase 2) lèvera.
 *
 *  P1  le propriétaire du catalogue est résolu, ACTIF et administrateur (il est UN compte : l'adresse est unique, le port le résout) ;
 *  P2  aucune ligne (recette, liste, ingrédient de liste, choix du drive) d'un compte autre que le propriétaire, QUEL QUE SOIT SON STATUT, ne
 *      désigne un ingrédient sans créateur ;
 *  P3  tout compte ACTIF autre que le propriétaire est amorcé et a au moins un ingrédient ;
 *  P4  l'amorçage à l'activation est ALLUMÉ (sinon un compte activé après la passation n'aurait rien) ;
 *  P5  les ingrédients sans créateur sont EXACTEMENT ceux du fichier de classement, sans origine ni forme de slug de compte ;
 *  P6  aucune référence orpheline ;
 *  P7  aucune ligne du propriétaire ne désigne l'ingrédient personnel d'un autre compte (sinon l'invariant d'après la passation serait rouge
 *      sans que la passation y soit pour rien).
 */
final readonly class CatalogHandoverPreconditions
{
    /**
     * @param array<string, bool> $classification
     * @param list<string> $accountsToSeed les comptes que la phase 2 servirait : `P2` et `P3` sont aussi jugées « une fois l'amorçage fait »
     *
     * @return list<HandoverPrecondition> P1 à P7, dans l'ordre
     */
    public function evaluate(CatalogHandoverState $state, array $classification, bool $seedingEnabled, array $accountsToSeed = []): array
    {
        $toSeed = array_fill_keys($accountsToSeed, true);

        // P1
        $owner = match (true) {
            $state->ownerId === null => "le propriétaire du catalogue n'existe pas",
            $state->ownerStatus !== 'ACTIVE' => "le propriétaire du catalogue n'est pas actif",
            !$state->ownerIsAdministrator => "le propriétaire du catalogue n'est pas administrateur",
            default => '',
        };

        // P2 : les lignes des autres comptes sur un ingrédient sans créateur ; l'amorçage repointe celles des comptes qu'il sert
        $foreign = $state->foreignLines();
        $foreignAfterSeeding = [];
        foreach ($state->foreignLinesByAccount as $accountId => $tables) {
            if (!isset($toSeed[$accountId])) {
                $foreignAfterSeeding[] = $accountId;
            }
        }

        // P3
        $notSeeded = $state->activeNotSeeded;
        $withoutIngredient = $state->activeWithoutIngredient;
        $notSeededAfter = array_values(array_filter($notSeeded, static fn(string $id): bool => !isset($toSeed[$id])));
        $withoutIngredientAfter = array_values(array_filter($withoutIngredient, static fn(string $id): bool => !isset($toSeed[$id])));

        // P5
        $expected = array_keys($classification);
        $unknown = count(array_diff($state->slugsWithoutCreator, $expected));
        $missing = count(array_diff($expected, $state->slugsWithoutCreator));
        $p5 = [];
        if ($unknown > 0) {
            $p5[] = sprintf("%d ingrédient(s) sans créateur ne sont pas dans le fichier", $unknown);
        }
        if ($missing > 0) {
            $p5[] = sprintf("%d ingrédient(s) du fichier ne sont pas sans créateur", $missing);
        }
        if ($state->withoutCreatorWithOrigin > 0) {
            $p5[] = sprintf("%d ont une origine (adopted_from)", $state->withoutCreatorWithOrigin);
        }
        if ($state->withoutCreatorAccountFormat > 0) {
            $p5[] = sprintf("%d ont un slug de la forme d'un slug de compte", $state->withoutCreatorAccountFormat);
        }

        $p3 = [];
        if ($notSeeded !== []) {
            $p3[] = sprintf('%d compte(s) actif(s) sans amorçage', count($notSeeded));
        }
        if ($withoutIngredient !== []) {
            $p3[] = sprintf('%d compte(s) actif(s) sans aucun ingrédient', count($withoutIngredient));
        }

        return [
            new HandoverPrecondition('P1', 'le propriétaire du catalogue existe, est actif et administrateur', $owner === '', $owner, $owner === ''),
            new HandoverPrecondition(
                'P2',
                "aucune ligne d'un autre compte ne désigne un ingrédient sans créateur",
                $foreign === [],
                self::describeLines($foreign) . sprintf(' (dans %d compte(s))', count($state->foreignLinesByAccount)),
                $foreignAfterSeeding === [],
            ),
            new HandoverPrecondition('P3', 'tout compte actif, hors propriétaire, est amorcé et a des ingrédients', $p3 === [], implode(', ', $p3), $notSeededAfter === [] && $withoutIngredientAfter === []),
            new HandoverPrecondition('P4', "l'amorçage à l'activation est allumé (INGREDIENT_SEEDING_ENABLED)", $seedingEnabled, 'il est coupé', $seedingEnabled),
            new HandoverPrecondition('P5', 'les ingrédients sans créateur sont exactement ceux du fichier de classement', $p5 === [], implode(', ', $p5), $p5 === []),
            new HandoverPrecondition('P6', 'aucune référence orpheline', $state->orphans === [], self::describeLines($state->orphans), $state->orphans === []),
            new HandoverPrecondition(
                'P7',
                "aucune ligne du propriétaire ne désigne l'ingrédient personnel d'un autre compte",
                $state->ownerLinesOnOthers === [],
                self::describeLines($state->ownerLinesOnOthers),
                $state->ownerLinesOnOthers === [],
            ),
        ];
    }

    /**
     * Les phrases des préconditions qui manquent (vide : la passation peut partir).
     *
     * @param array<string, bool> $classification
     *
     * @return list<string>
     */
    public function unmet(CatalogHandoverState $state, array $classification, bool $seedingEnabled): array
    {
        return array_values(array_map(
            static fn(HandoverPrecondition $precondition): string => $precondition->describe(),
            array_filter($this->evaluate($state, $classification, $seedingEnabled), static fn(HandoverPrecondition $precondition): bool => !$precondition->met),
        ));
    }

    /**
     * @param array<string, int> $byTable
     */
    private static function describeLines(array $byTable): string
    {
        return implode(', ', array_map(static fn(string $table, int $count): string => sprintf('%s : %d', $table, $count), array_keys($byTable), $byTable));
    }
}
