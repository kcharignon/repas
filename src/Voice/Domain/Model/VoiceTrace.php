<?php

namespace Repas\Voice\Domain\Model;


use DateTimeImmutable;

/**
 * Un échange avec Alexa, tel que la trace le garde (ADR 0029 décision 14, amendement du 2026-11-15, demande du propriétaire : phase de test).
 * Alexa comprend parfois mal une phrase ; sans trace, rien ne dit ce qu'Amazon a envoyé. Une ligne par requête authentique du webhook :
 *
 * - la requête **assainie par liste blanche** (`AlexaTraceSanitizer` : jamais le jeton d'accès, jamais l'identifiant d'utilisateur Alexa ni d'appareil),
 *   la réponse rendue, l'issue, la durée, et en cas de panne la classe, l'endroit et le message tronqué de l'exception ;
 * - **bornée** : au plus `RETENTION` échanges, les plus récents ; l'insertion supprime ce qui dépasse. Chaque texte tient en `MAX_TEXT_LENGTH`
 *   caractères, chaque JSON en `MAX_JSON_BYTES` octets ;
 * - **sous interrupteur** (`ALEXA_TRACE_ENABLED`, coupé par défaut), purgée avec le compte, jamais lue par une route HTTP : une commande de console
 *   (`repas:voice:traces`) la lit. À retirer ou à couper à la fin de la phase de test.
 *
 * L'identité de la ligne (son numéro croissant) est donnée par la base à l'insertion.
 */
final readonly class VoiceTrace
{
    /** Les échanges gardés : les 1 000 plus récents. */
    public const int RETENTION = 1000;

    /** Longueur maximale (en caractères) de toute chaîne gardée. */
    public const int MAX_TEXT_LENGTH = 500;

    /** Taille maximale (en octets) de la requête et de la réponse gardées, chacune. */
    public const int MAX_JSON_BYTES = 16384;

    /** Tailles des colonnes courtes de `voice_trace`. */
    private const int MAX_REQUEST_TYPE_LENGTH = 50;
    private const int MAX_INTENT_LENGTH = 100;
    private const int MAX_DECISION_LENGTH = 60;

    /**
     * @param array<array-key, mixed> $request la requête assainie
     * @param array<array-key, mixed> $response la réponse rendue, bornée
     */
    private function __construct(
        public DateTimeImmutable $createdAt,
        public ?string $userId,
        public ?string $linkId,
        public string $requestType,
        public ?string $intent,
        public ?bool $sessionNew,
        public TraceOutcome $outcome,
        public ?string $decision,
        public int $durationMs,
        public array $request,
        public array $response,
        public ?TracedFailure $failure,
    ) {
    }

    /**
     * @param array<array-key, mixed> $response la réponse déjà bornée (`AlexaTraceSanitizer::response`)
     */
    public static function record(
        DateTimeImmutable $createdAt,
        ?string $userId,
        ?string $linkId,
        TracedRequest $request,
        array $response,
        TraceOutcome $outcome,
        ?string $decision,
        int $durationMs,
        ?TracedFailure $failure,
    ): self {
        return new self(
            $createdAt,
            $userId,
            $linkId,
            mb_substr($request->type ?? 'inconnu', 0, self::MAX_REQUEST_TYPE_LENGTH),
            $request->intent === null ? null : mb_substr($request->intent, 0, self::MAX_INTENT_LENGTH),
            $request->sessionNew,
            $outcome,
            $decision === null ? null : mb_substr($decision, 0, self::MAX_DECISION_LENGTH),
            max(0, $durationMs),
            $request->payload,
            $response,
            $failure,
        );
    }

    /**
     * Un texte gardé : sans caractère nul (un JSONB ne sait pas le stocker), sans octet invalide, tronqué à `$max` caractères.
     */
    public static function bounded(string $text, int $max = self::MAX_TEXT_LENGTH): string
    {
        return mb_substr(mb_scrub(str_replace("\0", '', $text)), 0, $max);
    }
}
