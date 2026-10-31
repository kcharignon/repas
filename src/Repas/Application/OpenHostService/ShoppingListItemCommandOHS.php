<?php

namespace Repas\Repas\Application\OpenHostService;

use LogicException;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientCommand;
use Repas\Repas\Domain\Model\AddedItem;
use Repas\Repas\Domain\Model\RemovedItem;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : ajouter un article à la liste de courses de l'utilisateur (ADR 0029 décision 5,
 * VOX-103) ou en retirer (décision 12, VOX-304). Chaque appel est une commande sur le bus transactionnel, dont le handler renvoie ce qu'il a fait
 * (ADR 0015 §12) : un échec n'écrit rien.
 *
 * La liste active reçoit la ligne (une unité d'achat par défaut, ou la quantité et l'unité dites), ou s'ajoute à la ligne de cet ingrédient qui
 * y est déjà ; sans liste active (aucune, ou envoyée au drive), une nouvelle liste est créée. Une liste envoyée n'est jamais modifiée.
 */
final readonly class ShoppingListItemCommandOHS
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    /**
     * @param string  $ingredientSlug un ingrédient de l'utilisateur, ou un ancien slug que ses alias traduisent
     * @param int     $quantity       combien, de 1 à 99
     * @param ?string $unitSlug       l'unité dite : utilisée seulement si l'ingrédient la connaît (son unité d'achat ou une de ses unités
     *                                compatibles) ; sinon la ligne garde son unité (`AddedItem::unitSubstituted`)
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException l'ingrédient n'existe pas ou est à un autre utilisateur, ou la quantité est hors de
     *                                                          1 à 99 (rien n'est écrit)
     */
    public function addItem(string $userId, string $ingredientSlug, int $quantity = 1, ?string $unitSlug = null): AddedItem
    {
        $result = $this->commandBus->dispatch(new AddSpokenIngredientCommand($userId, $ingredientSlug, UuidGenerator::new(), $quantity, $unitSlug));
        if (!$result instanceof AddedItem) {
            throw new LogicException('AddSpokenIngredientHandler doit renvoyer un AddedItem.');
        }

        return $result;
    }

    /**
     * Retire `$quantity` de la ligne de cet ingrédient dans la liste ACTIVE (jamais une liste envoyée au drive, jamais de liste créée) : la ligne
     * disparaît quand la quantité retirée est au moins celle qu'elle contient. Même règle d'unité que l'ajout : l'unité dite est convertie dans
     * celle de la ligne, ou retirée telle quelle si aucune conversion n'existe ; une unité que l'ingrédient ne connaît pas est laissée de côté.
     *
     * @param string  $ingredientSlug un ingrédient de l'utilisateur, ou un ancien slug que ses alias traduisent
     * @param int     $quantity       combien, de 1 à 99
     * @param ?string $unitSlug       l'unité dite
     *
     * @return RemovedItem `found` faux (rien n'est écrit) s'il n'y a pas de liste active ou pas de ligne pour cet ingrédient
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException la quantité est hors de 1 à 99 (rien n'est écrit)
     */
    public function removeItem(string $userId, string $ingredientSlug, int $quantity = 1, ?string $unitSlug = null): RemovedItem
    {
        $result = $this->commandBus->dispatch(new RemoveSpokenIngredientCommand($userId, $ingredientSlug, $quantity, $unitSlug));
        if (!$result instanceof RemovedItem) {
            throw new LogicException('RemoveSpokenIngredientHandler doit renvoyer un RemovedItem.');
        }

        return $result;
    }
}
