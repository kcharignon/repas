<?php

namespace Repas\Repas\Application\Command\MarkIngredientsDistinct;


use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientDistinctRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\IngredientDistinctPair;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * « Pas le même » (ADR 0028 décision h, `docs/ingredients/fusion.md` § 8, ING-006) : l'utilisateur écarte, pour toujours, une paire
 * d'ingrédients que les doublons possibles lui proposaient. Rien d'autre n'est écrit : aucun ingrédient, aucune recette, aucune liste ne
 * change.
 *
 * Les deux ingrédients doivent être à l'utilisateur, sans exception pour un administrateur : l'ingrédient d'un autre ou un slug inconnu
 * est inconnu (`404`, on ne révèle rien), un commun n'est à personne (aussi `404` : jamais proposé, donc jamais écarté). Deux fois le même
 * est une erreur de l'appelant (`422`). Idempotent : la repose répond comme la première fois.
 */
#[AsMessageHandler]
readonly class MarkIngredientsDistinctHandler
{
    public function __construct(
        private IngredientRepository $ingredientRepository,
        private IngredientDistinctRepository $distinctRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws IngredientException
     */
    public function __invoke(MarkIngredientsDistinctCommand $command): void
    {
        if ($command->slug === $command->otherSlug) {
            throw IngredientException::cannotBeDistinctFromItself();
        }

        // Chacun des deux est chargé une seule fois (ADR 0013 règle 7) ; un slug inconnu lève `404`
        $ingredients = $this->ingredientRepository->findBySlugs(Tab::fromArray($command->slug, $command->otherSlug));
        foreach ($ingredients as $ingredient) {
            $ingredient->assertOwnedBy($command->userId);
        }

        $this->distinctRepository->save(IngredientDistinctPair::create($command->userId, $command->slug, $command->otherSlug, $this->clock->now()));
    }
}
