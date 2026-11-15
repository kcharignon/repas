<?php

namespace Repas\Voice\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `voice_trace` (ADR 0029 décision 14, amendement du 2026-11-15) : la trace des échanges avec Alexa pendant la phase de test, les
 * `VoiceTrace::RETENTION` plus récents. Écrite et lue en SQL direct (`VoiceTracePostgreSQLRepository`, `VoiceTracePostgreSQLLoader`) : cette entité ne
 * sert qu'à décrire le schéma. `id` est un numéro croissant donné par la base. `user_id` et `link_id` sont vides quand aucun jeton n'a été résolu ;
 * `user_id` est effacé avec le compte (`PurgeUserVoiceData`, ADR 0021). `request` est la requête ASSAINIE par liste blanche (jamais le jeton d'accès),
 * `response` la réponse rendue, `failure` la panne éventuelle. Aucune clé étrangère.
 */
#[ORM\Entity]
#[ORM\Table(name: 'voice_trace')]
#[ORM\Index(name: 'idx_voice_trace_user', columns: ['user_id'])]
class VoiceTrace
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    public ?string $id = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36, nullable: true)]
    public ?string $userId = null;

    #[ORM\Column(name: 'link_id', type: Types::STRING, length: 36, nullable: true)]
    public ?string $linkId = null;

    #[ORM\Column(name: 'request_type', type: Types::STRING, length: 50)]
    public string $requestType;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    public ?string $intent = null;

    #[ORM\Column(name: 'session_new', type: Types::BOOLEAN, nullable: true)]
    public ?bool $sessionNew = null;

    #[ORM\Column(type: Types::STRING, length: 40)]
    public string $outcome;

    #[ORM\Column(type: Types::STRING, length: 60, nullable: true)]
    public ?string $decision = null;

    #[ORM\Column(name: 'duration_ms', type: Types::INTEGER)]
    public int $durationMs = 0;

    /** @var array<array-key, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $request = [];

    /** @var array<array-key, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $response = [];

    /** @var array<string, string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $failure = null;
}
