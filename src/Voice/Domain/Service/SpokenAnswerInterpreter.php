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
 * |              | sinon « laquelle ? » ; « non » sans proposition = on abandonne ; un autre article (`AddItemIntent`) = nouvelle demande |
 * | `department` | le rayon (id de la résolution d'entité, à défaut le texte entendu rapproché des rayons) ; « non » = on abandonne        |
 * | `unit`       | l'unité, de la même façon                                                                                              |
 * | `confirm`    | « oui » = créer ; « non » = on abandonne                                                                                |
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
                DialogueStep::CHOOSE, DialogueStep::CONFIRM => SpokenAnswerKind::REPEAT,
            });
        }

        return match ($state->step) {
            DialogueStep::CHOOSE => $state->isTieBreak()
                ? $this->interpretTie($request, $intent, $state->options)
                : $this->interpretChoice($request, $intent, $state->options),
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

                return $intent === AlexaIntent::ADD_ITEM && $request->phrase !== null && !$request->hasUnusableQuantity()
                    ? SpokenAnswer::newRequest($request->phrase)
                    : SpokenAnswer::of(SpokenAnswerKind::UNCLEAR);
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

    /**
     * @param list<DialogueOption> $options
     */
    private function interpretChoice(AlexaRequest $request, ?AlexaIntent $intent, array $options): SpokenAnswer
    {
        $count = count($options);

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
                return $intent === AlexaIntent::ADD_ITEM && $request->phrase !== null && !$request->hasUnusableQuantity()
                    ? SpokenAnswer::newRequest($request->phrase)
                    : SpokenAnswer::of(SpokenAnswerKind::UNCLEAR);
        }
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
