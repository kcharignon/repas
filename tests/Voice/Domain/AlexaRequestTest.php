<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Voice\Domain\Model\AlexaIntent;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SlotValue;
use Repas\Voice\Domain\Model\SpokenDialogueState;

/**
 * Ce que le webhook lit d'une requête d'Alexa déjà vérifiée (VOX-204) : lecture tolérante, jamais d'exception.
 */
class AlexaRequestTest extends TestCase
{
    public function testAnIntentRequestIsReadWithItsPhraseLocaleSessionTokenAndUser(): void
    {
        // Given
        $body = AlexaTestRequest::addItem('des tomates')->withAccessToken('the-token')->withAlexaUserId('amzn1.ask.account.ME')->toArray();

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then
        $this->assertSame(AlexaRequest::INTENT, $request->type);
        $this->assertSame(AlexaIntent::ADD_ITEM, $request->intent());
        $this->assertSame('des tomates', $request->phrase);
        $this->assertTrue($request->hasSupportedLocale());
        $this->assertTrue($request->isFirstOfSession);
        $this->assertFalse($request->isInOpenSession());
        $this->assertSame('the-token', $request->accessToken);
        $this->assertSame('amzn1.ask.account.ME', $request->alexaUserId);
    }

    public function testAProductAmazonPutsInTheUnitSlotIsTheProductWhenTheItemSlotIsEmpty(): void
    {
        // Given : « ajoute vingt et un yaourts » (profileur d'énoncés, 2026-10-26) : `item` vide, `unit` = « yaourts » sans identifiant, `quantity` = 21
        $body = AlexaTestRequest::addItem(null)->withQuantity('21')->withSlot('unit', 'yaourts')->toArray();

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then : « yaourts » n'est pas une unité : c'est le produit, et la quantité reste
        $this->assertSame('yaourts', $request->phrase);
        $this->assertNull($request->unit);
        $this->assertNull($request->unitSlug());
        $this->assertSame(21, $request->quantity);
    }

    public function testAKnownUnitStaysTheUnitAndAnAnsweredUnitIsNeverTheProduct(): void
    {
        // Given : « ajoute deux kilos » (unité connue sans identifiant) ; un identifiant résolu ; la réponse à « en quelle unité ? »
        $heardKnown = AlexaRequest::fromDecoded(AlexaTestRequest::addItem(null)->withQuantity('2')->withSlot('unit', 'kilos')->toArray());
        $resolved = AlexaRequest::fromDecoded(AlexaTestRequest::addItem(null)->withQuantity('2')->withSlot('unit', 'machin', 'kilo')->toArray());
        $answer = AlexaRequest::fromDecoded(AlexaTestRequest::answerUnit('casseroles', null)->toArray());

        // Then : l'unité reste l'unité, aucun produit n'est inventé
        $this->assertNull($heardKnown->phrase);
        $this->assertSame('kilos', $heardKnown->unit?->text);
        $this->assertNull($resolved->phrase);
        $this->assertSame('kilo', $resolved->unit?->id);
        $this->assertNull($answer->phrase);
        $this->assertSame('casseroles', $answer->unit?->text);
    }

    public function testAProductInTheUnitSlotOfARemovalIsTheProductToo(): void
    {
        // Given
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::removeItem(null)->withQuantity('2')->withSlot('unit', 'yaourts')->toArray());

