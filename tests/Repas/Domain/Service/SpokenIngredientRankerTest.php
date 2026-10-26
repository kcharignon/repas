<?php

namespace Repas\Tests\Repas\Domain\Service;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\Confidence;
use Repas\Repas\Domain\Model\RankedIngredient;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Shared\Domain\Tool\SearchRelevance;
use Repas\Shared\Domain\Tool\StringTool;

/**
 * Le choix de l'ingrédient pour une phrase prononcée (VOX-101, `docs/voice/alexa.md` § 4) : pur, sans base. Une table de cas lisible :
 * la phrase, les ingrédients de l'utilisateur avec leur nombre d'usages sur douze mois, et ce qu'on attend.
 */
class SpokenIngredientRankerTest extends TestCase
{
    private SpokenIngredientRanker $ranker;

    protected function setUp(): void
    {
        $this->ranker = new SpokenIngredientRanker();
    }

    /** Un ingrédient de l'utilisateur : son nom et le nombre de fois où il l'a utilisé ; le slug est celui du nom. */
    private static function own(string $name, int $uses = 0, string $department = 'Divers'): SpokenCandidate
    {
        $slugged = StringTool::slugify($name);

        return new SpokenCandidate($slugged . '-u', $slugged, $name, $department, $uses);
    }

    /**
     * @param list<SpokenCandidate> $candidates
     */
    #[DataProvider('cases')]
    public function testItPicksTheMostLikelyIngredient(string $phrase, array $candidates, ?string $expectedName, ?Confidence $expectedConfidence, ?string $remembered = null): void
    {
        // Given : les candidats, dans l'ordre donné par la table
        // When
        $ranked = $this->ranker->rank($phrase, $candidates, $remembered === null ? null : StringTool::slugify($remembered) . '-u');

        // Then
        $this->assertSame([$expectedName, $expectedConfidence], [$ranked?->name, $ranked?->confidence]);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<SpokenCandidate>, 2: ?string, 3: ?Confidence, 4?: ?string}>
     */
    public static function cases(): iterable
    {
        // Le nom dit l'emporte sur l'habitude
        yield '« tomate » : le nom exact, même moins acheté que « tomates pelées »'
            => ['tomate', [self::own('Tomate', 0), self::own('Tomates pelées', 40)], 'Tomate', Confidence::SURE];
        yield '« tomates » (pluriel) : le nom exact au singulier'
            => ['tomates', [self::own('Tomates pelées', 40), self::own('Tomate', 0)], 'Tomate', Confidence::SURE];
        yield '« tomates pelées » : le nom complet'
            => ['tomates pelées', [self::own('Tomate', 12), self::own('Tomates pelées', 0)], 'Tomates pelées', Confidence::SURE];
        yield '« des pommes de terre » : mots vides retirés, pluriel replié'
            => ['des pommes de terre', [self::own('Pomme', 30), self::own('Pomme de terre', 2)], 'Pomme de terre', Confidence::SURE];
        yield '« du lait » : le nom exact, pas « lait de coco » plus acheté'
            => ['du lait', [self::own('Lait de coco', 20), self::own('Lait', 5), self::own('Laitue', 9)], 'Lait', Confidence::SURE];
        yield '« pâtes » : accent et pluriel'
            => ['pâtes', [self::own('Pâtes', 8), self::own('Pâtes complètes', 3), self::own('Pâte feuilletée', 4)], 'Pâtes', Confidence::SURE];
        yield 'une majuscule et un accent de plus ne changent rien'
            => ['Crème Fraîche', [self::own('Crème fraîche', 1), self::own('Crème liquide', 9)], 'Crème fraîche', Confidence::SURE];
        yield '« un oeuf » : un article retiré'
            => ['un oeuf', [self::own('Oeuf', 6)], 'Oeuf', Confidence::SURE];

        // Pas de nom exact : l'habitude départage les noms proches
        yield '« farine » : la plus achetée, nettement'
            => ['farine', [self::own('Farine de maïs', 2), self::own('Farine de blé', 30)], 'Farine de blé', Confidence::PROBABLE];
        yield '« farine » : un seul candidat proche'
            => ['farine', [self::own('Farine de blé', 0)], 'Farine de blé', Confidence::PROBABLE];
        yield '« farine » : deux habitudes voisines, on le dit'
            => ['farine', [self::own('Farine de maïs', 10), self::own('Farine de blé', 11)], 'Farine de blé', Confidence::UNSURE];
        yield '« farine » : jamais achetées, les deux se valent, le premier par nom'
            => ['farine', [self::own('Farine de riz'), self::own('Farine de blé')], 'Farine de blé', Confidence::UNSURE];
        yield '« tomate » sans le nom exact : la cerise plus achetée l\'emporte'
            => ['tomate', [self::own('Tomate cerise', 25), self::own('Tomates pelées', 1)], 'Tomate cerise', Confidence::PROBABLE];
        yield 'une faute de frappe proche reste trouvée'
            => ['courgete', [self::own('Courgette', 3), self::own('Concombre', 50)], 'Courgette', Confidence::PROBABLE];

        // Deux noms identiques une fois normalisés : l'habitude, mais on le dit s'ils se touchent
        yield '« tomate » en double : le plus acheté, nettement'
            => ['tomate', [self::own('Tomate', 1), self::own('Tomates', 30)], 'Tomates', Confidence::PROBABLE];
        yield '« tomate » en double, mêmes habitudes : on le dit'
            => ['tomate', [self::own('Tomates', 4), self::own('Tomate', 4)], 'Tomate', Confidence::UNSURE];

        // La mémoire de phrase l'emporte sur tout
        yield 'mémoire : « tomate » se souvient de « tomate cerise », zéro achat'
            => ['tomate', [self::own('Tomate', 50), self::own('Tomate cerise', 0)], 'Tomate cerise', Confidence::SURE, 'Tomate cerise'];
        yield 'mémoire : même pour un nom très différent de la phrase'
            => ['patates', [self::own('Pomme de terre', 3), self::own('Patate douce', 9)], 'Pomme de terre', Confidence::SURE, 'Pomme de terre'];
        yield 'mémoire : un ingrédient qui n\'est plus là est ignoré'
            => ['tomate', [self::own('Tomate', 2), self::own('Tomate cerise', 0)], 'Tomate', Confidence::SURE, 'Concombre'];

        // Rien à répondre
        yield '« xyz » : aucun nom assez proche'
            => ['xyz', [self::own('Tomate', 100), self::own('Farine', 100)], null, null];
        yield 'une habitude ne fait pas passer un nom trop différent'
            => ['carotte', [self::own('Chocolat noir', 5000), self::own('Pomme de terre', 5000)], null, null];
        yield 'que des mots vides'
            => ['des du de la un une', [self::own('Tomate', 5), self::own('Une', 5)], null, null];
        yield 'phrase vide'
            => ['', [self::own('Tomate', 5)], null, null];
        yield 'des signes seulement'
            => [' -- ?! ', [self::own('Tomate', 5)], null, null];
        yield 'aucun candidat'
            => ['tomate', [], null, null];
        yield 'phrase vide : même la mémoire n\'y change rien'
            => ['des', [self::own('Tomate', 5)], null, null, 'Tomate'];
    }

