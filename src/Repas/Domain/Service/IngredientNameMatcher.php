<?php

namespace Repas\Repas\Domain\Service;


use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Repas\Domain\Model\IngredientMatch;
use Repas\Repas\Domain\Model\MatchLevel;
use Repas\Repas\Domain\Model\NameSimilarity;
use Repas\Shared\Domain\Tool\JaroWinkler;
use Repas\Shared\Domain\Tool\StringTool;

/**
 * Dit si deux noms d'ingrédients sont LE MÊME ingrédient, avec certitude (ADR 0028 décision 8, `docs/ingredients/rapprochement.md`).
 * Pur : ni base ni horloge, les candidats lui sont donnés (`IngredientMatchCandidates`).
 *
 * Le principe : se tromper vers le doublon, jamais vers la fusion. Un doublon est visible et se répare d'un geste ; un faux rapprochement
 * est silencieux (les quantités se cumulent sur le mauvais produit). On ne branche donc que sur une certitude.
 *
 * 1. Normalisation (`N2`) : minuscules, accents et ponctuation retirés (`StringTool::slugify`), mots vides retirés, pluriels repliés.
 *    Elle ne sert qu'à COMPARER : le nom stocké d'un ingrédient n'est jamais réécrit.
 * 2. Garde-fou (`G6`) : même nombre de mots, et chaque mot identique ou à UNE faute près (insertion, oubli, substitution ou permutation de
 *    deux lettres voisines) quand le plus long des deux a cinq lettres ou plus ; un mot qui contient un chiffre doit être identique.
 *    « tomate » n'est jamais « tomate cerise », « couche taille 4 » n'est jamais « couche taille 5 ».
 * 3. Score : Jaro-Winkler des noms normalisés (`Shared\Domain\Tool\JaroWinkler`, ADR 0019), 1 pour deux noms normalisés identiques.
 *    La pertinence de recherche `SearchRelevance` n'est PAS utilisée : elle donne 0,946 à « tomate » contre « tomate cerise ».
 * 4. Niveaux : `EXACT` (nom normalisé identique) et `TYPO` (score de 0,97 au moins) branchent ; de 0,90 à 0,97 on ne fait que proposer.
 * 5. Départage : un nom exact l'emporte ; plusieurs exacts, le premier par slug (déterministe). Au niveau `TYPO`, un second candidat à moins
 *    de 0,02 du premier rend le lien ambigu : aucun lien.
 *
 * Mesure (1 421 cas de vérité terrain, `docs/ingredients/experiments/`, rejouée par `IngredientNameMatcherTest`) : zéro faux lien à 0,97,
 * 83 % de rappel dont 100 % des variantes d'écriture. Les constantes ne se changent que sur une relecture de `experiments/resultats.md`.
 */
