<?php

namespace Repas\Voice\Domain\Service;


use Repas\Voice\Domain\Model\TracedRequest;
use Repas\Voice\Domain\Model\VoiceTrace;
use stdClass;

/**
 * Ce que la trace des échanges avec Alexa a le droit de garder (ADR 0029 décision 14). Pur : un corps décodé entre, une copie assainie sort.
 *
 * **Liste blanche, jamais liste noire** : seuls les champs nommés ici sont copiés, tout le reste est ignoré, y compris ce qu'Amazon ajouterait demain.
 *
 * - `request.{type, requestId, timestamp, locale, reason}`, `request.error.{type, message}` ;
 * - `request.intent.{name, confirmationStatus}` et, pour chaque créneau, `{name, value, confirmationStatus, source}` et ses `resolutions` : pour chaque
 *   autorité, le type du créneau (le dernier segment de l'autorité : jamais l'identifiant du skill qu'elle contient), le code de statut
 *   (`ER_SUCCESS_MATCH`, `ER_SUCCESS_NO_MATCH`…) et les valeurs `{name, id}` : c'est ce qui dit POURQUOI un rayon ou une unité n'a pas été reconnu ;
 * - `session.{new, sessionId, attributes}` : l'état du dialogue que notre réponse précédente a mis dans la séance.
 *
 * **Jamais** : `session.user`, `context.System.user` (le jeton d'accès, l'identifiant d'utilisateur Alexa), `apiAccessToken`, `apiEndpoint`, les
 * identifiants d'appareil, `session.application`, les en-têtes de signature, l'adresse de la chaîne de certificats : aucun de ces noms n'est lu.
 *
 * Toute chaîne est tronquée à `VoiceTrace::MAX_TEXT_LENGTH` caractères, la profondeur est bornée, chaque tableau garde 50 entrées au plus, et le JSON
 * entier tient en `VoiceTrace::MAX_JSON_BYTES` octets : au-delà, on garde ce qui tient et la clé `truncated` le dit.
 */
