<?php

namespace Repas\Voice\Domain\Model;


use DateTimeImmutable;
use JsonException;

/**
 * Ce que la vérification d'une requête d'Alexa lit dans son corps (ADR 0029 décision 7) : l'horodatage (`request.timestamp`) et le
 * ou les identifiants de skill (`session.application.applicationId`, `context.System.application.applicationId`). Rien d'autre :
 * le contenu de la requête (la phrase prononcée) n'est jamais gardé ici, ni journalisé.
 *
 * Le corps est construit seulement s'il est un objet JSON (profondeur 32 au plus) dont `request.timestamp` est une date ISO 8601
 * valide et complète, et dont au moins un identifiant de skill est présent ; un identifiant présent mais mal formé (pas une chaîne,
 * vide, ou sous un nœud qui n'est pas un objet) refuse le corps entier plutôt que d'être ignoré.
 */
final readonly class AlexaRequestBody
{
    /** Tolérance de la page d'Amazon : une requête à 150 s de l'horloge ou moins est fraîche (151 s, périmée). */
    public const int TOLERANCE_SECONDS = 150;

    private const int MAX_DEPTH = 32;
    private const string TIMESTAMP_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?<fraction>\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/';

    /** @var list<list<string>> les deux endroits où Alexa écrit l'identifiant du skill */
    private const array APPLICATION_ID_PATHS = [
        ['session', 'application', 'applicationId'],
        ['context', 'System', 'application', 'applicationId'],
    ];

    /**
     * @param list<string> $applicationIds tous les identifiants présents, au moins un
     */
    private function __construct(
        private DateTimeImmutable $timestamp,
        private array $applicationIds,
    ) {
    }

    public static function tryFrom(string $rawBody): ?self
    {
        try {
            $data = json_decode($rawBody, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || array_is_list($data)) {
            return null;
        }

        $request = $data['request'] ?? null;
        $timestamp = is_array($request) ? self::parseTimestamp($request['timestamp'] ?? null) : null;
        if ($timestamp === null) {
            return null;
        }

        $applicationIds = [];
        foreach (self::APPLICATION_ID_PATHS as $path) {
            $found = self::valueAt($data, $path);
            if ($found === false) {
                return null;
            }
            if ($found !== null) {
                $applicationIds[] = $found;
            }
        }

        return $applicationIds === [] ? null : new self($timestamp, $applicationIds);
    }

    /**
     * L'horodatage est à 150 s de `$now` ou moins, avant comme après (une horloge d'Amazon en avance ou en retard).
     */
    public function isFreshAt(DateTimeImmutable $now): bool
    {
        $difference = self::microseconds($now) - self::microseconds($this->timestamp);

        return abs($difference) <= self::TOLERANCE_SECONDS * 1_000_000;
    }

    /**
     * Tous les identifiants présents sont celui de notre skill. `$skillId` vide (skill non configuré) ne correspond à rien.
     */
    public function isAddressedTo(string $skillId): bool
    {
        if ($skillId === '') {
            return false;
        }

        foreach ($this->applicationIds as $applicationId) {
            if (!hash_equals($skillId, $applicationId)) {
                return false;
            }
        }

        return true;
    }

    private static function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match(self::TIMESTAMP_PATTERN, $value, $matches) !== 1) {
            return null;
        }

        $format = ($matches['fraction'] ?? '') === '' ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP';
        $timestamp = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($timestamp === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            // Une date qui n'existe pas (31 février) déborde sur le mois suivant avec un simple avertissement : on la refuse.
            return null;
        }

        return $timestamp;
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $path
     *
     * @return string|false|null la valeur, `null` si le chemin n'existe pas, `false` s'il existe mais n'est pas une chaîne non vide
     */
    private static function valueAt(array $data, array $path): string|false|null
    {
        $node = $data;
        foreach ($path as $key) {
            if (!is_array($node)) {
                return false;
            }
            if (!array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return is_string($node) && $node !== '' ? $node : false;
    }

    private static function microseconds(DateTimeImmutable $date): int
    {
        return $date->getTimestamp() * 1_000_000 + (int) $date->format('u');
    }
}