    /**
     * Le choix AUTOMATIQUE (`isChosenByItself`) : seuil de recherche (0,85) ET nom identique, phrase qui commence le nom ou un mot du nom, ou faute
     * de frappe d'au moins `TYPO_THRESHOLD` (0,94). Les scores viennent de `SearchRelevance` : « ciboulette » contre *coquilette* 0,907 ;
     * « ciboulete » contre *ciboulette* 0,98 ; « mozarella » contre *mozzarella di bufala* 0,879 ; « laitue » contre *lait* 0,933 ; « steak haché »
     * contre *steack haché* 0,947 ; « pain de campagne » contre *pain de mie* 0,883.
     *
     * @param list<SpokenCandidate> $candidates
     */
    #[DataProvider('automaticChoiceCases')]
    public function testItOnlyChoosesByItselfWhatIsCertainEnoughToNotAsk(string $phrase, array $candidates, ?string $expectedName): void
    {
        $this->assertSame($expectedName, $this->ranker->rank($phrase, $candidates)?->name);
    }

    /**
     * @return iterable<string, array{string, list<SpokenCandidate>, ?string}>
     */
    public static function automaticChoiceCases(): iterable
    {
        yield '« ciboulette » n\'est PAS *coquilette* (0,907) : on demande'
            => ['ciboulette', [self::own('Coquilette'), self::own('Croquette'), self::own('Courgette')], null];
        yield '« ciboulete » est *ciboulette* : une faute de frappe (0,98)'
            => ['ciboulete', [self::own('Ciboulette'), self::own('Coquilette')], 'Ciboulette'];
        yield '« steak haché » est *steack haché* (0,947)'
            => ['steak haché', [self::own('Steack haché')], 'Steack haché'];
        yield '« laitue » n\'est pas *lait* (0,933, sous le seuil des fautes de frappe)'
            => ['laitue', [self::own('Lait'), self::own('La laitière')], null];
        yield '« mozarella » n\'est pas deviné contre *mozzarella di bufala*'
            => ['mozarella', [self::own('Mozzarella di bufala')], null];
        yield 'mais *mozzarella* seule, elle, est une faute de frappe (0,977)'
            => ['mozarella', [self::own('Mozzarella'), self::own('Mozzarella di bufala')], 'Mozzarella'];
        yield '« pain » commence *pain de mie* : choisi'
            => ['pain', [self::own('Pain de mie')], 'Pain de mie'];
        yield '« pain » commence un mot de *farine pain* : choisi'
            => ['pain', [self::own('Farine pain')], 'Farine pain'];
        yield '« pain de campagne » ne commence pas *pain de mie* : on demande'
            => ['pain de campagne', [self::own('Pain de mie'), self::own('Pain')], null];
        yield '« jambon de dinde » ne devine pas *jambon* : le nom est plus court que la phrase'
            => ['jambon de dinde', [self::own('Jambon'), self::own('Jambon blanc')], null];
        yield '« lardons » est *lardon* : le pluriel est replié, nom identique'
            => ['lardons', [self::own('Lardon')], 'Lardon'];
        yield '« tomates pelées » : le nom identique, pas *tomate*'
            => ['tomates pelées', [self::own('Tomate'), self::own('Tomates pelées')], 'Tomates pelées'];
    }

