<?php

namespace Repas\Voice\Application\Command\AddSpokenMeal;


use Repas\Voice\Application\Port\MealPlanCommandPort;
use Repas\Voice\Application\Port\RecipeResolutionCommandPort;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\RecipeSuggestion;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Planifie dans la liste de courses le repas que l'utilisateur vient de dire (ADR 0029 décision 13, VOX-305), et dit en une phrase ce qui a été fait :
 *
 * 1. `RecipeResolutionCommandPort` choisit, parmi les recettes de l'utilisateur seulement (jamais les produits : les deux recherches sont séparées), la plus
 *    probable pour la phrase (la mémoire de phrase d'abord, puis le classement). Rien de sûr : **la recette est inconnue**, rien n'est écrit, et Alexa
 *    propose les trois recettes les plus proches (voir plus bas) ; **jamais de création de recette à la voix** ;
 * 2. `MealPlanCommandPort` planifie le repas dans la liste active, ou dans une liste neuve s'il n'y en a pas (une liste envoyée au drive n'est jamais
 *    modifiée), pour le nombre de personnes dit ou, à défaut, celui du compte. Une seule écriture.
 *
 * « C'est noté : hachis parmentier, pour 4 personnes. » **Une recette déjà dans la liste n'y est jamais deux fois** : rien n'est écrit, Alexa dit pour
 * combien de personnes elle est prévue et demande le nouveau total ou de ne rien changer (le dialogue continue à l'étape `servings`, dans
 * `AnswerSpokenDialogueHandler`).
 *
 * Recette inconnue : « Je ne trouve pas la recette X. Tu veux dire A, B ou C ? Ou un produit ? » : les trois recettes les PLUS PROCHES, même lointaines (jamais
 * un silence) ; s'il n'a aucune recette : « Tu n'as encore aucune recette dans Repas. C'est un produit ? ». L'issue « un
 * produit » n'est proposée que si les produits n'ont pas déjà été cherchés pour cette phrase (`AddSpokenMealCommand::$otherSideTried`) ; une fois les
 * deux essayés et rien trouvé : « Je ne trouve rien qui s'appelle X. ». Choisir une proposition planifie le repas ET retient la phrase.
 *
 * Quand l'utilisateur répond à des propositions en nommant une autre recette (`AddSpokenMealCommand::rememberAs`, posé seulement si des propositions ont
 * été faites), la phrase d'origine est retenue pour la recette que la nouvelle phrase désigne.
 *
 * Aucune exception n'est attrapée ici : si un port échoue (la recette a disparu, un ingrédient sans conversion), la commande est annulée en entier — le
 * bus est transactionnel — et le webhook répond par la phrase d'échec. Les ports sont ceux de ce contexte (ADR 0035) : il n'importe rien de `Repas`.
 */
