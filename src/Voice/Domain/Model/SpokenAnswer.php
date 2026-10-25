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
