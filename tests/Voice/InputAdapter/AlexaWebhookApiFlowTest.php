<?php

namespace Repas\Tests\Voice\InputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Shared\Application\Interface\SpokenIngredientResolver;
use Repas\Shared\Application\Interface\VoiceFeature;
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

        // When : « Alexa, demande à mes courses d'ajouter du pain »
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
        $this->assertSame('Je ne connais pas xyz. Je le crée ?', $this->speech());
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
        $this->assertSame('Je ne connais pas pain. Je le crée ?', $this->speech());
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

    public function testInAnOpenSessionTheAnswerAsksForMoreAndTheSessionStaysOpen(): void
    {
        // Given : « Alexa, ouvre mes courses », puis « du pain », puis « du pain » encore
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
        $this->assertSame(["J'ai commencé une nouvelle liste avec pain. Autre chose ?", false], [$first['outputSpeech']['text'], $first['shouldEndSession']]);
        $this->assertSame('Que veux-tu ajouter ?', $first['reprompt']['outputSpeech']['text']);
        $this->assertSame("C'est noté : pain. Autre chose ?", $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->entityManager->clear();
        $this->assertCount(1, $this->lists->findByOwner($user->getId()), 'une seule liste pour toute la séance');
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
        $this->assertSame('Je ne connais pas sirop de menthe. Tu veux dire sirop fruit rouge ? Ou un nouveau produit ?', $this->speech());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame('Tu veux dire sirop fruit rouge ? Ou un nouveau produit ?', $this->jsonBody()['response']['reprompt']['outputSpeech']['text']);
        $this->assertSame('choose', $this->attributes()['dialogue']['step']);
        $this->assertSame(0, $this->rowCount('shopping_list_row'));
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));

        // When : « oui » (une seule proposition)
        $this->converse(AlexaTestRequest::yes(), $this->attributes());

        // Then : ajouté, retenu, dit ; le dialogue est fini (attributs vides), la séance reste ouverte
        $this->assertSame("C'est noté : sirop fruit rouge. Je m'en souviendrai. Autre chose ?", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
        $this->assertSame(
            [[$user->getId(), 'sirop-menthe', $syrup->getSlug(), 1]],
            array_map(static fn(array $row): array => [$row['user_id'], $row['phrase'], $row['ingredient_slug'], (int) $row['times']], $this->connection()->fetchAllAssociative('SELECT * FROM spoken_phrase_choice')),
        );

        // When : plus tard, la même phrase, en appel direct
        $this->say(AlexaTestRequest::addItem('du sirop de menthe'));

        // Then : directement l'ingrédient retenu, comme un nom exact, sans question
        $this->assertSame("C'est noté : sirop fruit rouge.", $this->speech());
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
        $this->assertSame('Je ne connais pas pâtes à pizza. Tu veux dire pâte ramen, pâte brisée ou pâte sablée ? Ou un nouveau produit ? Autre chose ?', $this->speech() . ' Autre chose ?');
        $this->assertCount(3, $this->attributes()['dialogue']['options']);

        // When : « oui » seul ne suffit pas, il y en a trois
        $this->converse(AlexaTestRequest::yes(), $this->attributes());
        $this->assertSame('Lequel ? pâte ramen, pâte brisée ou pâte sablée. Ou un nouveau produit ?', $this->speech());

        // When : « le deuxième »
        $this->converse(AlexaTestRequest::chooseOption('le deuxième', '2'), $this->attributes());

        // Then : la deuxième proposition, retenue pour la phrase
        $this->assertSame("C'est noté : pâte brisée. Je m'en souviendrai. Autre chose ?", $this->speech());
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
        $this->assertSame('Je ne connais pas ciboulette. Je le crée ?', $this->speech());

        // When : « oui » -> le rayon
        $this->converse(AlexaTestRequest::yes(), $this->attributes());
        $this->assertSame('Dans quel rayon ?', $this->speech());
        $this->assertSame($ingredientsBefore, $this->rowCount('ingredient'), "rien n'est créé avant la confirmation");

        // When : « les fruits et légumes » -> l'unité
        $this->converse(AlexaTestRequest::answerDepartment('les fruits et légumes', 'cereale'), $this->attributes());
        $this->assertSame('Quelle unité ? Par exemple pièce, kilo ou litre.', $this->speech());

        // When : « des pièces » -> la confirmation
        $this->converse(AlexaTestRequest::answerUnit('des pièces', 'unite'), $this->attributes());
        $this->assertSame("Je crée ciboulette, céréale, unité. C'est bon ?", $this->speech());
        $this->assertSame($ingredientsBefore, $this->rowCount('ingredient'));

        // When : « oui »
        $this->converse(AlexaTestRequest::yes(), $this->attributes());

        // Then : créé pour l'utilisateur, avec une seule unité, et ajouté à sa liste ; plus d'état ; rien n'est retenu pour la phrase
        $this->assertSame("C'est créé et noté : ciboulette. Autre chose ?", $this->speech());
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

        // Then
        $this->assertSame("C'est noté : ciboulette.", $this->speech());
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
        $this->assertSame("D'accord, je ne crée rien. Autre chose ?", $this->speech());
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
        $this->assertSame("Je n'ai pas compris. Dans quel rayon ?", $this->speech());
        $this->assertSame(1, $this->attributes()['dialogue']['misses']);

        // When : une seconde fois
        $this->converse(AlexaTestRequest::intent('AMAZON.FallbackIntent'), $this->attributes());

        // Then : on abandonne, sans état, la séance reste ouverte pour la suite
        $this->assertSame("Je n'ai pas compris. On laisse tomber. Autre chose ?", $this->speech());
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
        $this->assertSame("J'ai commencé une nouvelle liste avec pain. Autre chose ?", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
        $this->assertSame(0, $this->rowCount('spoken_phrase_choice'));

        // When : plus tard, « de la ciboulette » de nouveau, en appel direct
        $this->say(AlexaTestRequest::addItem('de la ciboulette'));

        // Then : la question revient, ciboulette n'est pas devenue du pain
        $this->assertSame('Je ne connais pas ciboulette. Je le crée ?', $this->speech());
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
        $this->assertSame("C'est noté : pomme de terre. Je m'en souviendrai. Autre chose ?", $this->speech());
        $this->assertSame([], $this->attributes());
        $this->assertSame(1, $this->rowCount('shopping_list_row'));
        $this->assertSame(
            [[$user->getId(), 'patate', $potato->getSlug(), 1]],
            array_map(static fn(array $row): array => [$row['user_id'], $row['phrase'], $row['ingredient_slug'], (int) $row['times']], $this->connection()->fetchAllAssociative('SELECT * FROM spoken_phrase_choice')),
        );

        // When : plus tard, « patates » de nouveau, en appel direct
        $this->say(AlexaTestRequest::addItem('des patates'));

        // Then : directement la pomme de terre, sans question
        $this->assertSame("C'est noté : pomme de terre.", $this->speech());
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
        $this->assertSame('Je ne connais pas sirop de menthe. Je le crée ?', $this->speech());
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

        foreach ([
            'autre skill' => AlexaTestRequest::addItem('du pain')->withAccessToken($token)->withApplicationId('amzn1.ask.skill.00000000-other-0000-0000-000000000002'),
            'horodatage vieux de 151 s' => AlexaTestRequest::addItem('du pain')->withAccessToken($token)->at(new DateTimeImmutable('-151 seconds')),
            'horodatage dans 151 s' => AlexaTestRequest::addItem('du pain')->withAccessToken($token)->at(new DateTimeImmutable('+151 seconds')),
        ] as $name => $request) {
            // When
            $this->signedPost($request);

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

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
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
}
