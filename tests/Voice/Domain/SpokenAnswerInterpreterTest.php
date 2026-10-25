<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenAnswerKind;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Service\SpokenAnswerInterpreter;

/**
 * Comprendre la réponse à la question de l'étape (ADR 0029 décision 11, VOX-303) : toute la matrice état × intention, et ce que les créneaux
 * apportent. Alexa choisit l'intention sans connaître notre état, donc la même intention veut dire des choses différentes selon l'étape.
 */
class SpokenAnswerInterpreterTest extends TestCase
{
    private SpokenAnswerInterpreter $interpreter;

    protected function setUp(): void
    {
        $this->interpreter = new SpokenAnswerInterpreter();
    }

    private static function option(string $name): DialogueOption
    {
        return new DialogueOption($name . '-u', $name);
    }

    /**
     * @param list<string> $names
     */
    private static function choose(array $names): SpokenDialogueState
    {
        return SpokenDialogueState::choose('de la ciboulette', array_map(self::option(...), $names), 'ciboulette');
    }

    private static function department(): SpokenDialogueState
    {
        return self::choose([])->askingDepartment();
    }

    private static function unit(): SpokenDialogueState
    {
        return self::department()->withDepartment(new DialogueOption('boucherie', 'boucherie'));
    }

    private static function confirm(): SpokenDialogueState
    {
        return self::unit()->withUnit(new DialogueOption('unite', 'unité'));
    }

    /**
     * @return list<DialogueOption>
     */
    private static function departments(): array
    {
        return [
            new DialogueOption('boucherie', 'boucherie'),
            new DialogueOption('fruit-et-legume', 'fruit & légume'),
            new DialogueOption('produit-menager', 'produit ménager'),
            new DialogueOption('fromage', 'fromage'),
        ];
    }

    /**
     * @return list<DialogueOption>
     */
    private static function units(): array
    {
        return [
            new DialogueOption('kilo', 'kilo'),
            new DialogueOption('unite', 'unité'),
            new DialogueOption('gramme', 'gramme'),
            new DialogueOption('cuillere-a-soupe', 'cuillère à soupe'),
            new DialogueOption('boite', 'boîte'),
        ];
    }