#[AsMessageHandler]
readonly class AddSpokenMealHandler
{
    public function __construct(
        private RecipeResolutionCommandPort $recipeResolver,
        private MealPlanCommandPort $mealPlanner,
        private SpokenIngredientNamer $namer,
    ) {
    }

    public function __invoke(AddSpokenMealCommand $command): AddSpokenItemResult
    {
        $name = $this->namer->nameFrom($command->recipePhrase);
        if ($name === null) {
            return new AddSpokenItemResult(VoiceReplies::NOT_UNDERSTOOD . ' ' . VoiceReplies::MEAL_EXAMPLE, keepSessionOpen: $command->sessionIsOpen, added: false);
        }

        $resolution = $this->recipeResolver->resolveOrSuggest($command->userId, $name);
        if ($resolution->recipe === null) {
            return $this->unknown($command, $name, $resolution->suggestions, $resolution->hasRecipes);
        }

        return $this->plan(
            $command->userId,
            new DialogueOption($resolution->recipe->id, $resolution->recipe->name),
            $command->servings,
            $command->rememberAs,
            keepSessionOpen: $command->sessionIsOpen,
        );
    }

    /**
     * Planifie la recette choisie (reconnue tout de suite, ou choisie parmi les propositions) puis, si on le demande, retient la phrase d'origine. Le
     * `slug` de l'option n'est jamais cru : `MealPlanCommandPort` et `RecipeResolutionCommandPort::remember` vérifient que la recette est à l'utilisateur. Une
     * recette déjà dans la liste ouvre la question du nouveau total, rien n'est écrit.
     */
    public function plan(string $userId, DialogueOption $recipe, ?int $servings, ?string $rememberAs, bool $keepSessionOpen): AddSpokenItemResult
    {
        $planned = $this->mealPlanner->plan($userId, $recipe->slug, $servings);
        if ($rememberAs !== null) {
            // Corrige une proposition : le repas est planifié PUIS la phrase d'origine est retenue pour cette recette
            $this->recipeResolver->remember($userId, $rememberAs, $recipe->slug);
        }

        if ($planned->alreadyPlanned) {
            $state = SpokenDialogueState::askingServings(new DialogueOption($recipe->slug, $planned->recipeName), $planned->servings);
            $question = VoiceReplies::question($state);

            return new AddSpokenItemResult($question, keepSessionOpen: true, added: false, nextState: $state, reprompt: $question);
        }

        return new AddSpokenItemResult(
            VoiceReplies::mealPlanned($planned->recipeName, $planned->servings, $planned->listCreated, $planned->previousListSent, remembered: $rememberAs !== null, typedListNames: $planned->typedListNames, inMainList: $planned->inMainList),
            keepSessionOpen: $keepSessionOpen,
            added: true,
        );
    }

    /**
     * La réponse au nouveau total de personnes d'un repas déjà dans la liste : le même nombre que celui d'avant ne change rien. Le repas a pu quitter la
     * liste depuis la question (rien n'est écrit, et Alexa le dit).
     */
    public function changeServings(string $userId, DialogueOption $recipe, int $plannedServings, int $servings): AddSpokenItemResult
    {
        if ($servings === $plannedServings) {
            return new AddSpokenItemResult(VoiceReplies::MEAL_UNCHANGED, keepSessionOpen: false, added: false);
        }

        $changed = $this->mealPlanner->changeServings($userId, $recipe->slug, $servings);

        return new AddSpokenItemResult(
            match (true) {
                $changed->listSent => VoiceReplies::LIST_SENT,
                $changed->found => VoiceReplies::mealServingsChanged($changed->recipeName, $changed->servings),
                default => VoiceReplies::MEAL_NO_LONGER_PLANNED,
            },
            keepSessionOpen: false,
            added: $changed->found,
        );
    }

    /**
     * « Je ne trouve pas la recette X » : rien d'écrit, une question, la séance reste ouverte. Sans proposition, et quand les produits ont déjà été
     * cherchés, il n'y a plus rien à demander : « Je ne trouve rien qui s'appelle X. ».
     *
     * @param list<RecipeSuggestion> $suggestions les trois recettes les plus proches, même lointaines ; vide seulement si l'utilisateur n'en a aucune
     */
    private function unknown(AddSpokenMealCommand $command, string $name, array $suggestions, bool $hasRecipes): AddSpokenItemResult
    {
        if ($suggestions === [] && $command->otherSideTried) {
            return new AddSpokenItemResult(VoiceReplies::nothingFound($name), keepSessionOpen: $command->sessionIsOpen, added: false);
        }

        $options = array_map(
            static fn(RecipeSuggestion $suggestion): DialogueOption => new DialogueOption($suggestion->id, $suggestion->name),
            $suggestions,
        );
        $state = SpokenDialogueState::chooseRecipe($name, $options, $name, $command->servings, $command->otherSideTried, $command->originalPhrase);

        return new AddSpokenItemResult(
            VoiceReplies::unknownMeal($name, array_map(static fn(DialogueOption $option): string => $option->name, $state->options), offerItem: !$command->otherSideTried, hasRecipes: $hasRecipes),
            keepSessionOpen: true,
            added: false,
            nextState: $state,
            reprompt: VoiceReplies::question($state),
        );
    }
}