    public function testTheThresholdOfATypoIsStricterThanTheOneOfTheSearchButNotTheFloorOfTheProposals(): void
    {
        $this->assertGreaterThan(SearchRelevance::MINIMUM, SpokenIngredientRanker::TYPO_THRESHOLD);
        $this->assertGreaterThan(SpokenIngredientRanker::SUGGESTION_FLOOR, SearchRelevance::MINIMUM);
    }

    public function testTheResultCarriesTheDepartmentTheSlugAndAScore(): void
    {
        // Given
        $candidates = [self::own('Tomate', 0, 'Fruits et légumes')];

        // When
        $ranked = $this->ranker->rank('tomate', $candidates);

        // Then : un nom identique jamais acheté vaut 1
        $this->assertSame(['tomate-u', 'Tomate', 'Fruits et légumes', 1.0], [$ranked->slug, $ranked->name, $ranked->departmentName, $ranked->score]);
    }

    public function testTheHabitRaisesTheScoreByAtMostItsWeight(): void
    {
        // Given : trois usages valent la moitié du poids, mille presque tout le poids
        $few = $this->ranker->rank('farine', [self::own('Farine de blé', 3)]);
        $many = $this->ranker->rank('farine', [self::own('Farine de blé', 1000)]);
        $never = $this->ranker->rank('farine', [self::own('Farine de blé', 0)]);

        // Then
        $this->assertEqualsWithDelta(SpokenIngredientRanker::HABIT_WEIGHT / 2, $few->score - $never->score, 1e-9);
        $this->assertLessThan(SpokenIngredientRanker::HABIT_WEIGHT, $many->score - $never->score);
        $this->assertGreaterThan(0.99 * SpokenIngredientRanker::HABIT_WEIGHT, $many->score - $never->score);
    }

    public function testTheMemoryScoreBeatsAnyComputedScore(): void
    {
        // Given
        $candidates = [self::own('Tomate', 100000)];

        // When
        $remembered = $this->ranker->rank('tomate', $candidates, 'tomate-u');
        $computed = $this->ranker->rank('tomate', $candidates);

        // Then
        $this->assertGreaterThan($computed->score, $remembered->score);
    }

