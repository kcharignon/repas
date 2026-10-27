<?php

namespace Repas\Tests\Repas\Domain\Service;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\DuplicatePair;
use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Repas\Domain\Model\IngredientDistinctPair;
use Repas\Repas\Domain\Model\MatchLevel;
use Repas\Repas\Domain\Service\DuplicateIngredientFinder;
use Repas\Repas\Domain\Service\IngredientNameMatcher;
use Repas\Tests\Helper\PerformanceReport;

/**
 * Les doublons possibles (ING-006, `docs/ingredients/fusion.md` § 8) : pur, sans base. Les règles une à une, puis la vérité terrain de
 * l'expérience (`rapprochement.md` : zéro faux lien à 0,97, les « pièges » écrits exprès sont proposés à 0,90 et c'est voulu : l'utilisateur
 * décide).
 */
class DuplicateIngredientFinderTest extends TestCase
{
    private DuplicateIngredientFinder $finder;

    protected function setUp(): void
    {
        $this->finder = new DuplicateIngredientFinder(new IngredientNameMatcher());
    }

    private static function own(string $slug, string $name, string $department = 'fruit-et-legume', ?string $adoptedFrom = null): IngredientCandidate
    {
        return new IngredientCandidate($slug, $name, $department, 'kilo', [], $adoptedFrom);
    }

    /**
     * @param list<DuplicatePair> $pairs
     * @return list<string>
     */
    private static function slugs(array $pairs): array
    {
        return array_map(static fn(DuplicatePair $pair): string => $pair->first->slug . '+' . $pair->second->slug, $pairs);
    }

    public function testTwoNamesThatAreTheSameOnceNormalizedAreAnExactDuplicate(): void
    {
        // Given
        $candidates = [self::own('tomates-u', 'Tomates'), self::own('tomate-u', 'Tomate'), self::own('courgette-u', 'Courgette')];

        // When
        $pairs = $this->finder->find($candidates);

        // Then : le premier slug d'abord, quel que soit l'ordre des ingrédients
        $this->assertSame(['tomate-u+tomates-u'], self::slugs($pairs));
        $this->assertSame([MatchLevel::EXACT, 1.0], [$pairs[0]->similarity->level, $pairs[0]->similarity->score]);
    }

    public function testATypoIsProposedAtItsLevel(): void
    {
        // Given : une lettre en trop (0,98) et une lettre changée (0,94) dans un nom long
        $candidates = [self::own('concombre-u', 'Concombre'), self::own('concombrre-u', 'Concombrre'), self::own('mozzarella-u', 'Mozzarella'), self::own('mozzerella-u', 'Mozzerella')];

        // When
        $pairs = $this->finder->find($candidates);

        // Then : triées de la plus probable à la moins probable
        $this->assertSame(['concombre-u+concombrre-u', 'mozzarella-u+mozzerella-u'], self::slugs($pairs));
        $this->assertSame([MatchLevel::TYPO, MatchLevel::SUGGESTION], [$pairs[0]->similarity->level, $pairs[1]->similarity->level]);
    }

    public function testAQualifiedNameIsNotAProposal(): void
    {
        // Given : « Tomate » et « Tomate cerise », « Poivre » et « Poivre noir » : pas le même nombre de mots, le garde-fou les écarte
        $candidates = [self::own('tomate-u', 'Tomate'), self::own('tomate-cerise-u', 'Tomate cerise'), self::own('poivre-u', 'Poivre', 'epicerie'), self::own('poivre-noir-u', 'Poivre noir', 'epicerie')];

        // When / Then
        $this->assertSame([], $this->finder->find($candidates));
    }

    public function testTwoIngredientsOfDifferentDepartmentsAreNeverProposed(): void
    {
        // Given
        $candidates = [self::own('tomate-u', 'Tomate', 'fruit-et-legume'), self::own('tomates-u', 'Tomates', 'surgele')];

        // When / Then
        $this->assertSame([], $this->finder->find($candidates));
    }

    public function testTwoCopiesOfDifferentOriginsAreNeverProposedButTwoOfTheSameOriginAre(): void
    {
        // Given : le propriétaire du catalogue a voulu « confiture fraise » et « confiture fraisse » distincts ; deux copies de la même origine, non
        $candidates = [
            self::own('fraise-u', 'Confiture fraise', 'epicerie', 'confiture-fraise'),
            self::own('fraise-2-u', 'Confiture fraises', 'epicerie', 'confiture-fraise'),
            self::own('fraisee-u', 'Confiture fraisse', 'epicerie', 'confiture-fraisse'),
        ];

        // When
        $pairs = $this->finder->find($candidates);

        // Then
        $this->assertSame(['fraise-2-u+fraise-u'], self::slugs($pairs));
    }

