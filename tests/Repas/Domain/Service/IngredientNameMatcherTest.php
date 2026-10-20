<?php

namespace Repas\Tests\Repas\Domain\Service;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Repas\Domain\Model\MatchLevel;
use Repas\Repas\Domain\Model\NameSimilarity;
use Repas\Repas\Domain\Service\IngredientNameMatcher;

/**
 * Le rapprochement de noms d'ingrédients (ADR 0028 décision 8, `docs/ingredients/rapprochement.md`), avec les constantes de PRODUCTION :
 * la vérité terrain de l'expérience (1 421 cas synthétiques sur les communs publics du dépôt, aucune donnée d'utilisateur) y est rejouée.
 * Un faux lien est silencieux : le critère n'est pas la précision moyenne mais ZÉRO faux lien.
 */
class IngredientNameMatcherTest extends TestCase
{
    private IngredientNameMatcher $matcher;

    /** @var array<string, list<IngredientCandidate>> les communs du jeu de référence, par rayon */
    private array $byDepartment = [];

    protected function setUp(): void
    {
        $this->matcher = new IngredientNameMatcher();
        $commons = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/src/Repas/Infrastructure/DataFixture/Data/ingredient.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($commons as $common) {
            $candidate = new IngredientCandidate($common['slug'], $common['name'], $common['department'], $common['default_purchase_unit'], $common['compatible_units']);
            $this->byDepartment[$common['department']][] = $candidate;
        }
    }

    // --- Les constantes ------------------------------------------------------------------------------------------------------------

    public function testTheProductionConstantsAreTheOnesTheExperimentMeasured(): void
    {
        // Lues par réflexion : un changement de valeur doit casser CE test, et se justifier par une relecture de `experiments/resultats.md`
        $constants = new \ReflectionClass(IngredientNameMatcher::class)->getConstants();
        $this->assertSame(0.97, $constants['AUTO_THRESHOLD']);
        $this->assertSame(0.90, $constants['SUGGESTION_THRESHOLD']);
        $this->assertSame(0.02, $constants['AMBIGUITY_MARGIN']);
        $this->assertSame(['de', 'du', 'des', 'd', 'l', 'la', 'le', 'les', 'un', 'une', 'a', 'au', 'aux', 'en', 'et', 'pour'], $constants['STOP_WORDS']);
    }

    // --- La vérité terrain ---------------------------------------------------------------------------------------------------------

    public function testNoCaseExpectingNoLinkIsEverLinked(): void
    {
        // Given
        $cases = array_filter($this->truth(), static fn(array $case): bool => $case['expected'] === null);

        // When
        $falseLinks = [];
        foreach ($cases as $case) {
            $match = $this->matcher->match($case['query'], $case['department'], $this->byDepartment[$case['department']] ?? []);
            if ($match !== null) {
                $falseLinks[] = sprintf('[%s] « %s » -> %s', $case['category'], $case['query'], $match->candidate->slug);
            }
        }

        // Then : zéro faux lien
        $this->assertGreaterThan(60, count($cases), 'La vérité terrain attend 66 cas « aucun » (qualificatifs et frères trompeurs).');
        $this->assertSame([], $falseLinks);
    }

    public function testEveryCommonReplayedAgainstTheOthersOfItsDepartmentIsNeverLinked(): void
    {
        // Given : aucun doublon dans le jeu de référence, donc tout lien d'un commun vers un AUTRE commun de son rayon est faux
        $falseLinks = [];
        $replayed = 0;
        foreach ($this->byDepartment as $department => $candidates) {
            foreach ($candidates as $common) {
                $others = array_values(array_filter($candidates, static fn(IngredientCandidate $other): bool => $other->slug !== $common->slug));

                // When
                $match = $this->matcher->match($common->name, $department, $others);
                $replayed++;

                // Then
                if ($match !== null) {
                    $falseLinks[] = sprintf('« %s » -> %s', $common->name, $match->candidate->slug);
                }
            }
        }

        $this->assertSame(256, $replayed);
        $this->assertSame([], $falseLinks);
    }

