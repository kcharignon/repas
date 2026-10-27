<?php

namespace Repas\Tests\Voice\InputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Shared\Application\Interface\SpokenIngredientResolver;
use Repas\Shared\Application\Interface\VoiceFeature;
use Repas\Shared\Application\Interface\VoiceMealPlanner;
use Repas\Shared\Application\Interface\VoiceShoppingList;
use Repas\Tests\Helper\Alexa\AlexaTestAuthority;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Tests\Helper\Alexa\AlexaTestLeaf;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\Alexa\ChainFetcherDouble;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledVoiceFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SpokenIngredientResolverInMemory;
use Repas\User\Domain\Model\User;
use Repas\Voice\Domain\Interface\AccessGrantRepository;
use Repas\Voice\Domain\Interface\AlexaRequestVerifier;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\OpaqueToken;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Infrastructure\Security\OpenSslAlexaRequestVerifier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\NativeClock;

/**
 * Le webhook d'Alexa de bout en bout (VOX-204) : le VRAI vérificateur avec de VRAIES signatures RSA (autorité et certificat « echo-api.amazon.com »
 * nés dans le test, ADR 0029 décision 7), les vrais bus, la vraie base, le vrai classeur d'ingrédients, la vraie liste de courses. Alexa est jouée
 * par ce test : aucun appel à Amazon, la chaîne de certificats vient d'un double du port.
 */
class AlexaWebhookApiFlowTest extends DatabaseTestCase
{
    private const string URL = '/api/voice/alexa';
    private const string ACCESS_TOKEN_SEED = 'access';

    private static AlexaTestAuthority $authority;
    private static AlexaTestLeaf $leaf;