        // Then
        $this->assertSame('yaourts', $request->phrase);
        $this->assertNull($request->unit);
    }

    public function testASessionThatIsNotNewIsOpen(): void
    {
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::addItem()->inOpenSession()->toArray());

        $this->assertTrue($request->isInOpenSession());
    }

    #[DataProvider('sessionsThatAreNotExplicitlyOpen')]
    public function testAnythingButAnExplicitFalseIsADirectCall(mixed $new): void
    {
        // Given : `session.new` absent, `true`, ou d'une forme inattendue
        $body = AlexaTestRequest::addItem()->toArray();
        $body['session']['new'] = $new;

        // When / Then : la séance se fermera
        $this->assertFalse(AlexaRequest::fromDecoded($body)->isInOpenSession());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function sessionsThatAreNotExplicitlyOpen(): iterable
    {
        yield 'true' => [true];
        yield 'null' => [null];
        yield 'la chaîne false' => ['false'];
        yield 'zéro' => [0];
    }

    public function testAMissingSessionIsADirectCallAndTheTokenComesFromTheContext(): void
    {
        // Given
        $body = AlexaTestRequest::launch()->withAccessToken('ctx-token')->withTokenInContextOnly()->toArray();

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then
        $this->assertArrayNotHasKey('session', $body);
        $this->assertFalse($request->isInOpenSession());
        $this->assertSame('ctx-token', $request->accessToken);
        $this->assertSame(AlexaTestRequest::USER_ID, $request->alexaUserId);
        $this->assertSame(AlexaRequest::LAUNCH, $request->type);
    }

    public function testTheSessionTokenWinsOverTheContextOne(): void
    {
        // Given
        $body = AlexaTestRequest::launch()->withAccessToken('session-token')->toArray();
        $body['context']['System']['user']['accessToken'] = 'context-token';

        // When / Then
        $this->assertSame('session-token', AlexaRequest::fromDecoded($body)->accessToken);
    }

    public function testNoTokenMeansNoToken(): void
    {
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->toArray())->accessToken);
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withAccessToken('')->toArray())->accessToken);
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withAccessToken(str_repeat('a', AlexaRequest::MAX_ACCESS_TOKEN_LENGTH + 1))->toArray())->accessToken);
    }

    public function testAnAlexaUserIdThatDoesNotFitTheColumnIsIgnored(): void
    {
        $this->assertSame(str_repeat('a', 255), AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withAlexaUserId(str_repeat('a', 255))->toArray())->alexaUserId);
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withAlexaUserId(str_repeat('a', 256))->toArray())->alexaUserId);
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withAlexaUserId(null)->toArray())->alexaUserId);
    }

    #[DataProvider('phrases')]
    public function testThePhraseIsCleanedOrDropped(?string $said, ?string $expected): void
    {
        // When
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::addItem($said)->toArray());

        // Then
        $this->assertSame($expected, $request->phrase);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function phrases(): iterable
    {
        yield 'telle quelle' => ['du pain de mie', 'du pain de mie'];
        yield 'espaces réduits' => ["  du   pain \t de mie\n", 'du pain de mie'];
        yield 'caractères de contrôle' => ["du\u{0000}pain\u{200B}", 'du pain'];
        yield 'créneau sans valeur' => [null, null];
        yield 'vide' => ['', null];
        yield 'espaces seuls' => ['   ', null];
        yield 'point d\'interrogation d\'Alexa' => ['?', null];
        yield 'ponctuation seule' => ['… !', null];
        yield 'accents' => ['crème fraîche', 'crème fraîche'];
        yield 'chiffres' => ['3', '3'];
        yield 'cent caractères' => [str_repeat('a', 100), str_repeat('a', 100)];
        yield 'plus de cent caractères' => [str_repeat('a', 101), null];
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // La quantité et l'unité dites (ADR 0029 décision 5)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    #[DataProvider('quantities')]
    public function testTheQuantityIsAWholeNumberFromOneToNinetyNineOtherwiseUnusable(string|int|float|null $said, ?int $expected, bool $heard): void
    {
        // When
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::addQuantified('des tomates', $said)->toArray());

        // Then : la valeur, ou rien ; et si quelque chose a été entendu sans être utilisable, `hasUnusableQuantity`
        $this->assertSame($expected, $request->quantity);
        $this->assertSame($heard, $request->quantityHeard);
        $this->assertSame($heard && $expected === null, $request->hasUnusableQuantity());
    }

    /**
     * @return iterable<string, array{string|int|float|null, ?int, bool}>
     */
    public static function quantities(): iterable
    {
        yield 'absente' => [null, null, false];
        yield 'un' => ['1', 1, true];
        yield 'trois' => ['3', 3, true];
        yield 'quatre-vingt-dix-neuf' => ['99', 99, true];
        yield 'avec des espaces' => [' 3 ', 3, true];
        yield 'un vrai nombre JSON' => [4, 4, true];
        yield 'zéro' => ['0', null, true];
        yield 'cent' => ['100', null, true];
        yield 'énorme' => ['99999999999999999999', null, true];
        yield 'décimal' => ['1.5', null, true];
        yield 'décimal JSON' => [2.5, null, true];
        yield 'virgule' => ['1,5', null, true];
        yield 'négative' => ['-2', null, true];
        yield 'avec un signe plus' => ['+2', null, true];
        yield 'un mot' => ['beaucoup', null, true];
        yield 'mélange' => ['3 paquets', null, true];
        yield 'point d\'interrogation d\'Alexa' => ['?', null, false];
        yield 'vide' => ['', null, false];
        yield 'espaces seuls' => ['   ', null, false];
    }

    public function testAQuantityThatIsNotAScalarIsNotHeard(): void
    {
        // Given
        $body = AlexaTestRequest::addItem()->toArray();
        $body['request']['intent']['slots']['quantity'] = ['name' => 'quantity', 'value' => ['3']];

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then : ni utilisable ni entendue, jamais d'exception
        $this->assertNull($request->quantity);
        $this->assertFalse($request->hasUnusableQuantity());
    }

    public function testTheQuantityTypeResolutionIdWinsOverTheSaidText(): void
    {
        // Given : « deux » entendu, rapproché de la valeur « 2 » du type QUANTITY du modèle
        $body = AlexaTestRequest::addItem()->toArray();
        $body['request']['intent']['slots']['quantity'] = [
            'name' => 'quantity',
            'value' => 'deux',
            'resolutions' => ['resolutionsPerAuthority' => [[
                'authority' => 'amzn1.er-authority.echo-sdk.amzn1.ask.skill.test.QUANTITY',
                'status' => ['code' => 'ER_SUCCESS_MATCH'],
                'values' => [['value' => ['name' => '2', 'id' => '2']]],
            ]]],
        ];

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then : la quantité est 2, entendue et utilisable
        $this->assertSame(2, $request->quantity);
        $this->assertTrue($request->quantityHeard);
        $this->assertFalse($request->hasUnusableQuantity());
    }

    public function testAQuantityWordAlexaDidNotMatchToTheModelIsHeardButUnusable(): void
    {
        // Given : « deux cent » n'est pas une valeur du type (1 à 99)
        $body = AlexaTestRequest::addItem()->toArray();
        $body['request']['intent']['slots']['quantity'] = [
            'name' => 'quantity',
            'value' => 'deux cent',
            'resolutions' => ['resolutionsPerAuthority' => [[
                'authority' => 'amzn1.er-authority.echo-sdk.amzn1.ask.skill.test.QUANTITY',
                'status' => ['code' => 'ER_SUCCESS_NO_MATCH'],
            ]]],
        ];

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then : on redemande « combien ? », rien n'est écrit
        $this->assertNull($request->quantity);
        $this->assertTrue($request->hasUnusableQuantity());
    }

    public function testTheQuantityIsOnlyReadFromAnIntentRequest(): void
    {
        // Given : un créneau qui traîne dans un lancement
        $body = AlexaTestRequest::launch()->toArray();
        $body['request']['intent'] = ['name' => 'AddItemIntent', 'slots' => ['quantity' => ['value' => '3']]];

        // When / Then
        $this->assertNull(AlexaRequest::fromDecoded($body)->quantity);
        $this->assertFalse(AlexaRequest::fromDecoded($body)->quantityHeard);
    }

    public function testTheUnitIsReadWithItsTextAndTheIdOfItsResolution(): void
    {
        // When
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::addQuantified('croquettes', 3, 'paquets', 'paquet')->toArray());

        // Then
        $this->assertEquals(new SlotValue('paquets', 'paquet'), $request->unit);
        $this->assertSame('paquet', $request->unitSlug());
        $this->assertSame(3, $request->quantity);
        $this->assertSame('croquettes', $request->phrase);
    }

    public function testTheUnitSlugFallsBackOnTheHeardTextWhenAlexaDidNotResolveIt(): void
    {
        // When : « kilos » entendu, mais sans identifiant de résolution
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::addQuantified('tomates', 2, 'Kilos')->toArray());

        // Then : le catalogue des unités le rapproche
        $this->assertSame('kilo', $request->unitSlug());
    }

    public function testNoUnitAndAnUnknownUnitGiveNoSlug(): void
    {
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::addQuantified('tomates', 2)->toArray())->unitSlug());
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::addQuantified('tomates', 2, 'poignées')->toArray())->unitSlug());
    }
    public function testAPhraseThatIsNotAStringIsDropped(): void
    {
        // Given
        $body = AlexaTestRequest::addItem()->toArray();
        $body['request']['intent']['slots']['item']['value'] = ['du pain'];

        // When / Then
        $this->assertNull(AlexaRequest::fromDecoded($body)->phrase);
    }

    public function testThePhraseIsOnlyReadFromAnIntentRequest(): void
    {
        // Given : un créneau qui traîne dans un lancement
        $body = AlexaTestRequest::launch()->toArray();
        $body['request']['intent'] = ['name' => 'AddItemIntent', 'slots' => ['item' => ['value' => 'du pain']]];

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then
        $this->assertNull($request->phrase);
        $this->assertNull($request->intentName);
        $this->assertNull($request->intent());
    }

    public function testAnIntentTheSkillDoesNotDeclareIsKnownByNameButHasNoIntent(): void
    {
        // When
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::intent('AMAZON.NavigateHomeIntent')->toArray());

        // Then
        $this->assertSame('AMAZON.NavigateHomeIntent', $request->intentName);
        $this->assertNull($request->intent());
    }

    public function testOnlyFrenchFromFranceIsSupported(): void
    {
        $this->assertTrue(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->toArray())->hasSupportedLocale());
        foreach (['fr-CA', 'en-US', 'fr-fr', 'fr', ''] as $locale) {
            $this->assertFalse(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withLocale($locale)->toArray())->hasSupportedLocale(), $locale);
        }

        $body = AlexaTestRequest::launch()->toArray();
        unset($body['request']['locale']);
        $this->assertFalse(AlexaRequest::fromDecoded($body)->hasSupportedLocale());
    }

    #[DataProvider('shapesThatAreNotARequest')]
    public function testAnythingStrangeIsReadWithoutAnException(mixed $decoded): void
    {
        // When
        $request = AlexaRequest::fromDecoded($decoded);

        // Then : un type vide, rien d'autre, une séance fermée par défaut
        $this->assertSame('', $request->type);
        $this->assertNull($request->intent());
        $this->assertNull($request->phrase);
        $this->assertNull($request->accessToken);
        $this->assertNull($request->alexaUserId);
        $this->assertFalse($request->hasSupportedLocale());
        $this->assertFalse($request->isInOpenSession());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function shapesThatAreNotARequest(): iterable
    {
        yield 'null' => [null];
        yield 'une chaîne' => ['IntentRequest'];
        yield 'un nombre' => [42];
        yield 'une liste vide' => [[]];
        yield 'request n\'est pas un objet' => [['request' => 'IntentRequest']];
        yield 'type numérique' => [['request' => ['type' => 7]]];
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Les créneaux de la réponse à une question (ADR 0029 décision 11, VOX-303)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testASlotCarriesWhatWasHeardAndTheIdOfTheValueAlexaMatchedItTo(): void
    {
        // Given : « le deuxième » rapproché du rang 2 ; « fruits et légumes » du rayon fruit-et-legume ; « kilos » de l'unité kilo
        $rank = AlexaRequest::fromDecoded(AlexaTestRequest::chooseOption('le deuxième', '2')->toArray());
        $department = AlexaRequest::fromDecoded(AlexaTestRequest::answerDepartment('fruits et légumes', 'fruit-et-legume')->toArray());
        $unit = AlexaRequest::fromDecoded(AlexaTestRequest::answerUnit('  kilos ', 'kilo')->toArray());

        // Then
        $this->assertSame(AlexaIntent::CHOOSE_OPTION, $rank->intent());
        $this->assertEquals(new SlotValue('le deuxième', '2'), $rank->rank);
        $this->assertEquals(new SlotValue('fruits et légumes', 'fruit-et-legume'), $department->department);
        $this->assertEquals(new SlotValue('kilos', 'kilo'), $unit->unit);
        $this->assertNull($rank->department);
        $this->assertSame(['le deuxième'], $rank->heardTexts());
    }

    public function testASlotWithoutMatchKeepsOnlyWhatWasHeard(): void
    {
        // Given : un texte hors liste, `ER_SUCCESS_NO_MATCH`
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::answerDepartment('la crémerie du coin', null)->toArray());

        // Then
        $this->assertEquals(new SlotValue('la crémerie du coin', null), $request->department);
    }

    public function testASlotWithNeitherTextNorMatchIsAbsent(): void
    {
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::answerUnit(null, null)->toArray())->unit);
    }

    public function testAnIdIsOnlyTrustedWhenTheMatchSucceededAndLooksLikeASlug(): void
    {
        // Given : un statut d'échec avec des valeurs, un id trop long, un id qui n'est pas un slug, un id qui n'est pas une chaîne
        $cases = [
            'statut en échec' => ['status' => ['code' => 'ER_SUCCESS_NO_MATCH'], 'values' => [['value' => ['name' => 'x', 'id' => 'kilo']]]],
            'id trop long' => ['status' => ['code' => 'ER_SUCCESS_MATCH'], 'values' => [['value' => ['name' => 'x', 'id' => str_repeat('a', 101)]]]],
            'id avec des caractères étranges' => ['status' => ['code' => 'ER_SUCCESS_MATCH'], 'values' => [['value' => ['name' => 'x', 'id' => "kilo'; DROP TABLE x;--"]]]],
            'id numérique (entier)' => ['status' => ['code' => 'ER_SUCCESS_MATCH'], 'values' => [['value' => ['name' => 'x', 'id' => 2]]]],
            'valeurs absentes' => ['status' => ['code' => 'ER_SUCCESS_MATCH']],
            'valeur qui n\'est pas un objet' => ['status' => ['code' => 'ER_SUCCESS_MATCH'], 'values' => ['kilo']],
        ];

        foreach ($cases as $name => $authority) {
            // When
            $body = AlexaTestRequest::answerUnit('kilos', null)->toArray();
            $body['request']['intent']['slots']['unit']['resolutions'] = ['resolutionsPerAuthority' => [$authority]];

            // Then : le texte entendu reste, jamais l'identifiant
            $this->assertEquals(new SlotValue('kilos', null), AlexaRequest::fromDecoded($body)->unit, $name);
        }
    }

    public function testTheFirstSuccessfulAuthorityWins(): void
    {
        // Given : une première autorité sans correspondance, une seconde avec
        $body = AlexaTestRequest::answerUnit('kilos', null)->toArray();
        $body['request']['intent']['slots']['unit']['resolutions'] = ['resolutionsPerAuthority' => [
            ['status' => ['code' => 'ER_SUCCESS_NO_MATCH']],
            ['status' => ['code' => 'ER_SUCCESS_MATCH'], 'values' => [['value' => ['name' => 'kilo', 'id' => 'kilo']], ['value' => ['name' => 'gramme', 'id' => 'gramme']]]],
        ]];

        // Then
        $this->assertSame('kilo', AlexaRequest::fromDecoded($body)->unit?->id);
    }

    public function testEveryTextHeardInTheSlotsIsListedOnceInTheOrderItemDepartmentUnitRank(): void
    {
        // Given
        $body = AlexaTestRequest::addItem('persil')->toArray();
        $body['request']['intent']['slots']['unit'] = ['name' => 'unit', 'value' => 'kilos'];
        $body['request']['intent']['slots']['rank'] = ['name' => 'rank', 'value' => 'persil'];

        // Then
        $this->assertSame(['persil', 'kilos'], AlexaRequest::fromDecoded($body)->heardTexts());
    }

    public function testTheSlotsAreOnlyReadFromAnIntentRequest(): void
    {
        // Given
        $body = AlexaTestRequest::launch()->toArray();
        $body['request']['intent'] = ['name' => 'AnswerUnitIntent', 'slots' => ['unit' => ['name' => 'unit', 'value' => 'kilos']]];
        $body['session']['attributes'] = ['dialogue' => SpokenDialogueState::choose('x', [], 'x')->toArray()];

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then
        $this->assertNull($request->unit);
        $this->assertNull($request->dialogueState);
    }

    public function testTheDialogueStateIsReadFromTheSessionAttributes(): void
    {
        // Given
        $state = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('persil-u', 'persil'), new DialogueOption('basilic-u', 'basilic')], 'ciboulette');

        // When
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::yes()->withSessionAttributes(['dialogue' => $state->toArray()])->toArray());

        // Then
        $this->assertEquals($state, $request->dialogueState);
        $this->assertTrue($request->isInOpenSession());
    }

    public function testNoAttributesMeansNoState(): void
    {
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::yes()->toArray())->dialogueState);
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::yes()->withSessionAttributes([])->toArray())->dialogueState);
    }

    /**
     * @param mixed $attributes ce que la séance rend, valable ou non
     */
    #[DataProvider('untrustworthyAttributes')]
    public function testAStateThatIsNotWellFormedIsIgnoredWithoutAnException(mixed $attributes): void
    {
        // Given
        $body = AlexaTestRequest::yes()->toArray();
        $body['session']['attributes'] = $attributes;

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then
        $this->assertNull($request->dialogueState);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function untrustworthyAttributes(): iterable
    {
        $valid = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('persil-u', 'persil')], 'ciboulette')->toArray();

        yield 'attributs qui ne sont pas un objet' => ['dialogue'];
        yield 'dialogue qui n\'est pas un objet' => [['dialogue' => 'choose']];
        yield 'dialogue vide' => [['dialogue' => []]];
        yield 'étape inconnue' => [['dialogue' => [...$valid, 'step' => 'hack']]];
        yield 'étape qui n\'est pas une chaîne' => [['dialogue' => [...$valid, 'step' => 3]]];
        yield 'phrase absente' => [['dialogue' => array_diff_key($valid, ['phrase' => 1])]];
        yield 'phrase trop longue' => [['dialogue' => [...$valid, 'phrase' => str_repeat('a', 101)]]];
        yield 'nom trop long' => [['dialogue' => [...$valid, 'name' => str_repeat('a', 101)]]];
        yield 'caractère de contrôle' => [['dialogue' => [...$valid, 'name' => "cibou\u{0007}lette"]]];
        yield 'UTF-8 invalide' => [['dialogue' => [...$valid, 'name' => "cibou\xC3\x28lette"]]];
        yield 'quatre propositions' => [['dialogue' => [...$valid, 'options' => array_fill(0, 4, ['slug' => 'a', 'name' => 'b'])]]];
        yield 'propositions qui ne sont pas une liste' => [['dialogue' => [...$valid, 'options' => ['a' => ['slug' => 'a', 'name' => 'b']]]]];
        yield 'proposition sans nom' => [['dialogue' => [...$valid, 'options' => [['slug' => 'a']]]]];
        yield 'proposition qui n\'est pas un objet' => [['dialogue' => [...$valid, 'options' => ['persil']]]];
        yield 'slug de proposition trop long' => [['dialogue' => [...$valid, 'options' => [['slug' => str_repeat('a', 256), 'name' => 'b']]]]];
        yield 'étape rayon sans rayon' => [['dialogue' => ['step' => 'unit', 'phrase' => 'x', 'options' => [], 'name' => 'x', 'misses' => 0]]];
        yield 'confirmation sans unité' => [['dialogue' => ['step' => 'confirm', 'phrase' => 'x', 'options' => [], 'name' => 'x', 'departmentSlug' => 'a', 'departmentName' => 'b', 'misses' => 0]]];
        yield 'étape rayon avec des propositions' => [['dialogue' => ['step' => 'department', 'phrase' => 'x', 'options' => [['slug' => 'a', 'name' => 'b']], 'name' => 'x', 'misses' => 0]]];
        yield 'l\'état est une liste' => [['dialogue' => [$valid]]];
    }

    public function testTheMissCountIsBoundedAndForgivingWhenItIsNotANumber(): void
    {
        // Given
        $valid = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette')->toArray();
        $read = static fn(mixed $misses): ?SpokenDialogueState => AlexaRequest::fromDecoded(
            AlexaTestRequest::yes()->withSessionAttributes(['dialogue' => [...$valid, 'misses' => $misses]])->toArray(),
        )->dialogueState;

        // Then
        $this->assertSame(0, $read('beaucoup')?->misses);
        $this->assertSame(0, $read(-5)?->misses);
        $this->assertSame(SpokenDialogueState::MAX_MISSES, $read(999)?->misses);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Un repas (ADR 0029 décision 13, VOX-305)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testTheMealIntentIsReadWithItsRecipeAndItsServings(): void
    {
        // When : « planifie le repas hachis parmentier pour six personnes »
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::addMeal('hachis parmentier', 'six', '6')->toArray());

        // Then
        $this->assertSame(AlexaIntent::ADD_MEAL, $request->intent());
        $this->assertSame('hachis parmentier', $request->recipePhrase);
        $this->assertNull($request->phrase, 'le créneau item est celui des produits');
        $this->assertSame(6, $request->servings);
        $this->assertTrue($request->servingsHeard);
        $this->assertFalse($request->hasUnusableServings());
        $this->assertContains('hachis parmentier', $request->heardTexts());
    }

    public function testANumberOfPeopleThatIsMissingIsNotHeard(): void
    {
        // When
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::addMeal('hachis parmentier')->toArray());

        // Then : le nombre du compte sera pris
        $this->assertNull($request->servings);
        $this->assertFalse($request->servingsHeard);
        $this->assertFalse($request->hasUnusableServings());
    }

    #[DataProvider('unusableServings')]
    public function testANumberOfPeopleThatCannotBeWrittenIsHeardButUnusable(string $said): void
    {
        // When
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::addMeal('hachis', $said)->toArray());

        // Then : rien n'est écrit, on redemande combien
        $this->assertNull($request->servings);
        $this->assertTrue($request->hasUnusableServings());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableServings(): iterable
    {
        yield 'zéro' => ['0'];
        yield 'cent' => ['100'];
        yield 'un décimal' => ['1.5'];
        yield 'un mot' => ['beaucoup'];
    }

    public function testTheServingsAreReadFromTheIdOfTheResolutionFirstAndTheQuantityIsLeftAlone(): void
    {
        // When : « six » rapproché de « 6 », et une quantité de produit dans le même corps
        $body = AlexaTestRequest::answerServings('six', '6')->withQuantity('3')->toArray();
        $request = AlexaRequest::fromDecoded($body);

        // Then
        $this->assertSame(AlexaIntent::ANSWER_SERVINGS, $request->intent());
        $this->assertSame(6, $request->servings);
        $this->assertSame(3, $request->quantity);
    }

    public function testTheKindIsReadWithItsTextAndTheIdOfItsResolution(): void
    {
        // When : « une recette »
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::chooseKind('recette', 'meal')->toArray());

        // Then
        $this->assertSame(AlexaIntent::CHOOSE_KIND, $request->intent());
        $this->assertEquals(new SlotValue('recette', 'meal'), $request->kind);
        $this->assertContains('recette', $request->heardTexts());
    }

    public function testTheNewIntentsAreKnown(): void
    {
        $this->assertSame(AlexaIntent::KEEP_MEAL, AlexaRequest::fromDecoded(AlexaTestRequest::keepMeal()->toArray())->intent());
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::keepMeal()->toArray())->kind);
    }

    public function testTheMealSlotsAreOnlyReadFromAnIntentRequest(): void
    {
        // Given : des créneaux qui traînent dans un lancement
        $body = AlexaTestRequest::launch()->toArray();
        $body['request']['intent'] = ['name' => 'AddMealIntent', 'slots' => ['recipe' => ['value' => 'gratin'], 'servings' => ['value' => '4'], 'kind' => ['value' => 'recette']]];

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then
        $this->assertNull($request->recipePhrase);
        $this->assertNull($request->servings);
        $this->assertFalse($request->servingsHeard);
        $this->assertNull($request->kind);
    }
}