    public function testAnAdoptionAndAnIngredientTheUserCreatedAreProposed(): void
    {
        // Given : la copie d'un ingrédient du catalogue et un ingrédient créé à la main
        $candidates = [self::own('tomate-u', 'Tomate', adoptedFrom: 'tomate'), self::own('tomates-u', 'Tomates')];

        // When / Then
        $this->assertSame(['tomate-u+tomates-u'], self::slugs($this->finder->find($candidates)));
    }

    public function testADistinctPairIsNeverProposedAgainInAnyOrder(): void
    {
        // Given
        $candidates = [self::own('tomate-u', 'Tomate'), self::own('tomates-u', 'Tomates'), self::own('tomate-2-u', 'Tomate')];
        $distinct = [IngredientDistinctPair::keyOf('tomates-u', 'tomate-u') => true];

        // When
        $pairs = $this->finder->find($candidates, $distinct);

        // Then : seule la paire écartée disparaît, les deux autres restent
        $this->assertSame(['tomate-2-u+tomate-u', 'tomate-2-u+tomates-u'], self::slugs($pairs));
        $this->assertSame(self::slugs($pairs), self::slugs($this->finder->find($candidates, [IngredientDistinctPair::keyOf('tomate-u', 'tomates-u') => true])));
    }

    public function testTheResultDoesNotDependOnTheOrderOfTheIngredients(): void
    {
        // Given : quatre ingrédients dont trois à égalité de score (ex æquo)
        $candidates = [self::own('tomate-b', 'Tomate'), self::own('tomate-a', 'Tomate'), self::own('tomates-c', 'Tomates'), self::own('concombre-u', 'Concombre'), self::own('concombrre-u', 'Concombrre')];

        // When
        $expected = self::slugs($this->finder->find($candidates));

        // Then : un score décroissant, puis l'ordre des slugs
        $this->assertSame(['tomate-a+tomate-b', 'tomate-a+tomates-c', 'tomate-b+tomates-c', 'concombre-u+concombrre-u'], $expected);
        foreach ([array_reverse($candidates), [$candidates[2], $candidates[4], $candidates[0], $candidates[3], $candidates[1]]] as $shuffled) {
            $this->assertSame($expected, self::slugs($this->finder->find($shuffled)));
        }
    }

    public function testTheListIsBounded(): void
    {
        // Given : 12 paires « tomate / tomates », une par rayon
        $candidates = [];
        for ($i = 1; $i <= 12; $i++) {
            $candidates[] = self::own("tomate-$i", 'Tomate', "rayon-$i");
            $candidates[] = self::own("tomates-$i", 'Tomates', "rayon-$i");
        }

        // When / Then
        $this->assertCount(12, $this->finder->find($candidates));
        $this->assertCount(5, $this->finder->find($candidates, [], 5));
        $this->assertSame([], $this->finder->find($candidates, [], 0));
    }

    public function testNothingToCompareIsNotAnError(): void
    {
        $this->assertSame([], $this->finder->find([]));
        $this->assertSame([], $this->finder->find([self::own('tomate-u', 'Tomate')]));
        $this->assertSame([], $this->finder->find([self::own('symboles-u', '???'), self::own('symboles-2-u', '!!!')]), 'Un nom sans lettre ne ressemble à rien.');
    }

    public function testTheLevelsAreTheOnesOfTheMatcher(): void
    {
        // Given
        $matcher = new IngredientNameMatcher();
        $candidates = [self::own('a-u', 'Mozzarella'), self::own('b-u', 'mozzarellas'), self::own('c-u', 'Mozzerella'), self::own('d-u', 'Concombre'), self::own('e-u', 'Concombrre')];

        // When
        $pairs = $this->finder->find($candidates);

        // Then : le même verdict que `similar`, et que le garde-fou du rapprochement
        $this->assertNotEmpty($pairs);
        foreach ($pairs as $pair) {
            $this->assertSame($matcher->similar($pair->first->name, $pair->second->name), $pair->similarity->level);
        }
    }

    // --- La vérité terrain ---------------------------------------------------------------------------------------------------------

