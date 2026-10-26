<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
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

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Un repas (ADR 0029 décision 13, VOX-305) : l'aiguillage entre produit et recette, le choix d'une recette, le nombre de personnes
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @param list<string> $names
     */
    private static function recipes(array $names, bool $otherSideTried = false, ?int $servings = null): SpokenDialogueState
    {
        return SpokenDialogueState::chooseRecipe('gratin', array_map(static fn(string $name): DialogueOption => new DialogueOption($name . '-id', $name), $names), 'gratin', $servings, $otherSideTried);
    }

    private static function planned(): SpokenDialogueState
    {
        return SpokenDialogueState::askingServings(new DialogueOption('gratin-id', 'gratin dauphinois'), 4);
    }

    /**
     * @return iterable<string, array{AlexaTestRequest, bool, SpokenAnswerKind}>
     */
    public static function productSideKinds(): iterable
    {
        // « Je ne connais pas X. Tu veux dire A, B ou C ? Ou un nouveau produit, ou une recette ? »
        yield 'une recette par le créneau' => [AlexaTestRequest::chooseKind('une recette', 'meal'), false, SpokenAnswerKind::SWITCH_TO_MEAL];
        yield 'une recette par le texte seul' => [AlexaTestRequest::chooseKind('une recette', null), false, SpokenAnswerKind::SWITCH_TO_MEAL];
        yield 'le repas par le texte seul' => [AlexaTestRequest::chooseKind('le repas', null), false, SpokenAnswerKind::SWITCH_TO_MEAL];
        yield 'recette dite à l\'intention des produits' => [AlexaTestRequest::addItem('une recette'), false, SpokenAnswerKind::SWITCH_TO_MEAL];
        yield 'c\'est une recette dit à l\'intention des produits' => [AlexaTestRequest::addItem("c'est une recette"), false, SpokenAnswerKind::SWITCH_TO_MEAL];
        yield 'les recettes ont déjà été cherchées' => [AlexaTestRequest::chooseKind('une recette', 'meal'), true, SpokenAnswerKind::UNCLEAR];
        yield 'un produit veut dire un nouveau produit' => [AlexaTestRequest::chooseKind('un produit', 'item'), false, SpokenAnswerKind::START_CREATION];
        yield 'un produit par le texte seul' => [AlexaTestRequest::addItem('un produit'), false, SpokenAnswerKind::START_CREATION];
        yield 'un produit, recettes déjà cherchées' => [AlexaTestRequest::chooseKind('un produit', 'item'), true, SpokenAnswerKind::START_CREATION];
    }

    #[DataProvider('productSideKinds')]
    public function testARecipeSaidToTheQuestionOfAnUnknownProductSwitchesToTheRecipesOnce(AlexaTestRequest $request, bool $otherSideTried, SpokenAnswerKind $expected): void
    {
        // Given : deux propositions de produits
        $state = SpokenDialogueState::choose('gratin', [self::option('persil'), self::option('basilic')], 'gratin', otherSideTried: $otherSideTried);

        // Then
        $this->assertSame($expected, $this->interpret($request, $state)[0]);
        // Et sans aucune proposition (« Je le crée ? »)
        $none = SpokenDialogueState::choose('gratin', [], 'gratin', otherSideTried: $otherSideTried);
        $this->assertSame($expected, $this->interpret($request, $none)[0]);
    }

    /**
     * @return iterable<string, array{AlexaTestRequest, list<string>, bool, SpokenAnswerKind, ?string}>
     */
    public static function mealChooseCases(): iterable
    {
        $three = ['gratin dauphinois', 'gratin de pâtes', 'gratin de courgettes'];

        yield 'rang 2' => [AlexaTestRequest::chooseOption('le deuxième', '2'), $three, false, SpokenAnswerKind::PICK_OPTION, 'gratin de pâtes'];
        yield 'rang 3 sans résolution' => [AlexaTestRequest::chooseOption('trois', null), $three, false, SpokenAnswerKind::PICK_OPTION, 'gratin de courgettes'];
        yield 'rang hors des propositions' => [AlexaTestRequest::chooseOption('trois', '3'), ['gratin dauphinois'], false, SpokenAnswerKind::UNCLEAR, null];
        yield 'le nom en entier, dit à l\'intention des repas' => [AlexaTestRequest::addMeal('gratin de pâtes'), $three, false, SpokenAnswerKind::PICK_OPTION, 'gratin de pâtes'];
        yield 'le nom en entier, dit à l\'intention des produits' => [AlexaTestRequest::addItem('gratin de pâtes'), $three, false, SpokenAnswerKind::PICK_OPTION, 'gratin de pâtes'];
        yield 'le nom avec le mot recette' => [AlexaTestRequest::addItem('la recette gratin dauphinois'), $three, false, SpokenAnswerKind::NEW_MEAL, null];
        yield 'oui avec une seule proposition' => [AlexaTestRequest::yes(), ['gratin dauphinois'], false, SpokenAnswerKind::PICK_OPTION, 'gratin dauphinois'];
        yield 'oui avec plusieurs propositions' => [AlexaTestRequest::yes(), $three, false, SpokenAnswerKind::WHICH_ONE, null];
        yield 'oui sans proposition : c\'est un produit' => [AlexaTestRequest::yes(), [], false, SpokenAnswerKind::SWITCH_TO_ITEM, null];
        yield 'oui sans proposition, produits déjà cherchés' => [AlexaTestRequest::yes(), [], true, SpokenAnswerKind::ABANDON, null];
        yield 'non avec propositions : on abandonne, jamais de création' => [AlexaTestRequest::no(), $three, false, SpokenAnswerKind::ABANDON, null];
        yield 'non sans proposition' => [AlexaTestRequest::no(), [], false, SpokenAnswerKind::ABANDON, null];
        yield 'un produit' => [AlexaTestRequest::chooseKind('un produit', 'item'), $three, false, SpokenAnswerKind::SWITCH_TO_ITEM, null];
        yield 'un produit par le texte seul' => [AlexaTestRequest::addItem("c'est un produit"), $three, false, SpokenAnswerKind::SWITCH_TO_ITEM, null];
        yield 'un nouveau produit' => [AlexaTestRequest::createItem(), $three, false, SpokenAnswerKind::SWITCH_TO_ITEM, null];
        yield 'un produit, produits déjà cherchés' => [AlexaTestRequest::chooseKind('un produit', 'item'), $three, true, SpokenAnswerKind::UNCLEAR, null];
        yield 'aucun, produits déjà cherchés' => [AlexaTestRequest::createItem(), $three, true, SpokenAnswerKind::ABANDON, null];
        yield 'une recette à une question de recettes' => [AlexaTestRequest::chooseKind('une recette', 'meal'), $three, false, SpokenAnswerKind::WHICH_ONE, null];
        yield 'une recette sans proposition' => [AlexaTestRequest::chooseKind('une recette', 'meal'), [], false, SpokenAnswerKind::UNCLEAR, null];
        yield 'un autre produit' => [AlexaTestRequest::addItem('du pain'), $three, false, SpokenAnswerKind::NEW_REQUEST, null];
        yield 'un retrait' => [AlexaTestRequest::removeItem('du pain'), $three, false, SpokenAnswerKind::NEW_REMOVAL, null];
        yield 'rien de compris' => [AlexaTestRequest::answerUnit('kilos', 'kilo'), $three, false, SpokenAnswerKind::UNCLEAR, null];
        yield 'l\'intention d\'un nombre' => [AlexaTestRequest::answerServings('six', '6'), $three, false, SpokenAnswerKind::UNCLEAR, null];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('mealChooseCases')]
    public function testTheQuestionOfAnUnknownRecipeIsAnsweredByTheStateWhateverTheIntent(AlexaTestRequest $request, array $names, bool $otherSideTried, SpokenAnswerKind $expected, ?string $option): void
    {
        // When
        [$kind, $picked] = $this->interpret($request, self::recipes($names, $otherSideTried));

        // Then
        $this->assertSame($expected, $kind);
        $this->assertSame($option, $picked);
    }

    public function testANewMealSaidInTheMiddleOfAQuestionIsANewRequestWithItsServings(): void
    {
        // Given
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::addMeal('lasagnes', 'six', '6')->toArray());

        // When : à la question d'une recette, d'un produit, d'un départage et d'un nombre de personnes
        foreach ([self::recipes(['gratin dauphinois']), self::choose(['persil']), self::choose([])->askingDepartment(), self::planned(), SpokenDialogueState::choose('tomate', [new DialogueOption('a', 'Tomate', 'fruit-et-legume', 'Fruit & légume'), new DialogueOption('b', 'Tomate', 'surgele', 'Surgelé')], 'tomate')] as $state) {
            $answer = $this->interpreter->interpret($request, $state, self::departments());

            // Then : pas aux étapes du rayon et de l'unité, qui ne comprennent que leur catalogue
            if ($state->step === DialogueStep::DEPARTMENT) {
                $this->assertSame(SpokenAnswerKind::UNCLEAR, $answer->kind);
                continue;
            }
            $this->assertSame(SpokenAnswerKind::NEW_MEAL, $answer->kind);
            $this->assertSame(['lasagnes', 6], [$answer->phrase, $answer->number]);
        }
    }

    public function testANewMealWithoutARecipeOrWithAnUnusableNumberIsNotUnderstood(): void
    {
        foreach ([AlexaTestRequest::addMeal(null), AlexaTestRequest::addMeal('lasagnes', '0')] as $request) {
            $answer = $this->interpreter->interpret(AlexaRequest::fromDecoded($request->toArray()), self::recipes(['gratin dauphinois']));

            $this->assertSame(SpokenAnswerKind::UNCLEAR, $answer->kind);
        }
    }

    /**
     * @return iterable<string, array{AlexaTestRequest, SpokenAnswerKind, ?int}>
     */
    public static function servingsCases(): iterable
    {
        // Le nouveau total, où qu'Alexa l'ait mis
        yield 'le créneau servings' => [AlexaTestRequest::answerServings('six', '6'), SpokenAnswerKind::PICK_SERVINGS, 6];
        yield 'le créneau servings sans résolution : le texte seul n\'est pas un nombre' => [AlexaTestRequest::answerServings('six', null), SpokenAnswerKind::UNCLEAR, null];
        yield 'le créneau quantity' => [AlexaTestRequest::addItem('personnes')->withSlot('quantity', 'huit', '8'), SpokenAnswerKind::PICK_SERVINGS, 8];
        yield 'le rang' => [AlexaTestRequest::chooseOption('deux', '2'), SpokenAnswerKind::PICK_SERVINGS, 2];
        yield 'le nombre dit comme un produit' => [AlexaTestRequest::addItem('six'), SpokenAnswerKind::PICK_SERVINGS, 6];
        yield 'six personnes dit comme un produit' => [AlexaTestRequest::addItem('six personnes'), SpokenAnswerKind::PICK_SERVINGS, 6];
        yield 'pour six personnes dit comme un produit' => [AlexaTestRequest::addItem('pour six personnes'), SpokenAnswerKind::PICK_SERVINGS, 6];
        yield 'en chiffres' => [AlexaTestRequest::addItem('6 personnes'), SpokenAnswerKind::PICK_SERVINGS, 6];
        yield 'le même nombre qu\'avant (rien ne sera écrit par le handler)' => [AlexaTestRequest::answerServings('quatre', '4'), SpokenAnswerKind::PICK_SERVINGS, 4];
        // Ne rien changer
        yield 'non' => [AlexaTestRequest::no(), SpokenAnswerKind::ABANDON, null];
        yield 'rien (intention)' => [AlexaTestRequest::keepMeal(), SpokenAnswerKind::ABANDON, null];
        yield 'rien dit comme un produit' => [AlexaTestRequest::addItem('rien'), SpokenAnswerKind::ABANDON, null];
        yield 'ne change rien dit comme un produit' => [AlexaTestRequest::addItem('ne change rien'), SpokenAnswerKind::ABANDON, null];
        // Pas compris
        yield 'oui ne dit pas pour combien' => [AlexaTestRequest::yes(), SpokenAnswerKind::UNCLEAR, null];
        yield 'zéro' => [AlexaTestRequest::answerServings('zéro', '0'), SpokenAnswerKind::UNCLEAR, null];
        yield 'cent' => [AlexaTestRequest::addMeal(null, 'cent', '100'), SpokenAnswerKind::UNCLEAR, null];
        yield 'un rayon' => [AlexaTestRequest::answerDepartment('boucherie', 'boucherie'), SpokenAnswerKind::UNCLEAR, null];
        yield 'rien d\'entendu' => [AlexaTestRequest::intent('AMAZON.FallbackIntent'), SpokenAnswerKind::UNCLEAR, null];
        // Une nouvelle demande
        yield 'un autre produit' => [AlexaTestRequest::addItem('du pain'), SpokenAnswerKind::NEW_REQUEST, null];
        yield 'un retrait' => [AlexaTestRequest::removeItem('du pain'), SpokenAnswerKind::NEW_REMOVAL, null];
        yield 'une consultation' => [AlexaTestRequest::checkItem('du pain'), SpokenAnswerKind::NEW_CHECK, null];
        yield 'un autre repas' => [AlexaTestRequest::addMeal('lasagnes'), SpokenAnswerKind::NEW_MEAL, null];
    }

    #[DataProvider('servingsCases')]
    public function testTheNumberOfPeopleAnsweredIsTheNewTotalAndNothingIsTheSame(AlexaTestRequest $request, SpokenAnswerKind $expected, ?int $number): void
    {
        // When
        $answer = $this->interpreter->interpret(AlexaRequest::fromDecoded($request->toArray()), self::planned());

        // Then
        $this->assertSame($expected, $answer->kind);
        if ($expected === SpokenAnswerKind::PICK_SERVINGS) {
            $this->assertSame($number, $answer->number);
        }
    }

    public function testTheWordMealSaidAloneToAQuestionOfRecipesAsksWhichOne(): void
    {
        // « le repas » à « Tu veux dire gratin dauphinois ou gratin de pâtes ? » : laquelle ?
        $state = self::recipes(['gratin dauphinois', 'gratin de pâtes']);

        $this->assertSame(SpokenAnswerKind::WHICH_ONE, $this->interpret(AlexaTestRequest::addMeal('le repas'), $state)[0]);
    }

    public function testHelpAndWhichOnesRepeatTheQuestionOfTheMealSteps(): void
    {
        foreach ([AlexaTestRequest::intent('AMAZON.HelpIntent'), AlexaTestRequest::listChoices()] as $request) {
            foreach ([self::planned(), self::recipes(['gratin dauphinois'])] as $state) {
                $this->assertSame(SpokenAnswerKind::REPEAT, $this->interpret($request, $state)[0]);
            }
        }
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Un rang dit comme un nombre : Amazon range « deux » nu dans `servings` ou `quantity` (revue de VOX-305)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @return iterable<string, array{SpokenDialogueState, AlexaTestRequest, ?string}>
     */
    public static function numbersSaidAsARank(): iterable
    {
        $products = self::choose(['persil', 'basilic', 'coriandre']);
        $tie = SpokenDialogueState::choose('tomate', [new DialogueOption('a', 'Tomate', 'fruit-et-legume', 'Fruit & légume'), new DialogueOption('b', 'Tomates pelées', 'conserve', 'Conserve'), new DialogueOption('c', 'Tomate', 'surgele', 'Surgelé')], 'tomate');
        $recipes = self::recipes(['gratin dauphinois', 'gratin de pâtes', 'gratin de courgettes']);

        $requests = [
            'AnswerServingsIntent, servings' => [AlexaTestRequest::answerServings('deux', '2'), 2],
            'AnswerServingsIntent, servings (le 3)' => [AlexaTestRequest::answerServings('trois', '3'), 3],
            'AddItemIntent sans produit, quantity' => [AlexaTestRequest::addItem(null)->withSlot('quantity', 'deux', '2'), 2],
            'AddMealIntent sans recette, servings' => [AlexaTestRequest::addMeal(null, 'trois', '3'), 3],
            'ChooseOptionIntent sans rang, quantity' => [AlexaTestRequest::intent('ChooseOptionIntent')->withSlot('quantity', 'deux', '2'), 2],
        ];
        foreach (['produit inconnu' => $products, 'départage' => $tie, 'recette inconnue' => $recipes] as $stepName => $state) {
            foreach ($requests as $requestName => [$request, $rank]) {
                yield "$stepName : $requestName" => [$state, $request, $state->options[$rank - 1]->name];
            }
        }
    }

    #[DataProvider('numbersSaidAsARank')]
    public function testANumberSaidAloneInAnotherSlotIsReadAsTheRankOfTheProposal(SpokenDialogueState $state, AlexaTestRequest $request, string $expected): void
    {
        // When
        $answer = $this->interpreter->interpret(AlexaRequest::fromDecoded($request->toArray()), $state);

        // Then : « deux » reste un rang quelle que soit l'intention qu'Amazon a choisie
        $this->assertSame(SpokenAnswerKind::PICK_OPTION, $answer->kind);
        $this->assertSame($expected, $answer->option?->name);
    }

    /**
     * @return iterable<string, array{SpokenDialogueState}>
     */
    public static function choiceSteps(): iterable
    {
        yield 'produit inconnu' => [self::choose(['persil', 'basilic'])];
        yield 'départage' => [SpokenDialogueState::choose('tomate', [new DialogueOption('a', 'Tomate', 'fruit-et-legume', 'Fruit & légume'), new DialogueOption('b', 'Tomate', 'surgele', 'Surgelé')], 'tomate')];
        yield 'recette inconnue' => [self::recipes(['gratin dauphinois', 'gratin de pâtes'])];
    }

    #[DataProvider('choiceSteps')]
    public function testANumberBeyondTheProposalsOrUnusableIsNotARank(SpokenDialogueState $state): void
    {
        foreach ([AlexaTestRequest::answerServings('trois', '3'), AlexaTestRequest::answerServings('zéro', '0'), AlexaTestRequest::addItem(null)->withSlot('quantity', 'sept', '7'), AlexaTestRequest::answerServings('cent', '100')] as $request) {
            $this->assertSame(SpokenAnswerKind::UNCLEAR, $this->interpreter->interpret(AlexaRequest::fromDecoded($request->toArray()), $state)->kind);
        }
    }

    #[DataProvider('choiceSteps')]
    public function testARankIsNotReadFromANumberWhenSomethingElseWasSaidOrTheRankIsThere(SpokenDialogueState $state): void
    {
        // « deux croquettes » est une nouvelle demande de produit, pas le deuxième choix
        $newRequest = $this->interpreter->interpret(AlexaRequest::fromDecoded(AlexaTestRequest::addQuantified('croquettes', 2)->toArray()), $state);
        $this->assertSame(SpokenAnswerKind::NEW_REQUEST, $newRequest->kind);
        $this->assertSame('croquettes', $newRequest->phrase);

        // Le rang dit comme un rang gagne sur le nombre
        $both = $this->interpreter->interpret(AlexaRequest::fromDecoded(AlexaTestRequest::chooseOption('le premier', '1')->withSlot('servings', 'deux', '2')->toArray()), $state);
        $this->assertSame($state->options[0]->name, $both->option?->name);
    }

    public function testANumberSaidToAQuestionWithoutProposalsIsNotARank(): void
    {
        // « Je le crée ? » : aucune proposition, donc rien à choisir
        $this->assertSame(SpokenAnswerKind::UNCLEAR, $this->interpret(AlexaTestRequest::answerServings('deux', '2'), self::choose([]))[0]);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Le nombre de personnes dit où qu'Amazon l'ait rangé, dont le créneau `recipe` d'`AddMealIntent` (revue de VOX-305)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @return iterable<string, array{AlexaTestRequest, SpokenAnswerKind, ?int}>
     */
    public static function briefAnswersSaidAsARecipe(): iterable
    {
        yield 'six personnes' => [AlexaTestRequest::addMeal('six personnes'), SpokenAnswerKind::PICK_SERVINGS, 6];
        yield 'pour six' => [AlexaTestRequest::addMeal('pour six'), SpokenAnswerKind::PICK_SERVINGS, 6];
        yield 'pour six personnes' => [AlexaTestRequest::addMeal('pour six personnes'), SpokenAnswerKind::PICK_SERVINGS, 6];
        yield 'six' => [AlexaTestRequest::addMeal('six'), SpokenAnswerKind::PICK_SERVINGS, 6];
        yield '6' => [AlexaTestRequest::addMeal('6'), SpokenAnswerKind::PICK_SERVINGS, 6];
        yield 'trente' => [AlexaTestRequest::addMeal('trente'), SpokenAnswerKind::PICK_SERVINGS, 30];
        yield 'rien' => [AlexaTestRequest::addMeal('rien'), SpokenAnswerKind::ABANDON, null];
        yield 'non' => [AlexaTestRequest::addMeal('non'), SpokenAnswerKind::ABANDON, null];
        yield 'ne change rien' => [AlexaTestRequest::addMeal('ne change rien'), SpokenAnswerKind::ABANDON, null];
        yield 'laisse comme ça' => [AlexaTestRequest::addMeal('laisse comme ça'), SpokenAnswerKind::ABANDON, null];
        yield 'zéro' => [AlexaTestRequest::addMeal('zéro'), SpokenAnswerKind::UNCLEAR, null];
        yield '0' => [AlexaTestRequest::addMeal('0'), SpokenAnswerKind::UNCLEAR, null];
        yield 'cent personnes' => [AlexaTestRequest::addMeal('cent personnes'), SpokenAnswerKind::UNCLEAR, null];
        yield 'un vrai autre repas' => [AlexaTestRequest::addMeal('lasagnes'), SpokenAnswerKind::NEW_MEAL, null];
        yield 'un autre repas pour six' => [AlexaTestRequest::addMeal('lasagnes pour six personnes'), SpokenAnswerKind::NEW_MEAL, 6];
        yield 'le repas dit comme un produit' => [AlexaTestRequest::addItem('six personnes'), SpokenAnswerKind::PICK_SERVINGS, 6];
    }

    #[DataProvider('briefAnswersSaidAsARecipe')]
    public function testABriefAnswerSaidAsARecipeAtTheQuestionOfTheTotalIsTheAnswerNotANewMeal(AlexaTestRequest $request, SpokenAnswerKind $expected, ?int $number): void
    {
        // When
        $answer = $this->interpreter->interpret(AlexaRequest::fromDecoded($request->toArray()), self::planned());

        // Then : l'étape est « combien de personnes ? » : un nombre est un total, « rien » ne change rien
        $this->assertSame($expected, $answer->kind);
        $this->assertSame($number, $answer->number);
    }

    public function testAnUnusableNumberOfPeopleInANewMealAtTheQuestionOfTheTotalIsNotUnderstood(): void
    {
        $answer = $this->interpreter->interpret(AlexaRequest::fromDecoded(AlexaTestRequest::addMeal('lasagnes pour 100 personnes')->toArray()), self::planned());

        $this->assertSame(SpokenAnswerKind::UNCLEAR, $answer->kind);
    }

    public function testTheOriginalPhraseOfANewMealTravelsWithIt(): void
    {
        // Given : « le repas pour chien », dit pour un produit
        $answer = $this->interpreter->interpret(AlexaRequest::fromDecoded(AlexaTestRequest::addItem('le repas pour chien')->toArray()), self::recipes(['gratin dauphinois']));

        // Then
        $this->assertSame(SpokenAnswerKind::NEW_MEAL, $answer->kind);
        $this->assertSame(['pour chien', 'le repas pour chien'], [$answer->phrase, $answer->original]);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Un retrait dont la phrase n'est pas dans la liste : propositions de la liste et aiguillage (ADR 0029 décision 12, VOX-306)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @param list<string> $names
     */
    private static function mealsToRemove(array $names, bool $otherSideTried = false): SpokenDialogueState
    {
        return SpokenDialogueState::chooseMealToRemove('gratin', array_map(static fn(string $name): DialogueOption => new DialogueOption($name . '-id', $name), $names), 2, $otherSideTried);
    }

    /**
     * @param list<string> $names
     */
    private static function itemsToRemove(array $names, bool $otherSideTried = false): SpokenDialogueState
    {
        return SpokenDialogueState::proposeToRemove('du gratin', array_map(static fn(string $name): DialogueOption => new DialogueOption($name . '-id', $name), $names), 'gratin', otherSideTried: $otherSideTried);
    }

    /**
     * @return iterable<string, array{AlexaTestRequest, list<string>, bool, SpokenAnswerKind|string, ?string}>
     */
    public static function removalChooseCases(): iterable
    {
        $three = ['gratin dauphinois', 'gratin de pâtes', 'gratin de courgettes'];

        yield 'rang 2' => [AlexaTestRequest::chooseOption('le deuxième', '2'), $three, false, SpokenAnswerKind::PICK_OPTION, 'gratin de pâtes'];
        yield 'rang 3 sans résolution' => [AlexaTestRequest::chooseOption('trois', null), $three, false, SpokenAnswerKind::PICK_OPTION, 'gratin de courgettes'];
        yield 'rang hors des propositions' => [AlexaTestRequest::chooseOption('trois', '3'), ['gratin dauphinois'], false, SpokenAnswerKind::UNCLEAR, null];
        yield 'le nom en entier, quelle que soit l\'intention' => [AlexaTestRequest::removeItem('gratin de pâtes'), $three, false, SpokenAnswerKind::PICK_OPTION, 'gratin de pâtes'];
        yield 'le nom dit comme un ajout' => [AlexaTestRequest::addItem('gratin de pâtes'), $three, false, SpokenAnswerKind::PICK_OPTION, 'gratin de pâtes'];
        yield 'oui avec une seule proposition' => [AlexaTestRequest::yes(), ['gratin dauphinois'], false, SpokenAnswerKind::PICK_OPTION, 'gratin dauphinois'];
        yield 'oui avec plusieurs propositions' => [AlexaTestRequest::yes(), $three, false, SpokenAnswerKind::WHICH_ONE, null];
        yield 'oui sans proposition : c\'est l\'autre type' => [AlexaTestRequest::yes(), [], false, 'switch', null];
        yield 'oui sans proposition, autre type déjà cherché' => [AlexaTestRequest::yes(), [], true, SpokenAnswerKind::ABANDON, null];
        yield 'non : on abandonne, jamais de création' => [AlexaTestRequest::no(), $three, false, SpokenAnswerKind::ABANDON, null];
        yield 'non sans proposition' => [AlexaTestRequest::no(), [], false, SpokenAnswerKind::ABANDON, null];
        yield 'l\'autre type par le créneau' => [AlexaTestRequest::chooseKind('un autre', 'other'), $three, false, SpokenAnswerKind::UNCLEAR, null];
        yield 'aucun des trois : l\'autre type, une fois' => [AlexaTestRequest::createItem(), $three, false, 'switch', null];
        yield 'aucun, autre type déjà cherché' => [AlexaTestRequest::createItem(), $three, true, SpokenAnswerKind::ABANDON, null];
        yield 'un nouveau produit ne crée rien' => [AlexaTestRequest::createItem(), [], true, SpokenAnswerKind::ABANDON, null];
        yield 'rien de compris' => [AlexaTestRequest::answerUnit('kilos', 'kilo'), $three, false, SpokenAnswerKind::UNCLEAR, null];
        yield 'l\'intention d\'un nombre : le deuxième' => [AlexaTestRequest::answerServings('deux', '2'), $three, false, SpokenAnswerKind::PICK_OPTION, 'gratin de pâtes'];
        yield 'un nombre hors des propositions' => [AlexaTestRequest::answerServings('quatre', '4'), $three, false, SpokenAnswerKind::UNCLEAR, null];
        yield 'un autre produit, ajouté' => [AlexaTestRequest::addItem('du pain'), $three, false, SpokenAnswerKind::NEW_REQUEST, null];
        yield 'un autre retrait' => [AlexaTestRequest::removeItem('du pain'), $three, false, SpokenAnswerKind::NEW_REMOVAL, null];
        yield 'une consultation' => [AlexaTestRequest::checkItem('du pain'), $three, false, SpokenAnswerKind::NEW_CHECK, null];
        yield 'un repas à planifier' => [AlexaTestRequest::addMeal('lasagnes'), $three, false, SpokenAnswerKind::NEW_MEAL, null];
        yield 'un repas à retirer' => [AlexaTestRequest::removeMeal('lasagnes'), $three, false, SpokenAnswerKind::NEW_MEAL_REMOVAL, null];
        yield 'un repas à retirer dit à l\'intention des produits' => [AlexaTestRequest::removeItem('le repas lasagnes'), $three, false, SpokenAnswerKind::NEW_MEAL_REMOVAL, null];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('removalChooseCases')]
    public function testTheQuestionOfAMealToRemoveIsAnsweredByTheStateWhateverTheIntent(AlexaTestRequest $request, array $names, bool $otherSideTried, SpokenAnswerKind|string $expected, ?string $option): void
    {
        // When : un repas à retirer (l'autre type est « un produit »)
        [$kind, $picked] = $this->interpret($request, self::mealsToRemove($names, $otherSideTried));

        // Then
        $this->assertSame($expected === 'switch' ? SpokenAnswerKind::SWITCH_TO_ITEM : $expected, $kind);
        $this->assertSame($option, $picked);
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('removalChooseCases')]
    public function testTheQuestionOfAnItemToRemoveIsAnsweredByTheStateWhateverTheIntent(AlexaTestRequest $request, array $names, bool $otherSideTried, SpokenAnswerKind|string $expected, ?string $option): void
    {
        // When : un produit à retirer (l'autre type est « un repas ») ; le rang et le nom sont les mêmes
        [$kind, $picked] = $this->interpret($request, self::itemsToRemove($names, $otherSideTried));

        // Then
        $this->assertSame($expected === 'switch' ? SpokenAnswerKind::SWITCH_TO_MEAL : $expected, $kind);
        $this->assertSame($option, $picked);
    }

    /**
     * @return iterable<string, array{AlexaTestRequest, bool, bool, SpokenAnswerKind}>
     */
    public static function removalKinds(): iterable
    {
        // [la demande, l'état est celui d'un repas, l'autre type a déjà été cherché, la réponse attendue]
        yield 'un produit, à la question d\'un repas' => [AlexaTestRequest::chooseKind('un produit', 'item'), true, false, SpokenAnswerKind::SWITCH_TO_ITEM];
        yield 'un produit par le texte seul' => [AlexaTestRequest::removeItem("c'est un produit"), true, false, SpokenAnswerKind::SWITCH_TO_ITEM];
        yield 'un produit, déjà cherché' => [AlexaTestRequest::chooseKind('un produit', 'item'), true, true, SpokenAnswerKind::UNCLEAR];
        yield 'un repas, à la question d\'un produit' => [AlexaTestRequest::chooseKind('un repas', 'meal'), false, false, SpokenAnswerKind::SWITCH_TO_MEAL];
        yield 'une recette par le texte seul' => [AlexaTestRequest::removeItem("c'est une recette"), false, false, SpokenAnswerKind::SWITCH_TO_MEAL];
        yield 'un repas, déjà cherché' => [AlexaTestRequest::chooseKind('un repas', 'meal'), false, true, SpokenAnswerKind::UNCLEAR];
        yield 'le même type : laquelle ?' => [AlexaTestRequest::chooseKind('un repas', 'meal'), true, false, SpokenAnswerKind::WHICH_ONE];
        yield 'le même type pour un produit : lequel ?' => [AlexaTestRequest::chooseKind('un produit', 'item'), false, false, SpokenAnswerKind::WHICH_ONE];
    }

    #[DataProvider('removalKinds')]
    public function testTheOtherTypeIsSearchedOnceAndTheSameTypeAsksWhichOne(AlexaTestRequest $request, bool $isMeal, bool $otherSideTried, SpokenAnswerKind $expected): void
    {
        // Given : deux propositions tirées de la liste
        $state = $isMeal ? self::mealsToRemove(['gratin dauphinois', 'gratin de pâtes'], $otherSideTried) : self::itemsToRemove(['gratin dauphinois', 'gratin de pâtes'], $otherSideTried);

        // Then
        $this->assertSame($expected, $this->interpret($request, $state)[0]);
    }

    public function testAMealToRemoveSaidInTheMiddleOfAQuestionIsANewRemovalWithItsServings(): void
    {
        // Given
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::removeMeal('lasagnes', 'deux', '2')->toArray());

        // When : à la question d'un produit, d'une recette, d'un départage, d'un retrait proposé et d'un nombre de personnes
        foreach ([self::choose(['persil']), self::recipes(['gratin']), self::planned(), self::mealsToRemove(['gratin']), self::itemsToRemove(['gratin']), self::tie()] as $state) {
            $answer = $this->interpreter->interpret($request, $state, self::departments());

            // Then
            $this->assertSame(SpokenAnswerKind::NEW_MEAL_REMOVAL, $answer->kind);
            $this->assertSame(['lasagnes', 2], [$answer->phrase, $answer->number]);
        }
    }

    public function testANewRemovalOfAMealWithoutARecipeOrWithAnUnusableNumberIsNotUnderstood(): void
    {
        // Given
        $state = self::mealsToRemove(['gratin']);

        // Then : « enlève le repas » seul, ou « pour cent personnes » : on redemande, rien n'est retiré
        $this->assertSame(SpokenAnswerKind::UNCLEAR, $this->interpret(AlexaTestRequest::removeMeal(null), $state)[0]);
        $this->assertSame(SpokenAnswerKind::UNCLEAR, $this->interpret(AlexaTestRequest::removeMeal('lasagnes pour cent personnes'), $state)[0]);
        $this->assertSame(SpokenAnswerKind::UNCLEAR, $this->interpret(AlexaTestRequest::removeMeal('lasagnes', 'cent', '100'), $state)[0]);
    }

    public function testHelpAndWhichOnesRepeatTheQuestionOfARemovalProposal(): void
    {
        foreach ([self::mealsToRemove(['gratin']), self::itemsToRemove(['gratin'])] as $state) {
            $this->assertSame(SpokenAnswerKind::REPEAT, $this->interpret(AlexaTestRequest::intent('AMAZON.HelpIntent'), $state)[0]);
            $this->assertSame(SpokenAnswerKind::REPEAT, $this->interpret(AlexaTestRequest::listChoices(), $state)[0]);
        }
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Un rang dit seul (« deux », « le premier ») qu'Amazon a rangé dans le créneau d'un produit ou d'une recette : c'est le choix, pas une nouvelle demande
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @return iterable<string, array{AlexaTestRequest, ?int}>
     */
    public static function bareRanks(): iterable
    {
        // [la demande, le rang choisi parmi trois propositions ; null : c'est une nouvelle demande]
        yield 'deux dit à l\'intention des retraits' => [AlexaTestRequest::removeItem('deux'), 2];
        yield '2 en chiffre' => [AlexaTestRequest::removeItem('2'), 2];
        yield 'le premier' => [AlexaTestRequest::removeItem('le premier'), 1];
        yield 'le deuxième' => [AlexaTestRequest::removeItem('le deuxième'), 2];
        yield 'la troisième' => [AlexaTestRequest::removeItem('la troisième'), 3];
        yield 'numéro trois' => [AlexaTestRequest::removeItem('numéro trois'), 3];
        yield 'un, le premier' => [AlexaTestRequest::removeItem('un'), 1];
        yield 'dit à l\'intention des ajouts' => [AlexaTestRequest::addItem('le deuxième'), 2];
        yield 'dit à l\'intention des repas' => [AlexaTestRequest::addMeal('deux'), 2];
        yield 'dit à l\'intention des retraits de repas' => [AlexaTestRequest::removeMeal('le premier'), 1];
        yield 'dit à l\'intention des consultations' => [AlexaTestRequest::checkItem('trois'), 3];
        yield 'quatre : hors des propositions' => [AlexaTestRequest::removeItem('quatre'), null];
        yield 'zéro : hors des propositions' => [AlexaTestRequest::removeItem('0'), null];
        yield 'deux croquettes : un nombre et un produit' => [AlexaTestRequest::removeItem('deux croquettes'), null];
        yield 'le premier fromage : un mot de plus' => [AlexaTestRequest::removeItem('le premier fromage'), null];
    }

    #[DataProvider('bareRanks')]
    public function testARankSaidAloneIsTheChoiceWhateverTheSlotAmazonPutItIn(AlexaTestRequest $request, ?int $rank): void
    {
        // Given : trois propositions à un retrait de produit, de repas, à un départage et à un produit ou une recette inconnus
        $names = ['gratin dauphinois', 'gratin de pâtes', 'gratin de courgettes'];
        foreach ([self::mealsToRemove($names), self::itemsToRemove($names), self::recipes($names), self::choose($names)] as $state) {
            // When
            [$kind, $picked] = $this->interpret($request, $state);

            // Then : le rang choisit la proposition (jamais un produit qui s'appellerait « deux ») ; sinon c'est une nouvelle demande
            if ($rank === null) {
                $this->assertNotSame(SpokenAnswerKind::PICK_OPTION, $kind, $state->isMeal() ? 'repas' : 'produit');
                continue;
            }
            $this->assertSame(SpokenAnswerKind::PICK_OPTION, $kind, $state->isMeal() ? 'repas' : 'produit');
            $this->assertSame($names[$rank - 1], $picked);
        }
    }

    public function testARankSaidAloneAlsoChoosesAmongTheLinesOfATieBreak(): void
    {
        // Given : deux « tomate » à départager
        $state = self::tie();

        // When
        [$kind, $picked] = $this->interpret(AlexaTestRequest::removeItem('le deuxième'), $state);

        // Then
        $this->assertSame(SpokenAnswerKind::PICK_OPTION, $kind);
        $this->assertSame($state->options[1]->name, $picked);
        $this->assertSame($state->options[1]->slug, $this->interpreter->interpret(AlexaRequest::fromDecoded(AlexaTestRequest::removeItem('le deuxième')->toArray()), $state)->option?->slug);
    }

    public function testARankSaidAloneWithNoProposalIsStillANewRequest(): void
    {
        // Given : « C'est un repas ? » (aucune proposition)
        $state = self::itemsToRemove([]);

        // Then : rien à choisir, le texte est une nouvelle demande
        $this->assertNotSame(SpokenAnswerKind::PICK_OPTION, $this->interpret(AlexaTestRequest::removeItem('deux'), $state)[0]);
    }
}
