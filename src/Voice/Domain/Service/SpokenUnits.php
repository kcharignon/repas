<?php

namespace Repas\Voice\Domain\Service;

/**
 * Les unités du catalogue (`unit.json`) telles qu'on les dit à voix haute : le slug de chaque unité et les façons de la nommer, au singulier
 * et au pluriel (ADR 0029 décision 5, quantité dite). Pur.
 *
 * Deux usages : retrouver une unité d'après son texte quand la résolution d'entité d'Alexa n'a pas rendu son identifiant (`slugOf`), et
 * reconnaître une unité laissée EN TÊTE du créneau `item` (« paquets de croquettes ») pour la retirer de la phrase (`splitLeading`).
 * `docs/voice/skill/generate-model.mjs` écrit les mêmes mots dans le type `UNIT` du modèle d'interaction ; `DocsVoiceSkillTest` impose que
 * chaque mot du modèle soit connu ici, et chaque unité du catalogue présente.
 */
final readonly class SpokenUnits
{
    /** Le slug de l'unité « unité » (pièce) : la quantité sans autre unité, qui ne se dit pas. */
    public const string COUNT = 'unite';

    /** @var array<string, list<string>> slug => façons de dire l'unité (minuscules) */
    public const array FORMS = [
        'unite' => ['unité', 'unités', 'pièce', 'pièces'],
        'kilo' => ['kilo', 'kilos', 'kilogramme', 'kilogrammes'],
        'gramme' => ['gramme', 'grammes'],
        'litre' => ['litre', 'litres'],
        'millilitre' => ['millilitre', 'millilitres'],
        'centilitre' => ['centilitre', 'centilitres'],
        'cuillere-a-soupe' => ['cuillère à soupe', 'cuillères à soupe', 'cuillerée à soupe', 'cuillerées à soupe'],
        'cuillere-a-cafe' => ['cuillère à café', 'cuillères à café', 'cuillerée à café', 'cuillerées à café'],
        'tasse-a-cafe' => ['tasse à café', 'tasses à café', 'tasse', 'tasses'],
        'verre' => ['verre', 'verres'],
        'bol' => ['bol', 'bols'],
        'plaquette' => ['plaquette', 'plaquettes'],
        'tablette' => ['tablette', 'tablettes'],
        'tranche' => ['tranche', 'tranches'],
        'paquet' => ['paquet', 'paquets'],
        'boite' => ['boîte', 'boîtes', 'boite', 'boites'],
        'sachet' => ['sachet', 'sachets'],
        'bouteille' => ['bouteille', 'bouteilles'],
        'conserve' => ['conserve', 'conserves'],
        'cube' => ['cube', 'cubes'],
        'buche' => ['bûche', 'bûches', 'buche', 'buches'],
        'brique' => ['brique', 'briques'],
        'pot' => ['pot', 'pots'],
        'gousse' => ['gousse', 'gousses'],
        'flacon' => ['flacon', 'flacons'],
    ];

    /**
     * Le slug de l'unité que ce texte nomme (« Kilos », « paquet »), `null` si aucune.
     */
    public function slugOf(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = $this->normalize($text);
        foreach (self::FORMS as $slug => $forms) {
            if (in_array($normalized, $forms, true)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Si la phrase commence par une unité suivie de « de » ou « d' » et d'un nom (« paquets de croquettes », « 2 kilos » n'en est pas un),
     * le slug de l'unité et le reste de la phrase ; sinon `null`. Une unité seule, sans rien derrière, n'est pas retirée (c'est un nom).
     *
     * @return ?array{unitSlug: string, rest: string}
     */
    public function splitLeading(string $phrase): ?array
    {
        $normalized = $this->normalize($phrase);

        foreach ($this->longestFirst() as [$form, $slug]) {
            if (!str_starts_with($normalized, $form . ' ')) {
                continue;
            }
            $after = substr($normalized, strlen($form) + 1);
            $rest = match (true) {
                str_starts_with($after, 'de ') => substr($after, 3),
                str_starts_with($after, "d'") => substr($after, 2),
                default => null,
            };
            $rest = $rest === null ? '' : trim($rest);
            if ($rest !== '') {
                return ['unitSlug' => $slug, 'rest' => $rest];
            }
        }

        return null;
    }

    /**
     * @return list<array{string, string}> chaque façon de dire une unité avec son slug, les plus longues d'abord (« tasse à café » avant « tasse »)
     */
    private function longestFirst(): array
    {
        $pairs = [];
        foreach (self::FORMS as $slug => $forms) {
            foreach ($forms as $form) {
                $pairs[] = [$form, $slug];
            }
        }
        usort($pairs, static fn(array $first, array $second): int => mb_strlen($second[0]) <=> mb_strlen($first[0]));

        return $pairs;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(str_replace('’', "'", $text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