    private function interpret(AlexaTestRequest $request, SpokenDialogueState $state, array $catalogue = []): array
    {
        $answer = $this->interpreter->interpret(AlexaRequest::fromDecoded($request->toArray()), $state, $catalogue);

        return [$answer->kind, $answer->option?->name, $answer->phrase];
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Étape « choose »
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @return iterable<string, array{AlexaTestRequest, list<string>, SpokenAnswerKind, ?string, ?string}>
     */
    public static function chooseCases(): iterable
    {
        $three = ['persil', 'basilic', 'coriandre'];

        // Un rang
        yield 'rang 1 par la résolution' => [AlexaTestRequest::chooseOption('le premier', '1'), $three, SpokenAnswerKind::PICK_OPTION, 'persil', null];
        yield 'rang 2 par la résolution' => [AlexaTestRequest::chooseOption('le deuxième', '2'), $three, SpokenAnswerKind::PICK_OPTION, 'basilic', null];
        yield 'rang 3 par la résolution' => [AlexaTestRequest::chooseOption('trois', '3'), $three, SpokenAnswerKind::PICK_OPTION, 'coriandre', null];
        yield 'rang dit « deux » sans résolution' => [AlexaTestRequest::chooseOption('deux', null), $three, SpokenAnswerKind::PICK_OPTION, 'basilic', null];
        yield 'rang dit « le second » sans résolution' => [AlexaTestRequest::chooseOption('le second', null), $three, SpokenAnswerKind::PICK_OPTION, 'basilic', null];
        yield 'rang dit « un » sans résolution' => [AlexaTestRequest::chooseOption('un', null), $three, SpokenAnswerKind::PICK_OPTION, 'persil', null];
        yield 'rang écrit « 3 » sans résolution' => [AlexaTestRequest::chooseOption('3', null), $three, SpokenAnswerKind::PICK_OPTION, 'coriandre', null];
        yield 'rang 3 mais deux propositions' => [AlexaTestRequest::chooseOption('trois', '3'), ['persil', 'basilic'], SpokenAnswerKind::UNCLEAR, null, null];
        yield 'rang 0' => [AlexaTestRequest::chooseOption('zéro', '0'), $three, SpokenAnswerKind::UNCLEAR, null, null];
        yield 'rang sans rien' => [AlexaTestRequest::chooseOption(null, null), $three, SpokenAnswerKind::UNCLEAR, null, null];
        yield 'rang incompréhensible' => [AlexaTestRequest::chooseOption('bof', null), $three, SpokenAnswerKind::UNCLEAR, null, null];
        yield 'rang sans proposition' => [AlexaTestRequest::chooseOption('un', '1'), [], SpokenAnswerKind::UNCLEAR, null, null];

        // Un nom dit en entier, quelle que soit l'intention
        yield 'le nom d\'une proposition (AddItemIntent)' => [AlexaTestRequest::addItem('basilic'), $three, SpokenAnswerKind::PICK_OPTION, 'basilic', null];
        yield 'le nom avec un article' => [AlexaTestRequest::addItem('du persil'), $three, SpokenAnswerKind::PICK_OPTION, 'persil', null];
        yield 'le nom au pluriel' => [AlexaTestRequest::addItem('coriandres'), $three, SpokenAnswerKind::PICK_OPTION, 'coriandre', null];
        yield 'le nom d\'une proposition (autre intention à créneau)' => [AlexaTestRequest::answerUnit('basilic', null), $three, SpokenAnswerKind::PICK_OPTION, 'basilic', null];

        // Un nouveau produit
        yield '« un nouveau produit »' => [AlexaTestRequest::createItem(), $three, SpokenAnswerKind::START_CREATION, null, null];
        yield '« un nouveau produit » sans proposition' => [AlexaTestRequest::createItem(), [], SpokenAnswerKind::START_CREATION, null, null];
        yield '« non » avec propositions' => [AlexaTestRequest::no(), $three, SpokenAnswerKind::START_CREATION, null, null];
        yield '« non » avec une proposition' => [AlexaTestRequest::no(), ['persil'], SpokenAnswerKind::START_CREATION, null, null];
        yield '« non » sans proposition : on abandonne' => [AlexaTestRequest::no(), [], SpokenAnswerKind::ABANDON, null, null];

        // « Oui »
        yield '« oui » avec une seule proposition' => [AlexaTestRequest::yes(), ['persil'], SpokenAnswerKind::PICK_OPTION, 'persil', null];
        yield '« oui » avec plusieurs propositions : laquelle ?' => [AlexaTestRequest::yes(), $three, SpokenAnswerKind::WHICH_ONE, null, null];
        yield '« oui » sans proposition : on crée' => [AlexaTestRequest::yes(), [], SpokenAnswerKind::START_CREATION, null, null];

        // Un autre article : nouvelle demande
        yield 'un autre article sans rapport' => [AlexaTestRequest::addItem('du lait'), $three, SpokenAnswerKind::NEW_REQUEST, null, 'du lait'];
        yield 'un autre article sans proposition' => [AlexaTestRequest::addItem('du lait'), [], SpokenAnswerKind::NEW_REQUEST, null, 'du lait'];
        yield 'AddItemIntent sans phrase' => [AlexaTestRequest::addItem(null), $three, SpokenAnswerKind::UNCLEAR, null, null];

        // Le reste
        yield 'aide : on répète les propositions' => [AlexaTestRequest::intent('AMAZON.HelpIntent'), $three, SpokenAnswerKind::REPEAT, null, null];
        yield '« lesquels » : on répète les propositions' => [AlexaTestRequest::listChoices(), $three, SpokenAnswerKind::REPEAT, null, null];
        yield '« lesquels » sans proposition : on répète la question' => [AlexaTestRequest::listChoices(), [], SpokenAnswerKind::REPEAT, null, null];
        yield 'repli' => [AlexaTestRequest::intent('AMAZON.FallbackIntent'), $three, SpokenAnswerKind::UNCLEAR, null, null];
        yield 'intention inconnue' => [AlexaTestRequest::intent('AMAZON.NavigateHomeIntent'), $three, SpokenAnswerKind::UNCLEAR, null, null];
        yield 'une réponse de rayon n\'a pas de sens ici' => [AlexaTestRequest::answerDepartment('boucherie', 'boucherie'), $three, SpokenAnswerKind::UNCLEAR, null, null];
        yield 'une réponse d\'unité n\'a pas de sens ici' => [AlexaTestRequest::answerUnit('kilos', 'kilo'), $three, SpokenAnswerKind::UNCLEAR, null, null];
    }

    /**
     * @param list<string> $options
     */
    #[DataProvider('chooseCases')]
    public function testAnswerToTheProposals(AlexaTestRequest $request, array $options, SpokenAnswerKind $kind, ?string $optionName, ?string $phrase): void
    {
        $this->assertSame([$kind, $optionName, $phrase], $this->interpret($request, self::choose($options)));
    }

    public function testANewArticleSaidWithAQuantityIsANewRequestButAnUnusableQuantityIsNotUnderstood(): void
    {
        // Given : des propositions, puis « deux kilos de tomates » (une nouvelle demande) ou « zéro tomate »
        $state = self::choose(['pâte', 'pâte brisée']);

        // When / Then : le cas d'usage reprend la quantité et l'unité dites ; une quantité inutilisable est une réponse incomprise
        $this->assertSame([SpokenAnswerKind::NEW_REQUEST, null, 'tomates'], $this->interpret(AlexaTestRequest::addQuantified('tomates', 2, 'kilos', 'kilo'), $state));
        $this->assertSame([SpokenAnswerKind::UNCLEAR, null, null], $this->interpret(AlexaTestRequest::addQuantified('tomates', 0), $state));
    }

    /**
     * @return iterable<string, array{AlexaTestRequest, SpokenAnswerKind, ?string}>
     */
    public static function newDemands(): iterable
    {
        yield 'enlève du pain' => [AlexaTestRequest::removeItem('du pain'), SpokenAnswerKind::NEW_REMOVAL, 'du pain'];
        yield 'enlève deux kilos de tomates' => [AlexaTestRequest::removeItem('tomates')->withQuantity(2)->withSlot('unit', 'kilos', 'kilo'), SpokenAnswerKind::NEW_REMOVAL, 'tomates'];
        yield 'est-ce qu\'il y a du pain' => [AlexaTestRequest::checkItem('du pain'), SpokenAnswerKind::NEW_CHECK, 'du pain'];
        yield 'ajoute du lait' => [AlexaTestRequest::addItem('du lait'), SpokenAnswerKind::NEW_REQUEST, 'du lait'];
        yield 'enlève sans produit compris' => [AlexaTestRequest::removeItem(null), SpokenAnswerKind::UNCLEAR, null];
        yield 'est-ce qu\'il y a sans produit compris' => [AlexaTestRequest::checkItem('?'), SpokenAnswerKind::UNCLEAR, null];
        yield 'enlève zéro tomate : quantité inutilisable' => [AlexaTestRequest::removeItem('tomates')->withQuantity(0), SpokenAnswerKind::UNCLEAR, null];
    }

    #[DataProvider('newDemands')]
    public function testARemovalOrACheckSaidInTheMiddleOfAQuestionIsANewDemand(AlexaTestRequest $request, SpokenAnswerKind $kind, ?string $phrase): void
    {
        // Given : des propositions de produit inconnu, puis un ingrédient à départager : même réponse aux deux questions
        // When / Then : ajouter, retirer et consulter sont de nouvelles demandes (jamais une réponse incomprise, comme avant la revue)
        $this->assertSame([$kind, null, $phrase], $this->interpret($request, self::choose(['pâte', 'pâte brisée'])));
        $this->assertSame([$kind, null, $phrase], $this->interpret($request, self::choose([])));
        $this->assertSame([$kind, null, $phrase], $this->interpret($request, SpokenDialogueState::chooseToRemove('des tomates', [
            new DialogueOption('tomate-fl', 'tomate', 'fruit-et-legume', 'fruit & légume'),
            new DialogueOption('tomate-sg', 'tomate', 'surgele', 'surgelé'),
        ], 'tomate')));
    }

    public function testARemovalSaidAtTheDepartmentOrUnitQuestionIsNotANewDemand(): void
    {
        // Then : aux étapes de la création (rayon, unité) l'article d'un ajout ne l'est pas non plus : ce n'est pas un rayon, on redemande
        $this->assertSame([SpokenAnswerKind::UNCLEAR, null, null], $this->interpret(AlexaTestRequest::removeItem('du pain'), self::department(), self::departments()));
    }

    public function testTwoPropositionsThatTheHeardNameCannotTellApartAreNotChosenForTheUser(): void
    {
        // Given : « persil » se dit en entier mais deux propositions s'appellent presque pareil
        $state = self::choose(['persil plat', 'persil frisé']);

        // Then : la proposition n'est pas devinée ; ce n'est pas non plus un autre article
        $this->assertSame([SpokenAnswerKind::NEW_REQUEST, null, 'persil'], $this->interpret(AlexaTestRequest::addItem('persil'), $state));
        $this->assertSame([SpokenAnswerKind::PICK_OPTION, 'persil frisé', null], $this->interpret(AlexaTestRequest::addItem('persil frisé'), $state));
    }

    public function testTheNameIsMatchedAgainstTheOptionsOfTheStateOnly(): void
    {
        // Given : l'état ne propose que le basilic
        $state = self::choose(['basilic']);

        // Then : un persil dit n'est pas une proposition
        $this->assertSame([SpokenAnswerKind::NEW_REQUEST, null, 'persil'], $this->interpret(AlexaTestRequest::addItem('persil'), $state));
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Étape « choose » avec des ingrédients à départager par leur rayon
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    private static function tie(bool $sameDepartment = false): SpokenDialogueState
    {
        return SpokenDialogueState::choose('des tomates', [
            new DialogueOption('tomate-fl', 'tomate', 'fruit-et-legume', 'fruit & légume'),
            new DialogueOption('tomate-sg', 'tomate', $sameDepartment ? 'fruit-et-legume' : 'surgele', $sameDepartment ? 'fruit & légume' : 'surgelé'),
            new DialogueOption('tomate-pelee', 'tomates pelées', 'conserve', 'conserve'),
        ], 'tomate');
    }

    /**
     * @return iterable<string, array{AlexaTestRequest, SpokenAnswerKind, ?string}>
     */
    public static function tieCases(): iterable
    {
        yield 'le rayon par la résolution' => [AlexaTestRequest::answerDepartment('surgelé', 'surgele'), SpokenAnswerKind::PICK_OPTION, 'tomate-sg'];
        yield 'le rayon par la résolution (fruit et légume)' => [AlexaTestRequest::answerDepartment('fruits et légumes', 'fruit-et-legume'), SpokenAnswerKind::PICK_OPTION, 'tomate-fl'];
        yield 'le rayon par le texte seul' => [AlexaTestRequest::answerDepartment('les surgelés', null), SpokenAnswerKind::PICK_OPTION, 'tomate-sg'];
        yield 'le rayon dit comme un article' => [AlexaTestRequest::addItem('conserve'), SpokenAnswerKind::PICK_OPTION, 'tomate-pelee'];
        yield 'un rayon qui n\'est pas dans les propositions' => [AlexaTestRequest::answerDepartment('boucherie', 'boucherie'), SpokenAnswerKind::UNCLEAR, null];
        yield 'le rang' => [AlexaTestRequest::chooseOption('le deuxième', '2'), SpokenAnswerKind::PICK_OPTION, 'tomate-sg'];
        yield 'rang hors propositions' => [AlexaTestRequest::chooseOption('quatre', '4'), SpokenAnswerKind::UNCLEAR, null];
        yield 'le nom en entier (tomates pelées)' => [AlexaTestRequest::addItem('tomates pelées'), SpokenAnswerKind::PICK_OPTION, 'tomate-pelee'];
        yield '« tomate » seul ne départage pas' => [AlexaTestRequest::addItem('tomate'), SpokenAnswerKind::NEW_REQUEST, null];
        yield '« oui » : lequel ?' => [AlexaTestRequest::yes(), SpokenAnswerKind::WHICH_ONE, null];
        yield '« non » abandonne' => [AlexaTestRequest::no(), SpokenAnswerKind::ABANDON, null];
        yield 'pas de « nouveau produit »' => [AlexaTestRequest::createItem(), SpokenAnswerKind::UNCLEAR, null];
        yield 'un autre article' => [AlexaTestRequest::addItem('du lait'), SpokenAnswerKind::NEW_REQUEST, null];
        yield 'aide : on répète la question' => [AlexaTestRequest::intent('AMAZON.HelpIntent'), SpokenAnswerKind::REPEAT, null];
        yield '« lesquels » : on répète la question' => [AlexaTestRequest::listChoices(), SpokenAnswerKind::REPEAT, null];
        yield 'repli' => [AlexaTestRequest::intent('AMAZON.FallbackIntent'), SpokenAnswerKind::UNCLEAR, null];
    }

    #[DataProvider('tieCases')]
    public function testAnswerToTheQuestionThatTellsIngredientsApart(AlexaTestRequest $request, SpokenAnswerKind $kind, ?string $slug): void
    {
        $answer = $this->interpreter->interpret(AlexaRequest::fromDecoded($request->toArray()), self::tie());

        $this->assertSame([$kind, $slug], [$answer->kind, $answer->option?->slug]);
    }

    public function testTwoProposalsOfTheSameDepartmentAskWhichOne(): void
    {
        $answer = $this->interpreter->interpret(AlexaRequest::fromDecoded(AlexaTestRequest::answerDepartment('fruits et légumes', 'fruit-et-legume')->toArray()), self::tie(sameDepartment: true));

        $this->assertSame([SpokenAnswerKind::WHICH_ONE, null], [$answer->kind, $answer->option?->slug]);
    }

    public function testASaidNewArticleIsNeverTakenForACorrection(): void
    {
        $this->assertNull(self::tie()->rememberablePhrase());
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Étape « department »
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @return iterable<string, array{AlexaTestRequest, SpokenAnswerKind, ?string}>
     */
    public static function departmentCases(): iterable
    {
        yield 'le rayon par la résolution' => [AlexaTestRequest::answerDepartment('la boucherie', 'boucherie'), SpokenAnswerKind::PICK_DEPARTMENT, 'boucherie'];
        yield 'la résolution l\'emporte sur le texte' => [AlexaTestRequest::answerDepartment('fromage', 'boucherie'), SpokenAnswerKind::PICK_DEPARTMENT, 'boucherie'];
        yield 'le rayon par le texte (sans résolution)' => [AlexaTestRequest::answerDepartment('fromage', null), SpokenAnswerKind::PICK_DEPARTMENT, 'fromage'];
        yield 'le rayon par le texte, pluriel et signes' => [AlexaTestRequest::answerDepartment('fruits et légumes', null), SpokenAnswerKind::PICK_DEPARTMENT, 'fruit & légume'];
        yield 'le rayon par le texte, accents' => [AlexaTestRequest::answerDepartment('produits menagers', null), SpokenAnswerKind::PICK_DEPARTMENT, 'produit ménager'];
        yield 'le rayon dit comme un article (AddItemIntent)' => [AlexaTestRequest::addItem('boucherie'), SpokenAnswerKind::PICK_DEPARTMENT, 'boucherie'];
        yield 'une résolution hors catalogue, texte qui aide' => [AlexaTestRequest::answerDepartment('fromage', 'inconnu'), SpokenAnswerKind::PICK_DEPARTMENT, 'fromage'];
        yield 'rien qui ressemble' => [AlexaTestRequest::answerDepartment('zébulon', null), SpokenAnswerKind::UNCLEAR, null];
        yield 'une résolution hors catalogue sans texte' => [AlexaTestRequest::answerDepartment(null, 'inconnu'), SpokenAnswerKind::UNCLEAR, null];
        yield 'rien du tout' => [AlexaTestRequest::answerDepartment(null, null), SpokenAnswerKind::UNCLEAR, null];
        yield '« non » : on abandonne' => [AlexaTestRequest::no(), SpokenAnswerKind::ABANDON, null];
        yield '« oui » n\'est pas un rayon' => [AlexaTestRequest::yes(), SpokenAnswerKind::UNCLEAR, null];
        yield 'un rang n\'est pas un rayon' => [AlexaTestRequest::chooseOption('un', '1'), SpokenAnswerKind::UNCLEAR, null];
        yield '« un nouveau produit » n\'est pas un rayon' => [AlexaTestRequest::createItem(), SpokenAnswerKind::UNCLEAR, null];
        yield 'aide : on lit les rayons' => [AlexaTestRequest::intent('AMAZON.HelpIntent'), SpokenAnswerKind::LIST_CHOICES, null];
        yield '« lesquels » : on lit les rayons' => [AlexaTestRequest::listChoices(), SpokenAnswerKind::LIST_CHOICES, null];
        yield 'repli' => [AlexaTestRequest::intent('AMAZON.FallbackIntent'), SpokenAnswerKind::UNCLEAR, null];
    }

    #[DataProvider('departmentCases')]
    public function testAnswerToTheQuestionOfTheDepartment(AlexaTestRequest $request, SpokenAnswerKind $kind, ?string $optionName): void
    {
        $this->assertSame([$kind, $optionName, null], $this->interpret($request, self::department(), self::departments()));
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Étape « unit »
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @return iterable<string, array{AlexaTestRequest, SpokenAnswerKind, ?string}>
     */
    public static function unitCases(): iterable
    {
        yield 'l\'unité par la résolution (« pièces » est l\'unité)' => [AlexaTestRequest::answerUnit('pièces', 'unite'), SpokenAnswerKind::PICK_UNIT, 'unité'];
        yield 'l\'unité par la résolution (« kilos »)' => [AlexaTestRequest::answerUnit('kilos', 'kilo'), SpokenAnswerKind::PICK_UNIT, 'kilo'];
        yield 'l\'unité par le texte (sans résolution)' => [AlexaTestRequest::answerUnit('kilo', null), SpokenAnswerKind::PICK_UNIT, 'kilo'];
        yield 'l\'unité par le texte, pluriel' => [AlexaTestRequest::answerUnit('grammes', null), SpokenAnswerKind::PICK_UNIT, 'gramme'];
        yield 'l\'unité par le texte, accents' => [AlexaTestRequest::answerUnit('boite', null), SpokenAnswerKind::PICK_UNIT, 'boîte'];
        yield 'l\'unité par le texte, plusieurs mots' => [AlexaTestRequest::answerUnit('cuillère à soupe', null), SpokenAnswerKind::PICK_UNIT, 'cuillère à soupe'];
        yield 'l\'unité dite comme un article' => [AlexaTestRequest::addItem('kilo'), SpokenAnswerKind::PICK_UNIT, 'kilo'];
        yield 'rien qui ressemble' => [AlexaTestRequest::answerUnit('zébulon', null), SpokenAnswerKind::UNCLEAR, null];
        yield 'rien du tout' => [AlexaTestRequest::answerUnit(null, null), SpokenAnswerKind::UNCLEAR, null];
        yield '« non » : on abandonne' => [AlexaTestRequest::no(), SpokenAnswerKind::ABANDON, null];
        yield '« oui » n\'est pas une unité' => [AlexaTestRequest::yes(), SpokenAnswerKind::UNCLEAR, null];
        yield 'un rayon n\'est pas une unité' => [AlexaTestRequest::answerDepartment('boucherie', 'boucherie'), SpokenAnswerKind::UNCLEAR, null];
        yield 'aide : on lit les unités' => [AlexaTestRequest::intent('AMAZON.HelpIntent'), SpokenAnswerKind::LIST_CHOICES, null];
        yield '« lesquelles » : on lit les unités' => [AlexaTestRequest::listChoices(), SpokenAnswerKind::LIST_CHOICES, null];
        yield 'repli' => [AlexaTestRequest::intent('AMAZON.FallbackIntent'), SpokenAnswerKind::UNCLEAR, null];
    }

    #[DataProvider('unitCases')]
    public function testAnswerToTheQuestionOfTheUnit(AlexaTestRequest $request, SpokenAnswerKind $kind, ?string $optionName): void
    {
        $this->assertSame([$kind, $optionName, null], $this->interpret($request, self::unit(), self::units()));
    }

    public function testTwoDepartmentsThatTheHeardTextCannotTellApartAreNotChosen(): void
    {
        // Given : « produit » rapproché de deux rayons au score voisin
        $catalogue = [new DialogueOption('produit-menager', 'produit ménager'), new DialogueOption('produit-laitier', 'produit laitier')];

        // Then : on redemande plutôt que de choisir à la place de l'utilisateur
        $this->assertSame([SpokenAnswerKind::UNCLEAR, null, null], $this->interpret(AlexaTestRequest::answerDepartment('produit', null), self::department(), $catalogue));
    }

    public function testWithAnEmptyCatalogueOnlyAnIdThatIsInItCounts(): void
    {
        $this->assertSame([SpokenAnswerKind::UNCLEAR, null, null], $this->interpret(AlexaTestRequest::answerDepartment('boucherie', 'boucherie'), self::department(), []));
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Étape « confirm »
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @return iterable<string, array{AlexaTestRequest, SpokenAnswerKind}>
     */
    public static function confirmCases(): iterable
    {
        yield '« oui » : on crée' => [AlexaTestRequest::yes(), SpokenAnswerKind::CONFIRM];
        yield '« non » : on abandonne' => [AlexaTestRequest::no(), SpokenAnswerKind::ABANDON];
        yield 'aide : on répète la question' => [AlexaTestRequest::intent('AMAZON.HelpIntent'), SpokenAnswerKind::REPEAT];
        yield '« lesquels » : on répète la question' => [AlexaTestRequest::listChoices(), SpokenAnswerKind::REPEAT];
        yield 'repli' => [AlexaTestRequest::intent('AMAZON.FallbackIntent'), SpokenAnswerKind::UNCLEAR];
        yield 'un article n\'est pas une confirmation' => [AlexaTestRequest::addItem('du lait'), SpokenAnswerKind::UNCLEAR];
        yield 'un nouveau produit n\'est pas une confirmation' => [AlexaTestRequest::createItem(), SpokenAnswerKind::UNCLEAR];
        yield 'un rayon n\'est pas une confirmation' => [AlexaTestRequest::answerDepartment('boucherie', 'boucherie'), SpokenAnswerKind::UNCLEAR];
    }

    #[DataProvider('confirmCases')]
    public function testAnswerToTheConfirmation(AlexaTestRequest $request, SpokenAnswerKind $kind): void
    {
        $this->assertSame([$kind, null, null], $this->interpret($request, self::confirm()));
    }

    public function testStopAndCancelAreNotUnderstoodHereBecauseTheDialogueClosesTheSessionBefore(): void
    {
        foreach ([self::choose(['persil']), self::department(), self::unit(), self::confirm()] as $state) {
            $this->assertSame(SpokenAnswerKind::UNCLEAR, $this->interpret(AlexaTestRequest::intent('AMAZON.StopIntent'), $state, self::departments())[0]);
            $this->assertSame(SpokenAnswerKind::UNCLEAR, $this->interpret(AlexaTestRequest::intent('AMAZON.CancelIntent'), $state, self::departments())[0]);
        }
    }
}
