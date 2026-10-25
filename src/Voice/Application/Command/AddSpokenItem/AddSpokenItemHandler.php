<?php

namespace Repas\Voice\Application\Command\AddSpokenItem;


use Repas\Shared\Application\Dto\IngredientSuggestion;
use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Shared\Application\Interface\SpokenIngredientResolver;
use Repas\Shared\Application\Interface\VoiceShoppingList;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ajoute à la liste de courses l'article que l'utilisateur vient de dire (ADR 0029 décisions 3 à 5 et 11, VOX-204, VOX-303), et dit en une phrase
 * ce qui a été fait :
 *
 * 1. `SpokenIngredientResolver` choisit, parmi les ingrédients de l'utilisateur seulement, le plus probable pour la phrase (la mémoire de
 *    phrase d'abord, puis le classement). Rien de plausible : **le produit est inconnu**, rien n'est écrit, et Alexa demande « de quoi tu
 *    parles » (voir plus bas) ;
 * 2. `VoiceShoppingList` ajoute la ligne à la liste active, ou à une liste neuve s'il n'y en a pas (une liste envoyée au drive n'est jamais
 *    modifiée). Une seule écriture.
 *
 * Un choix sûr se dit court (« C'est noté : pain. »), un choix probable ou incertain dit aussi le rayon, pour que l'erreur se voie. En séance
 * ouverte la réponse invite à continuer.
 *
 * Produit inconnu : le nom à créer est tiré de la phrase (`SpokenIngredientNamer`) ; `SpokenIngredientResolver::suggest` donne les trois
 * ingrédients les plus proches. Alexa les propose avec l'issue « un nouveau produit » (aucun proche : « Je le crée ? »), la séance reste
 * ouverte même en appel direct, et le dialogue continue dans `AnswerSpokenDialogueHandler` (l'état voyage dans `nextState`). Un nom
 * impossible (sans lettre, trop long) : on redemande.
 *
 * Aucune exception n'est attrapée ici : si le second port échoue (l'ingrédient a disparu entre-temps, panne), la commande est annulée en
 * entier — le bus est transactionnel et une exception attrapée dans une transaction imbriquée la ferait échouer au `commit` — et le webhook
 * répond par la phrase d'échec. Les ports sont ceux de `Shared` : ce contexte n'importe rien de `Repas`.
 */
#[AsMessageHandler]
readonly class AddSpokenItemHandler
{
    public function __construct(
        private SpokenIngredientResolver $ingredientResolver,
        private VoiceShoppingList $shoppingList,
        private SpokenIngredientNamer $namer,
    ) {
    }

    public function __invoke(AddSpokenItemCommand $command): AddSpokenItemResult
    {
        $ingredient = $this->ingredientResolver->resolve($command->userId, $command->phrase);
        if ($ingredient === null) {
            return $this->unknown($command);
        }

        $added = $this->shoppingList->addItem($command->userId, $ingredient->slug);

        return $this->result(
            VoiceReplies::added($ingredient->name, $ingredient->departmentName, $ingredient->confidence === ResolvedIngredient::SURE, $added->listCreated, $added->previousListSent),
            $command,
            added: true,
        );
    }

    /**
     * « De quoi tu parles ? » : rien d'écrit, une question, la séance reste ouverte.
     */
    private function unknown(AddSpokenItemCommand $command): AddSpokenItemResult
    {
        $name = $this->namer->nameFrom($command->phrase);
        if ($name === null) {
            return new AddSpokenItemResult(VoiceReplies::NOT_UNDERSTOOD . ' ' . VoiceReplies::LAUNCH, keepSessionOpen: true, added: false);
        }

        $options = array_map(
            static fn(IngredientSuggestion $suggestion): DialogueOption => new DialogueOption($suggestion->slug, $suggestion->name),
            $this->ingredientResolver->suggest($command->userId, $command->phrase),
        );
        $state = SpokenDialogueState::choose($command->phrase, $options, $name);

        return new AddSpokenItemResult(
            VoiceReplies::unknown($name, array_map(static fn(DialogueOption $option): string => $option->name, $state->options)),
            keepSessionOpen: true,
            added: false,
            nextState: $state,
            reprompt: VoiceReplies::question($state),
        );
    }

    private function result(string $speech, AddSpokenItemCommand $command, bool $added): AddSpokenItemResult
    {
        return $command->sessionIsOpen
            ? new AddSpokenItemResult(VoiceReplies::withInvitation($speech), keepSessionOpen: true, added: $added)
            : new AddSpokenItemResult($speech, keepSessionOpen: false, added: $added);
    }
}