    /**
     * Les 256 communs du jeu de référence, rejoués comme les ingrédients PERSONNELS d'un utilisateur : aucun doublon dans le jeu, donc aucune
     * proposition (un jeu de 256 ingrédients bien tenus ne produit aucune corvée).
     */
    public function testTheReferenceCatalogueOwnedByOneUserHasNoDuplicate(): void
    {
        // Given
        $commons = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/src/Repas/Infrastructure/DataFixture/Data/ingredient.json'), true, flags: JSON_THROW_ON_ERROR);
        $candidates = array_map(static fn(array $common): IngredientCandidate => self::own($common['slug'], $common['name'], $common['department']), $commons);

        // When / Then
        $this->assertCount(256, $candidates);
        $this->assertSame([], self::slugs($this->finder->find($candidates, [], 1000)));
    }

    public function testEveryWritingVariantOfAnIngredientIsProposedAsAnExactDuplicate(): void
    {
        foreach ($this->truthByCategory(['casse', 'ponctuation', 'accent', 'pluriel']) as $case) {
            // Given : l'utilisateur a l'ingrédient d'origine ET le même écrit autrement
            $pairs = $this->finder->find([self::own('origine', $this->commonName($case['expected']), $case['department']), self::own('variante', $case['query'], $case['department'])]);

            // Then : toujours proposé (un seul cas, un pluriel irrégulier, n'est qu'à une faute près)
            $this->assertCount(1, $pairs, $case['query']);
        }
    }

    public function testTypingMistakesAreProposedAtLeastNineTimesOutOfTen(): void
    {
        // Given
        $cases = $this->truthByCategory(['faute']);

        // When
        $proposed = 0;
        foreach ($cases as $case) {
            $proposed += count($this->finder->find([self::own('origine', $this->commonName($case['expected']), $case['department']), self::own('faute', $case['query'], $case['department'])]));
        }

        // Then : 432 sur 481 (89,8 %) à 0,90 ; les autres sont des noms de moins de 6 lettres, où une faute ne se distingue pas d'un autre mot
        $this->assertCount(481, $cases);
        $this->assertGreaterThanOrEqual(430, $proposed);
    }

    public function testAQualifiedNameOfTheGroundTruthIsNeverProposedAgainstAnyIngredientOfItsDepartment(): void
    {
        // Given : « poivre noir », « sel fin », « lait d'amande »… face à tous les communs de leur rayon
        $commons = $this->commonsByDepartment();
        $falseProposals = [];
        foreach ($this->truthByCategory(['qualificatif']) as $case) {
            $candidates = [...$commons[$case['department']], self::own('qualifie', $case['query'], $case['department'])];
            foreach ($this->finder->find($candidates) as $pair) {
                $falseProposals[] = $case['query'] . ' -> ' . $pair->first->name . ' / ' . $pair->second->name;
            }
        }

        // Then
        $this->assertSame([], $falseProposals);
    }

    public function testTheFalseProposalsOfTheTrapsAreAFewAndNeverAnAutomaticLink(): void
    {
        // Given : les 26 « frères trompeurs » écrits exprès (« Poule » / « poulet », « Laitière » / « litière »…)
        $commons = $this->commonsByDepartment();
        $proposed = 0;
        foreach ($this->truthByCategory(['frere']) as $case) {
            $pairs = $this->finder->find([...$commons[$case['department']] ?? [], self::own('piege', $case['query'], $case['department'])]);
            $proposed += $pairs === [] ? 0 : 1;
            foreach ($pairs as $pair) {
                $this->assertNotSame(MatchLevel::EXACT, $pair->similarity->level, $case['query']);
            }
        }

        // Then : 6 sur 26 à 0,90 (`rapprochement.md` § 7.1) : l'utilisateur décide, « Pas le même » les écarte pour toujours
        $this->assertSame(6, $proposed);
    }

