<?php

namespace Repas\Voice\Application\Command\RemoveSpokenMeal;


use Repas\Voice\Domain\Model\ListedMeal;
use Repas\Voice\Application\Port\ListMealLookupCommandPort;
use Repas\Voice\Application\Port\MealPlanCommandPort;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retire de la liste le repas que l'utilisateur vient de dire (ADR 0029 décision 12, VOX-306) : l'inverse d'`AddSpokenMealHandler`, par des ports de ce contexte (ADR 0035)
 * pour la liste et pour la planification :
 *
 * 1. `ListMealLookupCommandPort` cherche le repas parmi les recettes PLANIFIÉES de la liste active (lecture seule) : pas de liste active (aucune : « Tu
 *    n'as pas de liste en cours. » ; partie au drive : « Ta liste est déjà partie au drive. », elle n'est jamais lue ni modifiée), un repas sûr, ou aucun ;
 * 2. un repas sûr : `MealPlanCommandPort::removeMeal` le retire entièrement (« C'est retiré : le repas hachis parmentier. »), ou en baisse le nombre de
 *    personnes quand l'utilisateur en a dit moins que le repas n'en compte (« C'est changé : hachis parmentier, pour 2 personnes. ») ;
 * 3. aucun repas sûr : les trois repas planifiés les plus proches (jamais devinés : deux noms qui se touchent sont proposés), puis l'issue « ou un
 *    produit ? » tant que les produits n'ont pas été cherchés pour cette phrase (`RemoveSpokenMealCommand::$otherSideTried`) ; une fois les deux essayés et
 *    rien trouvé : « Je ne trouve rien qui s'appelle X dans ta liste. ». Le dialogue continue dans `AnswerSpokenDialogueHandler`.
 *
 * Jamais de liste, de produit ni de recette créés, jamais de mémoire de phrase. Aucune exception n'est attrapée : un échec annule la commande entière
 * (le bus est transactionnel) et le webhook répond par la phrase d'échec. Le résultat est un `AddSpokenItemResult` (`added` faux : rien n'a été ajouté).
 */
#[AsMessageHandler]
readonly class RemoveSpokenMealHandler
{
    public function __construct(
        private ListMealLookupCommandPort $lookup,
        private MealPlanCommandPort $mealPlanner,
        private SpokenIngredientNamer $namer,
    ) {
    }

    public function __invoke(RemoveSpokenMealCommand $command): AddSpokenItemResult
    {
        $name = $this->namer->nameFrom($command->recipePhrase);
        if ($name === null) {
            return $this->said(VoiceReplies::NOT_UNDERSTOOD . ' ' . VoiceReplies::REMOVE_MEAL_EXAMPLE, $command->sessionIsOpen);
        }

        $found = $this->lookup->find($command->userId, $name);
        if (!$found->hasList) {
            return $this->said(VoiceReplies::noActiveList($found->listSent), $command->sessionIsOpen);
        }
        if ($found->meal === null) {
            return $this->unknown($command, $name, $found->suggestions);
        }

        return $this->remove($command->userId, new DialogueOption($found->meal->recipeId, $found->meal->name), $command->servings, keepSessionOpen: $command->sessionIsOpen);
    }

    /**
     * Retire le repas choisi (reconnu tout de suite, ou choisi parmi les propositions). Le `slug` de l'option n'est jamais cru : `MealPlanCommandPort`
     * vérifie que la recette est à l'utilisateur. Le repas a pu quitter la liste depuis la question (rien n'est écrit, et Alexa le dit).
     */
    public function remove(string $userId, DialogueOption $meal, ?int $servings, bool $keepSessionOpen): AddSpokenItemResult
    {
        $removed = $this->mealPlanner->removeMeal($userId, $meal->slug, $servings);

        return $this->said(match (true) {
            $removed->listSent => VoiceReplies::LIST_SENT,
            !$removed->found => VoiceReplies::MEAL_NO_LONGER_PLANNED,
            $removed->removedAll => VoiceReplies::mealRemoved($removed->recipeName),
            default => VoiceReplies::mealServingsChanged($removed->recipeName, $removed->servings),
        }, $keepSessionOpen);
    }

    /**
     * « Je ne trouve pas le repas X dans ta liste » : rien d'écrit, une question, la séance reste ouverte. Sans proposition, et quand les produits ont déjà
     * été cherchés, il n'y a plus rien à demander : « Je ne trouve rien qui s'appelle X dans ta liste. ».
     *
     * @param list<ListedMeal> $suggestions
     */
    private function unknown(RemoveSpokenMealCommand $command, string $name, array $suggestions): AddSpokenItemResult
    {
        if ($suggestions === [] && $command->otherSideTried) {
            return $this->said(VoiceReplies::nothingFoundInList($name), $command->sessionIsOpen);
        }

        $options = self::toldApart($suggestions);
        $state = SpokenDialogueState::chooseMealToRemove($name, $options, $command->servings, $command->otherSideTried, $command->originalPhrase);
        $names = array_map(static fn(DialogueOption $option): string => $option->name, $state->options);
        // Tous de même nom : ils sont dans la liste, « je ne trouve pas » serait faux ; la question seule suffit (elle dit en quoi ils diffèrent)
        $sameName = count($suggestions) > 1 && count(array_unique(array_map(VoiceReplies::speakable(...), array_map(static fn(ListedMeal $meal): string => $meal->name, $suggestions)))) === 1;

        return new AddSpokenItemResult(
            $sameName ? VoiceReplies::question($state) : VoiceReplies::unknownMealToRemove($name, $names, offerItem: !$command->otherSideTried),
            keepSessionOpen: true,
            added: false,
            nextState: $state,
            reprompt: VoiceReplies::question($state),
        );
    }

    /**
     * Les propositions, chaque repas sous son nom. Deux repas de même nom (deux recettes « Hachis parmentier », une copie par exemple) ne se distinguent à la
     * voix que par leur nombre de personnes : « hachis parmentier pour 4 », « hachis parmentier pour 2 » (le nom devient celui de la proposition, il sert à la
     * dire et à la reconnaître ; le repas retiré est dit par le nom de sa recette). Deux repas de même nom ET de même nombre de personnes ne se distinguent pas :
     * un seul est proposé.
     *
     * @param list<ListedMeal> $suggestions
     *
     * @return list<DialogueOption>
     */
    private static function toldApart(array $suggestions): array
    {
        $count = [];
        foreach ($suggestions as $meal) {
            $key = VoiceReplies::speakable($meal->name);
            $count[$key] = ($count[$key] ?? 0) + 1;
        }

        $options = [];
        foreach ($suggestions as $meal) {
            $homonym = $count[VoiceReplies::speakable($meal->name)] > 1;
            $label = $homonym ? $meal->name . ' pour ' . $meal->servings : $meal->name;
            if (isset($options[$label])) {
                continue;
            }
            $options[$label] = new DialogueOption($meal->recipeId, $label);
        }

        return array_values($options);
    }

    /** Une affirmation seule : la séance reste ouverte, sans relance, quand elle l'était ; sinon elle se ferme. */
    private function said(string $speech, bool $sessionIsOpen): AddSpokenItemResult
    {
        return new AddSpokenItemResult($speech, keepSessionOpen: $sessionIsOpen, added: false);
    }
}
