<?php

namespace Repas\Repas\Domain\Service;


use Repas\Repas\Domain\Model\Confidence;
use Repas\Repas\Domain\Model\RankedIngredient;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Shared\Domain\Tool\SearchRelevance;

/**
 * Choisit, parmi les ingrédients d'un utilisateur, le plus probable pour une phrase prononcée (« des tomates », « du pain de mie »),
 * d'après la pertinence du nom et les habitudes (ADR 0029 décision 3, `docs/voice/alexa.md` § 4). Pur : ni base ni horloge, les candidats
 * (et leur nombre d'usages sur douze mois) lui sont donnés.
 *
 * 1. Normalisation : `IngredientNameMatcher::tokens` (accents, casse et ponctuation retirés, pluriels repliés, mots vides retirés : ses
 *    `STOP_WORDS` couvrent déjà « du », « de la », « des », « un », « une »). Une phrase sans aucun mot qui compte : rien.
 * 2. Mémoire de phrase : si l'ingrédient retenu pour cette phrase est parmi les candidats, il l'emporte sur tout, `SURE`.
 * 3. Sinon, seuls restent les candidats que le classeur choisit SANS DEMANDER (`isChosenByItself`) : une pertinence d'au moins
 *    `SearchRelevance::MINIMUM` ET un nom identique, une phrase qui commence le nom ou l'un de ses mots (« pain » donne *pain de mie*), ou
 *    une simple faute de frappe (`TYPO_THRESHOLD`). Le seuil de recherche seul est trop généreux pour deviner : « ciboulette » ressemble à
 *    *coquilette* (0,907) sans que ce soit le même produit. Une habitude ne fait jamais passer un nom trop différent. Notés `pertinence + HABIT_WEIGHT × n / (n + HABIT_SOFTNESS)`.
 * 4. Un nom identique à la phrase (une fois normalisés) passe avant tout nom qui n'est que proche : « tomate » donne *tomate* même si
 *    *tomates pelées* est acheté bien plus souvent, car le bonus d'habitude (jusqu'à 0,1) peut dépasser l'écart de pertinence (0,95 contre 1).
 *    L'habitude ne départage donc que des noms de même rang.
 * 5. Confiance : voir `confidenceOf()`. Ordre déterministe : score, puis nom, puis slug.
 * 6. `UNSURE` : le premier et le suivant se touchent. `ties()` rend alors les ex æquo (trois au plus) : Alexa DEMANDE le rayon (ou le nom)
 *    au lieu de deviner (ADR 0029 décision 3, amendement du 2026-10-25). Les habitudes continuent de trancher quand elles le peuvent.
 *
 * Ce n'est pas `IngredientNameMatcher::compare` : lui dit « c'est le même ingrédient » avec certitude, en se trompant vers le doublon.
 * Ici l'utilisateur veut le plus probable, et l'erreur se répare d'un geste dans l'application.
 */
