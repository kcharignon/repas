<?php

namespace Repas\Voice\Application\Command\RemoveSpokenItem;


use Repas\Voice\Application\Port\ListLineLookupCommandPort;
use Repas\Voice\Application\Port\ShoppingListItemCommandPort;
use Repas\Voice\Domain\Model\ListedLine;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retire de la liste ce que l'utilisateur vient de dire (ADR 0029 décision 12, VOX-304) : l'inverse d'`AddSpokenItemHandler`, par les ports de ce contexte (ADR 0035) :
 *
 * 1. le nombre et l'unité qu'Amazon a laissés en tête du créneau `item` sont lus comme pour l'ajout (`SpokenIngredientNamer::splitSpoken`) ;
 * 2. `ListLineLookupCommandPort` cherche le produit parmi les lignes de la liste ACTIVE (lecture seule) : pas de liste active (aucune : « Tu n'as pas de liste en cours. » ; partie au
 *    drive : « Ta liste est déjà partie au drive. », elle n'est jamais lue ni modifiée), produit absent ou inconnu de l'utilisateur, une ligne, ou plusieurs lignes qui se valent ;
 * 3. une ligne : `ShoppingListItemCommandPort::removeItem` retire la quantité dite (1 par défaut) ; la ligne disparaît quand elle est épuisée ;
 * 4. plusieurs lignes (« tomate » en fruit et légume ET en surgelé, toutes deux dans la liste) : on demande le rayon, par le dialogue de
 *    départage de l'ajout (`SpokenDialogueState::chooseToRemove`, l'état dit « retirer ») ; la réponse est traitée par `AnswerSpokenDialogueHandler`.
 *
 * Jamais de liste créée, jamais de création de produit, jamais de mémoire de phrase. Un produit reconnu qui n'est pas dans la liste est simplement « pas
 * dans ta liste ». Une phrase qui ne désigne AUCUN de ses ingrédients (VOX-306 : « enlève le hachis parmentier ») n'est pas comprise : Alexa propose les
 * lignes de la liste qui lui ressemblent (trois au plus, `SpokenListLookupResult::$suggestions`), puis l'issue « ou un repas ? » tant que les repas n'ont
 * pas été cherchés pour cette phrase (`RemoveSpokenItemCommand::$otherSideTried`) ; rien trouvé de part et d'autre : « Je ne trouve rien qui s'appelle X
 * dans ta liste. ». Le dialogue continue dans `AnswerSpokenDialogueHandler`. Les textes sont dans `VoiceReplies`. La séance reste ouverte sans rien redemander quand elle l'était (comme un
 * ajout), sinon elle se ferme ; seule la question du rayon la garde ouverte, avec son `reprompt`.
 *
 * Aucune exception n'est attrapée : un échec (panne) annule la commande entière, le bus étant transactionnel, et le webhook répond par la phrase
 * d'échec. Le résultat est un `AddSpokenItemResult` (`added` faux : rien n'a été ajouté).
 */
#[AsMessageHandler]
readonly class RemoveSpokenItemHandler
{
    public function __construct(
        private ListLineLookupCommandPort $lookup,
        private ShoppingListItemCommandPort $shoppingList,
        private SpokenIngredientNamer $namer,
    ) {
    }

    public function __invoke(RemoveSpokenItemCommand $command): AddSpokenItemResult
    {
        ['phrase' => $phrase, 'quantity' => $quantity, 'unitSlug' => $unitSlug] = $this->namer->splitSpoken($command->phrase, $command->quantity, $command->unitSlug);

        $found = $this->lookup->find($command->userId, $phrase);
        if (!$found->hasList) {
            return $this->said(VoiceReplies::noActiveList($found->listSent), $command);
        }
        if ($found->lines === []) {
            // Un ingrédient reconnu mais absent de la liste : la réponse est définitive
            if ($found->absentName !== null) {
                return $this->said(VoiceReplies::notInList($found->absentName), $command);
            }

            // La phrase ne désigne aucun ingrédient : on propose ce que la liste contient, et l'issue vers les repas
            $name = $this->namer->nameFrom($phrase);

            return $name === null ? $this->said(VoiceReplies::NOT_UNDERSTOOD, $command) : $this->unknown($command, $name, $found->suggestions, $quantity, $unitSlug);
        }
        if ($found->isTied()) {
            return $this->askWhich($phrase, $found->lines, $quantity, $unitSlug);
        }

        $line = $found->lines[0];
        $removed = $this->shoppingList->removeItem($command->userId, $line->slug, $quantity, $unitSlug);
        if (!$removed->found) {
            // La ligne a disparu entre la lecture et l'écriture : elle n'y est plus
            return $this->said(VoiceReplies::notInList($line->name), $command);
        }

        return $this->said(VoiceReplies::removed($line->name, RemovedLineFactory::from($removed, unitCited: $unitSlug !== null)), $command);
    }

    /**
     * Des lignes qui se valent : « Tomate : en fruit et légume ou en surgelé ? ». Rien n'est retiré, la séance reste ouverte, et la réponse retirera
     * l'article choisi (`AnswerSpokenDialogueHandler`).
     *
     * @param list<ListedLine> $lines
     */
    private function askWhich(string $phrase, array $lines, int $quantity, ?string $unitSlug): AddSpokenItemResult
    {
        $options = array_map(
            static fn(ListedLine $line): DialogueOption => new DialogueOption($line->slug, $line->name, $line->departmentSlug, $line->departmentName),
            $lines,
        );
        $state = SpokenDialogueState::chooseToRemove($phrase, $options, $options[0]->name, $quantity, $unitSlug);
        $question = VoiceReplies::question($state);

        return new AddSpokenItemResult($question, keepSessionOpen: true, added: false, nextState: $state, reprompt: $question);
    }

    /**
     * La phrase n'est pas dans la liste et ne désigne aucun de ses produits : rien n'est retiré, une question, la séance reste ouverte (ADR 0029 décision 12
     * amendée, VOX-306). Sans proposition, et quand les repas ont déjà été cherchés, il n'y a plus rien à demander.
     *
     * @param list<ListedLine> $suggestions
     */
    private function unknown(RemoveSpokenItemCommand $command, string $name, array $suggestions, int $quantity, ?string $unitSlug): AddSpokenItemResult
    {
        if ($suggestions === [] && $command->otherSideTried) {
            return $this->said(VoiceReplies::nothingFoundInList($name), $command);
        }

        $options = array_map(static fn(ListedLine $line): DialogueOption => new DialogueOption($line->slug, $line->name), $suggestions);
        $state = SpokenDialogueState::proposeToRemove($name, $options, $name, $quantity, $unitSlug, $command->otherSideTried);

        return new AddSpokenItemResult(
            VoiceReplies::unknownItemToRemove($name, array_map(static fn(DialogueOption $option): string => $option->name, $state->options), offerMeal: !$command->otherSideTried),
            keepSessionOpen: true,
            added: false,
            nextState: $state,
            reprompt: VoiceReplies::question($state),
        );
    }

    /** Une affirmation seule : la séance reste ouverte, sans relance, quand elle l'était ; sinon elle se ferme. */
    private function said(string $speech, RemoveSpokenItemCommand $command): AddSpokenItemResult
    {
        return new AddSpokenItemResult($speech, keepSessionOpen: $command->sessionIsOpen, added: false);
    }
}