final readonly class AlexaTraceSanitizer
{
    private const int MAX_DEPTH = 6;
    private const int MAX_ENTRIES = 50;
    private const int MAX_SLOTS = 30;
    private const int MAX_AUTHORITIES = 10;
    private const int MAX_VALUES = 20;
    private const int MAX_KEY_LENGTH = 100;
    /** La place gardée pour la clé `truncated` et les accolades. */
    private const int MARKER_ROOM = 32;
    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * @param mixed $decoded le corps JSON décodé d'une requête DÉJÀ prouvée authentique
     */
    public function request(mixed $decoded): TracedRequest
    {
        $data = $this->objectIn($decoded);
        $request = $this->objectIn($data['request'] ?? null);
        $intent = $this->objectIn($request['intent'] ?? null);
        $session = $this->objectIn($data['session'] ?? null);

        $type = $this->text($request['type'] ?? null);
        $intentName = $this->text($intent['name'] ?? null);
        $sessionNew = is_bool($session['new'] ?? null) ? $session['new'] : null;

        $payload = $this->withoutEmpty([
            'request' => $this->withoutEmpty([
                'type' => $type,
                'requestId' => $this->text($request['requestId'] ?? null),
                'timestamp' => $this->text($request['timestamp'] ?? null),
                'locale' => $this->text($request['locale'] ?? null),
                'reason' => $this->text($request['reason'] ?? null),
                'error' => $this->error($request['error'] ?? null),
                'intent' => $this->intent($intent),
            ]),
            'session' => $this->withoutEmpty([
                'new' => $sessionNew,
                'sessionId' => $this->text($session['sessionId'] ?? null),
                'attributes' => is_array($session['attributes'] ?? null) ? $this->plain($session['attributes'], 0) : [],
            ]),
        ]);

        return new TracedRequest($type, $intentName, $sessionNew, $this->fitted($payload));
    }

    /**
     * La réponse rendue à Alexa (`AlexaResponse::toArray()`), bornée comme la requête.
     *
     * @param array<array-key, mixed> $response
     *
     * @return array<array-key, mixed>
     */
    public function response(array $response): array
    {
        $copy = $this->plain($response, 0);

        return $this->fitted(is_array($copy) ? $copy : []);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function error(mixed $error): array
    {
        $error = $this->objectIn($error);

        return $this->withoutEmpty([
            'type' => $this->text($error['type'] ?? null),
            'message' => $this->text($error['message'] ?? null),
        ]);
    }

    /**
     * @param array<array-key, mixed> $intent
     *
     * @return array<array-key, mixed>
     */
    private function intent(array $intent): array
    {
        if ($intent === []) {
            return [];
        }

        $slots = [];
        foreach ($this->objectIn($intent['slots'] ?? null) as $key => $slot) {
            if (count($slots) >= self::MAX_SLOTS) {
                break;
            }
            if (!is_string($key) || !is_array($slot)) {
                continue;
            }
            $slots[VoiceTrace::bounded($key, self::MAX_KEY_LENGTH)] = $this->withoutEmpty([
                'name' => $this->text($slot['name'] ?? null),
                'value' => $this->text($slot['value'] ?? null),
                'confirmationStatus' => $this->text($slot['confirmationStatus'] ?? null),
                'source' => $this->text($slot['source'] ?? null),
                'resolutions' => $this->resolutions($slot['resolutions'] ?? null),
            ]);
        }

        return $this->withoutEmpty([
            'name' => $this->text($intent['name'] ?? null),
            'confirmationStatus' => $this->text($intent['confirmationStatus'] ?? null),
            'slots' => $slots,
        ]);
    }

    /**
     * La résolution d'entité d'un créneau : pour chaque autorité, le type du créneau, le code de statut et les valeurs rapprochées.
     *
     * @return array<array-key, mixed>
     */
    private function resolutions(mixed $resolutions): array
    {
        $authorities = [];
        foreach (array_slice($this->listIn($this->objectIn($resolutions)['resolutionsPerAuthority'] ?? null), 0, self::MAX_AUTHORITIES) as $authority) {
            $authority = $this->objectIn($authority);

            $values = [];
            foreach (array_slice($this->listIn($authority['values'] ?? null), 0, self::MAX_VALUES) as $entry) {
                $value = $this->objectIn($this->objectIn($entry)['value'] ?? null);
                $kept = $this->withoutEmpty([
                    'name' => $this->text($value['name'] ?? null),
                    'id' => $this->text($value['id'] ?? null),
                ]);
                if ($kept !== []) {
                    $values[] = ['value' => $kept];
                }
            }

            $kept = $this->withoutEmpty([
                'authority' => $this->lastSegment($this->text($authority['authority'] ?? null)),
                'status' => $this->withoutEmpty(['code' => $this->text($this->objectIn($authority['status'] ?? null)['code'] ?? null)]),
                'values' => $values,
            ]);
            if ($kept !== []) {
                $authorities[] = $kept;
            }
        }

        return $authorities === [] ? [] : ['resolutionsPerAuthority' => $authorities];
    }

    /**
     * `amzn1.er-authority.echo-sdk.amzn1.ask.skill.<identifiant>.DEPARTMENT` devient `DEPARTMENT` : le type du créneau suffit, l'identifiant du skill
     * est propre au déploiement.
     */
    private function lastSegment(?string $authority): ?string
    {
        if ($authority === null) {
            return null;
        }
        $position = strrpos($authority, '.');

        return $position === false ? $authority : substr($authority, $position + 1);
    }

    /**
     * Une copie bornée en profondeur, en nombre d'entrées et en longueur de texte (les attributs de séance, la réponse).
     */
    private function plain(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            return VoiceTrace::bounded($value);
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return null;
        }
        if ($depth >= self::MAX_DEPTH) {
            return '…';
        }

        $copy = [];
        foreach ($value as $key => $entry) {
            if (count($copy) >= self::MAX_ENTRIES) {
                break;
            }
            $copy[is_string($key) ? VoiceTrace::bounded($key, self::MAX_KEY_LENGTH) : $key] = $this->plain($entry, $depth + 1);
        }

        return $copy;
    }

    /**
     * Le JSON tient en `MAX_JSON_BYTES` : sinon on garde ce qui tient, dans l'ordre, et `truncated` le dit.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private function fitted(array $payload): array
    {
        $dropped = false;
        $fitted = $this->shrunk($payload, VoiceTrace::MAX_JSON_BYTES - self::MARKER_ROOM, $dropped);
        if (!$dropped) {
            return $fitted;
        }

        $fitted['truncated'] = true;

        return $this->size($fitted) <= VoiceTrace::MAX_JSON_BYTES ? $fitted : ['truncated' => true];
    }

    /**
     * Le plus long début de `$value` (entrée après entrée, dans l'ordre) dont le JSON tient dans `$budget` octets ; une entrée qui est un tableau trop
     * gros est elle-même réduite à ce qui tient. `$dropped` devient vrai dès que quelque chose est écarté.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function shrunk(array $value, int $budget, bool &$dropped): array
    {
        if ($this->size($value) <= $budget) {
            return $value;
        }

        $dropped = true;
        $isList = array_is_list($value);
        $kept = [];
        foreach ($value as $key => $entry) {
            $candidate = $kept;
            if ($isList) {
                $candidate[] = $entry;
            } else {
                $candidate[$key] = $entry;
            }
            if ($this->size($candidate) <= $budget) {
                $kept = $candidate;
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            // L'entrée entière ne tient pas : ce qui peut en tenir, dans la place qui reste (celle de l'entrée vide `[]`, deux octets, comprise)
            $shell = $kept;
            if ($isList) {
                $shell[] = [];
            } else {
                $shell[$key] = [];
            }
            $room = $budget - $this->size($shell);
            if ($room <= 0) {
                continue;
            }
            $inner = $this->shrunk($entry, $room + 2, $dropped);
            if ($inner === []) {
                continue;
            }
            if ($isList) {
                $kept[] = $inner;
            } else {
                $kept[$key] = $inner;
            }
        }

        return $kept;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function size(array $value): int
    {
        return strlen((string) json_encode($value, self::JSON_FLAGS));
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? VoiceTrace::bounded($value) : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function objectIn(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function listIn(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function withoutEmpty(array $values): array
    {
        return array_filter($values, static fn(mixed $value): bool => $value !== null && $value !== []);
    }
}
