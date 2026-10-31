<?php

namespace Repas\Voice\Application\Command\AnswerSpokenDialogue;


use Repas\Voice\Application\Port\ListLineLookupCommandPort;
use Repas\Voice\Application\Port\ShoppingListItemCommandPort;
use Repas\Voice\Application\Command\AddSpokenItem\AddedLineFactory;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemCommand;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemHandler;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\AddSpokenMeal\AddSpokenMealCommand;
use Repas\Voice\Application\Command\AddSpokenMeal\AddSpokenMealHandler;
use Repas\Voice\Application\Command\RemoveSpokenItem\RemovedLineFactory;
use Repas\Voice\Application\Command\RemoveSpokenItem\RemoveSpokenItemCommand;
use Repas\Voice\Application\Command\RemoveSpokenItem\RemoveSpokenItemHandler;
use Repas\Voice\Application\Command\RemoveSpokenMeal\RemoveSpokenMealCommand;
use Repas\Voice\Application\Command\RemoveSpokenMeal\RemoveSpokenMealHandler;
use Repas\Voice\Application\Port\IngredientCreationCommandPort;
use Repas\Voice\Application\Port\IngredientResolutionCommandPort;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Model\SpokenAnswer;
use Repas\Voice\Domain\Model\SpokenAnswerKind;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\SpokenAnswerInterpreter;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;
use Repas\Voice\Domain\Service\SpokenListAnswerer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fait avancer le dialogue d'un produit inconnu d'un pas (ADR 0029 décision 11, VOX-303). Le texte de chaque réponse est dans `VoiceReplies`,
 * la compréhension de la réponse dans `SpokenAnswerInterpreter` (pur) ; ici, seulement ce qui lit ou écrit, par les ports :
 *
 * - une proposition choisie : l'article est ajouté (`ShoppingListItemCommandPort`) PUIS la phrase est retenue (`IngredientResolutionCommandPort::remember`) ;
 *   le slug de l'état n'est jamais cru, les deux ports vérifient que l'ingrédient est à l'utilisateur ;
 * - un départage de lignes à RETIRER (`SpokenDialogueState::isRemoval`, ADR 0029 décision 12 : « tomate » en fruit et légume ET en surgelé, toutes
 *   deux dans la liste) : la ligne choisie perd la quantité dite, rien n'est retenu pour la phrase ; « non » : « D'accord, je ne retire rien. » ;
 * - « un nouveau produit » : la question du rayon, puis de l'unité (les catalogues sont lus à ces étapes, `IngredientCreationCommandPort`), puis la
 *   confirmation « Je crée X, rayon, unité. C'est bon ? » ; « oui » : le produit est créé (rayon et unité vérifiés par `Repas`) puis ajouté ;
 * - la création repasse le nom de l'état par `SpokenIngredientNamer` (l'état est une entrée non fiable) : le nom d'un état honnête passe
 *   inchangé, un nom forgé est normalisé, un nom refusé ne crée rien (même réponse qu'une réponse incomprise) ;
 * - « enlève … » ou « est-ce qu'il y a … ? » dit au milieu d'une question : le dialogue est abandonné, la phrase est une nouvelle demande de retrait
 *   (`RemoveSpokenItemHandler`) ou de consultation (`SpokenListAnswerer`), dite comme si elle était seule (ADR 0029 décision 12) ;
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
 * Les repas (ADR 0029 décision 13, VOX-305) suivent les mêmes règles, avec `SpokenTarget::MEAL` : une proposition choisie planifie le repas ET retient la
 * phrase (`AddSpokenMealHandler::plan`) ; « une recette » à la question d'un produit inconnu (`SWITCH_TO_MEAL`) cherche la phrase d'origine dans les
 * recettes, « un produit » à la question d'une recette inconnue (`SWITCH_TO_ITEM`) la cherche dans les produits, chaque côté une seule fois (l'état retient
 * l'autre côté essayé) ; l'étape `servings` d'un repas déjà planifié change son nombre de personnes (`AddSpokenMealHandler::changeServings`, le nouveau
 * TOTAL ; le même nombre, « non » ou « rien » ne change rien) ; « planifie le repas … » au milieu d'une question est une nouvelle demande de repas
 * (`NEW_MEAL`) ; un produit dit à la place des recettes proposées ne retient rien pour une recette, et inversement.
 *
 * Les retraits (ADR 0029 décision 12 amendée, VOX-306) : quand « enlève … » n'est pas dans la liste, l'état porte `SpokenAction::REMOVE` et des propositions TIRÉES
 * DE LA LISTE (les trois lignes ou les trois repas planifiés les plus proches, ou aucune) ; une proposition choisie est retirée (le produit : la quantité
 * dite ; le repas : le nombre de personnes dit, ou le repas entier), jamais ajoutée ni retenue pour la phrase. « Un repas » / « un produit » aiguille vers l'autre
 * recherche, chaque côté une seule fois (`SWITCH_TO_MEAL` / `SWITCH_TO_ITEM` : l'état dit s'il s'agit d'un retrait) ; « enlève le repas … » dit au milieu d'une
 * question est une nouvelle demande de retrait de repas (`NEW_MEAL_REMOVAL`).
 *
 * La quantité et l'unité dites au départ (« trois croquettes ») voyagent dans l'état (`SpokenDialogueState::quantity`) et s'appliquent à l'article
 * qui aboutit, ajouté ou créé ; un autre article dit à la place porte les siennes (`AlexaRequest`).
 *
 * La séance reste ouverte tant qu'une question est posée ; une réponse finale (article ajouté, produit créé, refus, abandon) est une simple
 * affirmation, sans relance, et ferme la séance : le dialogue avait été ouvert pour une demande, elle est traitée. Aucune exception n'est attrapée :
 * un échec (slug d'un autre utilisateur, rayon inconnu, produit déjà possédé, panne) annule la commande entière, le bus étant transactionnel,
 * et le webhook répond par la phrase d'échec sans état.
 */
#[AsMessageHandler]
readonly class AnswerSpokenDialogueHandler
{
    public function __construct(
        private SpokenAnswerInterpreter $interpreter,
        private IngredientCreationCommandPort $creator,
        private IngredientResolutionCommandPort $ingredientResolver,
        private ShoppingListItemCommandPort $shoppingList,
        private AddSpokenItemHandler $addSpokenItem,
        private SpokenIngredientNamer $namer,
        private RemoveSpokenItemHandler $removeSpokenItem,
        private ListLineLookupCommandPort $listLookup,
        private SpokenListAnswerer $listAnswerer,
        private AddSpokenMealHandler $addSpokenMeal,
        private RemoveSpokenMealHandler $removeSpokenMeal,
    ) {
    }

    public function __invoke(AnswerSpokenDialogueCommand $command): AddSpokenItemResult
    {
        $state = $command->state;
        $catalogue = $this->catalogueFor($state);
        $answer = $this->interpreter->interpret($command->request, $state, $catalogue);

        return match ($answer->kind) {
            SpokenAnswerKind::LIST_CHOICES => $this->listChoices($state, $catalogue),
            SpokenAnswerKind::NEW_REQUEST => ($this->addSpokenItem)(new AddSpokenItemCommand(
                $command->userId,
                $answer->phrase ?? '',
                sessionIsOpen: false,
                // Un produit dit à la place de RECETTES proposées ne retient rien pour une recette
                rememberAs: $state->isMeal() ? null : $state->rememberablePhrase(),
                quantity: $command->request->quantity ?? 1,
                unitSlug: $command->request->unitSlug(),
            )),
            // « Enlève … » ou « est-ce qu'il y a … ? » dit au milieu d'une question (ADR 0029 décision 12) : le dialogue est abandonné, c'est une nouvelle
            // demande, traitée comme si elle était dite seule, et la séance se ferme
            SpokenAnswerKind::NEW_REMOVAL => ($this->removeSpokenItem)(new RemoveSpokenItemCommand(
                $command->userId,
                $answer->phrase ?? '',
                sessionIsOpen: false,
                quantity: $command->request->quantity ?? 1,
                unitSlug: $command->request->unitSlug(),
            )),
            SpokenAnswerKind::NEW_CHECK => $this->finish($this->consult($command->userId, $answer->phrase ?? '')),
            // « Planifie le repas … » dit au milieu d'une question (ADR 0029 décision 13) : une nouvelle demande de repas, qui corrige les recettes proposées
            // si elles l'étaient, jamais les produits proposés
            SpokenAnswerKind::NEW_MEAL => ($this->addSpokenMeal)(new AddSpokenMealCommand(
                $command->userId,
                $answer->phrase ?? '',
                sessionIsOpen: false,
                servings: $answer->number,
                rememberAs: $state->isMeal() ? $state->rememberablePhrase() : null,
                originalPhrase: $answer->original,
            )),
            // « Enlève le repas … » dit au milieu d'une question (décision 12, VOX-306) : une nouvelle demande de retrait d'un repas
            SpokenAnswerKind::NEW_MEAL_REMOVAL => ($this->removeSpokenMeal)(new RemoveSpokenMealCommand(
                $command->userId,
                $answer->phrase ?? '',
                sessionIsOpen: false,
                servings: $answer->number,
                originalPhrase: $answer->original,
            )),
            // Aiguillage (décision 13) : la phrase d'origine est cherchée de l'autre côté, une seule fois. Pour un retrait (VOX-306), c'est la liste qu'on cherche
            SpokenAnswerKind::SWITCH_TO_MEAL => $state->isRemoval()
                ? ($this->removeSpokenMeal)(new RemoveSpokenMealCommand($command->userId, $state->phrase, sessionIsOpen: false, otherSideTried: true))
                : ($this->addSpokenMeal)(new AddSpokenMealCommand($command->userId, $state->phrase, sessionIsOpen: false, otherSideTried: true)),
            // Les produits cherchent la phrase d'origine ENTIÈRE (« le repas pour chien » : un produit dont le nom commence par le mot « repas »), sans l'article
            SpokenAnswerKind::SWITCH_TO_ITEM => $state->isRemoval()
                ? ($this->removeSpokenItem)(new RemoveSpokenItemCommand($command->userId, $this->namer->nameFrom($state->originalPhrase ?? $state->phrase) ?? $state->phrase, sessionIsOpen: false, otherSideTried: true))
                : ($this->addSpokenItem)(new AddSpokenItemCommand($command->userId, $this->namer->nameFrom($state->originalPhrase ?? $state->phrase) ?? $state->phrase, sessionIsOpen: false, otherSideTried: true)),
            SpokenAnswerKind::PICK_SERVINGS => $this->addSpokenMeal->changeServings(
                $command->userId,
                $state->options[0] ?? throw new \LogicException('PICK_SERVINGS sans recette.'),
                $state->plannedServings ?? throw new \LogicException('PICK_SERVINGS sans nombre de personnes.'),
                $answer->number ?? throw new \LogicException('PICK_SERVINGS sans nombre.'),
            ),
            SpokenAnswerKind::PICK_OPTION => $this->pickOption($command, $answer),
            SpokenAnswerKind::START_CREATION => $this->ask($state->askingDepartment()),
            SpokenAnswerKind::PICK_DEPARTMENT => $this->ask($state->withDepartment($answer->option ?? throw new \LogicException('PICK_DEPARTMENT sans rayon.'))),
            SpokenAnswerKind::PICK_UNIT => $this->ask($state->withUnit($answer->option ?? throw new \LogicException('PICK_UNIT sans unité.'))),
            SpokenAnswerKind::CONFIRM => $this->create($command),
            SpokenAnswerKind::ABANDON => $this->finish(match (true) {
                $state->step === DialogueStep::SERVINGS => VoiceReplies::MEAL_UNCHANGED,
                $state->isRemoval() => VoiceReplies::DECLINED_REMOVAL,
                $state->isMeal() => VoiceReplies::DECLINED_MEAL,
                $state->isTieBreak() => VoiceReplies::DECLINED_TIE,
                default => VoiceReplies::DECLINED,
            }),
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
        return match ($state->step) {
            DialogueStep::DEPARTMENT => $this->creator->departments(),
            DialogueStep::UNIT => $this->creator->units(),
            default => [],
        };
    }

    private function pickOption(AnswerSpokenDialogueCommand $command, SpokenAnswer $answer): AddSpokenItemResult
    {
        $option = $answer->option ?? throw new \LogicException('PICK_OPTION sans proposition.');

        // Un repas de la liste, choisi parmi les propositions d'un retrait (décision 12, VOX-306) : il est retiré (le nombre de personnes dit, ou le repas
        // entier), rien n'est retenu. L'identifiant de l'état n'est pas cru (le port vérifie)
        if ($command->state->isRemoval() && $command->state->isMeal()) {
            return $this->removeSpokenMeal->remove($command->userId, $option, $command->state->servings, keepSessionOpen: false);
        }

        // Une recette proposée, choisie (décision 13) : le repas est planifié ET la phrase retenue. L'identifiant de l'état n'est pas cru (le port vérifie)
        if ($command->state->isMeal()) {
            return $this->addSpokenMeal->plan($command->userId, $option, $command->state->servings, $command->state->phrase, keepSessionOpen: false);
        }

        // Le départage de lignes à retirer : la ligne choisie perd la quantité dite, rien n'est ajouté ni retenu (le slug de l'état n'est pas cru :
        // une ligne qui n'est pas dans la liste de l'utilisateur répond « pas dans ta liste », rien n'est écrit)
        if ($command->state->isRemoval()) {
            $removed = $this->shoppingList->removeItem($command->userId, $option->slug, $command->state->quantity, $command->state->spokenUnitSlug);

            return $this->finish($removed->found
                ? VoiceReplies::removed($option->name, RemovedLineFactory::from($removed, unitCited: $command->state->spokenUnitSlug !== null))
                : VoiceReplies::notInList($option->name));
        }

        $added =$this->shoppingList->addItem($command->userId, $option->slug, $command->state->quantity, $command->state->spokenUnitSlug);
        $line = AddedLineFactory::from($added, unitCited: $command->state->spokenUnitSlug !== null);
        $this->ingredientResolver->remember($command->userId, $command->state->phrase, $option->slug);

        // Des ingrédients à départager par leur rayon : on répète le rayon choisi (« C'est noté : tomate, surgelé. Je m'en souviendrai. »)
        if ($command->state->isTieBreak()) {
            return $this->finish(VoiceReplies::rememberedIn($option->name, $option->departmentName ?? '', $added->listCreated, $added->previousListSent, $line), added: true);
        }

        return $this->finish(VoiceReplies::remembered($option->name, $added->listCreated, $added->previousListSent, $line), added: true);
    }

    /** Une consultation dite au milieu d'une question (« est-ce qu'il y a … ? ») : la liste est lue, rien n'est écrit. */
    private function consult(string $userId, string $phrase): string
    {
        $phrase = $this->listAnswerer->phraseToLookUp($phrase);

        return $this->listAnswerer->speech($this->listLookup->find($userId, $phrase), $phrase);
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
        $added = $this->shoppingList->addItem($command->userId, $created->slug, $state->quantity, $state->spokenUnitSlug);

        // Le nom du produit est exact : rien à retenir pour la phrase
        return $this->finish(VoiceReplies::created($created->name, $created->departmentName, $added->listCreated, $added->previousListSent, AddedLineFactory::from($added, unitCited: $state->spokenUnitSlug !== null)), added: true);
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

    /** Le dialogue est fini (demande traitée ou abandonnée) : la phrase seule, plus d'état, la séance se ferme. */
    private function finish(string $speech, bool $added = false): AddSpokenItemResult
    {
        return new AddSpokenItemResult($speech, keepSessionOpen: false, added: $added);
    }
}