    public function testTiesAreBrokenByNameThenSlugWhateverTheOrderOfTheCandidates(): void
    {
        // Given : trois noms de même score, dont deux de même nom et de slugs différents
        $blue = new SpokenCandidate('b-slug', 'farine-de-ble', 'Farine de blé', 'Épicerie', 0);
        $blueTwin = new SpokenCandidate('a-slug', 'farine-de-ble', 'Farine de blé', 'Épicerie', 0);
        $rice = self::own('Farine de riz');

        // When : toutes les permutations
        $slugs = [];
        foreach ([[$blue, $blueTwin, $rice], [$rice, $blueTwin, $blue], [$blueTwin, $rice, $blue], [$rice, $blue, $blueTwin]] as $order) {
            $slugs[] = $this->ranker->rank('farine', $order)->slug;
        }

        // Then : le premier par nom, puis par slug ; et deux appels identiques donnent le même résultat
        $this->assertSame(['a-slug', 'a-slug', 'a-slug', 'a-slug'], $slugs);
        $this->assertEquals($this->ranker->rank('farine', [$blue, $rice]), $this->ranker->rank('farine', [$blue, $rice]));
    }

    public function testTheGapBetweenProbableAndUnsureIsTheClearMargin(): void
    {
        // Given : deux noms de même pertinence, l'écart ne vient que de l'habitude
        // 3 usages : 0,05 ; 0 usage : 0 -> écart 0,05 >= 0,03
        $clear = $this->ranker->rank('farine', [self::own('Farine de blé', 3), self::own('Farine de riz', 0)]);
        // 1 usage : 0,025 ; 0 usage : 0 -> écart 0,025 < 0,03
        $close = $this->ranker->rank('farine', [self::own('Farine de blé', 1), self::own('Farine de riz', 0)]);

        // Then
        $this->assertSame([Confidence::PROBABLE, Confidence::UNSURE], [$clear->confidence, $close->confidence]);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Les ex æquo : Alexa demande le rayon au lieu de deviner (ADR 0029 décision 3, amendement du 2026-10-25)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    private static function inDepartment(string $name, string $department, int $uses = 0): SpokenCandidate
    {
        $slugged = StringTool::slugify($name);
        $departmentSlug = StringTool::slugify($department);

        return new SpokenCandidate($slugged . '-' . $departmentSlug, $slugged, $name, $department, $uses, $departmentSlug);
    }

    public function testTwoIngredientsOfTheSameNameInTwoDepartmentsAreTiedAndCarryTheirDepartment(): void
    {
        // Given
        $candidates = [self::inDepartment('Tomate', 'Surgelé'), self::inDepartment('Tomate', 'Fruit & légume'), self::own('Farine')];

        // When
        $ranked = $this->ranker->rank('tomate', $candidates);
        $ties = $this->ranker->ties('tomate', $candidates);

        // Then : le classeur n'est pas sûr, et les deux ex æquo sont rendus dans un ordre déterministe, avec le slug de leur rayon
        $this->assertSame(Confidence::UNSURE, $ranked?->confidence);
        $this->assertSame([['tomate-fruit-et-legume', 'fruit-et-legume'], ['tomate-surgele', 'surgele']], array_map(static fn($tie): array => [$tie->slug, $tie->departmentSlug], $ties));
        $this->assertSame(['Fruit & légume', 'Surgelé'], array_map(static fn($tie): string => $tie->departmentName, $ties));
        $this->assertEquals($ties, $this->ranker->ties('tomate', array_reverse($candidates)), "l'ordre des candidats n'y change rien");
    }

    public function testHabitsThatDecideLeaveNoTie(): void
    {
        // Given : « tomate » acheté 50 fois en fruit et légume
        $candidates = [self::inDepartment('Tomate', 'Surgelé'), self::inDepartment('Tomate', 'Fruit & légume', 50)];

        // Then : PROBABLE, sans question
        $this->assertSame(Confidence::PROBABLE, $this->ranker->rank('tomate', $candidates)?->confidence);
        $this->assertSame([], $this->ranker->ties('tomate', $candidates));
    }

    public function testASingleIngredientTheMemoryOrAnEmptyPhraseLeaveNoTie(): void
    {
        $both = [self::inDepartment('Tomate', 'Surgelé'), self::inDepartment('Tomate', 'Fruit & légume')];

        $this->assertSame([], $this->ranker->ties('tomate', [$both[0]]));
        $this->assertSame([], $this->ranker->ties('tomate', $both, 'tomate-surgele'), 'la mémoire de phrase passe avant tout');
        $this->assertSame([], $this->ranker->ties('des', $both));
        $this->assertSame([], $this->ranker->ties('tomate', []));
        $this->assertSame([], $this->ranker->ties('xyz', $both));
    }

    public function testTiesAreAtMostThreeAndCiteTheNameWhenTheNamesDiffer(): void
    {
        // Given : quatre noms voisins de même rayon
        $candidates = [self::inDepartment('Farine de blé', 'Épicerie'), self::inDepartment('Farine de riz', 'Épicerie'), self::inDepartment('Farine de maïs', 'Épicerie'), self::inDepartment('Farine de seigle', 'Épicerie')];

        // When
        $ties = $this->ranker->ties('farine', $candidates);

        // Then : trois au plus, des noms différents (on les départage par le nom)
        $this->assertCount(3, $ties);
        $this->assertCount(3, array_unique(array_map(static fn($tie): string => $tie->name, $ties)));
    }

    public function testTwoTiedIngredientsThatSaySameNameAndDepartmentAreNotAQuestion(): void
    {
        // Given : deux « tomate » dans le même rayon, une question ne les départagerait pas
        $candidates = [
            new SpokenCandidate('a', 'tomate', 'Tomate', 'Fruit & légume', 0, 'fruit-et-legume'),
            new SpokenCandidate('b', 'tomate', 'Tomate', 'Fruit & légume', 0, 'fruit-et-legume'),
        ];

        // Then : le classeur garde son premier choix incertain
        $this->assertSame(Confidence::UNSURE, $this->ranker->rank('tomate', $candidates)?->confidence);
        $this->assertSame([], $this->ranker->ties('tomate', $candidates));
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Les propositions : « De quoi tu parles ? » (ADR 0029 décision 11)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @param list<SpokenCandidate> $candidates
     * @param list<string> $expectedNames
     */
    #[DataProvider('suggestionCases')]
    public function testItSuggestsTheClosestIngredientsThatAreNotChosenByThemselves(string $phrase, array $candidates, array $expectedNames): void
    {
        // When
        $suggestions = $this->ranker->suggest($phrase, $candidates);

        // Then
        $this->assertSame($expectedNames, array_map(static fn($ranked): string => $ranked->name, $suggestions));
    }

    /**
     * La table de cas qui fixe le plancher (`SUGGESTION_FLOOR`, début de mot en commun). Les scores viennent de `SearchRelevance` : « sirop
     * de menthe » contre « sirop fruit rouge » 0,827 ; « zébulon » contre « melon » 0,79 ; « fenouil » contre « nouille » 0,81 sans début en
     * commun ; « crevettes » contre « courgette » 0,825 sans début en commun.
     *
     * @return iterable<string, array{0: string, 1: list<SpokenCandidate>, 2: list<string>}>
     */
    public static function suggestionCases(): iterable
    {
        yield 'un nom qui commence pareil et ressemble assez'
            => ['sirop de menthe', [self::own('Sirop fruit rouge'), self::own('Farine'), self::own('Tomate')], ['Sirop fruit rouge']];
        yield '« zébulon » : rien d\'assez proche, on propose de créer'
            => ['zébulon', [self::own('Melon'), self::own('Bouillon'), self::own('Reblochon')], []];
        yield '« fenouil » : une ressemblance de lettres sans début en commun n\'est pas une proposition'
            => ['fenouil', [self::own('Nouille'), self::own('Feuille de brique')], []];
        yield '« basilic » ne propose pas « ail »'
            => ['basilic', [self::own('Ail')], []];
        yield '« crevettes » ne propose ni « courgette » ni « croquette »'
            => ['crevettes', [self::own('Courgette'), self::own('Croquette')], []];
        yield '« xyz » : rien'
            => ['xyz', [self::own('Riz'), self::own('Pizza')], []];
        yield 'ce que le classeur choisit tout seul n\'est pas une proposition'
            => ['tomate cerise', [self::own('Tomate cerise'), self::own('Sauce tomate')], ['Sauce tomate']];
        yield 'trois propositions au plus, classées par score puis par nom'
            => ['pâtes à pizza', [self::own('Pâte sablée'), self::own('Pâte lasagne'), self::own('Pâte ramen'), self::own('Pâte brisée'), self::own('Sauce tomate')], ['Pâte ramen', 'Pâte brisée', 'Pâte sablée']];
        yield 'l\'habitude remonte une proposition'
            => ['pâtes à pizza', [self::own('Pâte ramen', 0), self::own('Pâte brisée', 20), self::own('Pâte sablée', 0)], ['Pâte brisée', 'Pâte ramen', 'Pâte sablée']];
        yield '« ciboulette » ne propose pas *coquilette* (0,907) : aucun début de mot en commun'
            => ['ciboulette', [self::own('Coquilette'), self::own('Croquette'), self::own('Courgette')], []];
        yield '« mozarella » (une faute) propose *mozzarella di bufala*, trop long pour être deviné (0,879)'
            => ['mozarella', [self::own('Mozzarella di bufala'), self::own('Farine')], ['Mozzarella di bufala']];
        yield '« laitue » propose *lait* (0,933) mais ne le choisit pas : ce n\'est pas la même chose'
            => ['laitue', [self::own('Lait'), self::own('La laitière')], ['Lait', 'La laitière']];
        yield '« pain de campagne » demande plutôt que de deviner *pain de mie*'
            => ['pain de campagne', [self::own('Pain de mie'), self::own('Pain')], ['Pain de mie', 'Pain']];
        yield '« jambon de dinde » demande plutôt que de deviner *jambon*'
            => ['jambon de dinde', [self::own('Jambon'), self::own('Jambon blanc')], ['Jambon', 'Jambon blanc']];
        yield 'aucun ingrédient'
            => ['sirop de menthe', [], []];
        yield 'que des mots vides'
            => ['des du de la', [self::own('Sirop fruit rouge')], []];
        yield 'phrase vide'
            => ['', [self::own('Sirop fruit rouge')], []];
    }

    public function testSuggestionsAreInTheSameOrderWhateverTheOrderOfTheCandidates(): void
    {
        // Given : deux noms au même score (pâte brisée et pâte sablée), dans les deux ordres
        $brisee = self::own('Pâte brisée');
        $sablee = self::own('Pâte sablée');

        // When
        $first = $this->ranker->suggest('pâtes à pizza', [$sablee, $brisee]);
        $second = $this->ranker->suggest('pâtes à pizza', [$brisee, $sablee]);

        // Then
        $this->assertEquals($first, $second);
        $this->assertSame(['Pâte brisée', 'Pâte sablée'], array_map(static fn($ranked): string => $ranked->name, $first));
    }

    public function testTheNumberOfSuggestionsIsBoundedByTheLimitAndNeverNegative(): void
    {
        // Given
        $candidates = [self::own('Pâte brisée'), self::own('Pâte sablée'), self::own('Pâte ramen'), self::own('Pâte lasagne')];

        // Then
        $this->assertCount(SpokenIngredientRanker::MAX_SUGGESTIONS, $this->ranker->suggest('pâtes à pizza', $candidates));
        $this->assertCount(1, $this->ranker->suggest('pâtes à pizza', $candidates, 1));
        $this->assertSame([], $this->ranker->suggest('pâtes à pizza', $candidates, 0));
        $this->assertSame([], $this->ranker->suggest('pâtes à pizza', $candidates, -2));
    }

    public function testTheClosestAreTheBestRankedEvenWhenFarWithoutFloorNorSharedPrefix(): void
    {
        // Given : aucune recette ne ressemble à « blanquette de veaux » : `suggest()` n'en propose aucune (plancher), `closest()` rend les trois meilleures
        $candidates = [self::own('Lasagnes'), self::own('Blanquette de dinde'), self::own('Hachis parmentier'), self::own('Gratin dauphinois')];

        // When
        $closest = $this->ranker->closest('blanquette de veaux', $candidates);

        // Then : trois, la plus proche d'abord, jamais plus ; un nom sans rapport n'est pas écarté
        $this->assertCount(SpokenIngredientRanker::MAX_SUGGESTIONS, $closest);
        $this->assertSame('Blanquette de dinde', $closest[0]->name);
        $this->assertSame([], $this->ranker->suggest('zzz qqq', [self::own('Lasagnes')]), 'les produits gardent leur plancher');
        $this->assertCount(1, $this->ranker->closest('zzz qqq', [self::own('Lasagnes')]), 'les recettes, non');
    }

    public function testTheClosestIsOrderedDeterministicallyBoundedAndEmptyWithoutWordsOrCandidates(): void
    {
        // Given
        $a = self::own('Pâte brisée');
        $b = self::own('Pâte sablée');

        // Then : l'ordre ne dépend pas de celui des candidats ; les bornes sont celles de `suggest()`
        $this->assertEquals($this->ranker->closest('pâte', [$a, $b]), $this->ranker->closest('pâte', [$b, $a]));
        $this->assertCount(1, $this->ranker->closest('pâte', [$a, $b], 1));
        $this->assertSame([], $this->ranker->closest('pâte', [$a, $b], 0));
        $this->assertSame([], $this->ranker->closest('pâte', []));
        $this->assertSame([], $this->ranker->closest('   ', [$a]));
    }

    public function testASuggestionIsNeverSureAndCarriesItsDepartment(): void
    {
        // When
        $suggestion = $this->ranker->suggest('sirop de menthe', [self::own('Sirop fruit rouge', 0, 'Boissons')])[0];

        // Then
        $this->assertSame(['sirop-fruit-rouge-u', 'Boissons', Confidence::UNSURE], [$suggestion->slug, $suggestion->departmentName, $suggestion->confidence]);
        $this->assertGreaterThanOrEqual(SpokenIngredientRanker::SUGGESTION_FLOOR, $suggestion->score);
    }

    public function testTheFloorIsBelowTheThresholdOfTheAutomaticChoice(): void
    {
        $this->assertLessThan(SearchRelevance::MINIMUM, SpokenIngredientRanker::SUGGESTION_FLOOR);
    }

    #[DataProvider('phraseKeys')]
    public function testThePhraseKeyIsTheNormalizedPhraseTheRankerReads(string $phrase, string $expectedKey): void
    {
        $this->assertSame($expectedKey, SpokenIngredientRanker::phraseKey($phrase));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function phraseKeys(): iterable
    {
        yield 'articles, accents et casse' => ['Du Pain', 'pain'];
        yield 'pluriel replié' => ['des pains', 'pain'];
        yield 'plusieurs mots' => ['des pommes de terre', 'pomme-terre'];
        yield 'ponctuation' => [' Crème   fraîche ?!', 'creme-fraiche'];
        yield 'rien qui compte' => ['de la', ''];
        yield 'vide' => ['', ''];
    }

    public function testThePhraseKeyIsBoundedToTheSizeOfTheColumn(): void
    {
        // Given : une phrase très longue (cent mots de quatre lettres)
        $phrase = implode(' ', array_fill(0, 100, 'abcd'));

        // Then
        $this->assertSame(SpokenIngredientRanker::MAX_KEY_LENGTH, mb_strlen(SpokenIngredientRanker::phraseKey($phrase)));
    }

    public function testTwoWaysToSayTheSameThingHaveTheSameKeyAndTheMemoryBeatsTheResemblance(): void
    {
        // Given
        $candidates = [self::own('Persil')];

        // Then : la clé d'écriture est la clé de lecture
        $this->assertSame(SpokenIngredientRanker::phraseKey('un persil'), SpokenIngredientRanker::phraseKey('le persil'));
        // Then : la mémoire l'emporte même sans la moindre ressemblance
        $this->assertSame('Persil', $this->ranker->rank('ciboulette', $candidates, 'persil-u')?->name);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Les équivalents : tout ce qui vaut la phrase par le nom, quelle que soit la confiance (ADR 0029 décision 12)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @param list<RankedIngredient> $ranked
     *
     * @return list<string>
     */
    private static function slugsOf(array $ranked): array
    {
        return array_map(static fn($entry): string => $entry->slug, $ranked);
    }

    public function testHabitsThatDecideDoNotHideTheOtherIngredientOfTheSameName(): void
    {
        // Given : « tomate » en fruit et légume (40 usages) et en surgelé (2) : les habitudes tranchent (`PROBABLE`), pas d'ex æquo
        $fresh = new SpokenCandidate('tomate-fl', 'tomate', 'tomate', 'Fruit & légume', 40, 'fruit-et-legume');
        $frozen = new SpokenCandidate('tomate-sg', 'tomate', 'tomate', 'Surgelé', 2, 'surgele');

        // When
        $ranked = $this->ranker->rank('des tomates', [$frozen, $fresh]);
        $ties = $this->ranker->ties('des tomates', [$frozen, $fresh]);
        $equivalents = $this->ranker->equivalents('des tomates', [$frozen, $fresh]);

        // Then : le classeur choisit la fraîche, rien à départager, mais les DEUX sont équivalentes (la plus utilisée d'abord)
        $this->assertSame([Confidence::PROBABLE, []], [$ranked?->confidence, $ties]);
        $this->assertSame(['tomate-fl', 'tomate-sg'], self::slugsOf($equivalents));
    }

    public function testEquivalentsAreIndependentOfTheOrderOfTheCandidates(): void
    {
        // Given
        $a = new SpokenCandidate('a', 'tomate', 'tomate', 'Conserve', 3, 'conserve');
        $b = new SpokenCandidate('b', 'tomate', 'tomate', 'Surgelé', 3, 'surgele');
        $c = new SpokenCandidate('c', 'tomate', 'tomate', 'Fruit', 3, 'fruit');

        // Then
        $this->assertSame(self::slugsOf($this->ranker->equivalents('tomate', [$a, $b, $c])), self::slugsOf($this->ranker->equivalents('tomate', [$c, $a, $b])));
        $this->assertCount(3, $this->ranker->equivalents('tomate', [$a, $b, $c]), 'tous rendus, sans la limite de trois des propositions');
    }

    public function testTwoIngredientsOfTheSameNameAndDepartmentAreBothEquivalentsEvenWhenTiesRefuseThem(): void
    {
        // Given : des doublons non fusionnés (même nom, même rayon) : `ties()` ne les départage pas par une question
        $first = new SpokenCandidate('tomate-1', 'tomate', 'tomate', 'Fruit & légume', 0, 'fruit-et-legume');
        $second = new SpokenCandidate('tomate-2', 'tomate', 'tomate', 'Fruit & légume', 0, 'fruit-et-legume');

        // Then
        $this->assertSame([], $this->ranker->ties('tomate', [$first, $second]));
        $this->assertSame(['tomate-1', 'tomate-2'], self::slugsOf($this->ranker->equivalents('tomate', [$second, $first])));
    }

    public function testAnIdenticalNameExcludesTheNamesThatAreOnlyClose(): void
    {
        // Given : « tomate » existe exactement ; « tomates pelées » est bien plus utilisée mais n'est que proche
        $exact = self::own('Tomate', 1);
        $close = self::own('Tomates pelées', 50);

        // Then : comme `rank()`, le nom identique passe seul
        $this->assertSame(['tomate-u'], self::slugsOf($this->ranker->equivalents('des tomates', [$close, $exact])));
    }

    public function testCloseNamesWithinTheClearMarginAreEquivalentsAndTheFartherOneIsNot(): void
    {
        // Given : aucun nom identique à « pain » ; deux noms qui le commencent, de pertinence voisine, et un plus long
        $mie = self::own('Pain de mie', 0);
        $complet = self::own('Pain complet', 0);
        $campagne = self::own('Pain au levain de campagne', 0);

        // When
        $equivalents = $this->ranker->equivalents('pain', [$campagne, $complet, $mie]);

        // Then : les deux premiers, jamais celui dont le nom est bien plus éloigné
        $this->assertEqualsCanonicalizing(['pain-complet-u', 'pain-de-mie-u'], array_slice(self::slugsOf($equivalents), 0, 2));
        $this->assertNotContains('pain-au-levain-de-campagne-u', self::slugsOf($equivalents));
    }

    public function testTheMemoryAloneIsTheEquivalentAndNothingChoosesNothing(): void
    {
        // Given
        $persil = self::own('Persil');
        $fresh = new SpokenCandidate('tomate-fl', 'tomate', 'tomate', 'Fruit & légume', 0, 'fruit-et-legume');
        $frozen = new SpokenCandidate('tomate-sg', 'tomate', 'tomate', 'Surgelé', 0, 'surgele');

        // Then : l'ingrédient mémorisé passe avant tout, seul ; une phrase sans rapport ou vide ne rend rien
        $this->assertSame(['persil-u'], self::slugsOf($this->ranker->equivalents('ciboulette', [$persil], 'persil-u')));
        $this->assertSame(['tomate-sg'], self::slugsOf($this->ranker->equivalents('des tomates', [$fresh, $frozen], 'tomate-sg')));
        $this->assertSame([], $this->ranker->equivalents('ciboulette', [$persil]));
        $this->assertSame([], $this->ranker->equivalents('de la', [$persil]));
        $this->assertSame([], $this->ranker->equivalents('persil', []));
    }
}