final readonly class IngredientNameMatcher
{
    /** Score à partir duquel on branche une recette sur un ingrédient existant, sans demander. */
    public const float AUTO_THRESHOLD = 0.97;

    /** Score à partir duquel on PROPOSE à l'utilisateur deux ingrédients comme doublon possible (écran « Doublons possibles », ING-006). */
    public const float SUGGESTION_THRESHOLD = 0.90;

    /** Un second candidat à moins de cette distance du premier rend le lien ambigu. */
    public const float AMBIGUITY_MARGIN = 0.02;

    /** Mots sans valeur pour distinguer deux ingrédients. « sans » (il change le sens) et « sur » n'y sont pas. */
    public const array STOP_WORDS = ['de', 'du', 'des', 'd', 'l', 'la', 'le', 'les', 'un', 'une', 'a', 'au', 'aux', 'en', 'et', 'pour'];

    /** Un mot de cette longueur ou moins ne tolère aucune faute de frappe (quand le plus long des deux l'atteint). */
    private const int EXACT_WORD_UP_TO = 4;

    /**
     * Le nom normalisé de comparaison, en mots (`N2`). Vide si le nom n'a ni lettre ni chiffre : un tel nom ne ressemble à rien.
     *
     * @return list<string>
     */
    public static function tokens(string $name): array
    {
        $words = array_values(array_filter(explode('-', StringTool::slugify($name)), static fn(string $word): bool => $word !== ''));
        // Pluriels repliés : un « s » ou un « x » final tombe sur les mots de plus de trois lettres
        $folded = array_map(
            static fn(string $word): string => strlen($word) > 3 && ($word[-1] === 's' || $word[-1] === 'x') ? substr($word, 0, -1) : $word,
            $words,
        );
        $meaningful = array_values(array_filter($folded, static fn(string $word): bool => !in_array($word, self::STOP_WORDS, true)));

        // Un nom fait seulement de mots vides garde ses mots
        return $meaningful === [] ? $folded : $meaningful;
    }

    /**
     * Le candidat sur lequel brancher un ingrédient de ce nom et de ce rayon, ou `null` s'il n'y en a aucun de sûr. Seuls les candidats du
     * MÊME rayon sont comparés : « tomate » surgelée n'est pas « tomate » fruit et légume.
     *
     * @param iterable<IngredientCandidate> $candidates
     */
    public function match(string $name, string $departmentSlug, iterable $candidates): ?IngredientMatch
    {
        $tokens = self::tokens($name);
        if ($tokens === []) {
            return null;
        }

        $ranked = [];
        foreach ($candidates as $candidate) {
            if ($candidate->departmentSlug !== $departmentSlug) {
                continue;
            }
            $score = self::score($tokens, $candidate->tokens);
            if ($score !== null) {
                $ranked[] = [$score, $candidate];
            }
        }
        if ($ranked === []) {
            return null;
        }

        // Le meilleur score d'abord, le slug en ordre alphabétique à score égal : le résultat ne dépend pas de l'ordre des candidats
        usort($ranked, static fn(array $first, array $second): int => [$second[0], $first[1]->slug] <=> [$first[0], $second[1]->slug]);

        [$best, $candidate] = $ranked[0];
        if ($best >= 1.0) {
            return new IngredientMatch($candidate, MatchLevel::EXACT, $best);
        }
        if ($best < self::AUTO_THRESHOLD) {
            return null;
        }
        if (isset($ranked[1]) && $ranked[1][0] >= $best - self::AMBIGUITY_MARGIN) {
            // Deux candidats presque aussi bons : on ne choisit pas à la place de l'utilisateur
            return null;
        }

        return new IngredientMatch($candidate, MatchLevel::TYPO, $best);
    }

    /**
     * Le degré de ressemblance de deux noms, avec le même garde-fou que `match()` : sert à proposer des doublons possibles à
     * l'utilisateur (ING-006). Seuls `EXACT`, `TYPO` et `SUGGESTION` sont des ressemblances.
     */
    public function similar(string $first, string $second): MatchLevel
    {
        return self::levelOf(self::score(self::tokens($first), self::tokens($second)));
    }

    /**
     * Comme `similar()`, pour deux candidats dont les mots sont déjà calculés, et avec le score : de quoi comparer beaucoup de paires sans
     * normaliser dix fois le même nom (liste des doublons possibles, ING-006). `null` quand les deux ne se ressemblent pas assez pour
     * être proposés (niveau `NONE`). Le garde-fou apparie les mots dans l'ordre du premier : pour que le résultat ne dépende pas de
     * l'ordre dans lequel l'appelant les donne, il compare toujours le premier slug au second dans l'ordre alphabétique.
     */
    public function compare(IngredientCandidate $first, IngredientCandidate $second): ?NameSimilarity
    {
        [$first, $second] = strcmp($first->slug, $second->slug) <= 0 ? [$first, $second] : [$second, $first];
        $score = self::score($first->tokens, $second->tokens);
        $level = self::levelOf($score);

        return $level === MatchLevel::NONE ? null : new NameSimilarity($level, (float) $score);
    }

    private static function levelOf(?float $score): MatchLevel
    {
        return match (true) {
            $score === null => MatchLevel::NONE,
            $score >= 1.0 => MatchLevel::EXACT,
            $score >= self::AUTO_THRESHOLD => MatchLevel::TYPO,
            $score >= self::SUGGESTION_THRESHOLD => MatchLevel::SUGGESTION,
            default => MatchLevel::NONE,
        };
    }

    /**
     * Le score de deux noms normalisés, ou `null` quand ils ne passent pas le garde-fou (ou que l'un n'a aucun mot).
     *
     * @param list<string> $first
     * @param list<string> $second
     */
    private static function score(array $first, array $second): ?float
    {
        if ($first === [] || $second === [] || !self::passesGate($first, $second)) {
            return null;
        }

        $firstName = implode('-', $first);
        $secondName = implode('-', $second);

        return $firstName === $secondName ? 1.0 : JaroWinkler::similarity($firstName, $secondName);
    }

    /**
     * Le Jaro-Winkler d'un nom entier est dominé par sa partie commune (« couche taille 4 » et « couche taille 5 » : 0,97) : on exige
     * donc, mot à mot, le même nombre de mots et un pareil pour chacun (le plus ressemblant des mots restants).
     *
     * @param list<string> $first
     * @param list<string> $second
     */
    private static function passesGate(array $first, array $second): bool
    {
        if (count($first) !== count($second) || !self::mayBeTheSameLetters($first, $second)) {
            return false;
        }

        $free = $second;
        foreach ($first as $word) {
            $bestKey = null;
            $best = -1.0;
            foreach ($free as $key => $other) {
                $score = $word === $other ? 1.0 : JaroWinkler::similarity($word, $other);
                if ($score > $best) {
                    $best = $score;
                    $bestKey = $key;
                }
            }
            if ($bestKey === null) {
                return false;
            }
            $other = $free[$bestKey];
            unset($free[$bestKey]);

            if ($word === $other) {
                continue;
            }
            // Un mot différent n'est toléré que s'il est à une faute près, qu'il est assez long pour qu'une faute soit crédible et qu'il
            // ne porte aucun chiffre (« taille 4 », « 2e âge »)
            if (preg_match('/\d/', $word . $other) === 1 || max(strlen($word), strlen($other)) <= self::EXACT_WORD_UP_TO) {
                return false;
            }
            if (self::editDistance($word, $other) > 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Un refus rapide, SANS changer le verdict du garde-fou : deux noms dont chaque mot est identique ou à une faute près ne peuvent
     * différer, au total, que de `n` lettres en longueur et de `2n` lettres dans l'inventaire de leurs lettres (une insertion ou un oubli
     * change un compte d'une lettre, une substitution de deux, une permutation de zéro), `n` étant le nombre de mots. Le contraire est
     * refusé avant d'apparier les mots un à un par Jaro-Winkler, ce qui coûte bien plus cher : la liste des doublons possibles compare
     * des milliers de couples.
     *
     * @param list<string> $first
     * @param list<string> $second
     */
    private static function mayBeTheSameLetters(array $first, array $second): bool
    {
        $firstLetters = implode('', $first);
        $secondLetters = implode('', $second);
        $words = count($first);
        if (abs(strlen($firstLetters) - strlen($secondLetters)) > $words) {
            return false;
        }

        $inventory = count_chars($firstLetters, 1);
        foreach (count_chars($secondLetters, 1) as $letter => $count) {
            $inventory[$letter] = ($inventory[$letter] ?? 0) - $count;
        }

        return array_sum(array_map(abs(...), $inventory)) <= 2 * $words;
    }

    /**
     * Distance d'édition « optimal string alignment » : une insertion, un oubli, une substitution ou une permutation de deux lettres
     * voisines valent 1. Les mots sont de l'ASCII (`slugify`).
     */
    private static function editDistance(string $first, string $second): int
    {
        $n = strlen($first);
        $m = strlen($second);
        $distance = [];
        for ($i = 0; $i <= $n; $i++) {
            $distance[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $distance[0][$j] = $j;
        }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = $first[$i - 1] === $second[$j - 1] ? 0 : 1;
                $distance[$i][$j] = min($distance[$i - 1][$j] + 1, $distance[$i][$j - 1] + 1, $distance[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $first[$i - 1] === $second[$j - 2] && $first[$i - 2] === $second[$j - 1]) {
                    $distance[$i][$j] = min($distance[$i][$j], $distance[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $distance[$n][$m];
    }
}
