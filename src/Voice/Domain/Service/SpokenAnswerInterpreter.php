<?php

namespace Repas\Voice\Domain\Service;

use Repas\Shared\Domain\Tool\SearchRelevance;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Voice\Domain\Model\AlexaIntent;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Model\SlotValue;
use Repas\Voice\Domain\Model\SpokenAnswer;
use Repas\Voice\Domain\Model\SpokenAnswerKind;
use Repas\Voice\Domain\Model\SpokenDialogueState;

/**
 * Comprend la réponse de l'utilisateur à la question de l'étape du dialogue en cours (ADR 0029 décision 11, VOX-303). Pur : la requête, l'état
 * et, pour les étapes « rayon » et « unité », le catalogue des choix possibles lui sont donnés.
 *
 * Alexa choisit l'intention SANS connaître notre état : « oui » peut arriver comme `AMAZON.YesIntent`, « persil » comme `AddItemIntent`, « le
 * deuxième » comme `ChooseOptionIntent`. L'interprétation tient donc compte de l'ÉTAT d'abord. La matrice :
 *
 * | étape        | comprend                                                                                                               |
 * |--------------|------------------------------------------------------------------------------------------------------------------------|
 * | `choose`     | un rang (1 à n) ; un nom proche d'une proposition dit en entier (quelle que soit l'intention) ; « nouveau produit »,   |
 * |              | « non » (avec propositions) = créer ; « oui » = la proposition s'il n'y en a qu'une, la création s'il n'y en a aucune, |
 * |              | sinon « laquelle ? » ; « non » sans proposition = on abandonne ; un autre article (`AddItemIntent`, `RemoveItemIntent`, `CheckItemIntent`) = nouvelle demande |
 * | `department` | le rayon (id de la résolution d'entité, à défaut le texte entendu rapproché des rayons) ; « non » = on abandonne        |
 * | `unit`       | l'unité, de la même façon                                                                                              |
 * | `confirm`    | « oui » = créer ; « non » = on abandonne                                                                                |
 * | `choose` (repas) | un rang ; un nom de recette proche dit en entier ; « une recette »/« un produit » ; « oui » = la proposition s'il n'y en a qu'une, le produit s'il n'y en a aucune ; « non » = on abandonne ; « planifie le repas … » = nouvelle demande de repas |
 * | `servings`   | un nombre (« 6 », « six personnes », « pour six ») = le nouveau total ; « non », « rien », « ne change rien » = on ne change rien |
 *
 * Aiguillage (ADR 0029 décision 13) : « une recette » à la question d'un produit inconnu cherche la phrase d'origine dans les recettes (`SWITCH_TO_MEAL`),
 * « un produit » à la question d'une recette inconnue la cherche dans les produits (`SWITCH_TO_ITEM`), tant que l'autre côté n'a pas déjà été essayé
 * (`SpokenDialogueState::$otherSideTried`) ; « un produit » à la question d'un produit inconnu veut dire « un nouveau produit ». Le créneau `kind` d'Alexa
 * (`ChooseKindIntent`) ou le texte entendu disent le genre : l'intention qu'Alexa a choisie ne compte pas.
 *
 * « Lesquels ? » (`ListChoicesIntent`) et « aide » (`AMAZON.HelpIntent`) sont la même demande : aux étapes `department` et `unit`, la liste des
 * rayons ou des unités (`LIST_CHOICES`) ; aux étapes `choose` et `confirm`, la question de l'étape est répétée (`REPEAT`). Le reste est `UNCLEAR` : l'appelant compte l'échec (`SpokenDialogueState::missed`). « Stop » et
 * « annule » ne passent pas ici : `AlexaDialogue` les traite avant, dans tous les états.
 */
