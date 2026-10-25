<?php

namespace Repas\Voice\Domain\Model;

/**
 * Ce qu'Alexa a entendu pour un créneau de l'intention : le texte dit, nettoyé et borné, et l'identifiant de la valeur du modèle
 * d'interaction auquel Alexa l'a rapproché (la résolution d'entité, `ER_SUCCESS_MATCH`). Les deux sont facultatifs : un créneau d'un type
 * personnalisé accepte aussi un texte hors liste, sans identifiant. Lecture TOLÉRANTE comme `AlexaRequest` : rien d'inattendu ne lève.
 */
final readonly class SlotValue
{
    /** Un identifiant du modèle (slug de rayon ou d'unité, rang) est court. */
    public const int MAX_ID_LENGTH = 100;

    public function __construct(
        public ?string $text,
        public ?string $id,
    ) {
    }

    /**
     * @param mixed $slot le créneau décodé (`request.intent.slots.<nom>`)
     */
    public static function fromDecoded(mixed $slot): ?self
    {
        if (!is_array($slot)) {
            return null;
        }

        $text = self::cleanText($slot['value'] ?? null);
        $id = self::matchedId($slot['resolutions'] ?? null);

        return $text === null && $id === null ? null : new self($text, $id);
    }

    /**
     * L'identifiant de la première valeur rapprochée avec succès, toutes autorités confondues.
     */
    private static function matchedId(mixed $resolutions): ?string
    {
        $authorities = is_array($resolutions) && is_array($resolutions['resolutionsPerAuthority'] ?? null) ? $resolutions['resolutionsPerAuthority'] : [];
        foreach ($authorities as $authority) {
            if (!is_array($authority) || ($authority['status']['code'] ?? null) !== 'ER_SUCCESS_MATCH' || !is_array($authority['values'] ?? null)) {
                continue;
            }
            foreach ($authority['values'] as $value) {
                $id = is_array($value) && is_array($value['value'] ?? null) ? ($value['value']['id'] ?? null) : null;
                if (is_string($id) && $id !== '' && mb_strlen($id) <= self::MAX_ID_LENGTH && preg_match('/^[a-z0-9-]+$/', $id) === 1) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Espaces réduits, caractères de contrôle retirés. `null` si vide, sans lettre ni chiffre (Alexa écrit « ? » quand elle n'a rien
     * compris) ou plus long que `AlexaRequest::MAX_PHRASE_LENGTH`.
     */
    public static function cleanText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $text = preg_replace(['/\p{C}+/u', '/\s+/u'], ' ', $value);
        $text = $text === null ? '' : trim($text);
        if ($text === '' || mb_strlen($text) > AlexaRequest::MAX_PHRASE_LENGTH || preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            return null;
        }

        return $text;
    }
}
