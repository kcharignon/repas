<?php

namespace Repas\Drive\Infrastructure\Acl;

use Repas\Drive\Application\Command\MarkLinesInBasket\NotAddedLine;
use Repas\Drive\Application\Port\BasketLineCommandPort;
use Repas\Repas\Application\OpenHostService\BasketLineCheckCommandOHS;

/**
 * Couche anticorruption (ADR 0035) : écrire ce que le passage a fait des lignes d'une liste envoyée, par l'Open Host Service d'écriture de `Repas`. Les
 * identifiants passent tels quels ; les issues de `Drive` sont publiées en chaînes, que `Repas` range dans ses propres types. Les refus de `Repas` (liste
 * inconnue, à un autre, pas envoyée) remontent tels quels.
 */
final readonly class BasketLineCommandAdapter implements BasketLineCommandPort
{
    public function __construct(
        private BasketLineCheckCommandOHS $basketLines,
    ) {
    }

    public function check(string $userId, string $shoppingListId, array $ingredientSlugs, array $notAdded = []): void
    {
        $this->basketLines->check(
            $userId,
            $shoppingListId,
            $ingredientSlugs,
            array_map(
                static fn(NotAddedLine $line): array => ['ingredientSlug' => $line->ingredientSlug, 'outcome' => $line->outcome->value, 'reason' => $line->reason],
                $notAdded,
            ),
        );
    }
}
