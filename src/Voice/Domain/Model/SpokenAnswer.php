<?php

namespace Repas\Voice\Domain\Model;

/**
 * La réponse de l'utilisateur à une étape du dialogue, comprise (`SpokenAnswerInterpreter`) : un genre et, selon lui, l'option retenue ou la
 * nouvelle phrase.
 */
final readonly class SpokenAnswer
{
    private function __construct(
        public SpokenAnswerKind $kind,
        public ?DialogueOption $option = null,
        public ?string $phrase = null,
        /** Un nombre de personnes : celui de `PICK_SERVINGS`, ou celui qu'une nouvelle demande de repas (`NEW_MEAL`) a dit (`null` : celui du compte). */
        public ?int $number = null,
        /** La phrase d'une nouvelle demande de repas telle que dite, avec le mot « repas » ou « recette » (`SpokenMealRequest::$original`). */
        public ?string $original = null,
    ) {
    }

    public static function of(SpokenAnswerKind $kind): self
    {
        return new self($kind);
    }

    public static function pickOption(DialogueOption $option): self
    {
        return new self(SpokenAnswerKind::PICK_OPTION, $option);
    }

    public static function pickDepartment(DialogueOption $department): self
    {
        return new self(SpokenAnswerKind::PICK_DEPARTMENT, $department);
    }

    public static function pickUnit(DialogueOption $unit): self
    {
        return new self(SpokenAnswerKind::PICK_UNIT, $unit);
    }

    /** « Planifie le repas … » dit au milieu d'une question : une nouvelle demande, de repas (ADR 0029 décision 13). */
    public static function newMeal(string $phrase, ?int $servings, ?string $original = null): self
    {
        return new self(SpokenAnswerKind::NEW_MEAL, null, $phrase, $servings, $original);
    }

    /** Le nouveau total de personnes d'un repas déjà planifié. */
    public static function pickServings(int $servings): self
    {
        return new self(SpokenAnswerKind::PICK_SERVINGS, null, null, $servings);
    }

    public static function newRequest(string $phrase): self
    {
        return new self(SpokenAnswerKind::NEW_REQUEST, null, $phrase);
    }

    /** « Enlève … » dit au milieu d'une question : une nouvelle demande, de retrait (ADR 0029 décision 12). */
    public static function newRemoval(string $phrase): self
    {
        return new self(SpokenAnswerKind::NEW_REMOVAL, null, $phrase);
    }

    /** « Est-ce qu'il y a … ? » dit au milieu d'une question : une nouvelle demande, de consultation de la liste (ADR 0029 décision 12). */
    public static function newCheck(string $phrase): self
    {
        return new self(SpokenAnswerKind::NEW_CHECK, null, $phrase);
    }
}
