<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\SpokenMealRequest;
use Repas\Voice\Domain\Service\SpokenMealPhrase;

/**
 * Ce que l'utilisateur dit d'un repas (ADR 0029 décision 13, VOX-305) : le mot « repas » ou « recette » annonce une recette, le « pour six personnes »
 * en fin de phrase donne le nombre de personnes, et les réponses brèves du dialogue (« six », « rien », « une recette ») se comprennent quelle que soit
 * l'intention qu'Alexa a choisie.
 */
class SpokenMealPhraseTest extends TestCase
{
    private SpokenMealPhrase $meal;

    protected function setUp(): void
    {
        $this->meal = new SpokenMealPhrase();
    }

    #[DataProvider('announcements')]
    public function testTheWordMealOrRecipeAnnouncesAMeal(string $phrase, bool $isMeal): void
    {
        $this->assertSame($isMeal, $this->meal->announcesMeal($phrase), $phrase);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function announcements(): iterable
    {
        yield 'le repas' => ['le repas hachis parmentier', true];
        yield 'la recette' => ['la recette hachis parmentier', true];
        yield 'une recette' => ['une recette de gratin', true];
        yield 'un repas' => ['un repas de pâtes', true];
        yield 'sans article' => ['recette hachis parmentier', true];
        yield 'majuscules' => ['Le Repas Hachis', true];
        yield 'un produit' => ['un hachis parmentier', false];
        yield 'des croquettes' => ['des croquettes', false];
        yield 'un mot qui commence pareil' => ['repasser du linge', false];
        yield 'le mot au milieu' => ['croquettes pour repas', false];
        yield 'des repas (pluriel de produit)' => ['des repas pour chien', false];
    }

    #[DataProvider('parsed')]
    public function testItKeepsTheNameOfTheRecipeAndTheNumberOfPeople(string $phrase, ?int $slot, string $recipe, ?int $servings): void
    {
        $request = $this->meal->parse($phrase, $slot);

        $this->assertSame([$recipe, $servings, false], [$request->recipe, $request->servings, $request->unusableServings], $phrase);
    }

    /**
     * @return iterable<string, array{string, ?int, string, ?int}>
     */
    public static function parsed(): iterable
    {
        yield 'le nom seul' => ['hachis parmentier', null, 'hachis parmentier', null];
        yield 'le repas' => ['le repas hachis parmentier', null, 'hachis parmentier', null];
        yield 'la recette de' => ['la recette de hachis parmentier', null, 'hachis parmentier', null];
        yield 'la recette du' => ['la recette du hachis', null, 'hachis', null];
        yield 'la recette d\'' => ["la recette d'osso bucco", null, 'osso bucco', null];
        yield 'pour six personnes' => ['hachis parmentier pour six personnes', null, 'hachis parmentier', 6];
        yield 'pour 6' => ['le repas hachis parmentier pour 6', null, 'hachis parmentier', 6];
        yield 'pour 6 personnes' => ['la recette hachis pour 6 personnes', null, 'hachis', 6];
        yield 'pour une personne' => ['gratin pour une personne', null, 'gratin', 1];
        yield 'pour douze convives' => ['gratin pour douze convives', null, 'gratin', 12];
        yield 'le créneau passe avant la queue de la phrase' => ['gratin pour six personnes', 4, 'gratin', 4];
        yield 'la queue est retirée même avec le créneau' => ['le repas gratin pour 6', 2, 'gratin', 2];
        yield 'pour quelqu\'un d\'autre n\'est pas un nombre' => ['gratin pour le chat', null, 'gratin pour le chat', null];
        yield 'pour trente personnes' => ['gratin pour trente personnes', null, 'gratin', 30];
        yield 'pour vingt et un' => ['gratin pour vingt et un', null, 'gratin', 21];
        yield 'pour quatre vingt dix neuf personnes' => ['gratin pour quatre vingt dix neuf personnes', null, 'gratin', 99];
        yield 'pour 99' => ['gratin pour 99', null, 'gratin', 99];
        yield 'le mot seul' => ['le repas', null, '', null];
        yield 'la recette seule' => ['la recette', 3, '', 3];
        yield 'espaces et majuscules' => ["  Le   REPAS  Hachis  POUR  Six  Personnes ", null, 'hachis', 6];
        yield 'le nom contient « repas »' => ['repas du dimanche', null, 'dimanche', null];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableServings(): iterable
    {
        // Un nombre LU mais hors de 1 à 99 : la recette ne le garde pas dans son nom, et Alexa redemande combien
        yield 'pour 100 personnes' => ['hachis parmentier pour 100 personnes', 'hachis parmentier'];
        yield 'pour 0' => ['hachis parmentier pour 0', 'hachis parmentier'];
        yield 'pour 0 personnes' => ['le repas hachis parmentier pour 0 personnes', 'hachis parmentier'];
        yield 'pour 999 personnes' => ['hachis parmentier pour 999 personnes', 'hachis parmentier'];
        yield 'pour cent personnes' => ['hachis parmentier pour cent personnes', 'hachis parmentier'];
    }

    #[DataProvider('unusableServings')]
    public function testANumberOfPeopleThatIsReadButOutOfRangeIsNotKeptInTheNameAndIsAskedAgain(string $phrase, string $recipe): void
    {
        // When
        $request = $this->meal->parse($phrase);

        // Then : rien n'est écrit, le nombre de personnes n'existe pas, et l'indication de redemander est posée
        $this->assertSame($recipe, $request->recipe);
        $this->assertNull($request->servings);
        $this->assertTrue($request->unusableServings);
    }

    public function testASlotThatIsUsableWinsOverAnUnusableTailAndTheTailIsStillRemoved(): void
    {
        $request = $this->meal->parse('hachis pour 100 personnes', 4);

        $this->assertSame(['hachis', 4, false], [$request->recipe, $request->servings, $request->unusableServings]);
    }

    public function testTheOriginalPhraseKeepsTheWordButNotTheNumberOfPeople(): void
    {
        // « ajoute le repas pour chien » dit pour un PRODUIT : la phrase d'origine (avec le mot) est ce que la recherche de produits reprendra
        $request = $this->meal->parse('Le repas pour chien');
        $this->assertSame(['pour chien', 'le repas pour chien'], [$request->recipe, $request->original]);

        $withServings = $this->meal->parse('la recette de gratin pour six personnes');
        $this->assertSame(['gratin', 'la recette de gratin', 6], [$withServings->recipe, $withServings->original, $withServings->servings]);

        $plain = $this->meal->parse('hachis parmentier');
        $this->assertSame(['hachis parmentier', 'hachis parmentier'], [$plain->recipe, $plain->original]);
    }

    public function testTheRequestIsAMealForTheMealIntentAndForAnItemPhraseThatAnnouncesOne(): void
    {
        // Given
        $mealIntent = AlexaRequest::fromDecoded(AlexaTestRequest::addMeal('hachis parmentier pour six personnes')->toArray());
        $itemWithTheWord = AlexaRequest::fromDecoded(AlexaTestRequest::addItem('le repas hachis parmentier')->toArray());
        $plainItem = AlexaRequest::fromDecoded(AlexaTestRequest::addItem('un hachis parmentier')->toArray());
        $remove = AlexaRequest::fromDecoded(AlexaTestRequest::removeItem('le repas hachis')->toArray());
        $withSlot = AlexaRequest::fromDecoded(AlexaTestRequest::addMeal('hachis', 'cinq', '5')->toArray());
        $noRecipe = AlexaRequest::fromDecoded(AlexaTestRequest::addMeal(null)->toArray());

        // Then
        $this->assertSame(['hachis parmentier', 6], $this->recipeAndServings($this->meal->requestOf($mealIntent)));
        $this->assertSame(['hachis parmentier', null], $this->recipeAndServings($this->meal->requestOf($itemWithTheWord)));
        $this->assertNull($this->meal->requestOf($plainItem), '« ajoute un hachis parmentier » reste un produit');
        $this->assertNull($this->meal->requestOf($remove), 'retirer un repas est VOX-306 : « enlève » reste réservé aux produits');
        $this->assertSame(['hachis', 5], $this->recipeAndServings($this->meal->requestOf($withSlot)));
        $this->assertSame(['', null], $this->recipeAndServings($this->meal->requestOf($noRecipe)));
        $this->assertNull($this->meal->requestOf(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->toArray())));
    }

    #[DataProvider('servingsAnswers')]
    public function testANumberOfPeopleSaidAloneIsTheNewTotal(string $text, ?int $expected): void
    {
        $this->assertSame($expected, $this->meal->servingsAnswer($text), $text);
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function servingsAnswers(): iterable
    {
        yield '6' => ['6', 6];
        yield 'six' => ['six', 6];
        yield 'six personnes' => ['six personnes', 6];
        yield '6 personnes' => ['6 personnes', 6];
        yield 'pour six' => ['pour six', 6];
        yield 'pour 6 personnes' => ['pour 6 personnes', 6];
        yield 'une personne' => ['une personne', 1];
        yield 'vingt' => ['vingt', 20];
        yield 'majuscules' => ['SIX', 6];
        yield '99' => ['99', 99];
        yield 'zéro' => ['0', null];
        yield 'trois chiffres' => ['100', null];
        yield 'trente' => ['trente', 30];
        yield 'pour quarante cinq personnes' => ['pour quarante cinq personnes', 45];
        yield 'un mot qui n\'est pas un nombre' => ['pain', null];
        yield 'une phrase' => ['six croquettes de poulet', null];
        yield 'vide' => ['', null];
    }

    #[DataProvider('keepAnswers')]
    public function testSayingNothingToChangeIsUnderstood(string $text, bool $expected): void
    {
        $this->assertSame($expected, $this->meal->isKeepAnswer($text), $text);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function keepAnswers(): iterable
    {
        yield 'rien' => ['rien', true];
        yield 'ne change rien' => ['ne change rien', true];
        yield 'ne rien changer' => ['ne rien changer', true];
        yield 'laisse comme ça' => ['laisse comme ça', true];
        yield 'tel quel' => ['Tel quel', true];
        yield 'pareil' => ['pareil', true];
        yield 'un nombre' => ['six', false];
        yield 'un produit' => ['du pain', false];
        yield 'vide' => ['', false];
    }

    #[DataProvider('kinds')]
    public function testARecipeOrAProductSaidAloneIsUnderstood(string $text, ?string $kind): void
    {
        $this->assertSame($kind, $this->meal->kindOf($text), $text);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function kinds(): iterable
    {
        yield 'recette' => ['recette', 'meal'];
        yield 'une recette' => ['une recette', 'meal'];
        yield 'c\'est une recette' => ["c'est une recette", 'meal'];
        yield 'plutôt un repas' => ['plutôt un repas', 'meal'];
        yield 'la recette de cuisine' => ['la recette de cuisine', 'meal'];
        yield 'produit' => ['produit', 'item'];
        yield 'un produit' => ['un produit', 'item'];
        yield 'c\'est un article' => ["c'est un article", 'item'];
        yield 'un ingrédient' => ['un ingrédient', 'item'];
        yield 'les deux' => ['un produit ou une recette', null];
        yield 'un nom qui contient le mot' => ['recette de ma grand-mère', null];
        yield 'des croquettes' => ['des croquettes', null];
        yield 'un nouveau produit' => ['un nouveau produit', null];
        yield 'vide' => ['', null];
    }

    /**
     * @return array{string, ?int}
     */
    private function recipeAndServings(?SpokenMealRequest $request): array
    {
        $this->assertNotNull($request);

        return [$request->recipe, $request->servings];
    }
}