    /**
     * @param list<string> $categories
     * @return list<array{query: string, department: string, expected: ?string, category: string}>
     */
    private function truthByCategory(array $categories): array
    {
        $truth = json_decode((string) file_get_contents(__DIR__ . '/IngredientNameMatching/ground-truth.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_filter($truth['cases'], static fn(array $case): bool => in_array($case['category'], $categories, true)));
    }

    public function testAGroupOnlyExaminesItsFirstIngredientsInSlugOrder(): void
    {
        // Given : un groupe borné à 5 ingrédients, 8 ingrédients du même rayon et du même nombre de mots donnés dans l'ordre inverse des slugs
        $finder = new DuplicateIngredientFinder(new IngredientNameMatcher(), maxCandidatesPerGroup: 5);
        $candidates = [];
        for ($i = 7; $i >= 0; $i--) {
            $candidates[] = self::own("tomate-$i", 'Tomate');
        }

        // When : toutes les paires sont exactes, on les garde toutes
        $pairs = $finder->find($candidates, limit: 100);

        // Then : C(5, 2) paires et pas une de plus, aucune ne touche un slug au-delà du cinquième, quel que soit l'ordre donné
        $this->assertCount(10, $pairs);
        $this->assertLessThan('tomate-5', max(array_map(static fn(DuplicatePair $pair): string => $pair->second->slug, $pairs)));
    }

    public function testAGroupUnderTheBoundIsExaminedWhole(): void
    {
        // Given : exactement 5 ingrédients pour une borne de 5, dont les deux derniers par slug sont des doublons
        $finder = new DuplicateIngredientFinder(new IngredientNameMatcher(), maxCandidatesPerGroup: 5);
        $candidates = [self::own('a-pomme', 'Pomme'), self::own('b-poire', 'Poire'), self::own('c-kiwi', 'Kiwi'), self::own('z-tomate', 'Tomate'), self::own('z-tomates', 'Tomates')];

        // When
        $pairs = $finder->find($candidates);

        // Then
        $this->assertSame(['z-tomate+z-tomates'], self::slugs($pairs));
    }

    public function testTheWholeAccountIsBoundedByABudgetOfComparisons(): void
    {
        // Given : un budget de 25 comparaisons, quatre rayons de 5 ingrédients dont les noms se valent tous (10 couples par rayon, 40 sans le budget)
        $finder = new DuplicateIngredientFinder(new IngredientNameMatcher(), maxComparisons: 25);
        $candidates = [];
        foreach (['viande', 'surgele', 'fruit-et-legume', 'epicerie'] as $department) {
            for ($i = 0; $i < 5; $i++) {
                $candidates[] = self::own("$department-$i", 'Nom', $department);
            }
        }

        // When
        $pairs = $finder->find($candidates, limit: 1000);

        // Then : 25 couples examinés, dans l'ordre alphabétique des rayons (deux en entier, le troisième entamé), quel que soit l'ordre donné
        $this->assertCount(25, $pairs);
        $departments = array_unique(array_map(static fn(DuplicatePair $pair): string => preg_replace('/-\d$/', '', $pair->first->slug), $pairs));
        sort($departments);
        $this->assertSame(['epicerie', 'fruit-et-legume', 'surgele'], $departments);
    }

    public function testTheBoundsOfProductionAreTheDefaultOnes(): void
    {
        // Given / When
        $defaults = array_map(
            static fn(\ReflectionParameter $parameter): mixed => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            new \ReflectionClass(DuplicateIngredientFinder::class)->getConstructor()->getParameters(),
        );

        // Then : 500 ingrédients par groupe, 250 000 comparaisons au plus pour tout le compte
        $this->assertSame([null, 500, 250_000], $defaults);
    }

    public function testAnOrdinaryAccountIsNeverCutByTheBounds(): void
    {
        // Given : 300 ingrédients répartis dans 5 rayons, dont des doublons dans le dernier par ordre alphabétique
        $candidates = [];
        foreach (['epicerie', 'fruit-et-legume', 'surgele', 'viande'] as $department) {
            for ($i = 0; $i < 60; $i++) {
                $candidates[] = self::own(sprintf('%s-%02d', $department, $i), 'Ingrédient ' . $i . ' ' . $department, $department);
            }
        }
        $candidates[] = self::own('zz-tomate', 'Tomate', 'zzz-dernier-rayon');
        $candidates[] = self::own('zz-tomates', 'Tomates', 'zzz-dernier-rayon');

        // When
        $start = hrtime(true);
        $pairs = $this->finder->find($candidates);
        PerformanceReport::line(sprintf("\n[plafond] 242 ingrédients en 5 rayons, doublons possibles : %.1f ms\n", (hrtime(true) - $start) / 1e6));

        // Then : la mesure est seulement rapportée (`TEST_PERF_REPORT=1`), jamais comparée à un temps
        $this->assertContains('zz-tomate+zz-tomates', self::slugs($pairs));
    }

    private function commonName(string $slug): string
    {
        foreach ($this->commonsByDepartment() as $candidates) {
            foreach ($candidates as $candidate) {
                if ($candidate->slug === $slug) {
                    return $candidate->name;
                }
            }
        }

        $this->fail("Commun inconnu : $slug");
    }

    /**
     * @return array<string, list<IngredientCandidate>> les communs du jeu de référence, comme ingrédients de l'utilisateur, par rayon
     */
    private function commonsByDepartment(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            $commons = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/src/Repas/Infrastructure/DataFixture/Data/ingredient.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($commons as $common) {
                $cache[$common['department']][] = self::own($common['slug'], $common['name'], $common['department']);
            }
        }

        return $cache;
    }
}