    #[DataProvider('writingVariantCategories')]
    public function testEveryWritingVariantIsLinkedToTheRightCandidate(string $category): void
    {
        // Given
        $cases = array_filter($this->truth(), static fn(array $case): bool => $case['category'] === $category);

        // When
        $missed = [];
        foreach ($cases as $case) {
            $match = $this->matcher->match($case['query'], $case['department'], $this->byDepartment[$case['department']] ?? []);
            if ($match?->candidate->slug !== $case['expected']) {
                $missed[] = sprintf('« %s » -> %s (attendu %s)', $case['query'], $match?->candidate->slug ?? 'aucun', $case['expected']);
            }
        }

        // Then : 100 %
        $this->assertNotEmpty($cases);
        $this->assertSame([], $missed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writingVariantCategories(): iterable
    {
        foreach (['casse', 'ponctuation', 'accent', 'pluriel'] as $category) {
            yield $category => [$category];
        }
    }

    public function testAtLeastHalfOfTheTypingMistakesAreLinkedAndNoneToTheWrongCandidate(): void
    {
        // Given
        $cases = array_filter($this->truth(), static fn(array $case): bool => $case['category'] === 'faute');

        // When
        $linked = 0;
        $wrong = [];
        foreach ($cases as $case) {
            $match = $this->matcher->match($case['query'], $case['department'], $this->byDepartment[$case['department']] ?? []);
            if ($match === null) {
                continue;
            }
            if ($match->candidate->slug === $case['expected']) {
                $linked++;
            } else {
                $wrong[] = sprintf('« %s » -> %s (attendu %s)', $case['query'], $match->candidate->slug, $case['expected']);
            }
        }

        // Then
        $this->assertSame([], $wrong, 'Une faute de frappe ne se branche jamais sur un autre produit.');
        $this->assertGreaterThanOrEqual(0.5, $linked / count($cases));
    }

    public function testTheGroundTruthIsTheFullExperiment(): void
    {
        $this->assertCount(1421, $this->truth());
    }

    // --- Les cas qui expliquent les règles ----------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, ?string}> nom reçu, nom du candidat, niveau attendu (null : aucun lien)
     */
    public static function pairs(): iterable
    {
        yield 'casse, accent et point final' => ['  TOMATE. ', 'tomate', 'exact'];
        yield 'pluriel' => ['Tomates', 'tomate', 'exact'];
        yield 'pluriel en x' => ['Choux', 'chou', 'exact'];
        yield 'mots vides et pluriel' => ['pommes de terre', 'Pomme-de-terre', 'exact'];
        yield 'oe et œ' => ['Œufs', 'oeuf', 'exact'];
        yield 'mots vides retirés' => ['lingettes pour bébé', 'Lingette bébé', 'exact'];
        yield 'accent manquant' => ['spaghetti integral', 'spaghetti intégral', 'exact'];
        yield 'une lettre en trop dans un nom long' => ['concombre', 'concombrre', 'typo'];
        yield 'une lettre oubliée dans un nom long' => ['mozzarella', 'mozarella', 'typo'];
        yield 'une lettre changée, sous le seuil : proposée seulement' => ['mozzarella', 'mozzerella', 'suggestion'];
        yield 'un nom précisé n est pas le nom' => ['tomate', 'tomate cerise', null];
        yield 'le poivre n est pas le poivre noir' => ['poivre', 'poivre noir', null];
        yield 'un chiffre doit être identique' => ['couche taille 4', 'couche taille 5', null];
        yield 'deux frères' => ['confiture framboise', 'confiture fraise', null];
        yield 'un mot court n a pas de faute permise' => ['pot', 'pou', null];
        yield 'des mots dans le désordre ne sont pas le même nom' => ['noir poivre', 'poivre noir', null];
        yield 'rien à comparer' => ['---', '...', null];
    }

    #[DataProvider('pairs')]
    public function testSimilarityOfTwoNames(string $first, string $second, ?string $expected): void
    {
        // When
        $level = $this->matcher->similar($first, $second);
        $reverse = $this->matcher->similar($second, $first);

        // Then : le niveau d'un lien (exact, faute) est celui attendu, dans les deux sens
        if ($expected === null) {
            $this->assertNotContains($level, [MatchLevel::EXACT, MatchLevel::TYPO]);
            $this->assertNotContains($reverse, [MatchLevel::EXACT, MatchLevel::TYPO]);

            return;
        }
        $this->assertSame(MatchLevel::from($expected), $level);
        $this->assertSame($level, $reverse);
    }

    public function testASimilarNameBelowTheAutomaticThresholdIsOnlyASuggestionAndNeverALink(): void
    {
        // Given : une lettre changée dans un nom long (Jaro-Winkler 0,938 : sous 0,97, au-dessus de 0,90)
        $candidate = new IngredientCandidate('mozzerella', 'mozzerella', 'frais', 'unite');

        // When / Then
        $this->assertSame(MatchLevel::SUGGESTION, $this->matcher->similar('mozzarella', 'mozzerella'));
        $this->assertNull($this->matcher->match('mozzarella', 'frais', [$candidate]), 'Une suggestion se propose, elle ne branche jamais seule.');
    }

    public function testTheMatchIsOnlyLookedForInTheSameDepartment(): void
    {
        // Given : « tomate » surgelée et « tomate » fruit et légume
        $frozen = new IngredientCandidate('tomate-surgelee', 'Tomate', 'surgele', 'kilo');
        $fresh = new IngredientCandidate('tomate', 'Tomate', 'fruit-et-legume', 'kilo');

        // When / Then
        $this->assertSame('tomate', $this->matcher->match('tomate', 'fruit-et-legume', [$frozen, $fresh])?->candidate->slug);
        $this->assertSame('tomate-surgelee', $this->matcher->match('tomates', 'surgele', [$fresh, $frozen])?->candidate->slug);
        $this->assertNull($this->matcher->match('tomate', 'epicerie', [$fresh, $frozen]));
        $this->assertNull($this->matcher->match('tomate', 'fruit-et-legume', [$frozen]));
    }

    public function testTwoExactCandidatesGiveTheFirstBySlugWhateverTheOrder(): void
    {
        // Given : le copieur a lui-même deux fois « Tomate » dans le rayon
        $second = new IngredientCandidate('tomate-2-u', 'Tomate', 'fruit-et-legume', 'kilo');
        $first = new IngredientCandidate('tomate-u', 'Tomate', 'fruit-et-legume', 'kilo');

        // When
        $forward = $this->matcher->match('Tomates', 'fruit-et-legume', [$second, $first]);
        $backward = $this->matcher->match('Tomates', 'fruit-et-legume', [$first, $second]);

        // Then
        $this->assertSame('tomate-2-u', $forward?->candidate->slug);
        $this->assertSame('tomate-2-u', $backward?->candidate->slug, 'Premier par ordre alphabétique de slug.');
        $this->assertSame(MatchLevel::EXACT, $forward->level);
        $this->assertSame(1.0, $forward->score);
    }

    public function testAnExactCandidateBeatsATypoCandidate(): void
    {
        // Given
        $typo = new IngredientCandidate('a-concombrre', 'concombrre', 'fruit-et-legume', 'unite');
        $exact = new IngredientCandidate('z-concombre', 'concombre', 'fruit-et-legume', 'unite');

        // When
        $match = $this->matcher->match('concombre', 'fruit-et-legume', [$typo, $exact]);

        // Then
        $this->assertSame('z-concombre', $match?->candidate->slug);
        $this->assertSame(MatchLevel::EXACT, $match->level);
    }

    public function testTwoTypoCandidatesWithinTheMarginAreAmbiguousAndGiveNoLink(): void
    {
        // Given : deux noms à une faute du nom reçu (0,980 et 0,978), à moins de 0,02 l'un de l'autre
        $first = new IngredientCandidate('concombrre', 'concombrre', 'fruit-et-legume', 'unite');
        $second = new IngredientCandidate('concomber', 'concomber', 'fruit-et-legume', 'unite');

        // When / Then
        $this->assertNull($this->matcher->match('concombre', 'fruit-et-legume', [$first, $second]));
        $this->assertNull($this->matcher->match('concombre', 'fruit-et-legume', [$second, $first]));
        // Un seul des deux : le lien est fait
        $this->assertSame(MatchLevel::TYPO, $this->matcher->match('concombre', 'fruit-et-legume', [$first])?->level);
    }

    public function testATypoCandidateFarEnoughFromTheSecondOneIsLinked(): void
    {
        // Given : le second candidat passe le garde-fou mais à 0,958, soit plus de 0,02 sous le premier (0,980)
        $typo = new IngredientCandidate('concombrre', 'concombrre', 'fruit-et-legume', 'unite');
        $farther = new IngredientCandidate('concombere', 'concombere', 'fruit-et-legume', 'unite');
        $other = new IngredientCandidate('courgette', 'courgette', 'fruit-et-legume', 'unite');

        // When
        $match = $this->matcher->match('concombre', 'fruit-et-legume', [$other, $farther, $typo]);

        // Then
        $this->assertSame('concombrre', $match?->candidate->slug);
        $this->assertSame(MatchLevel::TYPO, $match->level);
    }

    public function testANameWithoutAnyLetterMatchesNothing(): void
    {
        // Given : deux noms qui se normalisent en rien seraient « identiques »
        $symbols = new IngredientCandidate('symboles', '???', 'epicerie', 'unite');

        // When / Then
        $this->assertNull($this->matcher->match('!!!', 'epicerie', [$symbols]));
        $this->assertNull($this->matcher->match('', 'epicerie', [$symbols]));
    }

    public function testTheNormalizedNameIsOnlyForComparison(): void
    {
        // Given
        $candidate = new IngredientCandidate('pomme-de-terre', 'Pommes de terre', 'fruit-et-legume', 'kilo');

        // Then : le nom reste celui de l'utilisateur, la comparaison se fait sur une autre forme
        $this->assertSame('Pommes de terre', $candidate->name);
        $this->assertSame('pomme-terre', $candidate->normalizedName());
        $this->assertSame(['oeuf'], IngredientNameMatcher::tokens('Œufs'));
        $this->assertSame(['san', 'gluten'], IngredientNameMatcher::tokens('sans gluten'), '« sans » change le sens : ce n\'est pas un mot vide (son « s » final se replie comme un pluriel).');
        $this->assertSame(['de', 'la'], IngredientNameMatcher::tokens('de la'), 'Un nom fait seulement de mots vides garde ses mots.');
    }

    // --- La comparaison de deux candidats (doublons possibles, ING-006) --------------------------------------------------------------

    #[DataProvider('pairs')]
    public function testCompareGivesTheSameLevelAsSimilarInBothDirectionsWithAScore(string $first, string $second, ?string $expected): void
    {
        // Given
        $one = new IngredientCandidate('a-' . md5($first), $first, 'epicerie', 'unite');
        $other = new IngredientCandidate('b-' . md5($second), $second, 'epicerie', 'unite');

        // When
        $forward = $this->matcher->compare($one, $other);
        $backward = $this->matcher->compare($other, $one);

        // Then : `compare` ne dépend pas de l'ordre des arguments, et dit comme `similar` si le couple est proposable
        $this->assertEquals($forward, $backward);
        $level = $this->matcher->similar($first, $second);
        if ($forward === null) {
            $this->assertSame(MatchLevel::NONE, $level);

            return;
        }
        $this->assertSame($level, $forward->level);
        $this->assertGreaterThanOrEqual(IngredientNameMatcher::SUGGESTION_THRESHOLD, $forward->score);
        $this->assertLessThanOrEqual(1.0, $forward->score);
    }

    public function testCompareScoresAnExactNameOneAndASuggestionBetweenTheTwoThresholds(): void
    {
        // Given
        $tomato = new IngredientCandidate('tomate', 'Tomate', 'fruit-et-legume', 'kilo');
        $tomatoes = new IngredientCandidate('tomates', 'Tomates', 'fruit-et-legume', 'kilo');
        $mozzarella = new IngredientCandidate('mozzarella', 'mozzarella', 'frais', 'unite');
        $mozzerella = new IngredientCandidate('mozzerella', 'mozzerella', 'frais', 'unite');
        $cherry = new IngredientCandidate('tomate-cerise', 'Tomate cerise', 'fruit-et-legume', 'kilo');

        // When / Then
        $this->assertEquals(new NameSimilarity(MatchLevel::EXACT, 1.0), $this->matcher->compare($tomato, $tomatoes));
        $suggestion = $this->matcher->compare($mozzarella, $mozzerella);
        $this->assertSame(MatchLevel::SUGGESTION, $suggestion->level);
        $this->assertGreaterThanOrEqual(0.90, $suggestion->score);
        $this->assertLessThan(0.97, $suggestion->score);
        $this->assertNull($this->matcher->compare($tomato, $cherry), 'Un nom précisé n\'est pas le nom : le garde-fou refuse deux noms de longueurs différentes.');
    }

    /**
     * @return list<array{id: string, query: string, department: string, expected: ?string, category: string, origin: string}>
     */
    private function truth(): array
    {
        $truth = json_decode((string) file_get_contents(__DIR__ . '/IngredientNameMatching/ground-truth.json'), true, flags: JSON_THROW_ON_ERROR);

        return $truth['cases'];
    }
}
