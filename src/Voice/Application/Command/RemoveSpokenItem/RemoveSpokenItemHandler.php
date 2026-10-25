<?php

namespace Repas\Voice\Application\Command\RemoveSpokenItem;


use Repas\Shared\Application\Dto\ListedLine;
use Repas\Shared\Application\Interface\SpokenListLookup;
use Repas\Shared\Application\Interface\VoiceShoppingList;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retire de la liste ce que l'utilisateur vient de dire (ADR 0029 décision 12, VOX-304) : l'inverse d'`AddSpokenItemHandler`, par les ports de
 * `Shared` :
 *
 * 1. le nombre et l'unité qu'Amazon a laissés en tête du créneau `item` sont lus comme pour l'ajout (`SpokenIngredientNamer::splitSpoken`) ;
 * 2. `SpokenListLookup` cherche le produit parmi les lignes de la liste ACTIVE (lecture seule) : pas de liste active (aucune, ou partie au
 *    drive : elle n'est jamais modifiée), produit absent ou inconnu de l'utilisateur, une ligne, ou plusieurs lignes qui se valent ;
 * 3. une ligne : `VoiceShoppingList::removeItem` retire la quantité dite (1 par défaut) ; la ligne disparaît quand elle est épuisée ;
 * 4. plusieurs lignes (« tomate » en fruit et légume ET en surgelé, toutes deux dans la liste) : on demande le rayon, par le dialogue de
 *    départage de l'ajout (`SpokenDialogueState::chooseToRemove`, l'état dit « retirer ») ; la réponse est traitée par `AnswerSpokenDialogueHandler`.
 *
 * Jamais de liste créée, jamais de proposition ni de création de produit, jamais de mémoire de phrase : un produit qui n'est pas dans la liste est
 * simplement « pas dans ta liste ». Les textes sont dans `VoiceReplies`. La séance reste ouverte sans rien redemander quand elle l'était (comme un
 * ajout), sinon elle se ferme ; seule la question du rayon la garde ouverte, avec son `reprompt`.
 *
 * Aucune exception n'est attrapée : un échec (panne) annule la commande entière, le bus étant transactionnel, et le webhook répond par la phrase
 * d'échec. Le résultat est un `AddSpokenItemResult` (`added` faux : rien n'a été ajouté).
 */
#[AsMessageHandler]
readonly class RemoveSpokenItemHandler
{
    public function __construct(
        private SpokenListLookup $lookup,
        private VoiceShoppingList $shoppingList,
        private SpokenIngredientNamer $namer,
    ) {
    }

    public function __invoke(RemoveSpokenItemCommand $command): AddSpokenItemResult
    {
        ['phrase' => $phrase, 'quantity' => $quantity, 'unitSlug' => $unitSlug] = $this->namer->splitSpoken($command->phrase, $command->quantity, $command->unitSlug);

        $found = $this->lookup->find($command->userId, $phrase);
        if (!$found->hasList) {
            return $this->said(VoiceReplies::NO_LIST, $command);
        }
        if ($found->lines === []) {
            $name = $found->absentName ?? $this->namer->nameFrom($phrase);

            return $this->said($name === null ? VoiceReplies::NOT_UNDERSTOOD : VoiceReplies::notInList($name), $command);
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

    /** Une affirmation seule : la séance reste ouverte, sans relance, quand elle l'était ; sinon elle se ferme. */
    private function said(string $speech, RemoveSpokenItemCommand $command): AddSpokenItemResult
    {
        return new AddSpokenItemResult($speech, keepSessionOpen: $command->sessionIsOpen, added: false);
    }
}
