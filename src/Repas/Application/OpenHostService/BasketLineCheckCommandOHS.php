<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Application\Command\CheckRowsAddedToDriveBasket\CheckRowsAddedToDriveBasketCommand;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\DriveLineAttempt;
use Repas\Repas\Domain\Model\DriveLineOutcome;
use Repas\Shared\Application\Interface\CommandBusInterface;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : écrire, dans la liste ENVOYÉE au drive de l'utilisateur, ce que le passage a fait des
 * lignes (DRV-745, ADR 0041) : cocher celles que le drive a mises au panier, garder l'issue des autres. C'est la commande `CheckRowsAddedToDriveBasket`
 * sur le bus transactionnel, sans règle de plus.
 */
final readonly class BasketLineCheckCommandOHS
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    /**
     * Idempotent ; un ingrédient absent de la liste est ignoré, jamais une ligne n'est décochée. Un ingrédient qui est à la fois dans `$ingredientSlugs`
     * et dans `$notAdded` est au panier (le premier l'emporte).
     *
     * @param list<string> $ingredientSlugs des ingrédients de l'utilisateur (déjà traduits par ses alias) dont l'article est au panier : leurs lignes sont cochées
     * @param list<array{ingredientSlug: string, outcome: string, reason: ?string}> $notAdded les lignes que le passage n'a pas mises au panier, avec leur issue
     *                                                                                        (`NOT_IN_CART`, `UNAVAILABLE`, `SKIPPED`, `HAVE_AT_HOME`, `FAILED`) et, connue, leur raison
     *
     * @throws ShoppingListException liste inconnue (404), à un autre utilisateur (403), ou pas envoyée au drive (409) : rien n'est écrit
     */
    public function check(string $userId, string $shoppingListId, array $ingredientSlugs, array $notAdded = []): void
    {
        $attempts = [];
        foreach ($ingredientSlugs as $slug) {
            $attempts[$slug] = new DriveLineAttempt($slug, DriveLineOutcome::IN_CART);
        }
        foreach ($notAdded as $line) {
            $attempts[$line['ingredientSlug']] ??= new DriveLineAttempt($line['ingredientSlug'], DriveLineOutcome::from($line['outcome']), $line['reason']);
        }

        $this->commandBus->dispatch(new CheckRowsAddedToDriveBasketCommand($userId, $shoppingListId, array_values($attempts)));
    }
}