final readonly class SpokenIngredientRanker
{
    /** Poids maximal de l'habitude dans le score : assez pour départager des noms proches, trop peu pour faire passer un nom lointain. */
    public const float HABIT_WEIGHT = 0.1;

    /** Douceur de l'habitude `n / (n + HABIT_SOFTNESS)` : trois usages valent la moitié du poids, les suivants comptent de moins en moins. */
    public const int HABIT_SOFTNESS = 3;

    /** Écart de score à partir duquel le premier devance « nettement » le second (`PROBABLE` ; au-dessous, `UNSURE`). */
    public const float CLEAR_MARGIN = 0.03;

    /** Le score d'un ingrédient mémorisé pour la phrase : au-dessus de tout score calculé (au plus 0,999 + HABIT_WEIGHT). */
    public const float REMEMBERED_SCORE = 2.0;

    /**
     * Le seuil du choix AUTOMATIQUE d'une faute de frappe : un nom proche mais ni identique ni commencé par la phrase n'est choisi sans demander
     * qu'à partir de ce score (« ciboulete » contre *ciboulette* 0,98). Plus strict que `SearchRelevance::MINIMUM` (0,85), qui reste celui de la
     * recherche : chercher peut montrer des noms approchants, mais deviner à la place de l'utilisateur un produit qui n'existe pas (« ciboulette »
     * devinée *coquilette* à 0,907) ruine le dialogue.
     */
    public const float TYPO_THRESHOLD = 0.94;

    /** Le plancher de proximité des propositions : au-dessous, un nom n'a plus de rapport avec la phrase (voir `suggest()`). */
    public const float SUGGESTION_FLOOR = 0.80;

    /** Le nombre de propositions faites à l'utilisateur (trois se disent, quatre se retiennent mal). */
    public const int MAX_SUGGESTIONS = 3;

    /** Un nom proposé commence comme un mot de la phrase par au moins ce nombre de lettres (ou en est un mot plus court en entier). */
    public const int SHARED_PREFIX = 3;

    /** Longueur de la clé de la mémoire de phrase (`spoken_phrase_choice.phrase`). */
    public const int MAX_KEY_LENGTH = 100;

    /** Tolérance des comparaisons de scores à virgule flottante (l'écart de 0,03 compte, même avec l'arrondi des flottants). */
    private const float EPSILON = 1e-9;

    /**
     * @param list<SpokenCandidate> $candidates les ingrédients de l'utilisateur
     * @param ?string $rememberedSlug l'ingrédient que l'utilisateur a déjà choisi pour cette phrase
     */
    public function rank(string $phrase, array $candidates, ?string $rememberedSlug = null): ?RankedIngredient
    {
        if (self::normalize($phrase) === '') {
            return null;
        }

        $remembered = $this->remembered($candidates, $rememberedSlug);
        if ($remembered !== null) {
            return $remembered;
        }

        $pool = $this->pool($phrase, $candidates);
        if ($pool === []) {
            return null;
        }

        $best = $pool[0];
        $confidence = self::confidenceOf($best['exact'], $best['score'], $pool[1]['score'] ?? null);

        return self::ranked($best['candidate'], $best['score'], $confidence);
    }

    /**
     * Les ex æquo à départager par une question, quand `rank()` n'est pas sûr (`UNSURE`) : le meilleur et ceux dont le score est à moins de
     * `CLEAR_MARGIN` du sien (trois au plus, le meilleur d'abord, ordre déterministe). L'habitude a déjà tranché si elle le pouvait
     * (`PROBABLE`, aucun ex æquo) et la mémoire de phrase passe avant tout (aucun ex æquo). Vide aussi quand deux ex æquo ne se distinguent
     * ni par le nom ni par le rayon : une question ne les départagerait pas, l'appelant garde le premier de `rank()`.
     *
     * @param list<SpokenCandidate> $candidates les ingrédients de l'utilisateur
     *
     * @return list<RankedIngredient> deux à `MAX_SUGGESTIONS` ingrédients, ou aucun
     */
    public function ties(string $phrase, array $candidates, ?string $rememberedSlug = null): array
    {
        if (self::normalize($phrase) === '' || $this->remembered($candidates, $rememberedSlug) !== null) {
            return [];
        }

        $pool = $this->pool($phrase, $candidates);
        if (count($pool) < 2) {
            return [];
        }
        $best = $pool[0];
        if (self::confidenceOf($best['exact'], $best['score'], $pool[1]['score']) !== Confidence::UNSURE) {
            return [];
        }

        $tied = [];
        foreach ($pool as $entry) {
            if ($best['score'] - $entry['score'] >= self::CLEAR_MARGIN - self::EPSILON) {
                break;
            }
            $tied[] = $entry;
        }
        $tied = array_slice($tied, 0, self::MAX_SUGGESTIONS);

        // Une question départage par le nom ET le rayon : deux ex æquo qui disent la même chose ne se départagent pas
        $labels = array_map(static fn(array $entry): string => $entry['candidate']->sluggedName . '|' . $entry['candidate']->departmentName, $tied);
        if (count(array_unique($labels)) !== count($labels)) {
            return [];
        }

        return array_map(static fn(array $entry): RankedIngredient => self::ranked($entry['candidate'], $entry['score'], Confidence::UNSURE), $tied);
    }

    /**
     * L'ingrédient que l'utilisateur a déjà choisi pour la phrase, s'il est parmi les candidats.
     *
     * @param list<SpokenCandidate> $candidates
     */
    private function remembered(array $candidates, ?string $rememberedSlug): ?RankedIngredient
    {
        if ($rememberedSlug === null) {
            return null;
        }
        foreach ($candidates as $candidate) {
            if ($candidate->slug === $rememberedSlug) {
                return self::ranked($candidate, self::REMEMBERED_SCORE, Confidence::SURE);
            }
        }

        return null;
    }

    /**
     * Les candidats que le classeur retient sans demander, du meilleur au moins bon (les noms identiques à la phrase seuls, s'il y en a).
     *
     * @param list<SpokenCandidate> $candidates
     *
     * @return list<array{candidate: SpokenCandidate, exact: bool, score: float}>
     */
    private function pool(string $phrase, array $candidates): array
    {
        $search = self::normalize($phrase);
        if ($search === '' || $candidates === []) {
            return [];
        }

        $scored = [];
        foreach ($candidates as $candidate) {
            $name = self::normalize($candidate->sluggedName);
            $relevance = SearchRelevance::score($search, $name);
            if (!self::isChosenByItself($search, $name, $relevance)) {
                continue;
            }
            $scored[] = [
                'candidate' => $candidate,
                'exact' => $name === $search,
                'score' => $relevance + self::habit($candidate->uses12m),
            ];
        }
        if ($scored === []) {
            return [];
        }

        // Les noms identiques à la phrase seuls, s'il y en a ; sinon tous les noms assez proches
        $exact = array_values(array_filter($scored, static fn(array $entry): bool => $entry['exact']));
        $pool = $exact !== [] ? $exact : $scored;

        // Le meilleur score d'abord ; à score égal le nom puis le slug : le résultat ne dépend pas de l'ordre des candidats
        usort($pool, static fn(array $first, array $second): int => [$second['score'], $first['candidate']->sluggedName, $first['candidate']->slug]
            <=> [$first['score'], $second['candidate']->sluggedName, $second['candidate']->slug]);

        return $pool;
    }

    /**
     * Les ingrédients les plus proches d'une phrase que `rank()` ne choisit PAS tout seul : « De quoi tu parles ? » (ADR 0029 décision 11).
     * Au plus `MAX_SUGGESTIONS`, classés comme `rank()` (pertinence + bonus d'habitude), et seulement ceux qui passent le plancher :
     *
     * - une pertinence d'au moins `SUGGESTION_FLOOR` et un nom que `rank()` ne choisit pas tout seul (`isChosenByItself`) ;
     * - ET un début de mot en commun avec la phrase (`SHARED_PREFIX` lettres) : Jaro-Winkler seul note 0,81 « fenouil » contre « nouille » ou
     *   « basilic » contre « ail », deux noms sans rapport. Le début en commun ne retient que de vraies ressemblances (« sirop de menthe »
     *   et « sirop fruit rouge »).
     *
     * L'ordre est déterministe (score, puis nom, puis slug). Aucun ingrédient assez proche : liste vide, et l'appelant propose de créer.
     *
     * @param list<SpokenCandidate> $candidates les ingrédients de l'utilisateur
     *
     * @return list<RankedIngredient>
     */
    public function suggest(string $phrase, array $candidates, int $limit = self::MAX_SUGGESTIONS): array
    {
        $searchWords = self::words($phrase);
        if ($searchWords === [] || $candidates === []) {
            return [];
        }
        $search = implode('-', $searchWords);

        $scored = [];
        foreach ($candidates as $candidate) {
            $nameWords = self::words($candidate->sluggedName);
            $relevance = SearchRelevance::score($search, implode('-', $nameWords));
            if ($relevance < self::SUGGESTION_FLOOR || self::isChosenByItself($search, implode('-', $nameWords), $relevance) || !self::sharesAPrefix($searchWords, $nameWords)) {
                continue;
            }
            $scored[] = ['candidate' => $candidate, 'score' => $relevance + self::habit($candidate->uses12m)];
        }

        usort($scored, static fn(array $first, array $second): int => [$second['score'], $first['candidate']->sluggedName, $first['candidate']->slug]
            <=> [$first['score'], $second['candidate']->sluggedName, $second['candidate']->slug]);

        return array_map(
            static fn(array $entry): RankedIngredient => self::ranked($entry['candidate'], $entry['score'], Confidence::UNSURE),
            array_slice($scored, 0, max(0, $limit)),
        );
    }

    /**
     * La clé de la mémoire de phrase : la phrase normalisée comme le classeur la lit (`rank()`), bornée à `MAX_KEY_LENGTH`. « du pain » et
     * « le pains » ont la même clé. Vide si aucun mot ne compte : il n'y a alors rien à mémoriser.
     */
    public static function phraseKey(string $phrase): string
    {
        return mb_substr(self::normalize($phrase), 0, self::MAX_KEY_LENGTH);
    }

    /**
     * `SURE` : le seul nom identique à la phrase. `PROBABLE` : le premier devance le suivant d'au moins `CLEAR_MARGIN`, ou n'a pas de
     * suivant. `UNSURE` : le premier et le suivant se touchent (deux noms identiques ou deux noms proches d'habitudes voisines).
     */
    private static function confidenceOf(bool $exact, float $best, ?float $next): Confidence
    {
        if ($next === null) {
            return $exact ? Confidence::SURE : Confidence::PROBABLE;
        }

        return $best - $next >= self::CLEAR_MARGIN - self::EPSILON ? Confidence::PROBABLE : Confidence::UNSURE;
    }

    /**
     * Un candidat que `rank()` retient sans demander : une pertinence d'au moins `SearchRelevance::MINIMUM` ET (un nom identique à la phrase, OU
     * une phrase qui commence le nom ou l'un de ses mots — la branche « contient » de `SearchRelevance` : « pain » donne *pain de mie* —, OU une
     * pertinence d'au moins `TYPO_THRESHOLD`, la simple faute de frappe). Tout le reste est au plus une proposition.
     */
    private static function isChosenByItself(string $search, string $name, float $relevance): bool
    {
        if ($relevance < SearchRelevance::MINIMUM) {
            return false;
        }

        return $name === $search || str_contains('-' . $name, '-' . $search) || $relevance >= self::TYPO_THRESHOLD;
    }

    /** Le bonus d'habitude, de 0 (jamais utilisé) à `HABIT_WEIGHT` (très utilisé). */
    private static function habit(int $uses): float
    {
        $uses = max(0, $uses);

        return self::HABIT_WEIGHT * $uses / ($uses + self::HABIT_SOFTNESS);
    }

    /** Le nom ou la phrase normalisés (mots joints par « - ») ; vide si aucun mot ne compte. */
    private static function normalize(string $text): string
    {
        return implode('-', self::words($text));
    }

    /**
     * @return list<string> les mots qui comptent (pluriels repliés, mots vides retirés)
     */
    private static function words(string $text): array
    {
        return array_values(array_filter(
            IngredientNameMatcher::tokens($text),
            static fn(string $word): bool => !in_array($word, IngredientNameMatcher::STOP_WORDS, true),
        ));
    }

    /**
     * Un mot de la phrase et un mot du nom commencent pareil, sur `SHARED_PREFIX` lettres (ou sur toute la longueur du plus court).
     *
     * @param list<string> $searchWords
     * @param list<string> $nameWords
     */
    private static function sharesAPrefix(array $searchWords, array $nameWords): bool
    {
        foreach ($searchWords as $searchWord) {
            foreach ($nameWords as $nameWord) {
                $length = min(self::SHARED_PREFIX, strlen($searchWord), strlen($nameWord));
                if ($length > 0 && substr($searchWord, 0, $length) === substr($nameWord, 0, $length)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function ranked(SpokenCandidate $candidate, float $score, Confidence $confidence): RankedIngredient
    {
        return new RankedIngredient($candidate->slug, $candidate->name, $candidate->departmentName, $score, $confidence, $candidate->departmentSlug);
    }
}
