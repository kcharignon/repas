<?php

namespace Repas\Shared\Domain\Exception;


final class VoiceFeatureException extends DomainException
{
    public const string VOICE_DISABLED = 'VOICE_DISABLED';

    /**
     * L'interrupteur de la commande vocale est coupé : la route fait comme si elle n'existait pas, `404` (ADR 0029 décision 8).
     */
    public static function disabled(): self
    {
        return new self(self::VOICE_DISABLED, 404);
    }
}
