<?php

namespace Repas\Tests\Repas\Domain\Service;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\Confidence;
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
}
