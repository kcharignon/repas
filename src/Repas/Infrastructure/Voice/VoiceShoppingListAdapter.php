<?php

namespace Repas\Repas\Infrastructure\Voice;

use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Shared\Application\Interface\AddedItem;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\VoiceShoppingList;
use Repas\Shared\Domain\Tool\UuidGenerator;

/**
 * Adaptateur du port `VoiceShoppingList` : une commande sur le bus transactionnel, dont le handler renvoie le résultat (ADR 0015 §12).
 */
final readonly class VoiceShoppingListAdapter implements VoiceShoppingList
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function addItem(string $userId, string $ingredientSlug, int $quantity = 1, ?string $unitSlug = null): AddedItem
    {
        $result = $this->commandBus->dispatch(new AddSpokenIngredientCommand($userId, $ingredientSlug, UuidGenerator::new(), $quantity, $unitSlug));
        if (!$result instanceof AddedItem) {
            throw new \LogicException('AddSpokenIngredientHandler doit renvoyer un AddedItem.');
        }

        return $result;
    }
}
