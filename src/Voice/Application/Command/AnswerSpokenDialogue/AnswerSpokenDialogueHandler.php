<?php

namespace Repas\Voice\Application\Command\AnswerSpokenDialogue;


use Repas\Shared\Application\Dto\SpokenChoice;
use Repas\Shared\Application\Interface\SpokenIngredientCreator;
use Repas\Shared\Application\Interface\SpokenIngredientResolver;
use Repas\Shared\Application\Interface\VoiceShoppingList;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemCommand;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemHandler;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Model\SpokenAnswer;
use Repas\Voice\Domain\Model\SpokenAnswerKind;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\SpokenAnswerInterpreter;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fait avancer le dialogue d'un produit inconnu d'un pas (ADR 0029 décision 11, VOX-303). Le texte de chaque réponse est dans `VoiceReplies`,
 * la compréhension de la réponse dans `SpokenAnswerInterpreter` (pur) ; ici, seulement ce qui lit ou écrit, par les ports de `Shared` :
 *
 * - une proposition choisie : l'article est ajouté (`VoiceShoppingList`) PUIS la phrase est retenue (`SpokenIngredientResolver::remember`) ;
 *   le slug de l'état n'est jamais cru, les deux ports vérifient que l'ingrédient est à l'utilisateur ;
 * - « un nouveau produit » : la question du rayon, puis de l'unité (les catalogues sont lus à ces étapes, `SpokenIngredientCreator`), puis la
 *   confirmation « Je crée X, rayon, unité. C'est bon ? » ; « oui » : le produit est créé (rayon et unité vérifiés par `Repas`) puis ajouté ;
 * - la création repasse le nom de l'état par `SpokenIngredientNamer` (l'état est une entrée non fiable) : le nom d'un état honnête passe
 *   inchangé, un nom forgé est normalisé, un nom refusé ne crée rien (même réponse qu'une réponse incomprise) ;
 * - « non » : on abandonne, rien n'est écrit ; un autre article : le dialogue est abandonné et la phrase traitée comme un ajout
 *   (`AddSpokenItemHandler`, une seule lecture) ; si des propositions avaient été faites et qu'il désigne un ingrédient de l'utilisateur
 *   avec une confiance sûre ou probable, il corrige la proposition : la phrase d'ORIGINE est retenue pour lui aussi (« patates » puis
 *   « pomme de terre »). Sans proposition faite (« Je le crée ? »), ou si deux noms se touchent, c'est un ajout ordinaire : rien n'est
 *   retenu ni promis (`SpokenDialogueState::rememberablePhrase`). S'il est inconnu, le dialogue repart pour lui, rien n'est retenu ;
 * - « lesquels ? » (ou « aide ») aux étapes du rayon et de l'unité : la liste de tout le catalogue de l'étape, déjà lu pour l'interpréter, puis
 *   la question ; l'état et le compte des réponses incomprises ne bougent pas (aux autres étapes, la question est répétée) ;
 * - une réponse incomprise : on la redemande une fois (`SpokenDialogueState::missed`), la deuxième fois de suite dans la même étape on
 *   abandonne poliment (jamais de boucle sans fin).
 *
 * La séance est toujours ouverte (on est au milieu d'un échange) : chaque réponse finale invite à continuer. Aucune exception n'est attrapée :
 * un échec (slug d'un autre utilisateur, rayon inconnu, produit déjà possédé, panne) annule la commande entière, le bus étant transactionnel,
 * et le webhook répond par la phrase d'échec sans état.
 */
#[AsMessageHandler]
readonly class AnswerSpokenDialogueHandler
{
    public function __construct(
        private SpokenAnswerInterpreter $interpreter,
        private SpokenIngredientCreator $creator,
        private SpokenIngredientResolver $ingredientResolver,
        private VoiceShoppingList $shoppingList,
        private AddSpokenItemHandler $addSpokenItem,
        private SpokenIngredientNamer $namer,
    ) {
    }

    public function __invoke(AnswerSpokenDialogueCommand $command): AddSpokenItemResult
    {
        $state = $command->state;
        $catalogue = $this->catalogueFor($state);
        $answer = $this->interpreter->interpret($command->request, $state, $catalogue);

        return match ($answer->kind) {
            SpokenAnswerKind::LIST_CHOICES => $this->listChoices($state, $catalogue),
            SpokenAnswerKind::NEW_REQUEST => ($this->addSpokenItem)(new AddSpokenItemCommand($command->userId, $answer->phrase ?? '', sessionIsOpen: true, rememberAs: $state->rememberablePhrase())),
            SpokenAnswerKind::PICK_OPTION => $this->pickOption($command, $answer),
            SpokenAnswerKind::START_CREATION => $this->ask($state->askingDepartment()),
            SpokenAnswerKind::PICK_DEPARTMENT => $this->ask($state->withDepartment($answer->option ?? throw new \LogicException('PICK_DEPARTMENT sans rayon.'))),
            SpokenAnswerKind::PICK_UNIT => $this->ask($state->withUnit($answer->option ?? throw new \LogicException('PICK_UNIT sans unité.'))),
            SpokenAnswerKind::CONFIRM => $this->create($command),
            SpokenAnswerKind::ABANDON => $this->finish(VoiceReplies::DECLINED),
            SpokenAnswerKind::REPEAT => $this->ask($state),
            SpokenAnswerKind::WHICH_ONE => $this->miss($state, VoiceReplies::whichAgain($state)),
            SpokenAnswerKind::UNCLEAR => $this->miss($state, VoiceReplies::notUnderstood($state)),
        };
    }

    /**
     * Les rayons ou les unités que l'utilisateur peut citer, lus seulement à l'étape qui les demande.
     *
     * @return list<DialogueOption>
     */
    private function catalogueFor(SpokenDialogueState $state): array
    {
        $choices = match ($state->step) {
            DialogueStep::DEPARTMENT => $this->creator->departments(),
            DialogueStep::UNIT => $this->creator->units(),
            default => [],
        };

        return array_map(static fn(SpokenChoice $choice): DialogueOption => new DialogueOption($choice->slug, $choice->name), $choices);
    }

    private function pickOption(AnswerSpokenDialogueCommand $command, SpokenAnswer $answer): AddSpokenItemResult
    {
        $option = $answer->option ?? throw new \LogicException('PICK_OPTION sans proposition.');

        $added = $this->shoppingList->addItem($command->userId, $option->slug);
        $this->ingredientResolver->remember($command->userId, $command->state->phrase, $option->slug);

        return $this->finish(VoiceReplies::remembered($option->name, $added->listCreated, $added->previousListSent), added: true);
    }

    private function create(AnswerSpokenDialogueCommand $command): AddSpokenItemResult
    {
        $state = $command->state;
        $department = $state->department ?? throw new \LogicException('CONFIRM sans rayon.');
        $unit = $state->unit ?? throw new \LogicException('CONFIRM sans unité.');

        // Le nom de l'état vient d'Alexa comme le reste de l'état (entrée non fiable) : il repasse par la normalisation. Celui d'un état honnête
        // y reste inchangé (il vient déjà de `SpokenIngredientNamer`) ; un nom forgé est normalisé ; un nom que le normalisateur refuse ne crée
        // rien et reçoit la réponse d'une réponse incomprise (on redemande, puis on abandonne).
        $name = $this->namer->nameFrom($state->name);
        if ($name === null) {
            return $this->miss($state, VoiceReplies::notUnderstood($state));
        }

        $created = $this->creator->create($command->userId, $name, $department->slug, $unit->slug);
        $added = $this->shoppingList->addItem($command->userId, $created->slug);

        // Le nom du produit est exact : rien à retenir pour la phrase
        return $this->finish(VoiceReplies::created($created->name, $created->departmentName, $added->listCreated, $added->previousListSent), added: true);
    }

    /** La question de l'étape (nouvelle ou répétée), et le dialogue continue. */
    private function ask(SpokenDialogueState $state): AddSpokenItemResult
    {
        $question = VoiceReplies::question($state);

        return new AddSpokenItemResult($question, keepSessionOpen: true, added: false, nextState: $state, reprompt: $question);
    }

    /**
     * « Lesquels ? » : les rayons ou les unités que l'étape vient de lire, dits en entier, puis la question. L'état ne change pas et la demande
     * ne compte pas comme une réponse incomprise (`misses` reste ce qu'il était) : on peut la refaire autant qu'on veut. Aucune lecture de plus.
     *
     * @param list<DialogueOption> $catalogue
     */
    private function listChoices(SpokenDialogueState $state, array $catalogue): AddSpokenItemResult
    {
        $names = array_map(static fn(DialogueOption $choice): string => $choice->name, $catalogue);
        // Un catalogue vide n'a rien à énumérer : la question suffit
        $speech = VoiceReplies::choices($state, $names) ?? VoiceReplies::question($state);

        return new AddSpokenItemResult($speech, keepSessionOpen: true, added: false, nextState: $state, reprompt: VoiceReplies::question($state));
    }

    /** Une réponse incomprise : on la redemande, et à la deuxième de suite on abandonne. */
    private function miss(SpokenDialogueState $state, string $retry): AddSpokenItemResult
    {
        $missed = $state->missed();
        if ($missed->hasMissedTooMuch()) {
            return $this->finish(VoiceReplies::GIVE_UP);
        }

        return new AddSpokenItemResult($retry, keepSessionOpen: true, added: false, nextState: $missed, reprompt: VoiceReplies::question($missed));
    }

    /** Le dialogue est fini : la phrase, l'invitation à continuer, plus d'état. */
    private function finish(string $speech, bool $added = false): AddSpokenItemResult
    {
        return new AddSpokenItemResult(VoiceReplies::withInvitation($speech), keepSessionOpen: true, added: $added);
    }
}
