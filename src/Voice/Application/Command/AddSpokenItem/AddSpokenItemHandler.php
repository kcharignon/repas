<?php

namespace Repas\Voice\Application\Command\AddSpokenItem;


use Repas\Shared\Application\Interface\VoiceShoppingList;
use Repas\Voice\Application\Port\IngredientResolutionCommandPort;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\ResolvedIngredient;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ajoute à la liste de courses l'article que l'utilisateur vient de dire (ADR 0029 décisions 3 à 5 et 11, VOX-204, VOX-303), et dit en une phrase
 * ce qui a été fait :
 *
 * 1. `IngredientResolutionCommandPort` choisit, parmi les ingrédients de l'utilisateur seulement, le plus probable pour la phrase (la mémoire de
 *    phrase d'abord, puis le classement). Rien de plausible : **le produit est inconnu**, rien n'est écrit, et Alexa demande « de quoi tu
 *    parles » (voir plus bas) ;
 * 2. `VoiceShoppingList` ajoute la ligne à la liste active, ou à une liste neuve s'il n'y en a pas (une liste envoyée au drive n'est jamais
 *    modifiée). Une seule écriture.
 *
 * La quantité et l'unité dites (« trois paquets de croquettes ») vont au port avec le slug ; une unité laissée en tête de la phrase par Alexa
 * (« paquets de croquettes » dans le créneau `item`) est retirée de la phrase par `SpokenIngredientNamer::withoutLeadingUnit` (la phrase
 * résolue, retenue et citée est « croquettes »). Si la ligne de l'ingrédient est déjà dans la liste, la réponse dit le total
 * (`VoiceReplies::TOTAL_ADDED`) au lieu de « C'est noté ».
 *
 * Un choix sûr se dit court (« C'est noté : pain. »), un choix probable ou incertain dit aussi le rayon, pour que l'erreur se voie. En séance
 * ouverte la réponse reste une simple affirmation : la séance reste ouverte, sans question ni relance (« Autre chose ? » n'existe plus).
 *
 * Quand l'utilisateur répond à des propositions en nommant autre chose (`AddSpokenItemCommand::rememberAs`, posé seulement si des
 * propositions ont été faites), la phrase d'origine n'est retenue pour cet ingrédient (« C'est noté : pomme de terre. Je m'en
 * souviendrai. ») que si le choix est `SURE` ou `PROBABLE` : un choix `UNSURE` (deux noms qui se touchent) ajoute l'article comme un ajout
 * ordinaire, sans rien retenir ni promettre ; si cette nouvelle phrase est inconnue elle aussi, rien n'est retenu et le dialogue repart
 * pour elle.
 *
 * Produit inconnu : le nom à créer est tiré de la phrase (`SpokenIngredientNamer`) ; `IngredientResolutionCommandPort` a déjà rendu, dans la même lecture, les
 * trois ingrédients les plus proches. Alexa les propose avec l'issue « un nouveau produit » (aucun proche : « Je le crée ? »), la séance reste
 * ouverte même en appel direct, et le dialogue continue dans `AnswerSpokenDialogueHandler` (l'état voyage dans `nextState`). Un nom
 * impossible (sans lettre, trop long) : on redemande.
 *
 * Aucune exception n'est attrapée ici : si le second port échoue (l'ingrédient a disparu entre-temps, panne), la commande est annulée en
 * entier — le bus est transactionnel et une exception attrapée dans une transaction imbriquée la ferait échouer au `commit` — et le webhook
 * répond par la phrase d'échec. Le port de la liste est encore celui de `Shared` ; celui des ingrédients est un port de ce contexte (ADR 0035) : ce contexte n'importe rien de `Repas`.
 */
#[AsMessageHandler]
readonly class AddSpokenItemHandler
{
    public function __construct(
        private IngredientResolutionCommandPort $ingredientResolver,
        private VoiceShoppingList $shoppingList,
        private SpokenIngredientNamer $namer,
    ) {
    }

    public function __invoke(AddSpokenItemCommand $command): AddSpokenItemResult
    {
        // Le nombre (« 10 croquettes ») et l'unité (« paquets de croquettes ») qu'Amazon a laissés en tête du créneau `item` sont retirés de la phrase
        // et retenus comme quantité et unité dites, tant que les créneaux `quantity` et `unit` n'ont rien donné
        ['phrase' => $phrase, 'quantity' => $quantity, 'unitSlug' => $unitSlug] = $this->namer->splitSpoken($command->phrase, $command->quantity, $command->unitSlug);

        $resolution = $this->ingredientResolver->resolveOrSuggest($command->userId, $phrase);
        // Plusieurs ingrédients de l'utilisateur se valent (même nom dans deux rayons) : rien n'est deviné, on demande le rayon
        if ($resolution->ties !== []) {
            return $this->tied($phrase, $quantity, $unitSlug, $resolution->ties);
        }

        $ingredient = $resolution->ingredient;
        if ($ingredient === null) {
            return $this->unknown($phrase, $quantity, $unitSlug, $resolution->suggestions, $command->otherSideTried);
        }

        $added = $this->shoppingList->addItem($command->userId, $ingredient->slug, $quantity, $unitSlug);
        $line = AddedLineFactory::from($added, unitCited: $unitSlug !== null);

        if ($command->rememberAs !== null && $ingredient->confidence !== ResolvedIngredient::UNSURE) {
            // Corrige une proposition : l'article est ajouté PUIS la phrase d'origine est retenue pour lui (le port vérifie l'ingrédient)
            $this->ingredientResolver->remember($command->userId, $command->rememberAs, $ingredient->slug);

            return $this->result(VoiceReplies::remembered($ingredient->name, $added->listCreated, $added->previousListSent, $line), $command, added: true);
        }

        return $this->result(
            VoiceReplies::added($ingredient->name, $ingredient->departmentName, $ingredient->confidence === ResolvedIngredient::SURE, $added->listCreated, $added->previousListSent, $line),
            $command,
            added: true,
        );
    }

    /**
     * Des ingrédients qui se valent : « Tomate : en fruit et légume ou en surgelé ? ». Rien n'est écrit, la séance reste ouverte, et la réponse
     * ajoutera l'article ET retiendra la phrase (`AnswerSpokenDialogueHandler`). Sans « un nouveau produit » : le produit existe.
     *
     * @param list<DialogueOption> $options les ex æquo, chacun avec son rayon
     */
    private function tied(string $phrase, int $quantity, ?string $unitSlug, array $options): AddSpokenItemResult
    {
        $state = SpokenDialogueState::choose($phrase, $options, $this->namer->nameFrom($phrase) ?? $options[0]->name, $quantity, $unitSlug);
        $question = VoiceReplies::question($state);

        return new AddSpokenItemResult($question, keepSessionOpen: true, added: false, nextState: $state, reprompt: $question);
    }

    /**
     * « De quoi tu parles ? » : rien d'écrit, une question, la séance reste ouverte.
     *
     * @param list<DialogueOption> $options les propositions
     */
    private function unknown(string $phrase, int $quantity, ?string $unitSlug, array $options, bool $otherSideTried): AddSpokenItemResult
    {
        $name = $this->namer->nameFrom($phrase);
        if ($name === null) {
            return new AddSpokenItemResult(VoiceReplies::NOT_UNDERSTOOD . ' ' . VoiceReplies::LAUNCH, keepSessionOpen: true, added: false, reprompt: VoiceReplies::LAUNCH);
        }

        $state = SpokenDialogueState::choose($phrase, $options, $name, $quantity, $unitSlug, otherSideTried: $otherSideTried);

        return new AddSpokenItemResult(
            // L'issue « ou une recette ? » tant que les recettes n'ont pas été cherchées pour cette phrase (ADR 0029 décision 13)
            VoiceReplies::unknown($name, array_map(static fn(DialogueOption $option): string => $option->name, $state->options), offerMeal: !$otherSideTried),
            keepSessionOpen: true,
            added: false,
            nextState: $state,
            reprompt: VoiceReplies::question($state),
        );
    }

    private function result(string $speech, AddSpokenItemCommand $command, bool $added): AddSpokenItemResult
    {
        // Une affirmation, jamais de relance : dans une séance déjà ouverte elle reste ouverte sans rien demander, en appel direct elle se ferme
        return new AddSpokenItemResult($speech, keepSessionOpen: $command->sessionIsOpen, added: $added);
    }
}