    private KernelBrowser $client;
    private DataPersister $persister;
    private ShoppingListRepository $lists;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$authority = AlexaTestAuthority::root();
        self::$leaf = self::$authority->issue();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
        static::getContainer()->set(AlexaRequestVerifier::class, new OpenSslAlexaRequestVerifier(
            new ChainFetcherDouble(self::$leaf->chainPem()),
            new NativeClock(),
            new NullLogger(),
            AlexaTestRequest::SKILL_ID,
            [self::$authority->trustFile()],
        ));
        $this->persister = new DataPersister(static::getContainer());
        $this->lists = static::getContainer()->get(ShoppingListRepository::class);
    }

    public function testBreadIsAddedToTheExistingListAndAlexaSaysSo(): void
    {
        // Given : un utilisateur lié, son pain, une liste active
        $user = $this->linkedUser();
        $this->savedBread($user);
        $list = $this->savedActiveList($user);

        // When : « Alexa, demande à Jarvis d'ajouter du pain »
        $this->say(AlexaTestRequest::addItem('du pain'));

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertSame(['version' => '1.0', 'response' => [
            'outputSpeech' => ['type' => 'PlainText', 'text' => "C'est noté : pain."],
            'shouldEndSession' => true,
        ]], $this->jsonBody());
        $this->entityManager->clear();
        $reloaded = $this->lists->findOneById($list->getId());
        $this->assertCount(1, $reloaded->getRows());
        $this->assertSame($list->getVersion() + 1, $reloaded->getVersion());
        $this->assertCount(1, $this->lists->findByOwner($user->getId()));
    }

    public function testWithoutAListANewOneIsCreatedAndAlexaSaysSo(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->savedBread($user);

        // When
        $this->say(AlexaTestRequest::addItem('du pain'));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec pain.", $this->speech());
        $this->entityManager->clear();
        $lists = $this->lists->findByOwner($user->getId());
        $this->assertCount(1, $lists);
        $this->assertSame(ShoppingListStatus::ACTIVE, $lists->reset()->getStatus());
        $this->assertCount(1, $lists->reset()->getRows());
    }

    public function testWhenTheOnlyListWasSentToTheDriveANewOneIsStartedAndTheSentOneIsUntouched(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->savedBread($user);
        $butter = new IngredientBuilder()->isButter()->withCreator($user)->build();
        $this->persister->saveIngredient($butter);
        $sent = new ShoppingListBuilder()->withOwner($user)->addIngredient($butter)->withStatus(ShoppingListStatus::SENT)->build();
        $this->lists->save($sent);
        $this->entityManager->clear();

        // When
        $this->say(AlexaTestRequest::addItem('du pain'));

        // Then : la nouvelle liste est dite, avec celle qui est partie ; la liste envoyée n'a pas bougé
        $this->assertSame("J'ai commencé une nouvelle liste avec pain. Ta liste précédente est partie au drive.", $this->speech());
        $this->entityManager->clear();
        $reloaded = $this->lists->findOneById($sent->getId());
        $this->assertSame([ShoppingListStatus::SENT, $sent->getVersion(), 1], [$reloaded->getStatus(), $reloaded->getVersion(), count($reloaded->getRows())]);
        $this->assertCount(2, $this->lists->findByOwner($user->getId()));
        $this->assertCount(1, $this->lists->findOneActivateByOwner($user->getId())->getRows());
    }

    public function testSomethingThatIsNotInTheUsersIngredientsIsNotCreatedUntilHeAgreesAndNothingIsWritten(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->savedBread($user);
        $this->savedActiveList($user);
        $this->entityManager->clear();
        $versionBefore = $this->lists->findOneActivateByOwner($user->getId())->getVersion();
        $ingredientsBefore = $this->rowCount('ingredient');

        // When
        $this->say(AlexaTestRequest::addItem('xyz'));

        // Then : Alexa demande, la séance reste ouverte, rien n'est écrit
        $this->assertSame('Je ne connais pas xyz. Je le crée ? Ou c\'est une recette ?', $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->entityManager->clear();
        $list = $this->lists->findOneActivateByOwner($user->getId());
        $this->assertSame([$versionBefore, 0], [$list->getVersion(), count($list->getRows())]);
        $this->assertSame($ingredientsBefore, $this->rowCount('ingredient'));
        $this->assertSame(1, $this->rowCount('shopping_list'));
    }

    public function testAnIngredientThatDisappearsBetweenTheChoiceAndTheAddIsAnsweredWithASentenceAndNothingIsWritten(): void
    {
        // Given : le classeur choisit un ingrédient que l'ajout ne trouve plus (supprimé entre-temps, ou d'un autre : ici, d'un autre)
        $user = $this->linkedUser();
        $someoneElsesBread = new IngredientBuilder()->isBread()->withCreator($this->savedUser(), true)->build();
        $this->persister->saveIngredient($someoneElsesBread);
        static::getContainer()->set(SpokenIngredientResolver::class, new SpokenIngredientResolverInMemory()->willResolve(
            'du pain',
            new ResolvedIngredient($someoneElsesBread->getSlug(), 'Pain', 'Boulangerie', ResolvedIngredient::SURE),
        ));

        // When
        $this->say(AlexaTestRequest::addItem('du pain'));

        // Then : 200 et une phrase, jamais une trace ; le bus transactionnel a tout annulé (ni liste créée, ni suivi de la liaison)
        $this->assertSame(200, $this->statusCode());
        $this->assertSame("Je n'ai pas pu faire ça. Réessaie dans un instant.", $this->speech());
        $this->assertStringNotContainsString($someoneElsesBread->getSlug(), (string) $this->client->getResponse()->getContent());
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $this->assertNull($this->connection()->fetchOne('SELECT last_used_at FROM voice_link') ?: null);

        // When : la connexion et le bus restent sains pour la requête suivante
        $this->say(AlexaTestRequest::intent('AMAZON.HelpIntent'));

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertStringStartsWith('Dis par exemple', $this->speech());
        $this->assertCount(0, $this->lists->findByOwner($user->getId()));
    }

    public function testTheBreadOfAnotherUserIsNeverFoundNorWrittenTo(): void
    {
        // Given : le pain est à quelqu'un d'autre
        $user = $this->linkedUser();
        $this->savedBread($this->savedUser());

        // When
        $this->say(AlexaTestRequest::addItem('du pain'));

        // Then : pour lui le pain est inconnu, et rien n'est proposé de l'ingrédient de l'autre
        $this->assertSame('Je ne connais pas pain. Je le crée ? Ou c\'est une recette ?', $this->speech());
        $this->assertStringNotContainsString('pain-', (string) $this->client->getResponse()->getContent());
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $this->assertCount(0, $this->lists->findByOwner($user->getId()));
    }

    public function testAProbableChoiceSaysWhatWasPutAndWhere(): void
    {
        // Given : seul « tomates pelées » existe, l'utilisateur dit « tomate »
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->withName('tomates pelées')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user, true)->build());
        $this->savedActiveList($user);

        // When
        $this->say(AlexaTestRequest::addItem('tomate'));

        // Then : ajoutée quand même, et dite avec son rayon
        $this->assertSame("J'ai mis tomates pelées, conserve.", $this->speech());
        $this->entityManager->clear();
        $this->assertCount(1, $this->lists->findOneActivateByOwner($user->getId())->getRows());
    }

    public function testInAnOpenSessionTheAnswerIsAPlainStatementAndTheSessionStaysOpenWithoutAReprompt(): void
    {
        // Given : « Alexa, ouvre Jarvis », puis « du pain », puis « du pain » encore
        $user = $this->linkedUser();
        $this->savedBread($user);

        // When
        $this->say(AlexaTestRequest::launch());
        $launch = $this->jsonBody()['response'];
        $this->say(AlexaTestRequest::addItem('du pain')->inOpenSession());
        $first = $this->jsonBody()['response'];
        $this->say(AlexaTestRequest::addItem('du pain')->inOpenSession());

        // Then
        $this->assertSame(['Que veux-tu ajouter ?', false], [$launch['outputSpeech']['text'], $launch['shouldEndSession']]);
        $this->assertSame(["J'ai commencé une nouvelle liste avec pain.", false], [$first['outputSpeech']['text'], $first['shouldEndSession']]);
        $this->assertArrayNotHasKey('reprompt', $first, 'une affirmation, aucune question ni relance');
        // La ligne de pain est déjà dans la liste : on ajoute et on dit le total
        $this->assertSame("C'est ajouté, tu en as maintenant 2 dans ta liste.", $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertArrayNotHasKey('reprompt', $this->jsonBody()['response']);
        $this->entityManager->clear();
        $this->assertCount(1, $this->lists->findByOwner($user->getId()), 'une seule liste pour toute la séance');
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // La quantité et l'unité dites, le total de la ligne (ADR 0029 décision 5, amendée le 2026-10-25)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testThreeCroquettesAreAddedAsThreeUnitsAndAlexaSaysSo(): void
    {
        // Given : des croquettes qui se comptent à l'unité, une liste active
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->withName('croquettes')->withCreator($user, true)->build());
        $list = $this->savedActiveList($user);

        // When : « Alexa, demande à Jarvis d'ajouter trois croquettes »
        $this->say(AlexaTestRequest::addQuantified('croquettes', '3'));

        // Then
        $this->assertSame("C'est noté : 3 croquettes.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->entityManager->clear();
        $row = $this->lists->findOneById($list->getId())->getRows()->reset();
        $this->assertSame([3.0, 'unite'], [$row->getQuantity(), $row->getUnitSlug()]);
    }

    public function testTwoKilosOfAnIngredientThatDoesNotKnowTheKiloAreAddedInItsOwnUnitAndAlexaSaysIt(): void
    {
        // Given : des tomates qui se comptent à l'unité
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->withName('tomates')->withCreator($user, true)->build());
        $list = $this->savedActiveList($user);

        // When : « ajoute deux kilos de tomates »
        $this->say(AlexaTestRequest::addQuantified('tomates', '2', 'kilos', 'kilo'));

        // Then : deux tomates, et Alexa ne laisse pas croire à des kilos
        $this->assertSame("C'est noté : 2 tomates.", $this->speech());
        $this->assertStringNotContainsString('kilo', $this->speech());
        $this->entityManager->clear();
        $row = $this->lists->findOneById($list->getId())->getRows()->reset();
        $this->assertSame([2.0, 'unite'], [$row->getQuantity(), $row->getUnitSlug()]);
    }

    public function testTwoKilosOfAnIngredientThatKnowsTheKiloStartTheLineInKilosThenTheTotalIsSaidInKilos(): void
    {
        // Given : de la farine, achetée au kilo
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->isFlour()->withCreator($user, true)->build());
        $list = $this->savedActiveList($user);

        // When : « ajoute deux kilos de farine », puis « ajoute un kilo de farine »
        $this->say(AlexaTestRequest::addQuantified('farine', '2', 'kilos', 'kilo'));
        $first = $this->speech();
        $this->say(AlexaTestRequest::addQuantified('farine', '1', 'kilo', 'kilo'));

        // Then
        $this->assertSame("C'est noté : 2 kilos de farine.", $first);
        $this->assertSame("C'est ajouté, tu en as maintenant 3 kilos dans ta liste.", $this->speech());
        $this->entityManager->clear();
        $rows = $this->lists->findOneById($list->getId())->getRows();
        $this->assertCount(1, $rows);
        $this->assertSame([3.0, 'kilo'], [$rows->reset()->getQuantity(), $rows->reset()->getUnitSlug()]);
    }

    public function testAUnitAlexaLeftInTheItemSlotIsStrippedFromThePhrase(): void
    {
        // Given : « un paquet de farine » ; Alexa a laissé « paquets de farine » dans le créneau `item`
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->isFlour()->withCreator($user, true)->build());
        $list = $this->savedActiveList($user);

        // When
        $this->say(AlexaTestRequest::addItem('paquets de farine'));

        // Then : la farine est retrouvée, une unité d'achat (le paquet est inconnu de la farine : le kilo de la ligne se dit)
        $this->assertSame("C'est noté : 1 kilo de farine.", $this->speech());
        $this->entityManager->clear();
        $this->assertCount(1, $this->lists->findOneById($list->getId())->getRows());
    }

    #[DataProvider('unusableSpokenQuantities')]
    public function testAQuantityWeCannotWriteIsAskedAgainAndNothingIsWritten(string $quantity): void
    {
        // Given
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->withName('croquettes')->withCreator($user, true)->build());
        $list = $this->savedActiveList($user);

        // When
        $this->say(AlexaTestRequest::addQuantified('croquettes', $quantity));

        // Then : 200 et une question, la séance reste ouverte, la liste n'a pas bougé
        $this->assertSame(200, $this->statusCode());
        $this->assertSame('Combien ? Redis ta demande avec un nombre de 1 à 99.', $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->entityManager->clear();
        $reloaded = $this->lists->findOneById($list->getId());
        $this->assertCount(0, $reloaded->getRows());
        $this->assertSame($list->getVersion(), $reloaded->getVersion());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableSpokenQuantities(): iterable
    {
        yield 'zéro' => ['0'];
        yield 'cent' => ['100'];
        yield 'décimale' => ['1.5'];
        yield 'négative' => ['-2'];
        yield 'un mot' => ['beaucoup'];
    }

    public function testSayingBreadTwiceInADirectCallAddsTheSecondOneAndSaysTheTotal(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->savedBread($user);
        $list = $this->savedActiveList($user);

        // When : deux appels directs
        $this->say(AlexaTestRequest::addItem('du pain'));
        $first = $this->speech();
        $this->say(AlexaTestRequest::addItem('du pain'));

        // Then
        $this->assertSame("C'est noté : pain.", $first);
        $this->assertSame("C'est ajouté, tu en as maintenant 2 dans ta liste.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->entityManager->clear();
        $this->assertSame(2.0, $this->lists->findOneById($list->getId())->getRows()->reset()->getQuantity());
    }

    public function testTheSaidQuantityFollowsTheDialogueOfAnUnknownProduct(): void
    {
        // Given : « trois ciboulettes », inconnues, puis création guidée
        $user = $this->linkedUser();
        $this->savedCatalogue();
        $list = $this->savedActiveList($user);

        // When
        $this->say(AlexaTestRequest::addQuantified('ciboulette', '3'));
        $this->assertSame(3, $this->attributes()['dialogue']['quantity']);
        $this->converse(AlexaTestRequest::yes(), $this->attributes());
        $this->converse(AlexaTestRequest::answerDepartment('céréales', 'cereale'), $this->attributes());
        $this->converse(AlexaTestRequest::answerUnit('pièces', 'unite'), $this->attributes());
        $this->converse(AlexaTestRequest::yes(), $this->attributes());

        // Then : trois ciboulettes dans la liste
        $this->assertSame('C\'est créé et noté : 3 ciboulettes, rayon céréale.', $this->speech());
        $this->entityManager->clear();
        $this->assertSame(3.0, $this->lists->findOneById($list->getId())->getRows()->reset()->getQuantity());
    }
    public function testHelpStopAndFallbackAreSpoken(): void
    {
        // Given
        $this->linkedUser();

        // When / Then
        $this->say(AlexaTestRequest::intent('AMAZON.HelpIntent'));
        $this->assertSame('Dis par exemple : ajoute du pain. Je le mets dans ta liste de courses.', $this->speech());
        $this->say(AlexaTestRequest::intent('AMAZON.StopIntent'));
        $this->assertSame(['À bientôt.', true], [$this->speech(), $this->jsonBody()['response']['shouldEndSession']]);
        $this->say(AlexaTestRequest::intent('AMAZON.CancelIntent'));
        $this->assertSame('À bientôt.', $this->speech());
        $this->say(AlexaTestRequest::intent('AMAZON.FallbackIntent'));
        $this->assertSame('Dis par exemple : ajoute du pain.', $this->speech());
    }

    public function testTheEndOfASessionHasNoSpeech(): void
    {
        // Given
        $this->linkedUser();

        // When
        $this->say(AlexaTestRequest::sessionEnded());

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertSame('{"version":"1.0","response":{}}', $this->client->getResponse()->getContent());
    }

    /**
     * Les intentions du modèle d'interaction livré avec le dépôt (`docs/voice/skill/`) : chacune reçoit, signée, une réponse vocale valide.
     *
     * @return iterable<string, array{string}>
     */
    public static function intentsOfTheInteractionModel(): iterable
    {
        $model = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/docs/voice/skill/interaction-model.fr-FR.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($model['interactionModel']['languageModel']['intents'] as $intent) {
            yield $intent['name'] => [$intent['name']];
        }
    }

    #[DataProvider('intentsOfTheInteractionModel')]
    public function testEveryIntentOfTheInteractionModelGetsAValidSpokenAnswer(string $intent): void
    {
        // Given
        $this->linkedUser();
        $request = $intent === 'AddItemIntent' ? AlexaTestRequest::addItem('du pain') : AlexaTestRequest::intent($intent);

        // When
        $this->say($request);

        // Then
        $this->assertSame(200, $this->statusCode(), $intent);
        $json = $this->jsonBody();
        $this->assertSame('1.0', $json['version']);
        $this->assertSame('PlainText', $json['response']['outputSpeech']['type'], $intent);
        $this->assertNotSame('', $json['response']['outputSpeech']['text'], $intent);
        $this->assertIsBool($json['response']['shouldEndSession']);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Un produit inconnu : « de quoi tu parles ? » (ADR 0029 décision 11, VOX-303)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testAProposalIsChosenAddedRememberedAndGivenDirectlyNextTime(): void
    {
        // Given : il a un « sirop fruit rouge » ; il dit « du sirop de menthe », qu'il n'a pas (appel direct)
        $user = $this->linkedUser();
        $syrup = new IngredientBuilder()->withName('Sirop fruit rouge')->withCreator($user, true)->build();
        $this->persister->saveIngredient($syrup);
        $this->savedActiveList($user);

        // When
        $this->say(AlexaTestRequest::addItem('du sirop de menthe'));

        // Then : Alexa propose le sirop et « un nouveau produit », la séance reste ouverte, l'état voyage, rien n'est écrit
        $this->assertSame('Je ne connais pas sirop de menthe. Tu veux dire sirop fruit rouge ? Ou un nouveau produit, ou une recette ?', $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame('Tu veux dire sirop fruit rouge ? Ou un nouveau produit, ou une recette ?', $this->jsonBody()['response']['reprompt']['outputSpeech']['text']);
        $this->assertSame('choose', $this->attributes()['dialogue']['step']);
        $this->assertSame(0, $this->rowCount('shopping_list_row'));
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));

        // When : « oui » (une seule proposition)
        $this->converse(AlexaTestRequest::yes(), $this->attributes());

        // Then : ajouté, retenu, dit ; le dialogue est fini (aucun état), la demande est traitée : la séance se ferme, sans relance
        $this->assertSame("C'est noté : sirop fruit rouge. Je m'en souviendrai.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertArrayNotHasKey('reprompt', $this->jsonBody()['response']);
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
        $this->assertSame(
            [[$user->getId(), 'sirop-menthe', $syrup->getSlug(), 1]],
            array_map(static fn(array $row): array => [$row['user_id'], $row['phrase'], $row['ingredient_slug'], (int) $row['times']], $this->connection()->fetchAllAssociative('SELECT * FROM spoken_phrase_choice')),
        );

        // When : plus tard, la même phrase, en appel direct
        $this->say(AlexaTestRequest::addItem('du sirop de menthe'));

        // Then : directement l'ingrédient retenu, comme un nom exact, sans question
        $this->assertSame("C'est ajouté, tu en as maintenant 2 dans ta liste.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
        $this->entityManager->clear();
        $rows = $this->lists->findOneActivateByOwner($user->getId())->getRows();
        $this->assertCount(1, $rows, "même ingrédient, même unité : la ligne existante");
        $this->assertSame(2.0, $rows->reset()->getQuantity());
    }

    public function testTheRankOfAProposalChoosesItWhenSeveralAreProposed(): void
    {
        // Given : trois pâtes qui ressemblent à « pâtes à pizza », dont aucune ne s'appelle ainsi
        $user = $this->linkedUser();
        foreach (['Pâte ramen', 'Pâte brisée', 'Pâte sablée'] as $name) {
            $this->persister->saveIngredient(new IngredientBuilder()->withName($name)->withCreator($user, true)->build());
        }
        $this->savedActiveList($user);

        // When
        $this->say(AlexaTestRequest::addItem('des pâtes à pizza')->inOpenSession());

        // Then : les trois, du plus au moins proche
        $this->assertSame('Je ne connais pas pâtes à pizza. Tu veux dire pâte ramen, pâte brisée ou pâte sablée ? Ou un nouveau produit, ou une recette ?', $this->speech() . '');
        $this->assertCount(3, $this->attributes()['dialogue']['options']);

        // When : « oui » seul ne suffit pas, il y en a trois
        $this->converse(AlexaTestRequest::yes(), $this->attributes());
        $this->assertSame('Lequel ? pâte ramen, pâte brisée ou pâte sablée. Ou un nouveau produit, ou une recette ?', $this->speech());

        // When : « le deuxième »
        $this->converse(AlexaTestRequest::chooseOption('le deuxième', '2'), $this->attributes());

        // Then : la deuxième proposition, retenue pour la phrase
        $this->assertSame("C'est noté : pâte brisée. Je m'en souviendrai.", $this->speech());
        $this->assertSame('pate-pizza', $this->connection()->fetchOne('SELECT phrase FROM spoken_phrase_choice'));
        $this->entityManager->clear();
        $this->assertCount(1, $this->lists->findOneActivateByOwner($user->getId())->getRows());
    }

    public function testAnUnknownProductIsCreatedWithItsDepartmentAndUnitAndAddedToTheList(): void
    {
        // Given : rien ne ressemble à « ciboulette » ; les rayons et unités du catalogue existent
        $user = $this->linkedUser();
        $this->savedBread($user);
        $this->savedCatalogue();
        $list = $this->savedActiveList($user);
        $ingredientsBefore = $this->rowCount('ingredient');

        // When : « ajoute de la ciboulette » dans une séance ouverte
        $this->converse(AlexaTestRequest::addItem('de la ciboulette'));
        $this->assertSame('Je ne connais pas ciboulette. Je le crée ? Ou c\'est une recette ?', $this->speech());

        // When : « oui » -> le rayon
        $this->converse(AlexaTestRequest::yes(), $this->attributes());
        $this->assertSame('Dans quel rayon ? Dis lesquels pour les entendre.', $this->speech());
        $this->assertSame($ingredientsBefore, $this->rowCount('ingredient'), "rien n'est créé avant la confirmation");

        // When : « les fruits et légumes » -> l'unité
        $this->converse(AlexaTestRequest::answerDepartment('les fruits et légumes', 'cereale'), $this->attributes());
        $this->assertSame('Quelle unité ? Par exemple pièce, kilo ou litre. Dis lesquelles pour les entendre.', $this->speech());

        // When : « des pièces » -> la confirmation
        $this->converse(AlexaTestRequest::answerUnit('des pièces', 'unite'), $this->attributes());
        $this->assertSame("Je crée ciboulette, céréale, unité. C'est bon ?", $this->speech());
        $this->assertSame($ingredientsBefore, $this->rowCount('ingredient'));

        // When : « oui »
        $this->converse(AlexaTestRequest::yes(), $this->attributes());

        // Then : créé pour l'utilisateur, avec une seule unité, et ajouté à sa liste ; plus d'état ; rien n'est retenu pour la phrase
        $this->assertSame("C'est créé et noté : ciboulette, rayon céréale.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame($ingredientsBefore + 1, $this->rowCount('ingredient'));
        $created = $this->connection()->fetchAssociative("SELECT * FROM ingredient WHERE name = 'ciboulette'");
        $this->assertSame([$user->getId(), 'cereale', 'unite', 'unite', false], [$created['creator'], $created['department'], $created['default_cooking_unit'], $created['default_purchase_unit'], (bool) $created['is_base']]);
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));
        $this->entityManager->clear();
        $reloaded = $this->lists->findOneById($list->getId());
        $this->assertCount(1, $reloaded->getRows());
        $this->assertSame($created['slug'], $reloaded->getRows()->reset()->getIngredientSlug());

        // When : la prochaine fois, le produit existe
        $this->say(AlexaTestRequest::addItem('de la ciboulette'));

        // Then : la ligne existe déjà, on dit le total
        $this->assertSame("C'est ajouté, tu en as maintenant 2 dans ta liste.", $this->speech());
    }

    public function testAskingWhichOnesReadsTheDepartmentsThenTheUnitsAndTheCreationGoesOn(): void
    {
        // Given : rien ne ressemble à « ciboulette »
        $user = $this->linkedUser();
        $this->savedCatalogue();
        $this->savedActiveList($user);
        $ingredientsBefore = $this->rowCount('ingredient');

        // When : « ajoute de la ciboulette » -> « un nouveau produit » -> « lesquels ? »
        $this->converse(AlexaTestRequest::addItem('de la ciboulette'));
        $this->converse(AlexaTestRequest::createItem(), $this->attributes());
        $asked = $this->attributes();
        $this->converse(AlexaTestRequest::listChoices(), $asked);

        // Then : tous les rayons du catalogue, puis la question ; l'état est le même, la séance reste ouverte
        $this->assertSame('Les rayons : céréale. Lequel ?', $this->speech());
        $this->assertSame($asked, $this->attributes());
        $this->assertSame(0, $this->attributes()['dialogue']['misses']);
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame('Dans quel rayon ? Dis lesquels pour les entendre.', $this->jsonBody()['response']['reprompt']['outputSpeech']['text']);

        // When : « aide » donne la même liste, puis un rayon
        $this->converse(AlexaTestRequest::intent('AMAZON.HelpIntent'), $this->attributes());
        $this->assertSame('Les rayons : céréale. Lequel ?', $this->speech());
        $this->converse(AlexaTestRequest::answerDepartment('céréales', 'cereale'), $this->attributes());
        $this->assertSame('Quelle unité ? Par exemple pièce, kilo ou litre. Dis lesquelles pour les entendre.', $this->speech());

        // When : « lesquelles ? »
        $this->converse(AlexaTestRequest::listChoices(), $this->attributes());

        // Then : toutes les unités du catalogue
        $this->assertMatchesRegularExpression('/^Les unités : (kilo, unité|unité, kilo)\. Laquelle \?$/u', $this->speech());
        $this->assertSame(0, $this->attributes()['dialogue']['misses']);
        $this->assertSame($ingredientsBefore, $this->rowCount('ingredient'));

        // When : une unité, puis « oui »
        $this->converse(AlexaTestRequest::answerUnit('des pièces', 'unite'), $this->attributes());
        $this->assertSame("Je crée ciboulette, céréale, unité. C'est bon ?", $this->speech());
        $this->converse(AlexaTestRequest::yes(), $this->attributes());

        // Then : créé, rangé dans le rayon dit à voix haute, ajouté
        $this->assertSame("C'est créé et noté : ciboulette, rayon céréale.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame($ingredientsBefore + 1, $this->rowCount('ingredient'));
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
    }

    public function testAskingWhichOnesWithoutAnyDialogueGetsTheFallback(): void
    {
        // Given
        $this->linkedUser();

        // When : séance sans état
        $this->converse(AlexaTestRequest::listChoices());

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertSame('Dis par exemple : ajoute du pain.', $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testRefusingAtTheConfirmationCreatesAndAddsNothing(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->savedCatalogue();
        $this->savedActiveList($user);
        $ingredientsBefore = $this->rowCount('ingredient');

        // When
        $this->converse(AlexaTestRequest::addItem('de la ciboulette'));
        $this->converse(AlexaTestRequest::createItem(), $this->attributes());
        $this->converse(AlexaTestRequest::answerDepartment('céréales', 'cereale'), $this->attributes());
        $this->converse(AlexaTestRequest::answerUnit('kilos', 'kilo'), $this->attributes());
        $this->assertSame("Je crée ciboulette, céréale, kilo. C'est bon ?", $this->speech());
        $this->converse(AlexaTestRequest::no(), $this->attributes());

        // Then
        $this->assertSame("D'accord, je ne crée rien.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame($ingredientsBefore, $this->rowCount('ingredient'));
        $this->assertSame(0, $this->rowCount('shopping_list_row'));
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));
    }

    public function testTwoMisunderstandingsInARowAbandonPolitely(): void
    {
        // Given
        $this->linkedUser();
        $this->savedCatalogue();

        // When
        $this->converse(AlexaTestRequest::addItem('de la ciboulette'));
        $this->converse(AlexaTestRequest::yes(), $this->attributes());
        $this->converse(AlexaTestRequest::answerDepartment('zébulon', null), $this->attributes());

        // Then : une première fois, on redemande
        $this->assertSame("Je n'ai pas compris. Dans quel rayon ? Dis lesquels pour les entendre.", $this->speech());
        $this->assertSame(1, $this->attributes()['dialogue']['misses']);

        // When : une seconde fois
        $this->converse(AlexaTestRequest::intent('AMAZON.FallbackIntent'), $this->attributes());

        // Then : on abandonne, sans état, la séance reste ouverte pour la suite
        $this->assertSame("Je n'ai pas compris. On laisse tomber.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testSayingAnotherArticleInTheMiddleOfTheQuestionsAddsIt(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->savedBread($user);
        $this->savedCatalogue();
        $this->converse(AlexaTestRequest::addItem('de la ciboulette'));

        // When : « du pain », à la question « Je le crée ? »
        $this->converse(AlexaTestRequest::addItem('du pain'), $this->attributes());

        // Then : ajouté comme un ajout ordinaire ; aucune proposition n'avait été faite, il n'y avait rien à corriger : rien n'est retenu ni promis
        $this->assertSame("J'ai commencé une nouvelle liste avec pain.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));

        // When : plus tard, « de la ciboulette » de nouveau, en appel direct
        $this->say(AlexaTestRequest::addItem('de la ciboulette'));

        // Then : la question revient, ciboulette n'est pas devenue du pain
        $this->assertSame('Je ne connais pas ciboulette. Je le crée ? Ou c\'est une recette ?', $this->speech());
        $this->assertSame('choose', $this->attributes()['dialogue']['step']);
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
    }

    public function testNamingAnotherIngredientAfterTheProposalsAddsItAndRemembersTheOriginalPhrase(): void
    {
        // Given : il a de la pomme de terre et trois pâtes, pas de « patates »
        $user = $this->linkedUser();
        $potato = new IngredientBuilder()->withName('Pomme de terre')->withCreator($user, true)->build();
        $this->persister->saveIngredient($potato);
        foreach (['Pâte', 'Pâte brisée', 'Pâte feuilletée'] as $name) {
            $this->persister->saveIngredient(new IngredientBuilder()->withName($name)->withCreator($user, true)->build());
        }
        $this->savedActiveList($user);

        // When : « patates » -> des propositions -> « non, pomme de terre »
        $this->say(AlexaTestRequest::addItem('des patates'));
        $this->assertSame('choose', $this->attributes()['dialogue']['step']);
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));
        $this->converse(AlexaTestRequest::addItem('pomme de terre'), $this->attributes());

        // Then : ajoutée, "patates" retenu pour elle, dialogue fini
        $this->assertSame("C'est noté : pomme de terre. Je m'en souviendrai.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
        $this->assertSame(
            [[$user->getId(), 'patate', $potato->getSlug(), 1]],
            array_map(static fn(array $row): array => [$row['user_id'], $row['phrase'], $row['ingredient_slug'], (int) $row['times']], $this->connection()->fetchAllAssociative('SELECT * FROM spoken_phrase_choice')),
        );

        // When : plus tard, « patates » de nouveau, en appel direct
        $this->say(AlexaTestRequest::addItem('des patates'));

        // Then : directement la pomme de terre, sans question
        $this->assertSame("C'est ajouté, tu en as maintenant 2 dans ta liste.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
        $this->assertSame(1, $this->rowCount('shopping_list_row'), 'même ingrédient, même unité : la ligne existante');
    }

    public function testNamingAnotherUnknownIngredientAfterTheProposalsStartsANewDialogueAndRemembersNothing(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->withName('Pâte brisée')->withCreator($user, true)->build());
        $this->savedActiveList($user);
        $this->say(AlexaTestRequest::addItem('des patates'));

        // When
        $this->converse(AlexaTestRequest::addItem('xyzzy'), $this->attributes());

        // Then : un nouveau dialogue, pour la nouvelle phrase ; rien d'écrit
        $this->assertSame('xyzzy', $this->attributes()['dialogue']['name']);
        $this->assertSame('choose', $this->attributes()['dialogue']['step']);
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));
        $this->assertSame(0, $this->rowCount('shopping_list_row'));
    }

    public function testStopInTheMiddleOfTheQuestionsClosesTheSession(): void
    {
        // Given
        $this->linkedUser();
        $this->converse(AlexaTestRequest::addItem('de la ciboulette'));

        // When
        $this->converse(AlexaTestRequest::intent('AMAZON.StopIntent'), $this->attributes());

        // Then
        $this->assertSame(['À bientôt.', true], [$this->speech(), $this->jsonBody()['response']['shouldEndSession']]);
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Deux ingrédients de même nom dans deux rayons : Alexa demande le rayon (ADR 0029 décision 3, amendement du 2026-10-25)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testTwoTomatoesInTwoDepartmentsAskForTheDepartmentThenRememberTheChoice(): void
    {
        // Given : « tomate » en conserve et « tomate » en céréale (rayons du test), aucune habitude qui tranche
        $user = $this->linkedUser();
        $canned = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user)->withSlug('tomate-conserve-' . $user->getId())->build();
        $cereal = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user)->withSlug('tomate-cereale-' . $user->getId())->build();
        $this->persister->saveIngredient($canned);
        $this->persister->saveIngredient($cereal);
        $list = $this->savedActiveList($user);

        // When : « des tomates » (appel direct)
        $this->say(AlexaTestRequest::addItem('des tomates'));

        // Then : Alexa demande le rayon, sans l'issue « un nouveau produit » ; rien n'est écrit, la séance reste ouverte
        $this->assertMatchesRegularExpression('/^Tomate : en (céréale|conserve) ou en (céréale|conserve) \?$/u', $this->speech());
        $this->assertStringNotContainsString('nouveau produit', $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame($this->speech(), $this->jsonBody()['response']['reprompt']['outputSpeech']['text']);
        $this->assertCount(2, $this->attributes()['dialogue']['options']);
        $this->assertSame(0, $this->rowCount('shopping_list_row'));
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));

        // When : « en conserve » (le rayon dit)
        $this->converse(AlexaTestRequest::answerDepartment('conserve', 'conserve'), $this->attributes());

        // Then : ajouté, le rayon répété, la phrase retenue, la séance fermée sans relance
        $this->assertSame("C'est noté : tomate, conserve. Je m'en souviendrai.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
        $this->assertSame($canned->getSlug(), $this->connection()->fetchOne('SELECT ingredient_slug FROM spoken_phrase_choice'));
        $this->entityManager->clear();
        $this->assertSame($canned->getSlug(), $this->lists->findOneById($list->getId())->getRows()->reset()->getIngredientSlug());

        // When : la fois suivante
        $this->say(AlexaTestRequest::addItem('des tomates'));

        // Then : directement le même ingrédient, sans question
        $this->assertSame("C'est ajouté, tu en as maintenant 2 dans ta liste.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
    }

    public function testTheDepartmentCanBeSaidAsAnArticleOrByRankAndAWrongAnswerIsAsked(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user)->withSlug('tomate-conserve-' . $user->getId())->build());
        $this->persister->saveIngredient(new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user)->withSlug('tomate-cereale-' . $user->getId())->build());
        $this->savedActiveList($user);
        $this->converse(AlexaTestRequest::addItem('des tomates'));
        $asked = $this->attributes();

        // When : une réponse hors sujet, puis le rayon dit comme un article (Alexa a pris le créneau `item`)
        $this->converse(AlexaTestRequest::answerUnit('kilos', 'kilo'), $asked);
        $this->assertMatchesRegularExpression('/^Je n.ai pas compris\. Tomate : en/u', $this->speech());
        $this->assertSame(1, $this->attributes()['dialogue']['misses']);
        $this->converse(AlexaTestRequest::addItem('céréales'), $this->attributes());

        // Then
        $this->assertSame("C'est noté : tomate, céréale. Je m'en souviendrai.", $this->speech());
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
    }

    public function testRefusingTheDepartmentQuestionAbandonsWithoutWritingAnything(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user)->withSlug('tomate-conserve-' . $user->getId())->build());
        $this->persister->saveIngredient(new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user)->withSlug('tomate-cereale-' . $user->getId())->build());
        $this->converse(AlexaTestRequest::addItem('des tomates'));

        // When
        $this->converse(AlexaTestRequest::no(), $this->attributes());

        // Then
        $this->assertSame("D'accord, je n'ajoute rien.", $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list_row'));
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));
    }

    public function testAForgedDepartmentQuestionWithTheIngredientsOfAnotherUserAddsNothingAndRemembersNothing(): void
    {
        // Given : un état à départager dont les slugs sont ceux d'un AUTRE utilisateur
        $user = $this->linkedUser();
        $other = $this->savedUser();
        $first = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($other)->withSlug('tomate-conserve-' . $other->getId())->build();
        $second = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($other)->withSlug('tomate-cereale-' . $other->getId())->build();
        $this->persister->saveIngredient($first);
        $this->persister->saveIngredient($second);
        $forged = SpokenDialogueState::choose('des tomates', [
            new DialogueOption($first->getSlug(), 'tomate', 'conserve', 'conserve'),
            new DialogueOption($second->getSlug(), 'tomate', 'cereale', 'céréale'),
        ], 'tomate');

        // When
        $this->converse(AlexaTestRequest::answerDepartment('conserve', 'conserve'), ['dialogue' => $forged->toArray()]);

        // Then : une phrase polie, rien d'écrit
        $this->assertSame("Je n'ai pas pu faire ça. Réessaie dans un instant.", $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list_row'));
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));
        $this->assertCount(0, $this->lists->findByOwner($user->getId()));
    }

    public function testAForgedStateWithAGhostIngredientCannotWriteAnything(): void
    {
        // Given : des rayons bidons sur deux ingrédients de l'utilisateur ; le rayon dit n'est qu'un mot
        $user = $this->linkedUser();
        $mine = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user)->withSlug('tomate-conserve-' . $user->getId())->build();
        $this->persister->saveIngredient($mine);
        $forged = SpokenDialogueState::choose('des tomates', [
            new DialogueOption($mine->getSlug(), 'tomate', 'x', '<speak>x</speak>'),
            new DialogueOption('slug-fantome', 'tomate', 'y', 'y'),
        ], 'tomate');

        // When : le rayon bidon est choisi
        $this->converse(AlexaTestRequest::answerDepartment('y', 'y'), ['dialogue' => $forged->toArray()]);

        // Then : le slug fantôme est refusé, rien n'est écrit
        $this->assertSame("Je n'ai pas pu faire ça. Réessaie dans un instant.", $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list_row'));
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));
    }

    public function testAForgedStateWithTheIngredientOfAnotherUserAddsNothingRemembersNothingAndAnswersPolitely(): void
    {
        // Given : l'état désigne le pain d'un AUTRE utilisateur
        $user = $this->linkedUser();
        $theirBread = new IngredientBuilder()->isBread()->withCreator($this->savedUser(), true)->build();
        $this->persister->saveIngredient($theirBread);
        $forged = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption($theirBread->getSlug(), 'pain')], 'ciboulette');

        // When : « oui »
        $this->converse(AlexaTestRequest::yes(), ['dialogue' => $forged->toArray()]);

        // Then : une phrase polie, ni liste, ni mémoire, aucun état, et rien de l'autre n'est dit
        $this->assertSame(200, $this->statusCode());
        $this->assertSame("Je n'ai pas pu faire ça. Réessaie dans un instant.", $this->speech());
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
        $this->assertStringNotContainsString($theirBread->getSlug(), (string) $this->client->getResponse()->getContent());
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));
        $this->assertCount(0, $this->lists->findByOwner($user->getId()));
    }

    public function testAForgedConfirmationWithAnUnknownDepartmentCreatesNothing(): void
    {
        // Given : l'état de confirmation porte un rayon et une unité qui n'existent pas
        $this->linkedUser();
        $ingredientsBefore = $this->rowCount('ingredient');
        $forged = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette')->askingDepartment()
            ->withDepartment(new DialogueOption('rayon-fantome', 'fantôme'))
            ->withUnit(new DialogueOption('unite-fantome', 'fantôme'));

        // When
        $this->converse(AlexaTestRequest::yes(), ['dialogue' => $forged->toArray()]);

        // Then
        $this->assertSame("Je n'ai pas pu faire ça. Réessaie dans un instant.", $this->speech());
        $this->assertSame($ingredientsBefore, $this->rowCount('ingredient'));
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testAForgedNameAtTheConfirmationIsNormalisedBeforeTheProductIsStored(): void
    {
        // Given : l'état de confirmation porte un nom qui n'est jamais passé par le normalisateur, avec un rayon et une unité réels
        $user = $this->linkedUser();
        $this->savedCatalogue();
        $this->savedActiveList($user);
        $forged = SpokenDialogueState::choose('de la ciboulette', [], '<b>ÉVIL</b> "Ingrédient" & xxxx')->askingDepartment()
            ->withDepartment(new DialogueOption('cereale', 'céréale'))
            ->withUnit(new DialogueOption('unite', 'unité'));

        // When
        $this->converse(AlexaTestRequest::yes(), ['dialogue' => $forged->toArray()]);

        // Then : stocké sous le nom normalisé, jamais tel que reçu
        $this->assertSame("C'est créé et noté : b évil b ingrédient xxxx, rayon céréale.", $this->speech());
        $this->assertSame(1, $this->rowCount('ingredient'));
        $this->assertSame('b évil b ingrédient xxxx', $this->connection()->fetchOne('SELECT name FROM ingredient'));
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
    }

    public function testAForgedNameTheNormaliserRefusesCreatesNothingAndAsksAgain(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->savedCatalogue();
        $this->savedActiveList($user);
        $forged = SpokenDialogueState::choose('de la ciboulette', [], '12 34')->askingDepartment()
            ->withDepartment(new DialogueOption('cereale', 'céréale'))
            ->withUnit(new DialogueOption('unite', 'unité'));

        // When
        $this->converse(AlexaTestRequest::yes(), ['dialogue' => $forged->toArray()]);

        // Then : on redemande comme pour une réponse incomprise, rien n'est créé ni ajouté
        $this->assertSame("Je n'ai pas compris. Je crée 12 34, céréale, unité. C'est bon ?", $this->speech());
        $this->assertSame(1, $this->attributes()['dialogue']['misses']);
        $this->assertSame(0, $this->rowCount('ingredient'));
        $this->assertSame(0, $this->rowCount('shopping_list_row'));
    }

    public function testAForgedStateTheServerCannotReadIsIgnored(): void
    {
        // Given
        $this->linkedUser();

        // When : une étape inconnue, puis quatre propositions
        $this->converse(AlexaTestRequest::yes(), ['dialogue' => ['step' => 'hack', 'phrase' => 'x', 'name' => 'x', 'options' => []]]);
        $unknownStep = $this->speech();
        $this->converse(AlexaTestRequest::yes(), ['dialogue' => ['step' => 'choose', 'phrase' => 'x', 'name' => 'x', 'options' => array_fill(0, 4, ['slug' => 'a', 'name' => 'b'])]]);

        // Then : comme s'il n'y avait pas de dialogue — « oui » à rien : que veux-tu ajouter ?
        $this->assertSame('Que veux-tu ajouter ?', $unknownStep);
        $this->assertSame('Que veux-tu ajouter ?', $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testTheProposalsAreTheUsersOwnAndNeverShowAnotherUsersIngredients(): void
    {
        // Given : un sirop chez un autre utilisateur seulement
        $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->withName('Sirop fruit rouge')->withCreator($this->savedUser(), true)->build());

        // When
        $this->say(AlexaTestRequest::addItem('du sirop de menthe'));

        // Then : rien à proposer, il n'en a pas
        $this->assertSame('Je ne connais pas sirop de menthe. Je le crée ? Ou c\'est une recette ?', $this->speech());
        $this->assertStringNotContainsString('rouge', (string) $this->client->getResponse()->getContent());
    }

    public function testTheFlowDoesNotKeepTheSentenceAnywhereInTheDatabaseExceptTheProductAndThePhraseKeyTheUserAccepted(): void
    {
        // Given
        $this->linkedUser();
        $this->savedCatalogue();

        // When : un dialogue abandonné sur une phrase secrète
        $this->converse(AlexaTestRequest::addItem('PHRASE-SECRETE-A-NE-JAMAIS-GARDER'));
        $this->converse(AlexaTestRequest::no(), $this->attributes());

        // Then
        foreach (['voice_link', 'voice_access_grant', 'voice_authorization_code', 'shopping_list', 'spoken_phrase_choice', 'ingredient'] as $table) {
            $this->assertStringNotContainsString('SECRETE', strtoupper(json_encode($this->connection()->fetchAllAssociative("SELECT * FROM $table"), JSON_THROW_ON_ERROR)), $table);
        }
    }

    public function testARevokedLinkAbsentUnknownOrExpiredTokenAllGetTheLinkAccountCardAndNothingIsWritten(): void
    {
        // Given : du pain, et quatre façons de ne pas être lié
        $user = $this->linkedUser();
        $this->savedBread($user);
        $cases = [];
        // jeton absent
        $cases['absent'] = AlexaTestRequest::addItem('du pain')->withAccessToken(null);
        // jeton inconnu
        $cases['inconnu'] = AlexaTestRequest::addItem('du pain')->withAccessToken(AlexaTestClient::token('nobody'));
        // jeton qui n'est pas un des nôtres (un JWT, par exemple)
        $cases['jwt'] = AlexaTestRequest::addItem('du pain')->withAccessToken('eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.c2lnbmF0dXJl');

        foreach ($cases as $name => $request) {
            // When
            $this->signedPost($request);

            // Then
            $this->assertSame(200, $this->statusCode(), $name);
            $this->assertSame(['type' => 'LinkAccount'], $this->jsonBody()['response']['card'], $name);
            $this->assertSame("Ouvre l'application Alexa pour lier ton compte Repas.", $this->speech(), $name);
        }

        // Given : jeton périmé
        $expired = $this->linkedUser(seed: 'expired', issuedAt: new DateTimeImmutable('-2 hours'));
        // When
        $this->signedPost(AlexaTestRequest::addItem('du pain')->withAccessToken($this->tokenOf('expired')));
        // Then
        $this->assertSame(['type' => 'LinkAccount'], $this->jsonBody()['response']['card'], 'expiré');

        // Given : liaison retirée par l'utilisateur (son jeton a pourtant traîné)
        $revoked = $this->linkedUser(seed: 'revoked');
        $links = static::getContainer()->get(VoiceLinkRepository::class);
        $links->save($links->findOneById($this->linkIdOf('revoked'))->revoke(new DateTimeImmutable()));
        // When
        $this->signedPost(AlexaTestRequest::addItem('du pain')->withAccessToken($this->tokenOf('revoked')));
        // Then
        $this->assertSame(['type' => 'LinkAccount'], $this->jsonBody()['response']['card'], 'révoqué');

        // Then : rien n'a été écrit pour personne
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $this->assertCount(0, $this->lists->findByOwner($expired->getId()));
        $this->assertCount(0, $this->lists->findByOwner($revoked->getId()));
    }

    public function testADisabledAccountGetsTheLinkAccountCardAndNothingIsWritten(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->savedBread($user);
        $this->connection()->executeStatement('UPDATE "user" SET status = :status WHERE id = :id', ['status' => 'DISABLED', 'id' => $user->getId()]);
        $this->entityManager->clear();

        // When
        $this->say(AlexaTestRequest::addItem('du pain'));

        // Then : la requête ne distingue pas un compte inactif d'une liaison absente
        $this->assertSame(['type' => 'LinkAccount'], $this->jsonBody()['response']['card']);
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testWhenTheSwitchIsOffTheAnswerIs404AndNothingHappens(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->savedBread($user);
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(false));

        // When
        $this->say(AlexaTestRequest::addItem('du pain'));

        // Then
        $this->assertSame(404, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_DISABLED']], $this->jsonBody());
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testARequestThatIsNotProperlySignedIsRefusedWith400AndNothingIsWritten(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->savedBread($user);
        $body = AlexaTestRequest::addItem('du pain')->withAccessToken($this->tokenOf(self::ACCESS_TOKEN_SEED))->at(new DateTimeImmutable())->toJson();
        $otherLeaf = AlexaTestAuthority::root('Repas Test Other Root')->issue();
        $cases = [
            'sans signature' => [$body, AlexaTestRequest::CERT_CHAIN_URL, ''],
            'sans adresse de chaîne' => [$body, '', self::$leaf->sign($body)],
            'signature du corps modifié d\'un octet' => [$body . ' ', AlexaTestRequest::CERT_CHAIN_URL, self::$leaf->sign($body)],
            'signature d\'un autre corps' => [$body, AlexaTestRequest::CERT_CHAIN_URL, self::$leaf->sign(str_replace('du pain', 'du lait', $body))],
            'signature d\'un autre certificat' => [$body, AlexaTestRequest::CERT_CHAIN_URL, $otherLeaf->sign($body)],
            'adresse de chaîne hors d\'Amazon' => [$body, 'https://evil.example.test/echo.api/echo-api-cert.pem', self::$leaf->sign($body)],
            'adresse de chaîne hors du dossier d\'Alexa' => [$body, 'https://s3.amazonaws.com/other/echo-api-cert.pem', self::$leaf->sign($body)],
            'adresse en http' => [$body, 'http://s3.amazonaws.com/echo.api/echo-api-cert.pem', self::$leaf->sign($body)],
        ];

        foreach ($cases as $name => [$content, $chainUrl, $signature]) {
            // When
            $this->postRaw($content, $chainUrl, $signature);

            // Then : 400 et rien d'autre
            $this->assertSame(400, $this->statusCode(), $name);
            $this->assertSame(['error' => ['message' => 'INVALID_ALEXA_REQUEST']], $this->jsonBody(), $name);
        }
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $this->assertCount(0, $this->lists->findByOwner($user->getId()));
    }

    public function testARequestForAnotherSkillOrAStaleOneIsRefusedEvenIfCorrectlySigned(): void
    {
        // Given
        $this->linkedUser();
        $token = $this->tokenOf(self::ACCESS_TOKEN_SEED);

        // Chaque requête est construite au moment de l'envoi : l'horodatage à 151 s ne doit pas glisser sous la limite de 150 s pendant que les
        // requêtes précédentes sont traitées (suite complète, machine chargée)
        foreach ([
            'autre skill' => static fn(): AlexaTestRequest => AlexaTestRequest::addItem('du pain')->withAccessToken($token)->withApplicationId('amzn1.ask.skill.00000000-other-0000-0000-000000000002'),
            'horodatage vieux de 151 s' => static fn(): AlexaTestRequest => AlexaTestRequest::addItem('du pain')->withAccessToken($token)->at(new DateTimeImmutable('-151 seconds')),
            'horodatage dans 151 s' => static fn(): AlexaTestRequest => AlexaTestRequest::addItem('du pain')->withAccessToken($token)->at(new DateTimeImmutable('+151 seconds')),
        ] as $name => $buildRequest) {
            // When
            $this->signedPost($buildRequest());

            // Then
            $this->assertSame(400, $this->statusCode(), $name);
        }
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testTheFirstRequestRemembersTheAlexaUserAndTheLastUseAndRetiresTheOlderLinkOfTheSameEcho(): void
    {
        // Given : le même Echo relié deux fois — une ancienne liaison (utilisée, avec ses jetons) et la nouvelle
        $user = $this->linkedUser();
        $old = $this->linkedTo($user, seed: 'old', alexaUserId: AlexaTestRequest::USER_ID, createdAt: new DateTimeImmutable('-30 days'));

        // When
        $this->say(AlexaTestRequest::launch());

        // Then
        $this->entityManager->clear();
        $links = static::getContainer()->get(VoiceLinkRepository::class);
        $current = $links->findOneById($this->linkIdOf(self::ACCESS_TOKEN_SEED));
        $this->assertSame(AlexaTestRequest::USER_ID, $current->getAlexaUserId());
        $this->assertEqualsWithDelta(time(), $current->getLastUsedAt()->getTimestamp(), 5);
        $this->assertFalse($current->isRevoked());
        $this->assertTrue($links->findOneById($old)->isRevoked());
        $this->assertNull($this->connection()->fetchOne('SELECT alexa_user_id FROM voice_link WHERE id = ?', [$old]), "l'ancienne liaison n'a plus d'identifiant d'utilisateur Alexa");
        $this->assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM voice_access_grant WHERE link_id = ?', [$this->linkIdOf(self::ACCESS_TOKEN_SEED)]));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM voice_access_grant WHERE link_id = ?', [$old]));

        // When : la liaison retirée ne sert plus
        $this->signedPost(AlexaTestRequest::launch()->withAccessToken($this->tokenOf('old')));

        // Then
        $this->assertSame(['type' => 'LinkAccount'], $this->jsonBody()['response']['card']);
    }

    public function testASecondRequestWithinTenMinutesDoesNotRewriteTheDate(): void
    {
        // Given : une liaison déjà suivie, utilisée il y a une minute
        $this->linkedUser();
        $this->say(AlexaTestRequest::launch());
        $first = $this->connection()->fetchOne('SELECT last_used_at FROM voice_link');
        $this->connection()->executeStatement("UPDATE voice_link SET last_used_at = last_used_at - interval '1 minute'");
        $before = $this->connection()->fetchOne('SELECT last_used_at FROM voice_link');

        // When
        $this->say(AlexaTestRequest::launch());

        // Then : la date n'a pas bougé
        $this->assertNotSame($first, $before);
        $this->assertSame($before, $this->connection()->fetchOne('SELECT last_used_at FROM voice_link'));
    }

    public function testTheBodyThatCarriesTheVoiceIsNotKeptAnywhereInTheDatabaseExceptTheItemAddedToTheList(): void
    {
        // Given : une phrase dite qui ne correspond à rien
        $this->linkedUser();

        // When
        $this->say(AlexaTestRequest::addItem('PHRASE-SECRETE-A-NE-JAMAIS-GARDER'));

        // Then : elle est redite à l'utilisateur, mais n'est écrite nulle part (pas de transcription conservée, ADR 0029 décision 10)
        $this->assertStringContainsString('phrase-secrete-a-ne-jamais-garder', $this->speech());
        foreach (['voice_link', 'voice_access_grant', 'voice_authorization_code', 'shopping_list'] as $table) {
            $this->assertStringNotContainsString('SECRETE', json_encode($this->connection()->fetchAllAssociative("SELECT * FROM $table"), JSON_THROW_ON_ERROR), $table);
        }
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Retirer de la liste et demander ce qu'elle contient (ADR 0029 décision 12, VOX-304)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testRemovingOneCroquetteLowersTheLineAndAlexaSaysWhatIsLeft(): void
    {
        // Given : trois croquettes dans la liste active
        $user = $this->linkedUser();
        $croquettes = new IngredientBuilder()->withName('croquettes')->withCreator($user, true)->build();
        $this->persister->saveIngredient($croquettes);
        $list = $this->savedListWith($user, [[$croquettes, 3]]);

        // When : « Alexa, demande à Jarvis d'enlever des croquettes »
        $this->say(AlexaTestRequest::removeItem('des croquettes'));

        // Then : un de moins, il en reste 2 ; la séance se ferme (appel direct) ; la liste a une version de plus
        $this->assertSame(200, $this->statusCode());
        $this->assertSame(['version' => '1.0', 'response' => [
            'outputSpeech' => ['type' => 'PlainText', 'text' => "C'est retiré, il t'en reste 2 dans ta liste."],
            'shouldEndSession' => true,
        ]], $this->jsonBody());
        $this->entityManager->clear();
        $reloaded = $this->lists->findOneById($list->getId());
        $this->assertSame(2.0, $reloaded->getRows()->reset()->getQuantity());
        $this->assertSame($list->getVersion() + 1, $reloaded->getVersion());
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'), 'retirer ne retient jamais une phrase');
    }

    public function testTheQuantityTypeIdentifierSaysHowManyToRemoveWhateverTheHeardWord(): void
    {
        // Given : dix croquettes dans la liste
        $user = $this->linkedUser();
        $croquettes = new IngredientBuilder()->withName('croquettes')->withCreator($user, true)->build();
        $this->persister->saveIngredient($croquettes);
        $list = $this->savedListWith($user, [[$croquettes, 10]]);

        // When : « enlève trois paquets de croquettes » : Amazon rend « trois » rapproché de la valeur « 3 » du type QUANTITY
        $this->say(AlexaTestRequest::removeItem('croquettes')->withSlot('quantity', 'trois', '3'));

        // Then : trois de moins
        $this->assertSame("C'est retiré, il t'en reste 7 dans ta liste.", $this->speech());
        $this->entityManager->clear();
        $this->assertSame(7.0, $this->lists->findOneById($list->getId())->getRows()->reset()->getQuantity());
    }

    public function testANumberAmazonLeftInTheItemSlotIsTheQuantityToRemove(): void
    {
        // Given : dix croquettes
        $user = $this->linkedUser();
        $croquettes = new IngredientBuilder()->withName('croquettes')->withCreator($user, true)->build();
        $this->persister->saveIngredient($croquettes);
        $list = $this->savedListWith($user, [[$croquettes, 10]]);

        // When : « enlève 4 croquettes », le nombre est resté collé au produit
        $this->say(AlexaTestRequest::removeItem('4 croquettes'));

        // Then
        $this->assertSame("C'est retiré, il t'en reste 6 dans ta liste.", $this->speech());
        $this->entityManager->clear();
        $this->assertSame(6.0, $this->lists->findOneById($list->getId())->getRows()->reset()->getQuantity());
    }

    public function testRemovingMoreThanTheLineHoldsDropsTheLineAndSaysSo(): void
    {
        // Given : deux croquettes et un pain
        $user = $this->linkedUser();
        $croquettes = new IngredientBuilder()->withName('croquettes')->withCreator($user, true)->build();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($croquettes);
        $this->persister->saveIngredient($bread);
        $list = $this->savedListWith($user, [[$croquettes, 2], [$bread, 1]]);

        // When : « enlève cinq croquettes »
        $this->say(AlexaTestRequest::removeItem('croquettes')->withSlot('quantity', 'cinq', '5'));

        // Then : la ligne disparaît (jamais de quantité négative), le pain reste
        $this->assertSame("C'est retiré : 2 croquettes. Il n'y en a plus dans ta liste.", $this->speech());
        $this->entityManager->clear();
        $rows = $this->lists->findOneById($list->getId())->getRows();
        $this->assertCount(1, $rows);
        $this->assertSame($bread->getSlug(), $rows->reset()->getIngredientSlug());
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
    }

    public function testRemovingWithoutAnyListSaysSoAndCreatesNothing(): void
    {
        // Given : un utilisateur sans liste
        $user = $this->linkedUser();
        $this->savedBread($user);

        // When
        $this->say(AlexaTestRequest::removeItem('du pain'));

        // Then : « pas de liste en cours », aucune liste créée
        $this->assertSame("Tu n'as pas de liste en cours.", $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testRemovingNeverTouchesAListSentToTheDrive(): void
    {
        // Given : la seule liste est partie au drive avec du pain
        $user = $this->linkedUser();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        $sent = new ShoppingListBuilder()->withOwner($user)->addIngredient($bread)->withStatus(ShoppingListStatus::SENT)->build();
        $this->lists->save($sent);
        $this->entityManager->clear();

        // When
        $this->say(AlexaTestRequest::removeItem('du pain'));

        // Then : la liste envoyée n'est ni lue ni modifiée, aucune autre n'est créée, et la phrase dit pourquoi (pas « pas de liste en cours »)
        $this->assertSame('Ta liste est déjà partie au drive.', $this->speech());
        $this->entityManager->clear();
        $reloaded = $this->lists->findOneById($sent->getId());
        $this->assertSame([ShoppingListStatus::SENT, $sent->getVersion(), 1], [$reloaded->getStatus(), $reloaded->getVersion(), $reloaded->getRows()->count()]);
        $this->assertSame(1, $this->rowCount('shopping_list'));
    }

    public function testRemovingAProductThatIsNotInTheListIsSaidAndAnUnknownOneAsksIfItIsAMealWithoutCreatingAnything(): void
    {
        // Given : la liste a du pain ; la farine est connue mais absente de la liste ; la ciboulette est inconnue
        $user = $this->linkedUser();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $flour = new IngredientBuilder()->isFlour()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        $this->persister->saveIngredient($flour);
        $list = $this->savedListWith($user, [[$bread, 2]]);
        $ingredients = $this->rowCount('ingredient');

        // When
        $this->say(AlexaTestRequest::removeItem('de la farine'));
        $absent = $this->speech();
        $this->say(AlexaTestRequest::removeItem('de la ciboulette'));
        $unknown = $this->speech();

        // Then : un produit reconnu mais absent se dit, définitif ; un produit inconnu propose l'autre type, sans « de quoi tu parles ? » ni création
        $this->assertSame("Il n'y a pas de farine dans ta liste.", $absent);
        $this->assertSame("Il n'y a pas de ciboulette dans ta liste. C'est un repas ?", $unknown);
        $this->assertSame('remove', $this->attributes()['dialogue']['action']);
        $this->assertSame($ingredients, $this->rowCount('ingredient'));
        $this->entityManager->clear();
        $this->assertSame([2.0, $list->getVersion()], [$this->lists->findOneById($list->getId())->getRows()->reset()->getQuantity(), $this->lists->findOneById($list->getId())->getVersion()]);
    }

    public function testInAnOpenSessionARemovalOrACheckIsAStatementThatLeavesTheSessionOpenWithoutAReprompt(): void
    {
        // Given : « Alexa, ouvre Jarvis », puis « enlève du pain », puis « est-ce qu'il y a du pain ? »
        $user = $this->linkedUser();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        $this->savedListWith($user, [[$bread, 3]]);

        // When
        $this->say(AlexaTestRequest::removeItem('du pain')->inOpenSession());
        $removal = $this->jsonBody()['response'];
        $this->say(AlexaTestRequest::checkItem('du pain')->inOpenSession());
        $check = $this->jsonBody()['response'];

        // Then : jamais de relance
        $this->assertSame(["C'est retiré, il t'en reste 2 dans ta liste.", false], [$removal['outputSpeech']['text'], $removal['shouldEndSession']]);
        $this->assertArrayNotHasKey('reprompt', $removal);
        $this->assertSame(['Oui, tu en as 2.', false], [$check['outputSpeech']['text'], $check['shouldEndSession']]);
        $this->assertArrayNotHasKey('reprompt', $check);
    }

    public function testRemovingWithoutAnItemGivesAnExampleAndWritesNothing(): void
    {
        // Given
        $user = $this->linkedUser();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        $list = $this->savedListWith($user, [[$bread, 2]]);

        // When : « enlève » sans rien dire d'autre
        $this->say(AlexaTestRequest::removeItem(null));

        // Then : un exemple, pas une question ouverte (« du pain » ensuite serait entendu comme un ajout)
        $this->assertSame('Dis par exemple : enlève du pain.', $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->entityManager->clear();
        $this->assertSame(2.0, $this->lists->findOneById($list->getId())->getRows()->reset()->getQuantity());
    }

    public function testTwoTomatoesBothInTheListAskForTheDepartmentThenRemoveFromTheChosenOneAndRememberNothing(): void
    {
        // Given : « tomate » en conserve et en céréale (rayons du test), les deux dans la liste (2 et 4)
        $user = $this->linkedUser();
        $canned = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user)->withSlug('tomate-conserve-' . $user->getId())->build();
        $cereal = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user)->withSlug('tomate-cereale-' . $user->getId())->build();
        $this->persister->saveIngredient($canned);
        $this->persister->saveIngredient($cereal);
        $list = $this->savedListWith($user, [[$canned, 2], [$cereal, 4]]);

        // When : « enlève des tomates »
        $this->say(AlexaTestRequest::removeItem('des tomates'));

        // Then : Alexa demande le rayon, sans « un nouveau produit » ; rien n'est retiré, la séance reste ouverte
        $this->assertMatchesRegularExpression('/^Tomate : en (céréale|conserve) ou en (céréale|conserve) \?$/u', $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame('remove', $this->attributes()['dialogue']['action']);
        $this->assertCount(2, $this->attributes()['dialogue']['options']);
        $this->entityManager->clear();
        $this->assertSame(6.0, array_sum(array_map(static fn($row): float => $row->getQuantity(), $this->lists->findOneById($list->getId())->getRows()->toArray())));

        // When : « en conserve »
        $this->converse(AlexaTestRequest::answerDepartment('conserve', 'conserve'), $this->attributes());

        // Then : la tomate en conserve perd un, l'autre ne bouge pas, rien n'est retenu, la séance se ferme sans relance
        $this->assertSame("C'est retiré, il t'en reste 1 dans ta liste.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));
        $this->entityManager->clear();
        $quantities = [];
        foreach ($this->lists->findOneById($list->getId())->getRows() as $row) {
            $quantities[$row->getIngredientSlug()] = $row->getQuantity();
        }
        $this->assertEquals([$canned->getSlug() => 1.0, $cereal->getSlug() => 4.0], $quantities);
    }

    public function testRefusingToTellTheTwoLinesApartRemovesNothing(): void
    {
        // Given
        $user = $this->linkedUser();
        $canned = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user)->withSlug('tomate-conserve-' . $user->getId())->build();
        $cereal = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user)->withSlug('tomate-cereale-' . $user->getId())->build();
        $this->persister->saveIngredient($canned);
        $this->persister->saveIngredient($cereal);
        $list = $this->savedListWith($user, [[$canned, 2], [$cereal, 4]]);
        $this->say(AlexaTestRequest::removeItem('des tomates'));

        // When : « non »
        $this->converse(AlexaTestRequest::no(), $this->attributes());

        // Then
        $this->assertSame("D'accord, je ne retire rien.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->entityManager->clear();
        $this->assertSame(6.0, array_sum(array_map(static fn($row): float => $row->getQuantity(), $this->lists->findOneById($list->getId())->getRows()->toArray())));
    }

    public function testOnlyTheTomatoInTheListIsRemovedWithoutAnyQuestion(): void
    {
        // Given : deux « tomate » connues, une seule dans la liste (en céréale)
        $user = $this->linkedUser();
        $canned = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user)->withSlug('tomate-conserve-' . $user->getId())->build();
        $cereal = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user)->withSlug('tomate-cereale-' . $user->getId())->build();
        $this->persister->saveIngredient($canned);
        $this->persister->saveIngredient($cereal);
        $list = $this->savedListWith($user, [[$cereal, 3]]);

        // When
        $this->say(AlexaTestRequest::removeItem('des tomates'));

        // Then : pas de question, celle de la liste perd un
        $this->assertSame("C'est retiré, il t'en reste 2 dans ta liste.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->entityManager->clear();
        $this->assertSame(2.0, $this->lists->findOneById($list->getId())->getRows()->reset()->getQuantity());
    }

    public function testAForgedRemovalDialogueCannotRemoveFromTheListOfAnotherUser(): void
    {
        // Given : un autre utilisateur a 5 croquettes dans sa liste ; l'état forgé nomme son ingrédient
        $user = $this->linkedUser();
        $this->savedActiveList($user);
        $other = $this->savedUser();
        $theirs = new IngredientBuilder()->withName('croquettes')->withCreator($other, true)->build();
        $this->persister->saveIngredient($theirs);
        $theirList = $this->savedListWith($other, [[$theirs, 5]]);
        $forged = SpokenDialogueState::chooseToRemove('des croquettes', [
            new DialogueOption($theirs->getSlug(), 'croquettes', 'animal', 'animal'),
            new DialogueOption('fantome', 'croquettes', 'conserve', 'conserve'),
        ], 'croquettes');

        // When : « en animal »
        $this->converse(AlexaTestRequest::answerDepartment('animal', 'animal'), [SpokenDialogueState::ATTRIBUTE => $forged->toArray()]);

        // Then : la liste de l'utilisateur n'a pas cette ligne, celle de l'autre n'est jamais lue ni écrite
        $this->assertSame("Il n'y a pas de croquettes dans ta liste.", $this->speech());
        $this->entityManager->clear();
        $this->assertSame([5.0, $theirList->getVersion()], [$this->lists->findOneById($theirList->getId())->getRows()->reset()->getQuantity(), $this->lists->findOneById($theirList->getId())->getVersion()]);
    }

    public function testAskingIfTheListHoldsAProductAnswersYesWithTheQuantityOrNoAndWritesNothing(): void
    {
        // Given : deux paquets de croquettes dans la liste, du pain connu mais absent
        $user = $this->linkedUser();
        $croquettes = new IngredientBuilder()->withName('croquettes')->withCreator($user, true)->build();
        $this->persister->saveIngredient($croquettes);
        $this->savedBread($user);
        $list = $this->savedListWith($user, [[$croquettes, 2]]);

        // When
        $this->say(AlexaTestRequest::checkItem('des croquettes'));
        $yes = $this->speech();
        $this->say(AlexaTestRequest::checkItem('du pain'));
        $no = $this->speech();
        $this->say(AlexaTestRequest::checkItem('de la ciboulette'));
        $unknown = $this->speech();

        // Then : une lecture : aucune écriture, la version de la liste ne bouge pas
        $this->assertSame('Oui, tu en as 2.', $yes);
        $this->assertSame("Non, il n'y a pas de pain dans ta liste.", $no);
        $this->assertSame("Non, il n'y a pas de ciboulette dans ta liste.", $unknown);
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->entityManager->clear();
        $reloaded = $this->lists->findOneById($list->getId());
        $this->assertSame([$list->getVersion(), 2.0], [$reloaded->getVersion(), $reloaded->getRows()->reset()->getQuantity()]);
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));
    }

    public function testAskingWithoutAnyListSaysSoAndCreatesNone(): void
    {
        // Given
        $user = $this->linkedUser();
        $this->savedBread($user);

        // When
        $this->say(AlexaTestRequest::checkItem('du pain'));

        // Then
        $this->assertSame("Tu n'as pas de liste en cours.", $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testAskingAboutTwoTomatoesBothInTheListAnswersForBothDepartmentsAtOnce(): void
    {
        // Given : « tomate » en conserve (2) et en céréale (4), les deux dans la liste
        $user = $this->linkedUser();
        $canned = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user)->withSlug('tomate-conserve-' . $user->getId())->build();
        $cereal = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user)->withSlug('tomate-cereale-' . $user->getId())->build();
        $this->persister->saveIngredient($canned);
        $this->persister->saveIngredient($cereal);
        $this->savedListWith($user, [[$canned, 2], [$cereal, 4]]);

        // When
        $this->say(AlexaTestRequest::checkItem('des tomates'));

        // Then : une phrase pour les deux rayons, pas de question, la séance se ferme
        $this->assertMatchesRegularExpression('/^Oui : (2 tomates en conserve, 4 en céréale|4 tomates en céréale, 2 en conserve)\.$/u', $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
    }

    public function testASentListIsNotConsultedEither(): void
    {
        // Given
        $user = $this->linkedUser();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        $this->lists->save(new ShoppingListBuilder()->withOwner($user)->addIngredient($bread)->withStatus(ShoppingListStatus::SENT)->build());
        $this->entityManager->clear();

        // When
        $this->say(AlexaTestRequest::checkItem('du pain'));

        // Then : limite connue, la liste envoyée n'est pas lue ; la phrase le dit, sans prétendre que la liste n'existe pas
        $this->assertSame('Ta liste est déjà partie au drive.', $this->speech());
    }

    public function testTheTomatoOfTheListIsRemovedAndCountedEvenWhenTheHabitsPickTheOtherOne(): void
    {
        // Given : « tomate » en conserve, utilisée dans six listes terminées (l'habitude tranche : `PROBABLE`), et en céréale, seule dans la liste active (2)
        $user = $this->linkedUser();
        $canned = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user)->withSlug('tomate-conserve-' . $user->getId())->build();
        $cereal = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user)->withSlug('tomate-cereale-' . $user->getId())->build();
        $this->persister->saveIngredient($canned);
        $this->persister->saveIngredient($cereal);
        for ($index = 0; $index < 6; ++$index) {
            $this->lists->save(new ShoppingListBuilder()->withOwner($user)->addIngredient($canned)->withStatus(ShoppingListStatus::COMPLETED)->build());
        }
        $list = $this->savedListWith($user, [[$cereal, 2]]);

        // When : « est-ce qu'il y a des tomates ? » puis « enlève des tomates »
        $this->say(AlexaTestRequest::checkItem('des tomates'));
        $check = $this->speech();
        $this->say(AlexaTestRequest::removeItem('des tomates'));
        $removal = $this->speech();

        // Then : la ligne de la liste, pas « pas de tomate dans ta liste »
        $this->assertSame('Oui, tu en as 2.', $check);
        $this->assertSame("C'est retiré, il t'en reste 1 dans ta liste.", $removal);
        $this->entityManager->clear();
        $this->assertSame(1.0, $this->lists->findOneById($list->getId())->getRows()->reset()->getQuantity());
    }

    public function testTheDuplicateOfTheListIsRemovedEvenWhenItIsNotTheFirstOne(): void
    {
        // Given : deux « tomate » de même nom ET de même rayon (non fusionnées), seule la seconde est dans la liste (3)
        $user = $this->linkedUser();
        $first = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user)->withSlug('tomate-a-' . $user->getId())->build();
        $second = new IngredientBuilder()->withName('tomate')->withDepartment(new DepartmentBuilder()->isConserve())->withCreator($user)->withSlug('tomate-b-' . $user->getId())->build();
        $this->persister->saveIngredient($first);
        $this->persister->saveIngredient($second);
        // La première est un peu plus utilisée (deux listes terminées) : le classeur la préfère, mais elle n'est pas dans la liste active
        for ($index = 0; $index < 2; ++$index) {
            $this->lists->save(new ShoppingListBuilder()->withOwner($user)->addIngredient($first)->withStatus(ShoppingListStatus::COMPLETED)->build());
        }
        $list = $this->savedListWith($user, [[$second, 3]]);

        // When
        $this->say(AlexaTestRequest::removeItem('des tomates'));

        // Then : celle de la liste perd un, aucune question
        $this->assertSame("C'est retiré, il t'en reste 2 dans ta liste.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->entityManager->clear();
        $this->assertSame([$second->getSlug(), 2.0], [$this->lists->findOneById($list->getId())->getRows()->reset()->getIngredientSlug(), $this->lists->findOneById($list->getId())->getRows()->reset()->getQuantity()]);
    }

    public function testARemovalSaidInTheMiddleOfAQuestionIsDoneAndTheDialogueIsDropped(): void
    {
        // Given : du pain dans la liste (3), puis « de la ciboulette » ouvre le dialogue d'un produit inconnu
        $user = $this->linkedUser();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        $list = $this->savedListWith($user, [[$bread, 3]]);
        $this->say(AlexaTestRequest::addItem('de la ciboulette'));
        $ingredients = $this->rowCount('ingredient');

        // When : « enlève du pain » à la question
        $this->converse(AlexaTestRequest::removeItem('du pain'), $this->attributes());

        // Then : le retrait est fait, la séance se ferme, plus d'état, aucun produit créé
        $this->assertSame("C'est retiré, il t'en reste 2 dans ta liste.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
        $this->assertSame($ingredients, $this->rowCount('ingredient'));
        $this->entityManager->clear();
        $this->assertSame(2.0, $this->lists->findOneById($list->getId())->getRows()->reset()->getQuantity());
    }

    public function testACheckSaidInTheMiddleOfAQuestionIsAnsweredAndTheDialogueIsDropped(): void
    {
        // Given
        $user = $this->linkedUser();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        $list = $this->savedListWith($user, [[$bread, 3]]);
        $this->say(AlexaTestRequest::addItem('de la ciboulette'));

        // When : « est-ce qu'il y a du pain dans la liste ? » à la question
        $this->converse(AlexaTestRequest::checkItem('du pain'), $this->attributes());

        // Then : la réponse, la séance se ferme, rien n'est écrit
        $this->assertSame('Oui, tu en as 3.', $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
        $this->entityManager->clear();
        $this->assertSame([3.0, $list->getVersion()], [$this->lists->findOneById($list->getId())->getRows()->reset()->getQuantity(), $this->lists->findOneById($list->getId())->getVersion()]);
    }

    /**
     * Une liste active de `$owner` dont les lignes sont celles de `$lines` (un ingrédient et la quantité de sa ligne, comme si elle avait été
     * ajoutée à la main).
     *
     * @param list<array{Ingredient, int}> $lines
     */
    private function savedListWith(User $owner, array $lines): ShoppingList
    {
        $list = new ShoppingListBuilder()->withOwner($owner)->build();
        foreach ($lines as [$ingredient, $quantity]) {
            $unit = $ingredient->getDefaultPurchaseUnit()->getSlug();
            $list->addIngredient($ingredient->getSlug(), $unit, $quantity);
            $list->addRow($ingredient->getSlug(), $unit, $quantity);
        }
        $this->lists->save($list);
        $this->entityManager->clear();

        return $this->lists->findOneById($list->getId());
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

// ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Planifier un repas (ADR 0029 décision 13, VOX-305)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * Un utilisateur lié dont le compte compte `$serving` personnes.
     */
    private function linkedUserFor(int $serving): User
    {
        $user = new UserBuilder()->withServing($serving)->build();
        $this->persister->saveUser($user);
        $this->linkedTo($user, self::ACCESS_TOKEN_SEED);

        return $user;
    }

    /**
     * Une recette de `$author` nommée `$name`, avec les conversions de ses ingrédients vers leur unité d'achat.
     */
    private function savedRecipe(User $author, string $name, bool $withConversions = true): Recipe
    {
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withName($name)->withAuthor($author)->build();
        foreach ($recipe->getRows() as $row) {
            $row->getIngredient()->setCreatorId($author->getId());
        }
        $this->persister->saveRecipe($recipe);
        if ($withConversions) {
            foreach ($recipe->getRows() as $row) {
                $purchaseUnit = $row->getIngredient()->getDefaultPurchaseUnit();
                if (!$row->getUnit()->isEqual($purchaseUnit)) {
                    $this->persister->saveConversion(new ConversionBuilder()->withIngredient($row->getIngredient())->withStartUnit($purchaseUnit)->withEndUnit($row->getUnit())->withCoefficient(100.0)->build());
                }
            }
        }
        $this->entityManager->clear();

        return $recipe;
    }

    /**
     * Le repas de cette recette dans la liste active de l'utilisateur, `null` s'il n'y est pas.
     */
    private function mealOf(User $user, Recipe $recipe): ?Meal
    {
        $this->entityManager->clear();
        $list = $this->lists->findOneActivateByOwner($user->getId());

        return $list?->getMeals()->find(static fn(Meal $meal): bool => $meal->getRecipeId() === $recipe->getId());
    }

    public function testAMealIsPlannedForTheServingsOfTheAccountAndAlexaSaysSo(): void
    {
        // Given : un compte pour trois personnes, la recette « hachis parmentier » et une liste active
        $user = $this->linkedUserFor(3);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $list = $this->savedActiveList($user);

        // When : « Alexa, demande à Jarvis de planifier le repas hachis parmentier »
        $this->say(AlexaTestRequest::addMeal('le repas hachis parmentier'));

        // Then : planifié pour trois personnes, dans la liste existante, la séance se ferme sans relance
        $this->assertSame(["C'est noté : hachis parmentier, pour 3 personnes.", true], [$this->speech(), $this->jsonBody()['response']['shouldEndSession']]);
        $this->assertArrayNotHasKey('reprompt', $this->jsonBody()['response']);
        $this->assertSame(3, $this->mealOf($user, $recipe)?->getServing());
        $this->assertCount(1, $this->lists->findByOwner($user->getId()));
        $this->assertSame($list->getId(), $this->lists->findOneActivateByOwner($user->getId())->getId());
        $this->assertSame(0, $this->rowCount('spoken_recipe_phrase_choice'), 'un nom reconnu ne retient rien');
    }

    public function testTheServingsSaidInThePhraseOrInTheSlotAreForced(): void
    {
        // Given
        $user = $this->linkedUserFor(3);
        $first = $this->savedRecipe($user, 'Hachis parmentier');
        $second = $this->savedRecipe($user, 'Lasagnes');
        $this->savedActiveList($user);

        // When : « … pour six personnes » dit dans le nom, puis le créneau `servings`
        $this->say(AlexaTestRequest::addMeal('hachis parmentier pour six personnes'));
        $inPhrase = $this->speech();
        $this->say(AlexaTestRequest::addMeal('lasagnes', 'huit', '8'));

        // Then
        $this->assertSame("C'est noté : hachis parmentier, pour 6 personnes.", $inPhrase);
        $this->assertSame("C'est noté : lasagnes, pour 8 personnes.", $this->speech());
        $this->assertSame(6, $this->mealOf($user, $first)?->getServing());
        $this->assertSame(8, $this->mealOf($user, $second)?->getServing());
    }

    public function testAMealAnnouncedByTheWordButRangedInTheProductIntentIsStillAMeal(): void
    {
        // Given
        $user = $this->linkedUserFor(2);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');

        // When : « ajoute la recette hachis parmentier » qu'Amazon a rangé dans `AddItemIntent`
        $this->say(AlexaTestRequest::addItem('la recette hachis parmentier'));

        // Then : une liste est commencée pour ce repas
        $this->assertSame("J'ai commencé une nouvelle liste avec hachis parmentier, pour 2 personnes.", $this->speech());
        $this->assertSame(2, $this->mealOf($user, $recipe)?->getServing());
    }

    public function testWithoutTheWordAnAddIsAProductAndAskingForARecipeIsOneWordAway(): void
    {
        // Given : une recette, aucun produit de ce nom
        $user = $this->linkedUserFor(3);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->savedActiveList($user);
        $ingredientsBefore = $this->rowCount('ingredient');

        // When : « ajoute un hachis parmentier » (un produit)
        $this->say(AlexaTestRequest::addItem('un hachis parmentier'));

        // Then : un produit inconnu, et Alexa propose aussi « une recette » ; rien n'est écrit
        $this->assertSame('Je ne connais pas hachis parmentier. Je le crée ? Ou c\'est une recette ?', $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list_row'));

        // When : « une recette »
        $this->converse(AlexaTestRequest::chooseKind('une recette', 'meal'), $this->attributes());

        // Then : cherché dans les recettes avec la phrase d'origine, planifié, la séance se ferme
        $this->assertSame("C'est noté : hachis parmentier, pour 3 personnes.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame(3, $this->mealOf($user, $recipe)?->getServing());
        $this->assertSame($ingredientsBefore, $this->rowCount('ingredient'), 'aucun produit n\'est créé');
    }

    public function testTheWordRecipeSaidAsAPlainTextAnswerIsEnoughWhateverTheIntent(): void
    {
        // Given
        $user = $this->linkedUserFor(3);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->savedActiveList($user);
        $this->say(AlexaTestRequest::addItem('un hachis parmentier'));

        // When : Amazon range « c'est une recette » dans l'intention des produits
        $this->converse(AlexaTestRequest::addItem("c'est une recette"), $this->attributes());

        // Then
        $this->assertSame("C'est noté : hachis parmentier, pour 3 personnes.", $this->speech());
        $this->assertNotNull($this->mealOf($user, $recipe));
    }

    public function testAnUnknownRecipeProposesTheThreeClosestAndTheChoiceIsPlannedAndRemembered(): void
    {
        // Given : deux gratins, aucun nommé « gratin » ; celui de l'autre utilisateur n'est jamais proposé
        $user = $this->linkedUserFor(4);
        $dauphinois = $this->savedRecipe($user, 'Gratin dauphinois');
        $this->savedRecipe($user, 'Gratin de pâtes');
        $this->savedRecipe($this->savedUser(), 'Gratin de courgettes');

        // When : « planifie le gratin »
        $this->say(AlexaTestRequest::addMeal('gratin'));

        // Then : les deux recettes de l'utilisateur et l'issue « un produit », l'état voyage, rien n'est écrit, aucune recette n'est créée
        $this->assertSame('Je ne trouve pas la recette gratin. Tu veux dire gratin de pâtes ou gratin dauphinois ? Ou un produit ?', $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame('meal', $this->attributes()['dialogue']['target']);
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $recipesBefore = $this->rowCount('recipe');

        // When : « le deuxième »
        $this->converse(AlexaTestRequest::chooseOption('le deuxième', '2'), $this->attributes());

        // Then : planifié, la phrase retenue pour cette recette, rien de plus
        $this->assertSame("J'ai commencé une nouvelle liste avec gratin dauphinois, pour 4 personnes. Je m'en souviendrai.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame(4, $this->mealOf($user, $dauphinois)?->getServing());
        $this->assertSame(
            [[$user->getId(), 'gratin', $dauphinois->getId(), 1]],
            array_map(static fn(array $row): array => [$row['user_id'], $row['phrase'], $row['recipe_id'], (int) $row['times']], $this->connection()->fetchAllAssociative('SELECT * FROM spoken_recipe_phrase_choice')),
        );
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'), 'la mémoire des produits ne bouge pas');
        $this->assertSame($recipesBefore, $this->rowCount('recipe'));
    }

    public function testARecipeAlreadyInTheListIsNeverPlannedTwiceAndTheTotalIsAskedThenReplaced(): void
    {
        // Given : le repas est déjà planifié pour trois personnes
        $user = $this->linkedUserFor(3);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->say(AlexaTestRequest::addMeal('hachis parmentier'));
        $this->assertSame(3, $this->mealOf($user, $recipe)?->getServing());
        $versionBefore = $this->lists->findOneActivateByOwner($user->getId())->getVersion();

        // When : on le redit, même avec « pour six »
        $this->say(AlexaTestRequest::addMeal('hachis parmentier pour six personnes'));

        // Then : on dit qu'il y est et pour combien ; la séance reste ouverte ; rien n'est écrit
        $this->assertSame('Le repas hachis parmentier est déjà prévu pour 3 personnes. Tu le veux pour combien, ou on ne change rien ?', $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame('servings', $this->attributes()['dialogue']['step']);
        $this->entityManager->clear();
        $this->assertSame($versionBefore, $this->lists->findOneActivateByOwner($user->getId())->getVersion());

        // When : « six »
        $this->converse(AlexaTestRequest::answerServings('six', '6'), $this->attributes());

        // Then : le nouveau total (6), pas une addition (9) ; toujours UN seul repas
        $this->assertSame("C'est changé : hachis parmentier, pour 6 personnes.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame(6, $this->mealOf($user, $recipe)?->getServing());
        $this->assertCount(1, $this->lists->findOneActivateByOwner($user->getId())->getMeals());
    }

    #[DataProvider('answersThatChangeNothing')]
    public function testTheSameNumberNoOrNothingChangesNothing(AlexaTestRequest $answer): void
    {
        // Given : planifié pour trois, puis redit
        $user = $this->linkedUserFor(3);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->say(AlexaTestRequest::addMeal('hachis parmentier'));
        $this->say(AlexaTestRequest::addMeal('hachis parmentier'));
        $this->entityManager->clear();
        $versionBefore = $this->lists->findOneActivateByOwner($user->getId())->getVersion();

        // When
        $this->converse($answer, $this->attributes());

        // Then
        $this->assertSame("D'accord, je ne change rien.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame(3, $this->mealOf($user, $recipe)?->getServing());
        $this->assertSame($versionBefore, $this->lists->findOneActivateByOwner($user->getId())->getVersion());
    }

    /**
     * @return iterable<string, array{AlexaTestRequest}>
     */
    public static function answersThatChangeNothing(): iterable
    {
        yield 'le même nombre' => [AlexaTestRequest::answerServings('trois', '3')];
        yield 'non' => [AlexaTestRequest::no()];
        yield 'rien' => [AlexaTestRequest::keepMeal()];
        yield 'rien, dit comme un produit' => [AlexaTestRequest::addItem('ne change rien')];
    }

    public function testTheTotalCanBeAnsweredWhateverTheIntentAmazonChose(): void
    {
        // Given
        $user = $this->linkedUserFor(3);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->say(AlexaTestRequest::addMeal('hachis parmentier'));
        $this->say(AlexaTestRequest::addMeal('hachis parmentier'));

        // When : « pour deux personnes » rangé dans l'intention des produits, le rang d'Amazon ou une quantité
        $this->converse(AlexaTestRequest::addItem('pour deux personnes'), $this->attributes());

        // Then
        $this->assertSame("C'est changé : hachis parmentier, pour 2 personnes.", $this->speech());
        $this->assertSame(2, $this->mealOf($user, $recipe)?->getServing());
    }

    public function testAnUnknownRecipeThenAProductSwitchesToTheProductSearchOnceAndOffersNoRecipeAgain(): void
    {
        // Given : l'utilisateur n'a aucune recette et le produit n'existe pas non plus
        $this->linkedUserFor(3);

        // When : « planifie le repas xyz »
        $this->say(AlexaTestRequest::addMeal('xyz'));

        // Then : aucune recette du tout, Alexa le dit puis demande « c'est un produit ? »
        $this->assertSame("Tu n'as encore aucune recette dans Repas. C'est un produit ?", $this->speech());

        // When : « oui »
        $this->converse(AlexaTestRequest::yes(), $this->attributes());

        // Then : la recherche de produits repart, le dialogue ordinaire d'un produit inconnu, SANS « ou une recette ? » (déjà essayé)
        $this->assertSame('Je ne connais pas xyz. Je le crée ?', $this->speech());
        $this->assertTrue($this->attributes()['dialogue']['otherSideTried']);

        // When : « une recette » : plus d'issue, pas compris
        $this->converse(AlexaTestRequest::chooseKind('une recette', 'meal'), $this->attributes());

        // Then
        $this->assertSame("Je n'ai pas compris. Je ne connais pas xyz. Je le crée ?", $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testAnUnknownRecipeThatIsAProductIsAddedAsAProduct(): void
    {
        // Given : pas de recette « pain », mais un produit
        $user = $this->linkedUserFor(3);
        $this->savedBread($user);
        $this->savedActiveList($user);

        // When : « planifie le repas pain », « un produit »
        $this->say(AlexaTestRequest::addMeal('pain'));
        $asked = $this->speech();
        $this->converse(AlexaTestRequest::chooseKind('un produit', 'item'), $this->attributes());

        // Then
        $this->assertSame("Tu n'as encore aucune recette dans Repas. C'est un produit ?", $asked);
        $this->assertSame("C'est noté : pain.", $this->speech());
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
        $this->assertSame(0, $this->rowCount('meal'));
    }

    public function testAnUnknownProductThenARecipeThatDoesNotExistEitherEndsWithNothingFound(): void
    {
        // Given
        $this->linkedUserFor(3);

        // When : « ajoute xyz » -> « une recette »
        $this->say(AlexaTestRequest::addItem('xyz'));
        $this->converse(AlexaTestRequest::chooseKind('une recette', 'meal'), $this->attributes());

        // Then : les deux côtés sont essayés, plus rien à proposer, la séance se ferme
        $this->assertSame("Je ne trouve rien qui s'appelle xyz.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testTheRecipeOfAnotherUserIsNeverFoundNorPlanned(): void
    {
        // Given : le hachis est à quelqu'un d'autre
        $user = $this->linkedUserFor(3);
        $this->savedRecipe($this->savedUser(), 'Hachis parmentier');

        // When
        $this->say(AlexaTestRequest::addMeal('hachis parmentier'));

        // Then
        // La recette de l'autre n'existe pas pour lui : il n'a aucune recette
        $this->assertSame("Tu n'as encore aucune recette dans Repas. C'est un produit ?", $this->speech());
        $this->assertStringNotContainsString('Hachis parmentier', (string) $this->client->getResponse()->getContent());
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $this->assertCount(0, $this->lists->findByOwner($user->getId()));
    }

    public function testAForgedStateWithTheRecipeOfAnotherUserPlansNothingRemembersNothingAndAnswersPolitely(): void
    {
        // Given : l'état désigne la recette d'un AUTRE utilisateur
        $user = $this->linkedUserFor(3);
        $theirs = $this->savedRecipe($this->savedUser(), 'Hachis parmentier');
        $forged = SpokenDialogueState::chooseRecipe('gratin', [new DialogueOption($theirs->getId(), 'hachis parmentier')], 'gratin');

        // When : « le premier »
        $this->converse(AlexaTestRequest::chooseOption('le premier', '1'), ['dialogue' => $forged->toArray()]);

        // Then : une phrase polie, aucune liste, aucune mémoire, aucun état
        $this->assertSame(200, $this->statusCode());
        $this->assertSame("Je ne retrouve plus cette recette dans Repas.", $this->speech());
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $this->assertSame(0, $this->rowCount('spoken_recipe_phrase_choice'));
        $this->assertCount(0, $this->lists->findByOwner($user->getId()));
    }

    public function testAForgedServingsStateWithAGhostRecipeChangesNothing(): void
    {
        // Given
        $user = $this->linkedUserFor(3);
        $forged = SpokenDialogueState::askingServings(new DialogueOption('ghost-recipe', 'hachis parmentier'), 4);

        // When : « six »
        $this->converse(AlexaTestRequest::answerServings('six', '6'), ['dialogue' => $forged->toArray()]);

        // Then
        $this->assertSame("Je ne retrouve plus cette recette dans Repas.", $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $this->assertCount(0, $this->lists->findByOwner($user->getId()));
    }

    public function testWhenTheOnlyListWasSentTheMealStartsANewOneAndTheSentOneIsUntouched(): void
    {
        // Given : une liste envoyée au drive
        $user = $this->linkedUserFor(2);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $other = $this->savedRecipe($user, 'Lasagnes');
        $sent = new ShoppingListBuilder()->withOwner($user)->addRecipe($other)->build();
        foreach ($other->getRows() as $row) {
            $sent->addRow($row->getIngredient()->getSlug(), $row->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $row->getQuantity());
        }
        $sent->send(new DateTimeImmutable());
        $this->lists->save($sent);
        $this->entityManager->clear();

        // When
        $this->say(AlexaTestRequest::addMeal('hachis parmentier'));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec hachis parmentier, pour 2 personnes. Ta liste précédente est partie au drive.", $this->speech());
        $this->entityManager->clear();
        $reloaded = $this->lists->findOneById($sent->getId());
        $this->assertSame([ShoppingListStatus::SENT, $sent->getVersion(), 1], [$reloaded->getStatus(), $reloaded->getVersion(), count($reloaded->getMeals())]);
        $this->assertSame(2, $this->mealOf($user, $recipe)?->getServing());
    }

    public function testARecipeWhoseIngredientCannotBeConvertedAnswersPolitelyAndWritesNothing(): void
    {
        // Given : la recette ne se convertit pas dans l'unité d'achat de ses ingrédients
        $user = $this->linkedUserFor(3);
        $this->savedRecipe($user, 'Hachis parmentier', withConversions: false);

        // When
        $this->say(AlexaTestRequest::addMeal('hachis parmentier'));

        // Then : la phrase d'échec, comme dans l'application tout est annulé (y compris la liste neuve)
        $this->assertSame(200, $this->statusCode());
        $this->assertSame("Il manque une conversion pour crème fraiche épaisse. Ouvre la recette dans Repas.", $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $this->assertSame(0, $this->rowCount('meal'));
    }

    public function testAMealWithoutARecipeGetsAnExampleAndAnUnusableNumberAsksAgain(): void
    {
        // Given
        $this->linkedUserFor(3);

        // When / Then
        $this->say(AlexaTestRequest::addMeal(null));
        $this->assertSame('Dis par exemple : planifie le repas hachis parmentier.', $this->speech());
        $this->say(AlexaTestRequest::addItem('le repas'));
        $this->assertSame('Dis par exemple : planifie le repas hachis parmentier.', $this->speech());
        $this->say(AlexaTestRequest::addMeal('hachis parmentier', 'zéro', '0'));
        $this->assertSame('Combien ? Redis ta demande avec un nombre de 1 à 99.', $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testAProductWhoseNameStartsWithTheWordMealIsFoundUnderItsWholeNameAfterTheProductSwitch(): void
    {
        // Given : le produit « repas pour chien », aucune recette de ce nom
        $user = $this->linkedUserFor(3);
        $this->persister->saveIngredient(new IngredientBuilder()->withName('repas pour chien')->withCreator($user, true)->build());
        $this->savedActiveList($user);

        // When : « ajoute le repas pour chien » (dit pour un produit : le mot annonce une recette)
        $this->say(AlexaTestRequest::addItem('le repas pour chien'));

        // Then : aucune recette ne ressemble, « C'est un produit ? » ; l'état garde la phrase entière
        $this->assertSame("Tu n'as encore aucune recette dans Repas. C'est un produit ?", $this->speech());
        $this->assertSame('le repas pour chien', $this->attributes()['dialogue']['originalPhrase']);

        // When : « oui »
        $this->converse(AlexaTestRequest::yes(), $this->attributes());

        // Then : le produit est cherché sous son nom entier, retrouvé et ajouté (pas « pour chien »)
        $this->assertSame("C'est noté : repas pour chien.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
        $this->assertSame(0, $this->rowCount('meal'));
    }

    #[DataProvider('servingsOutOfRange')]
    public function testANumberOfPeopleOutOfOneToNinetyNineInThePhraseIsAskedAgainAndNothingIsWritten(AlexaTestRequest $request): void
    {
        // Given
        $user = $this->linkedUserFor(3);
        $this->savedRecipe($user, 'Hachis parmentier');

        // When
        $this->say($request);

        // Then : pas une recette qui s'appelle « … pour 100 personnes »
        $this->assertSame('Combien ? Redis ta demande avec un nombre de 1 à 99.', $this->speech());
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $this->assertSame(0, $this->rowCount('meal'));
    }

    /**
     * @return iterable<string, array{AlexaTestRequest}>
     */
    public static function servingsOutOfRange(): iterable
    {
        yield 'pour 100 personnes' => [AlexaTestRequest::addMeal('hachis parmentier pour 100 personnes')];
        yield 'pour 0' => [AlexaTestRequest::addMeal('hachis parmentier pour 0')];
        yield 'pour cent personnes' => [AlexaTestRequest::addMeal('hachis parmentier pour cent personnes')];
        yield 'dit à l\'intention des produits' => [AlexaTestRequest::addItem('le repas hachis parmentier pour 100 personnes')];
    }

    public function testAServingsNumberInWordsAboveTwentyIsRead(): void
    {
        // Given
        $user = $this->linkedUserFor(3);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->savedActiveList($user);

        // When : « … pour trente personnes »
        $this->say(AlexaTestRequest::addMeal('hachis parmentier pour trente personnes'));

        // Then
        $this->assertSame("C'est noté : hachis parmentier, pour 30 personnes.", $this->speech());
        $this->assertSame(30, $this->mealOf($user, $recipe)?->getServing());
    }

    #[DataProvider('totalsSaidAsARecipe')]
    public function testTheTotalSaidAsARecipeByAmazonIsStillTheTotal(AlexaTestRequest $answer, string $speech, int $serving): void
    {
        // Given : planifié pour trois, puis redit
        $user = $this->linkedUserFor(3);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->say(AlexaTestRequest::addMeal('hachis parmentier'));
        $this->say(AlexaTestRequest::addMeal('hachis parmentier'));

        // When : Amazon range la réponse dans le créneau `recipe` d'AddMealIntent
        $this->converse($answer, $this->attributes());

        // Then : une réponse à « combien ? », pas « Je ne trouve pas la recette six personnes »
        $this->assertSame($speech, $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame($serving, $this->mealOf($user, $recipe)?->getServing());
    }

    /**
     * @return iterable<string, array{AlexaTestRequest, string, int}>
     */
    public static function totalsSaidAsARecipe(): iterable
    {
        yield 'six personnes' => [AlexaTestRequest::addMeal('six personnes'), "C'est changé : hachis parmentier, pour 6 personnes.", 6];
        yield 'pour six' => [AlexaTestRequest::addMeal('pour six'), "C'est changé : hachis parmentier, pour 6 personnes.", 6];
        yield 'rien' => [AlexaTestRequest::addMeal('rien'), "D'accord, je ne change rien.", 3];
        yield 'non' => [AlexaTestRequest::addMeal('non'), "D'accord, je ne change rien.", 3];
        yield 'ne change rien' => [AlexaTestRequest::addMeal('ne change rien'), "D'accord, je ne change rien.", 3];
    }

    #[DataProvider('ranksSaidAsANumber')]
    public function testASecondChoiceSaidAsANumberAnywhereIsTheSecondChoice(AlexaTestRequest $answer): void
    {
        // Given : « du sirop de menthe » inconnu, deux sirops proposés
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->withName('Sirop fruit rouge')->withCreator($user, true)->build());
        $grenadine = new IngredientBuilder()->withName('Sirop de grenadine')->withCreator($user, true)->build();
        $this->persister->saveIngredient($grenadine);
        $this->savedActiveList($user);
        $this->say(AlexaTestRequest::addItem('du sirop de menthe'));
        $names = array_map(static fn(array $option): string => $option['name'], $this->attributes()['dialogue']['options']);

        // When : « deux », rangé par Amazon ailleurs que dans `rank`
        $this->converse($answer, $this->attributes());

        // Then : le deuxième proposé est choisi et ajouté
        $this->assertSame("C'est noté : " . mb_strtolower($names[1]) . ". Je m'en souviendrai.", $this->speech());
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
    }

    /**
     * @return iterable<string, array{AlexaTestRequest}>
     */
    public static function ranksSaidAsANumber(): iterable
    {
        yield 'le créneau servings' => [AlexaTestRequest::answerServings('deux', '2')];
        yield 'le créneau quantity' => [AlexaTestRequest::addItem(null)->withSlot('quantity', 'deux', '2')];
    }

    public function testAPhraseRememberedForARecipeGivesItDirectlyNextTimeWithoutAnyQuestion(): void
    {
        // Given : « le plat du dimanche » a déjà été retenu pour le hachis
        $user = $this->linkedUserFor(3);
        $hachis = $this->savedRecipe($user, 'Hachis parmentier');
        $this->savedRecipe($user, 'Lasagnes');
        $this->savedActiveList($user);
        $this->connection()->executeStatement(
            "INSERT INTO spoken_recipe_phrase_choice (user_id, phrase, recipe_id, times, updated_at) VALUES (?, 'plat-dimanche', ?, 1, NOW())",
            [$user->getId(), $hachis->getId()],
        );

        // When
        $this->say(AlexaTestRequest::addMeal('le plat du dimanche'));

        // Then : comme un nom exact, sans question ni promesse
        $this->assertSame("C'est noté : hachis parmentier, pour 3 personnes.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame(3, $this->mealOf($user, $hachis)?->getServing());
    }

    /**
     * Un utilisateur actif, lié à Alexa : une liaison et une paire de jetons dont le jeton d'accès est `tokenOf($seed)`.
     */
    private function linkedUser(string $seed = self::ACCESS_TOKEN_SEED, ?DateTimeImmutable $issuedAt = null): User
    {
        $user = $this->savedUser();
        $this->linkedTo($user, $seed, issuedAt: $issuedAt);

        return $user;
    }

    /**
     * Une liaison de `$user` avec sa paire de jetons ; rend l'identifiant de la liaison.
     */
    private function linkedTo(User $user, string $seed, ?string $alexaUserId = null, ?DateTimeImmutable $createdAt = null, ?DateTimeImmutable $issuedAt = null): string
    {
        $linkId = $this->linkIdOf($seed);
        $created = $createdAt ?? new DateTimeImmutable('-1 day');
        static::getContainer()->get(VoiceLinkRepository::class)->save(VoiceLink::load([
            'id' => $linkId,
            'user_id' => $user->getId(),
            'alexa_user_id' => $alexaUserId,
            'created_at' => $created,
            'last_used_at' => $alexaUserId === null ? null : new DateTimeImmutable('-1 day'),
            'revoked_at' => null,
        ]));
        static::getContainer()->get(AccessGrantRepository::class)->add(AccessGrant::issue(
            OpaqueToken::hash($this->tokenOf($seed)),
            OpaqueToken::hash(AlexaTestClient::token("refresh-$seed")),
            $linkId,
            $user->getId(),
            $issuedAt ?? new DateTimeImmutable(),
        ));

        return $linkId;
    }

    private function tokenOf(string $seed): string
    {
        return AlexaTestClient::token("access-$seed");
    }

    private function linkIdOf(string $seed): string
    {
        return "link-$seed";
    }

    private function savedBread(User $owner): void
    {
        $this->persister->saveIngredient(new IngredientBuilder()->isBread()->withCreator($owner, true)->build());
    }

    /**
     * Les rayons et unités du catalogue commun que le dialogue de création propose : céréale, unité et kilo.
     */
    private function savedCatalogue(): void
    {
        $this->persister->saveDepartment(new DepartmentBuilder()->isCereal()->build());
        $this->persister->saveUnit(new UnitBuilder()->isUnite()->build());
        $this->persister->saveUnit(new UnitBuilder()->isKilo()->build());
    }

    private function savedActiveList(User $owner): ShoppingList
    {
        $list = new ShoppingListBuilder()->withOwner($owner)->build();
        $this->lists->save($list);
        $this->entityManager->clear();

        return $list;
    }

    /**
     * Alexa parle : la requête, avec le jeton du seul utilisateur lié du test, signée.
     */
    private function say(AlexaTestRequest $request): void
    {
        $this->signedPost($request->withAccessToken($this->tokenOf(self::ACCESS_TOKEN_SEED)));
    }

    /**
     * Un tour de conversation dans la séance : la requête porte l'état que la réponse précédente a rendu (`$attributes`, ou rien).
     *
     * @param ?array<string, mixed> $attributes
     */
    private function converse(AlexaTestRequest $request, ?array $attributes = null): void
    {
        $this->say($attributes === null ? $request->inOpenSession() : $request->withSessionAttributes($attributes));
    }

    /**
     * Les attributs de séance que la dernière réponse rend à Alexa (`[]` : aucun état).
     *
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        return $this->jsonBody()['sessionAttributes'] ?? [];
    }

    private function signedPost(AlexaTestRequest $request): void
    {
        $body = $request->toJson();

        $this->postRaw($body, AlexaTestRequest::CERT_CHAIN_URL, self::$leaf->sign($body));
    }

    private function postRaw(string $body, string $certChainUrl, string $signature): void
    {
        $this->client->request('POST', self::URL, content: $body, server: [
            'CONTENT_TYPE' => 'application/json;charset=UTF-8',
            'HTTP_SIGNATURECERTCHAINURL' => $certChainUrl,
            'HTTP_SIGNATURE_256' => $signature,
            // Une adresse par requête : le plafond par adresse est prouvé ailleurs
            'REMOTE_ADDR' => '203.0.113.' . random_int(1, 254),
        ]);
    }

    private function speech(): string
    {
        return $this->jsonBody()['response']['outputSpeech']['text'];
    }

    private function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM $table");
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Retirer un repas, et proposer ce que la liste contient (ADR 0029 décision 12, VOX-306)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * Le repas de `$recipe` dans la liste active de `$user` (une liste neuve s'il n'en a pas), pour `$servings` personnes, planifié par le vrai port : les
     * lignes des ingrédients sont celles que la planification écrit.
     */
    private function plannedMeal(User $user, Recipe $recipe, int $servings): void
    {
        static::getContainer()->get(VoiceMealPlanner::class)->plan($user->getId(), $recipe->getId(), $servings);
        $this->entityManager->clear();
    }

    /**
     * Les quantités des lignes de la liste active de l'utilisateur, par ingrédient.
     *
     * @return array<string, float>
     */
    private function activeQuantities(User $user): array
    {
        $this->entityManager->clear();
        $quantities = [];
        foreach ($this->lists->findOneActivateByOwner($user->getId())?->getRows() ?? [] as $row) {
            $quantities[$row->getIngredientSlug()] = $row->getQuantity();
        }

        return $quantities;
    }

    public function testAPlannedMealIsRemovedWholeAndItsLinesGoWithIt(): void
    {
        // Given : le hachis est planifié pour quatre personnes
        $user = $this->linkedUserFor(4);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->plannedMeal($user, $recipe, 4);
        $this->assertNotEmpty($this->activeQuantities($user));

        // When : « Alexa, demande à Jarvis d'enlever le repas hachis parmentier »
        $this->say(AlexaTestRequest::removeMeal('le repas hachis parmentier'));

        // Then : retiré, ses lignes disparaissent, la séance se ferme sans relance
        $this->assertSame(["C'est retiré : le repas hachis parmentier.", true], [$this->speech(), $this->jsonBody()['response']['shouldEndSession']]);
        $this->assertArrayNotHasKey('reprompt', $this->jsonBody()['response']);
        $this->assertNull($this->mealOf($user, $recipe));
        $this->assertSame([], $this->activeQuantities($user));
        $this->assertCount(1, $this->lists->findByOwner($user->getId()), 'retirer ne crée jamais de liste');
    }

    public function testSomeServingsAreTakenOffAndTheLinesFollow(): void
    {
        // Given : un repas pour quatre
        $user = $this->linkedUserFor(4);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->plannedMeal($user, $recipe, 4);
        $forFour = $this->activeQuantities($user);

        // When : « enlève le repas hachis parmentier pour deux personnes »
        $this->say(AlexaTestRequest::removeMeal('hachis parmentier', 'deux', '2'));

        // Then : on déduit (4 - 2 = 2), le repas reste, les lignes ont suivi
        $this->assertSame('C\'est changé : hachis parmentier, pour 2 personnes.', $this->speech());
        $this->assertSame(2, $this->mealOf($user, $recipe)?->getServing());
        foreach ($forFour as $slug => $quantity) {
            $this->assertEqualsWithDelta($quantity / 2, $this->activeQuantities($user)[$slug], 0.0001, $slug);
        }

        // When : « enlève la recette hachis parmentier pour six personnes » (plus que ce qui reste), dit à l'intention des produits
        $this->say(AlexaTestRequest::removeItem('la recette hachis parmentier pour six personnes'));

        // Then : le repas quitte la liste
        $this->assertSame("C'est retiré : le repas hachis parmentier.", $this->speech());
        $this->assertNull($this->mealOf($user, $recipe));
        $this->assertSame([], $this->activeQuantities($user));
    }

    public function testRemovingWithoutTheWordMealStaysAProductAndKeepsTheMeals(): void
    {
        // Given : un repas planifié et du pain dans la liste
        $user = $this->linkedUserFor(4);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->plannedMeal($user, $recipe, 4);
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        static::getContainer()->get(VoiceShoppingList::class)->addItem($user->getId(), $bread->getSlug(), 2);
        $this->entityManager->clear();

        // When : « enlève du pain »
        $this->say(AlexaTestRequest::removeItem('du pain'));

        // Then : le pain diminue, le repas est intact
        $this->assertSame("C'est retiré, il t'en reste 1 dans ta liste.", $this->speech());
        $this->assertSame(4, $this->mealOf($user, $recipe)?->getServing());
    }

    public function testAMealThatIsNotInTheListProposesThePlannedMealsAndTheChoiceIsRemoved(): void
    {
        // Given : deux gratins planifiés, un troisième gratin d'un autre compte (jamais proposé), une recette qui n'est pas dans la liste
        $user = $this->linkedUserFor(4);
        $dauphinois = $this->savedRecipe($user, 'Gratin dauphinois');
        $pates = $this->savedRecipe($user, 'Gratin de pâtes');
        $this->savedRecipe($user, 'Gratin de chou-fleur');
        $this->savedRecipe($this->savedUser(), 'Gratin de courgettes');
        $this->plannedMeal($user, $dauphinois, 4);
        $this->plannedMeal($user, $pates, 2);

        // When : « enlève le gratin »
        $this->say(AlexaTestRequest::removeMeal('gratin'));

        // Then : les deux repas DE LA LISTE et l'issue « un produit », l'état voyage, rien n'est retiré
        $this->assertSame('Je ne trouve pas le repas gratin dans ta liste. Tu veux dire gratin de pâtes ou gratin dauphinois ? Ou un produit ?', $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame(['remove', 'meal'], [$this->attributes()['dialogue']['action'], $this->attributes()['dialogue']['target']]);
        $this->assertSame(4, $this->mealOf($user, $dauphinois)?->getServing());
        $this->assertSame(0, $this->rowCount('spoken_recipe_phrase_choice'), 'retirer ne retient aucune phrase');

        // When : « le deuxième »
        $this->converse(AlexaTestRequest::chooseOption('le deuxième', '2'), $this->attributes());

        // Then : le deuxième proposé quitte la liste, rien n'est retenu
        $this->assertSame("C'est retiré : le repas gratin dauphinois.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertNull($this->mealOf($user, $dauphinois));
        $this->assertSame(2, $this->mealOf($user, $pates)?->getServing());
        $this->assertSame(0, $this->rowCount('spoken_recipe_phrase_choice'));
    }

    public function testTheServingsToTakeOffSurviveTheProposals(): void
    {
        // Given : deux gratins planifiés pour quatre
        $user = $this->linkedUserFor(4);
        $dauphinois = $this->savedRecipe($user, 'Gratin dauphinois');
        $pates = $this->savedRecipe($user, 'Gratin de pâtes');
        $this->plannedMeal($user, $dauphinois, 4);
        $this->plannedMeal($user, $pates, 4);

        // When : « enlève le gratin pour deux personnes », puis « le premier »
        $this->say(AlexaTestRequest::removeMeal('gratin', 'deux', '2'));
        $this->converse(AlexaTestRequest::chooseOption('le premier', '1'), $this->attributes());

        // Then : deux personnes de moins pour la proposition choisie (gratin de pâtes, la première), l'autre repas est intact
        $this->assertSame('C\'est changé : gratin de pâtes, pour 2 personnes.', $this->speech());
        $this->assertSame(2, $this->mealOf($user, $pates)?->getServing());
        $this->assertSame(4, $this->mealOf($user, $dauphinois)?->getServing());
    }

    public function testAMealThatIsNotInTheListThenAProductRemovesTheProductFoundUnderItsWholeName(): void
    {
        // Given : le produit « repas pour chien » est dans la liste, aucun repas ne lui ressemble
        $user = $this->linkedUserFor(4);
        $dog = new IngredientBuilder()->withName('repas pour chien')->withCreator($user, true)->build();
        $this->persister->saveIngredient($dog);
        $this->savedActiveList($user);
        static::getContainer()->get(VoiceShoppingList::class)->addItem($user->getId(), $dog->getSlug(), 3);
        $this->entityManager->clear();

        // When : « enlève le repas pour chien »
        $this->say(AlexaTestRequest::removeItem('le repas pour chien'));

        // Then : aucun repas planifié ne ressemble, « C'est un produit ? », l'état garde la phrase entière
        $this->assertSame("Je ne trouve pas le repas pour chien dans ta liste. C'est un produit ?", $this->speech());
        $this->assertSame('le repas pour chien', $this->attributes()['dialogue']['originalPhrase']);

        // When : « oui »
        $this->converse(AlexaTestRequest::yes(), $this->attributes());

        // Then : le produit est cherché sous son nom entier dans la liste et diminue d'un
        $this->assertSame("C'est retiré, il t'en reste 2 dans ta liste.", $this->speech());
        $this->assertSame([], $this->attributes());
    }

    public function testAProductNotUnderstoodProposesTheLinesOfTheListThenAMealRemovesTheMeal(): void
    {
        // Given : le hachis est planifié ; la phrase « le hachis parmentier » ne désigne aucun produit
        $user = $this->linkedUserFor(4);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->plannedMeal($user, $recipe, 4);

        // When : « enlève le hachis parmentier » (sans le mot « repas »)
        $this->say(AlexaTestRequest::removeItem('le hachis parmentier'));

        // Then : un produit inconnu, rien de proche dans la liste : « C'est un repas ? »
        $this->assertSame("Il n'y a pas de hachis parmentier dans ta liste. C'est un repas ?", $this->speech());
        $this->assertSame('remove', $this->attributes()['dialogue']['action']);
        $this->assertSame(4, $this->mealOf($user, $recipe)?->getServing());

        // When : « oui »
        $this->converse(AlexaTestRequest::yes(), $this->attributes());

        // Then : le repas de la liste est cherché et retiré
        $this->assertSame("C'est retiré : le repas hachis parmentier.", $this->speech());
        $this->assertNull($this->mealOf($user, $recipe));
        $this->assertSame([], $this->attributes());
    }

    public function testWhenNeitherTheProductNorTheMealIsInTheListNothingMoreIsOfferedAndNothingIsRemoved(): void
    {
        // Given : une liste active qui porte un repas sans rapport
        $user = $this->linkedUserFor(4);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->plannedMeal($user, $recipe, 4);

        // When : « enlève la ciboulette » puis « un repas »
        $this->say(AlexaTestRequest::removeItem('de la ciboulette'));
        $first = $this->speech();
        $this->converse(AlexaTestRequest::chooseKind('un repas', 'meal'), $this->attributes());

        // Then : plus d'issue après les deux recherches, plus d'état, rien d'écrit
        $this->assertSame("Il n'y a pas de ciboulette dans ta liste. C'est un repas ?", $first);
        $this->assertSame("Je ne trouve rien qui s'appelle ciboulette dans ta liste.", $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
        $this->assertSame(4, $this->mealOf($user, $recipe)?->getServing());
    }

    public function testRemovingAMealWithoutAnActiveListOrWithASentListTouchesNothing(): void
    {
        // Given : aucune liste
        $user = $this->linkedUserFor(4);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');

        // When
        $this->say(AlexaTestRequest::removeMeal('hachis parmentier'));
        $none = $this->speech();

        // Given : une liste envoyée au drive qui porte le repas
        $sent = new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->build();
        foreach ($recipe->getRows() as $row) {
            $sent->addRow($row->getIngredient()->getSlug(), $row->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $row->getQuantity());
        }
        $sent->send(new DateTimeImmutable());
        $this->lists->save($sent);
        $this->entityManager->clear();

        // When
        $this->say(AlexaTestRequest::removeMeal('hachis parmentier'));
        $sentSpeech = $this->speech();

        // Then : jamais lue ni modifiée, aucune liste créée
        $this->assertSame("Tu n'as pas de liste en cours.", $none);
        $this->assertSame('Ta liste est déjà partie au drive.', $sentSpeech);
        $this->entityManager->clear();
        $reloaded = $this->lists->findOneById($sent->getId());
        $this->assertSame([ShoppingListStatus::SENT, $sent->getVersion(), 1], [$reloaded->getStatus(), $reloaded->getVersion(), count($reloaded->getMeals())]);
        $this->assertCount(1, $this->lists->findByOwner($user->getId()));
    }

    public function testTheMealOfAnotherUserIsNeverFoundNorRemoved(): void
    {
        // Given : le hachis est planifié dans la liste d'un autre compte
        $user = $this->linkedUserFor(4);
        $this->savedActiveList($user);
        $other = $this->savedUser();
        $theirs = $this->savedRecipe($other, 'Hachis parmentier');
        $this->plannedMeal($other, $theirs, 4);

        // When
        $this->say(AlexaTestRequest::removeMeal('hachis parmentier'));

        // Then : introuvable pour lui, et jamais cité
        $this->assertSame("Je ne trouve pas le repas hachis parmentier dans ta liste. C'est un produit ?", $this->speech());
        $this->assertStringNotContainsString('Hachis parmentier', (string) $this->client->getResponse()->getContent());
        $this->assertSame(4, $this->mealOf($other, $theirs)?->getServing());
    }

    public function testAForgedRemovalStateWithTheRecipeOfAnotherUserRemovesNothingAndAnswersPolitely(): void
    {
        // Given : l'état désigne le repas d'un AUTRE utilisateur
        $user = $this->linkedUserFor(4);
        $other = $this->savedUser();
        $theirs = $this->savedRecipe($other, 'Hachis parmentier');
        $this->plannedMeal($other, $theirs, 4);
        $forged = SpokenDialogueState::chooseMealToRemove('gratin', [new DialogueOption($theirs->getId(), 'hachis parmentier')]);

        // When : « le premier »
        $this->converse(AlexaTestRequest::chooseOption('le premier', '1'), ['dialogue' => $forged->toArray()]);

        // Then : une phrase polie, le repas de l'autre est intact, rien n'est créé
        $this->assertSame(200, $this->statusCode());
        $this->assertSame("Je ne retrouve plus cette recette dans Repas.", $this->speech());
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
        $this->assertSame(4, $this->mealOf($other, $theirs)?->getServing());
        $this->assertCount(0, $this->lists->findByOwner($user->getId()));
    }

    public function testAMealToRemoveWithoutARecipeGetsAnExampleAndAnUnusableNumberAsksAgain(): void
    {
        // Given
        $user = $this->linkedUserFor(4);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier');
        $this->plannedMeal($user, $recipe, 4);

        // When / Then
        $this->say(AlexaTestRequest::removeMeal(null));
        $this->assertSame('Dis par exemple : enlève le repas hachis parmentier.', $this->speech());
        $this->say(AlexaTestRequest::removeMeal('hachis parmentier pour cent personnes'));
        $this->assertSame('Combien ? Redis ta demande avec un nombre de 1 à 99.', $this->speech());
        $this->assertSame(4, $this->mealOf($user, $recipe)?->getServing());
    }

    public function testAnIngredientOfTheMealThatCannotBeConvertedAnswersPolitelyAndKeepsTheMeal(): void
    {
        // Given : un repas planifié dont les conversions ont disparu depuis
        $user = $this->linkedUserFor(4);
        $recipe = $this->savedRecipe($user, 'Hachis parmentier', withConversions: false);
        $list = new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->build();
        $this->lists->save($list);
        $this->entityManager->clear();

        // When
        $this->say(AlexaTestRequest::removeMeal('hachis parmentier'));

        // Then : la phrase d'échec, le bus a tout annulé, le repas est toujours dans la liste
        $this->assertSame(200, $this->statusCode());
        $this->assertSame("Il manque une conversion pour crème fraiche épaisse. Ouvre la recette dans Repas.", $this->speech());
        $this->assertNotNull($this->mealOf($user, $recipe));
    }

    public function testTwoPlannedMealsWithTheSameNameAreToldApartThenTheRightOneIsRemoved(): void
    {
        // Given : deux recettes « Hachis parmentier » (une copie), planifiées pour 4 et pour 2 personnes
        $user = $this->linkedUserFor(4);
        $big = $this->savedRecipe($user, 'Hachis parmentier');
        $small = $this->savedRecipe($user, 'Hachis parmentier');
        $this->plannedMeal($user, $big, 4);
        $this->plannedMeal($user, $small, 2);

        // When : « enlève le repas hachis parmentier »
        $this->say(AlexaTestRequest::removeMeal('hachis parmentier'));

        // Then : aucun des deux n'est retiré au hasard, la question les distingue par le nombre de personnes
        // (l'ordre des deux propositions suit les identifiants des recettes, tirés au hasard : l'état dit lequel est lequel)
        $this->assertContains($this->speech(), ['Tu veux dire hachis parmentier pour 4 ou hachis parmentier pour 2 ? Ou un produit ?', 'Tu veux dire hachis parmentier pour 2 ou hachis parmentier pour 4 ? Ou un produit ?']);
        $this->assertSame(4, $this->mealOf($user, $big)?->getServing());
        $this->assertSame(2, $this->mealOf($user, $small)?->getServing());
        $options = $this->attributes()['dialogue']['options'];
        $this->assertEqualsCanonicalizing(['Hachis parmentier pour 4', 'Hachis parmentier pour 2'], array_map(static fn(array $option): string => $option['name'], $options));
        $rank = (string) (array_search($small->getId(), array_column($options, 'slug'), true) + 1);

        // When : le rang de celui « pour 2 » (la question les cite dans cet ordre : le premier est « pour 4 » ou l'inverse, l'état dit lequel)
        $this->converse(AlexaTestRequest::chooseOption('le ' . ($rank === '1' ? 'premier' : 'deuxième'), $rank), $this->attributes());

        // Then : le repas pour 2 quitte la liste, celui pour 4 reste, rien n'est retenu
        $this->assertSame("C'est retiré : le repas hachis parmentier.", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertNull($this->mealOf($user, $small));
        $this->assertSame(4, $this->mealOf($user, $big)?->getServing());
        $this->assertSame(0, $this->rowCount('spoken_recipe_phrase_choice'));
    }

    public function testAHomonymRemovalSaidAgainWithTheServingsStillGuessesNothing(): void
    {
        // Given
        $user = $this->linkedUserFor(4);
        $big = $this->savedRecipe($user, 'Hachis parmentier');
        $small = $this->savedRecipe($user, 'Hachis parmentier');
        $this->plannedMeal($user, $big, 4);
        $this->plannedMeal($user, $small, 2);
        $this->say(AlexaTestRequest::removeMeal('hachis parmentier'));

        // When : « enlève le repas hachis parmentier pour 4 » dit à la question : c'est une nouvelle demande (« enlève 4 personnes du hachis »)
        $this->converse(AlexaTestRequest::removeMeal('hachis parmentier pour 4'), $this->attributes());

        // Then : elle retombe sur les deux homonymes, la question est reposée, rien n'est deviné ni retiré
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertNotNull($this->mealOf($user, $big));
        $this->assertNotNull($this->mealOf($user, $small));
    }

    public function testARankSaidAloneInTheSlotOfAProductChoosesTheProposedMealInsteadOfLosingTheDialogue(): void
    {
        // Given : deux gratins planifiés, « enlève le repas gratin » propose les deux
        $user = $this->linkedUserFor(4);
        $dauphinois = $this->savedRecipe($user, 'Gratin dauphinois');
        $pates = $this->savedRecipe($user, 'Gratin de pâtes');
        $this->plannedMeal($user, $dauphinois, 4);
        $this->plannedMeal($user, $pates, 2);
        $this->say(AlexaTestRequest::removeMeal('gratin'));
        $options = $this->attributes()['dialogue']['options'];

        // When : « le deuxième » qu'Amazon a rangé dans le créneau `item` d'un retrait de produit
        $this->converse(AlexaTestRequest::removeItem('le deuxième'), $this->attributes());

        // Then : c'est le choix du deuxième repas proposé (pas un produit qui s'appellerait « le deuxième »), le dialogue aboutit
        $removed = $options[1]['slug'];
        $this->assertSame("C'est retiré : le repas " . ($removed === $dauphinois->getId() ? 'gratin dauphinois' : 'gratin de pâtes') . '.', $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertNull($this->mealOf($user, $removed === $dauphinois->getId() ? $dauphinois : $pates));
        $this->assertNotNull($this->mealOf($user, $removed === $dauphinois->getId() ? $pates : $dauphinois));
    }

    // ————————————————————————————————————————————————————————————————
    // « Ajoute la recette blanquette de veaux » (2026-10-26) : une recette introuvable ou impossible se dit clairement, jamais un silence
    // ————————————————————————————————————————————————————————————————

    public function testARecipeSaidWithATypoIsPlannedUnderItsRealName(): void
    {
        // Given : la recette « Blanquette de veau » complète, une liste active ; Alexa a entendu « blanquette de veaux » (créneau texte libre, sans identifiant)
        $user = $this->linkedUserFor(4);
        $recipe = $this->savedRecipe($user, 'Blanquette de veau');
        $this->savedActiveList($user);

        // When : « Alexa, demande à Jarvis d'ajouter la recette blanquette de veaux »
        $this->say(AlexaTestRequest::addMeal('la recette blanquette de veaux'));

        // Then : planifiée sous son vrai nom, pour les personnes du compte
        $this->assertSame("C'est noté : blanquette de veau, pour 4 personnes.", $this->speech());
        $this->assertSame(4, $this->mealOf($user, $recipe)?->getServing());
    }

    public function testAnUnknownRecipeGetsTheThreeClosestEvenWhenTheyAreFarThenOffersAProductAndWritesNothing(): void
    {
        // Given : quatre recettes dont aucune ne ressemble à « blanquette de veaux » (la blanquette n'est pas dans Repas)
        $user = $this->linkedUserFor(4);
        foreach (['Lasagnes', 'Hachis parmentier', 'Gratin dauphinois', 'Quiche lorraine'] as $name) {
            $this->savedRecipe($user, $name);
        }
        $this->savedActiveList($user);

        // When
        $this->say(AlexaTestRequest::addMeal('blanquette de veaux'));

        // Then : trois recettes (jamais un silence), la séance reste ouverte, « ou un produit ? », rien d'écrit
        $this->assertMatchesRegularExpression("/^Je ne trouve pas la recette blanquette de veaux\. Tu veux dire [^,]+, [^,]+ ou [^,?]+ \? Ou un produit \?$/u", $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertCount(3, $this->attributes()['dialogue']['options']);
        $this->assertSame(0, $this->rowCount('meal'));
        $this->assertSame(0, $this->rowCount('spoken_recipe_phrase_choice'));
    }

    public function testTheClosestRecipeComesFirstAndAnswerSecondChoosesIt(): void
    {
        // Given : « Blanquette de dinde » est la plus proche de « blanquette de veaux » quand « blanquette de veau » n'existe pas
        $user = $this->linkedUserFor(4);
        $this->savedRecipe($user, 'Lasagnes');
        $dinde = $this->savedRecipe($user, 'Blanquette de dinde');
        $this->savedActiveList($user);
        $this->say(AlexaTestRequest::addMeal('blanquette de veaux'));
        $this->assertStringStartsWith('Je ne trouve pas la recette blanquette de veaux. Tu veux dire blanquette de dinde ou lasagnes ?', $this->speech());

        // When : « la première »
        $this->converse(AlexaTestRequest::chooseOption('la première', '1'), $this->attributes());

        // Then : planifiée, et la phrase est retenue pour cette recette
        $this->assertSame("C'est noté : blanquette de dinde, pour 4 personnes. Je m'en souviendrai.", $this->speech());
        $this->assertSame(4, $this->mealOf($user, $dinde)?->getServing());
        $this->assertSame(1, $this->rowCount('spoken_recipe_phrase_choice'));
    }

    public function testAUserWithoutAnyRecipeIsToldSoNotJustThatTheNameWasNotFound(): void
    {
        // Given
        $this->linkedUserFor(4);

        // When
        $this->say(AlexaTestRequest::addMeal('blanquette de veaux'));

        // Then
        $this->assertSame("Tu n'as encore aucune recette dans Repas. C'est un produit ?", $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame(0, $this->rowCount('shopping_list'));
    }

    public function testARecipeWhoseIngredientHasNoConversionIsSaidClearlyAndNothingIsWrittenNotEvenTheList(): void
    {
        // Given : « Blanquette de veau » dont un ingrédient n'a aucune conversion vers son unité d'achat, aucune liste
        $user = $this->linkedUserFor(4);
        $this->savedRecipe($user, 'Blanquette de veau', withConversions: false);

        // When
        $this->say(AlexaTestRequest::addMeal('la recette blanquette de veau'));

        // Then : une phrase qui dit quoi faire (pas « Je n'ai pas pu faire ça »), le nom de la recette sans identifiant, rien d'écrit
        $this->assertSame(200, $this->statusCode());
        $this->assertSame('Il manque une conversion pour crème fraiche épaisse. Ouvre la recette dans Repas.', $this->speech());
        $this->assertTrue($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame(0, $this->rowCount('shopping_list'));
        $this->assertSame(0, $this->rowCount('meal'));
    }
}