final readonly class SpokenAnswerInterpreter
{
    /** Un nom dit en entier se rapproche d'une proposition à partir de ce score (`SearchRelevance`). */
    public const float OPTION_THRESHOLD = 0.9;

    /** Un rayon ou une unité entendu se rapproche d'un choix du catalogue à partir de ce score. */
    public const float CATALOGUE_THRESHOLD = SearchRelevance::MINIMUM;

    /** Le meilleur doit devancer le suivant d'au moins cet écart, sinon on ne choisit pas à la place de l'utilisateur. */
    public const float MARGIN = 0.03;

    private SpokenMealPhrase $meals;

    public function __construct(?SpokenMealPhrase $meals = null)
    {
        $this->meals = $meals ?? new SpokenMealPhrase();
    }

    private const array STOP_WORDS = ['de', 'du', 'des', 'd', 'l', 'la', 'le', 'les', 'un', 'une', 'a', 'au', 'aux'];

    /** Le rang dit en mots, quand la résolution d'entité n'a pas rendu son identifiant. */
    private const array RANK_WORDS = [
        'un' => 1, 'une' => 1, 'premier' => 1, 'premiere' => 1,
        'deux' => 2, 'deuxieme' => 2, 'second' => 2, 'seconde' => 2,
        'trois' => 3, 'troisieme' => 3,
    ];

    /**
     * @param list<DialogueOption> $catalogue les rayons (étape `department`) ou les unités (étape `unit`) que l'utilisateur peut citer
     */
    public function interpret(AlexaRequest $request, SpokenDialogueState $state, array $catalogue = []): SpokenAnswer
    {
        $intent = $request->intent();
        // « Lesquels ? » et « aide » sont la même demande ; la réponse dépend de l'étape, pas de l'intention
        if ($intent === AlexaIntent::HELP || $intent === AlexaIntent::LIST_CHOICES) {
            return SpokenAnswer::of(match ($state->step) {
                DialogueStep::DEPARTMENT, DialogueStep::UNIT => SpokenAnswerKind::LIST_CHOICES,
                DialogueStep::CHOOSE, DialogueStep::CONFIRM, DialogueStep::SERVINGS => SpokenAnswerKind::REPEAT,
            });
        }

        return match ($state->step) {
            DialogueStep::CHOOSE => match (true) {
                $state->isMeal() => $this->interpretMealChoice($request, $intent, $state),
                $state->isTieBreak() => $this->interpretTie($request, $intent, $state->options),
                default => $this->interpretChoice($request, $intent, $state),
            },
            DialogueStep::SERVINGS => $this->interpretServings($request, $intent),
            DialogueStep::DEPARTMENT => $this->interpretCatalogue($request, $intent, $request->department, $catalogue, true),
            DialogueStep::UNIT => $this->interpretCatalogue($request, $intent, $request->unit, $catalogue, false),
            DialogueStep::CONFIRM => match ($intent) {
                AlexaIntent::YES => SpokenAnswer::of(SpokenAnswerKind::CONFIRM),
                AlexaIntent::NO => SpokenAnswer::of(SpokenAnswerKind::ABANDON),
                default => SpokenAnswer::of(SpokenAnswerKind::UNCLEAR),
            },
        };
    }

    /**
     * Plusieurs ingrédients de l'utilisateur se valent (`SpokenDialogueState::isTieBreak`) : « lequel ? » se répond par un rang, par le RAYON dit
     * (`AnswerDepartmentIntent`, ou le texte entendu rapproché des rayons des propositions) ou par le nom en entier. Deux propositions du même
     * rayon : « laquelle ? ». Il n'y a pas d'issue « un nouveau produit » (le produit existe) : « non » abandonne, « un nouveau produit » n'est pas compris.
     *
     * @param list<DialogueOption> $options
     */
    private function interpretTie(AlexaRequest $request, ?AlexaIntent $intent, array $options): SpokenAnswer
    {
        switch ($intent) {
            case AlexaIntent::CHOOSE_OPTION:
                $rank = $this->rankOf($request->rank);

                return $rank !== null && $rank >= 1 && $rank <= count($options)
                    ? SpokenAnswer::pickOption($options[$rank - 1])
                    : SpokenAnswer::of(SpokenAnswerKind::UNCLEAR);
            case AlexaIntent::NO:
                return SpokenAnswer::of(SpokenAnswerKind::ABANDON);
            case AlexaIntent::YES:
                return SpokenAnswer::of(SpokenAnswerKind::WHICH_ONE);
            case AlexaIntent::CREATE_ITEM:
                return SpokenAnswer::of(SpokenAnswerKind::UNCLEAR);
            default:
                $inDepartment = $this->optionsInTheSaidDepartment($request, $options);
                if (count($inDepartment) === 1) {
                    return SpokenAnswer::pickOption($inDepartment[0]);
                }
                if (count($inDepartment) > 1) {
                    return SpokenAnswer::of(SpokenAnswerKind::WHICH_ONE);
                }

                $option = $this->matchOption($request->heardTexts(), $options);
                if ($option !== null) {
                    return SpokenAnswer::pickOption($option);
                }

                return $this->newDemand($request, $intent);
        }
    }

    /**
     * Les propositions du rayon que l'utilisateur vient de dire : l'identifiant de la résolution d'entité (le slug du rayon), à défaut le texte
     * entendu rapproché des rayons des propositions. Vide si aucun rayon n'est reconnu.
     *
     * @param list<DialogueOption> $options
     *
     * @return list<DialogueOption>
     */
    private function optionsInTheSaidDepartment(AlexaRequest $request, array $options): array
    {
        $departments = [];
        foreach ($options as $option) {
            if ($option->departmentSlug !== null && $option->departmentName !== null) {
                $departments[$option->departmentSlug] = new DialogueOption($option->departmentSlug, $option->departmentName);
            }
        }
        $departments = array_values($departments);

        $department = $this->byId($request->department?->id, $departments) ?? $this->matchCatalogue($request->heardTexts(), $departments);
        if ($department === null) {
            return [];
        }

        return array_values(array_filter($options, static fn(DialogueOption $option): bool => $option->departmentSlug === $department->slug));
    }

    private function interpretChoice(AlexaRequest $request, ?AlexaIntent $intent, SpokenDialogueState $state): SpokenAnswer
    {
        $options = $state->options;
        $count = count($options);

        // « Une recette » : ce n'est pas un produit, on cherche la phrase d'origine dans les recettes (une fois). « Un produit » : un nouveau produit
        $kind = $this->kindHeard($request);
        if ($kind === 'meal') {
            return SpokenAnswer::of($state->otherSideTried ? SpokenAnswerKind::UNCLEAR : SpokenAnswerKind::SWITCH_TO_MEAL);
        }
        if ($kind === 'item') {
            return SpokenAnswer::of(SpokenAnswerKind::START_CREATION);
        }

        switch ($intent) {
            case AlexaIntent::CHOOSE_OPTION:
                $rank = $this->rankOf($request->rank);

                return $rank !== null && $rank >= 1 && $rank <= $count
                    ? SpokenAnswer::pickOption($options[$rank - 1])
                    : SpokenAnswer::of(SpokenAnswerKind::UNCLEAR);
            case AlexaIntent::CREATE_ITEM:
                return SpokenAnswer::of(SpokenAnswerKind::START_CREATION);
            case AlexaIntent::NO:
                return SpokenAnswer::of($count === 0 ? SpokenAnswerKind::ABANDON : SpokenAnswerKind::START_CREATION);
            case AlexaIntent::YES:
                return match ($count) {
                    0 => SpokenAnswer::of(SpokenAnswerKind::START_CREATION),
                    1 => SpokenAnswer::pickOption($options[0]),
                    default => SpokenAnswer::of(SpokenAnswerKind::WHICH_ONE),
                };
            default:
                // Un nom proche d'une proposition, dit en entier, quelle que soit l'intention qu'Alexa a choisie
                $option = $this->matchOption($request->heardTexts(), $options);
                if ($option !== null) {
                    return SpokenAnswer::pickOption($option);
                }

                // Un autre article, sans rapport avec les propositions : une nouvelle demande
                return $this->newDemand($request, $intent);
        }
    }

    /**
     * Un article dit au milieu d'une question, sans rapport avec les propositions : une NOUVELLE demande, d'ajout (« ajoute du pain »), de retrait
     * (« enlève du pain »), de consultation (« est-ce qu'il y a du pain dans la liste ? », ADR 0029 décision 12) ou de repas (« planifie le repas
     * lasagnes », décision 13). Sans article compris, ou avec une
     * quantité que nous ne savons pas écrire (une demande d'ajout ou de retrait), la réponse n'est pas comprise.
     */
    private function newDemand(AlexaRequest $request, ?AlexaIntent $intent): SpokenAnswer
    {
        // « Planifie le repas … » (ou « ajoute le repas … ») : une nouvelle demande de repas (ADR 0029 décision 13)
        $meal = $this->meals->requestOf($request);
        if ($meal !== null) {
            return $meal->recipe === '' || $request->hasUnusableServings() ? SpokenAnswer::of(SpokenAnswerKind::UNCLEAR) : SpokenAnswer::newMeal($meal->recipe, $meal->servings);
        }

        if ($request->phrase === null || $request->hasUnusableQuantity()) {
            return SpokenAnswer::of(SpokenAnswerKind::UNCLEAR);
        }

        return match ($intent) {
            AlexaIntent::ADD_ITEM => SpokenAnswer::newRequest($request->phrase),
            AlexaIntent::REMOVE_ITEM => SpokenAnswer::newRemoval($request->phrase),
            AlexaIntent::CHECK_ITEM => SpokenAnswer::newCheck($request->phrase),
            default => SpokenAnswer::of(SpokenAnswerKind::UNCLEAR),
        };
    }

    /**
     * Une recette inconnue (ADR 0029 décision 13) : « lequel ? » se répond par un rang, par le nom d'une proposition dit en entier, ou « un produit »
     * (on cherche la phrase d'origine dans les produits, une fois). Jamais de création de recette : « non » abandonne.
     */
    private function interpretMealChoice(AlexaRequest $request, ?AlexaIntent $intent, SpokenDialogueState $state): SpokenAnswer
    {
        $options = $state->options;
        $count = count($options);
        $kind = $this->kindHeard($request);

        // « Un produit » : la recherche de produits repart avec la phrase d'origine, une seule fois
        if ($kind === 'item' || $intent === AlexaIntent::CREATE_ITEM) {
            if (!$state->otherSideTried) {
                return SpokenAnswer::of(SpokenAnswerKind::SWITCH_TO_ITEM);
            }

            return SpokenAnswer::of($intent === AlexaIntent::CREATE_ITEM ? SpokenAnswerKind::ABANDON : SpokenAnswerKind::UNCLEAR);
        }

        switch ($intent) {
            case AlexaIntent::CHOOSE_OPTION:
                $rank = $this->rankOf($request->rank);

                return $rank !== null && $rank >= 1 && $rank <= $count
                    ? SpokenAnswer::pickOption($options[$rank - 1])
                    : SpokenAnswer::of(SpokenAnswerKind::UNCLEAR);
            case AlexaIntent::NO:
                return SpokenAnswer::of(SpokenAnswerKind::ABANDON);
            case AlexaIntent::YES:
                // Sans proposition la question était « C'est un produit ? » : oui aiguille vers les produits
                return match (true) {
                    $count === 0 => SpokenAnswer::of($state->otherSideTried ? SpokenAnswerKind::ABANDON : SpokenAnswerKind::SWITCH_TO_ITEM),
                    $count === 1 => SpokenAnswer::pickOption($options[0]),
                    default => SpokenAnswer::of(SpokenAnswerKind::WHICH_ONE),
                };
            default:
                // « Une recette » dit à la question d'une recette : laquelle ?
                if ($kind === 'meal') {
                    return SpokenAnswer::of($count === 0 ? SpokenAnswerKind::UNCLEAR : SpokenAnswerKind::WHICH_ONE);
                }

                // Un nom proche d'une proposition, dit en entier, quelle que soit l'intention qu'Alexa a choisie
                $option = $this->matchOption($request->heardTexts(), $options);
                if ($option !== null) {
                    return SpokenAnswer::pickOption($option);
                }

                return $this->newDemand($request, $intent);
        }
    }

    /**
     * Une recette déjà dans la liste (ADR 0029 décision 13) : le nombre dit est le NOUVEAU TOTAL de personnes (« 6 », « six personnes », « pour six ») ;
     * « non », « rien » et « ne change rien » ne changent rien. Le nombre est lu quel que soit le créneau où Alexa l'a mis (`servings`, `quantity`,
     * `rank`, ou le texte de l'article). Un nombre inutilisable (zéro, plus de 99) n'est pas compris.
     */
    private function interpretServings(AlexaRequest $request, ?AlexaIntent $intent): SpokenAnswer
    {
        if ($intent === AlexaIntent::NO || $intent === AlexaIntent::KEEP_MEAL) {
            return SpokenAnswer::of(SpokenAnswerKind::ABANDON);
        }
        foreach ($request->heardTexts() as $text) {
            if ($this->meals->isKeepAnswer($text)) {
                return SpokenAnswer::of(SpokenAnswerKind::ABANDON);
            }
        }

        // « Planifie les lasagnes pour six » n'est pas un total : c'est un autre repas
        $meal = $this->meals->requestOf($request);
        if ($meal !== null && $meal->recipe !== '') {
            return $request->hasUnusableServings() ? SpokenAnswer::of(SpokenAnswerKind::UNCLEAR) : SpokenAnswer::newMeal($meal->recipe, $meal->servings);
        }

        if ($request->hasUnusableServings() || $request->hasUnusableQuantity()) {
            return SpokenAnswer::of(SpokenAnswerKind::UNCLEAR);
        }
        $servings = $this->servingsHeard($request);
        if ($servings !== null) {
            return SpokenAnswer::pickServings($servings);
        }

        // « Oui » ne dit pas pour combien ; le reste est une nouvelle demande, ou n'est pas compris
        return $intent === AlexaIntent::YES ? SpokenAnswer::of(SpokenAnswerKind::UNCLEAR) : $this->newDemand($request, $intent);
    }

    /** Le nombre de personnes entendu, où qu'Alexa l'ait mis : `servings`, `quantity`, l'identifiant de `rank`, ou le texte de l'article. */
    private function servingsHeard(AlexaRequest $request): ?int
    {
        if ($request->servings !== null || $request->quantity !== null) {
            return $request->servings ?? $request->quantity;
        }
        if ($request->rank?->id !== null && ctype_digit($request->rank->id)) {
            return (int) $request->rank->id;
        }
        foreach ($request->heardTexts() as $text) {
            $servings = $this->meals->servingsAnswer($text);
            if ($servings !== null) {
                return $servings;
            }
        }

        return null;
    }

    /**
     * « Une recette » (`meal`) ou « un produit » (`item`), d'après le créneau `kind` (`ChooseKindIntent`) ou, à défaut, le texte entendu : l'intention
     * qu'Alexa a choisie ne compte pas. `null` si aucun des deux, ou si les textes entendus ne disent pas la même chose.
     */
    private function kindHeard(AlexaRequest $request): ?string
    {
        $id = $request->kind?->id;
        if ($id === 'meal' || $id === 'item') {
            return $id;
        }

        $kinds = array_values(array_unique(array_filter(array_map($this->meals->kindOf(...), $request->heardTexts()))));

        return count($kinds) === 1 ? $kinds[0] : null;
    }

    /**
     * @param list<DialogueOption> $catalogue
     */
    private function interpretCatalogue(AlexaRequest $request, ?AlexaIntent $intent, ?SlotValue $slot, array $catalogue, bool $isDepartment): SpokenAnswer
    {
        if ($intent === AlexaIntent::NO) {
            return SpokenAnswer::of(SpokenAnswerKind::ABANDON);
        }
        if ($intent === AlexaIntent::YES || $intent === AlexaIntent::CHOOSE_OPTION || $intent === AlexaIntent::CREATE_ITEM) {
            return SpokenAnswer::of(SpokenAnswerKind::UNCLEAR);
        }

        $choice = $this->byId($slot?->id, $catalogue) ?? $this->matchCatalogue($request->heardTexts(), $catalogue);
        if ($choice === null) {
            return SpokenAnswer::of(SpokenAnswerKind::UNCLEAR);
        }

        return $isDepartment ? SpokenAnswer::pickDepartment($choice) : SpokenAnswer::pickUnit($choice);
    }

    /**
     * @param list<DialogueOption> $catalogue
     */
    private function byId(?string $id, array $catalogue): ?DialogueOption
    {
        if ($id === null) {
            return null;
        }
        foreach ($catalogue as $choice) {
            if ($choice->slug === $id) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * Le rang entendu (1, 2, 3) : l'identifiant de la résolution d'entité, à défaut le texte dit (« deux », « le deuxième », « 2 »).
     */
    private function rankOf(?SlotValue $slot): ?int
    {
        if ($slot === null) {
            return null;
        }
        if ($slot->id !== null && ctype_digit($slot->id)) {
            return (int) $slot->id;
        }
        if ($slot->text === null) {
            return null;
        }

        foreach ($this->tokens($slot->text) as $word) {
            if (ctype_digit($word)) {
                return (int) $word;
            }
            if (isset(self::RANK_WORDS[$word])) {
                return self::RANK_WORDS[$word];
            }
        }

        return null;
    }

    /**
     * L'unique proposition qu'un des textes entendus nomme en entier (une fois les articles retirés) ; `null` si aucune, ou si deux se valent.
     *
     * @param list<string> $texts
     * @param list<DialogueOption> $options
     */
    private function matchOption(array $texts, array $options): ?DialogueOption
    {
        return $this->bestMatch($texts, array_map(
            fn(DialogueOption $option): array => [$option, [$this->normalize($option->name)]],
            $options,
        ), self::OPTION_THRESHOLD);
    }

    /**
     * @param list<string> $texts
     * @param list<DialogueOption> $catalogue
     */
    private function matchCatalogue(array $texts, array $catalogue): ?DialogueOption
    {
        return $this->bestMatch($texts, array_map(
            fn(DialogueOption $choice): array => [$choice, [$choice->slug, $this->normalize($choice->name)]],
            $catalogue,
        ), self::CATALOGUE_THRESHOLD);
    }

    /**
     * @param list<string> $texts
     * @param list<array{DialogueOption, list<string>}> $candidates chaque choix avec les formes normalisées sous lesquelles on peut le nommer
     */
    private function bestMatch(array $texts, array $candidates, float $threshold): ?DialogueOption
    {
        $best = null;
        foreach ($texts as $text) {
            $heard = $this->normalize($text);
            if ($heard === '') {
                continue;
            }

            $scored = [];
            foreach ($candidates as [$option, $forms]) {
                $score = max(array_map(static fn(string $form): float => SearchRelevance::score($heard, $form), $forms));
                if ($score >= $threshold) {
                    $scored[] = [$score, $option];
                }
            }
            if ($scored === []) {
                continue;
            }

            usort($scored, static fn(array $first, array $second): int => $second[0] <=> $first[0]);
            // Deux choix qui se touchent : on ne choisit pas à la place de l'utilisateur
            if (isset($scored[1]) && $scored[0][0] - $scored[1][0] < self::MARGIN) {
                continue;
            }
            if ($best !== null && $best->slug !== $scored[0][1]->slug) {
                // Deux textes, deux choix différents : même doute
                return null;
            }
            $best = $scored[0][1];
        }

        return $best;
    }

    /** Un texte comparable : sans accent ni majuscule, sans articles, mots joints par « - ». */
    private function normalize(string $text): string
    {
        return implode('-', $this->words($text));
    }

    /**
     * @return list<string> les mots sans les articles
     */
    private function words(string $text): array
    {
        return array_values(array_filter($this->tokens($text), static fn(string $word): bool => !in_array($word, self::STOP_WORDS, true)));
    }

    /**
     * @return list<string> tous les mots, sans accent ni majuscule
     */
    private function tokens(string $text): array
    {
        return array_values(array_filter(explode('-', StringTool::slugify($text)), static fn(string $word): bool => $word !== ''));
    }
}
