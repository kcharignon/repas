<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseCommand;
use Repas\Repas\Domain\Interface\IngredientPhraseCandidates;
use Repas\Repas\Domain\Interface\SpokenPhraseChoiceRepository;
use Repas\Repas\Domain\Model\IngredientPhraseResolution;
use Repas\Repas\Domain\Service\IngredientPhraseResolver;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Shared\Application\Interface\CommandBusInterface;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : quel ingrédient d'un utilisateur désigne une phrase prononcée (ADR 0029
 * décision 3, VOX-102), quels sont les plus proches quand aucun n'est sûr (décision 11, VOX-303), et retenir son choix pour la suite. Pour un
 * appelant qui écrit et en tire un choix dans sa transaction. Des identifiants et du texte seulement, jamais un agrégat.
 *
 * Le choix est le plus probable, pas le seul possible : la confiance dit comment l'annoncer, et l'erreur se répare dans l'application. Il
 * n'est automatique que s'il est sûr : un produit qui n'existe pas n'est pas deviné.
 */
final readonly class IngredientPhraseCommandOHS
{
    public function __construct(
        private IngredientPhraseCandidates $candidates,
        private SpokenPhraseChoiceRepository $memory,
        private IngredientPhraseResolver $resolver,
        private CommandBusInterface $commandBus,
    ) {
    }

    /**
     * Charge les ingrédients de l'utilisateur UNE fois, puis : la mémoire de phrase passe avant tout classement (si l'utilisateur a déjà
     * choisi un ingrédient pour cette phrase, c'est lui) ; sinon le classeur choisit s'il est sûr ; sinon les trois ingrédients les plus
     * proches sont proposés. Quand le classeur hésite entre des ingrédients qui se valent, il ne devine pas : les ex æquo sont rendus (deux
     * ou trois, chacun avec son rayon) pour que l'utilisateur choisisse.
     *
     * @param string $phrase la phrase reconnue (« des tomates », « du pain de mie »), telle quelle
     *
     * @return IngredientPhraseResolution un ingrédient de l'utilisateur, jamais celui d'un autre ; sinon ses propositions (aucune si rien
     *                                    ne ressemble à la phrase ou si le compte n'a aucun ingrédient)
     */
    public function resolveOrSuggest(string $userId, string $phrase): IngredientPhraseResolution
    {
        // Une seule lecture des ingrédients de l'utilisateur, pour choisir comme pour proposer
        $candidates = $this->candidates->forUser($userId);
        if ($candidates === []) {
            return IngredientPhraseResolution::unknown();
        }

        $key = SpokenIngredientRanker::phraseKey($phrase);
        $remembered = $key === '' ? null : $this->memory->rememberedSlug($userId, $key);

        return $this->resolver->resolve($phrase, $candidates, $remembered);
    }

    /**
     * Retient que cette phrase désigne cet ingrédient : la prochaine fois, `resolveOrSuggest()` le choisit tout de suite. N'écrit rien si la
     * phrase n'a aucun mot qui compte.
     *
     * @param string $ingredientSlug un ingrédient de l'utilisateur, ou un ancien slug que ses alias traduisent
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException l'ingrédient n'existe pas ou est à un autre utilisateur (rien n'est écrit)
     */
    public function remember(string $userId, string $phrase, string $ingredientSlug): void
    {
        $this->commandBus->dispatch(new RememberSpokenPhraseCommand($userId, $phrase, $ingredientSlug));
    }
}
