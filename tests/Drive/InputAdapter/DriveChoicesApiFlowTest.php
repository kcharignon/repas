<?php

namespace Repas\Tests\Drive\InputAdapter;


use DateTimeImmutable;
use DateTimeZone;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Parcours des préférences de produits avec les vrais bus, la vraie base et les listeners : le lot de l'agent se relit par
 * `GET`, s'oublie par `DELETE`, et chaque utilisateur ne voit que les siennes. Les tests unitaires des controllers doublent les
 * bus ; celui-ci prouve le câblage.
 */
class DriveChoicesApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
    }

    public function testTheBatchOfTheAgentBuildsTheChoicesThatTheListReturns(): void
    {
        // Given
        $user = $this->userWithAStore();

        // When : premier passage, un produit préféré pour la farine, un refus, « j'en ai toujours » pour le sel
        $this->put($user, [
            $this->add('farine', 'PRIMARY', '555111', 'Farine de blé T55 1 kg', ['value' => 1000, 'unit' => 'g'], ['cents' => 149, 'perMeasure' => ['cents' => 149, 'unit' => 'kg']]),
            $this->add('farine', 'FALLBACK', '222333', 'Farine de blé T45 1 kg', ['value' => 1, 'unit' => 'kg']),
            ['ingredientSlug' => 'farine', 'action' => 'REJECT_PRODUCT', 'product' => ['retailerProductId' => '999000', 'label' => 'Farine complète bio 1 kg']],
            ['ingredientSlug' => 'sel', 'action' => 'HAVE_AT_HOME'],
        ]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->get($user);
        $farine = $this->jsonBody()['member'][0];
        $this->assertSame(2, $this->jsonBody()['totalItems']);
        $this->assertSame(['ingredientSlug', 'ingredientName', 'haveAtHome', 'accepted', 'rejected', 'updatedAt', 'searchQuery'], array_keys($farine));
        $this->assertNull($farine['searchQuery']);
        $this->assertSame(['farine', 'farine', false], [$farine['ingredientSlug'], $farine['ingredientName'], $farine['haveAtHome']]);
        $this->assertSame([[1, '555111', 'Farine de blé T55 1 kg'], [2, '222333', 'Farine de blé T45 1 kg']], array_map(static fn(array $a): array => [$a['rank'], $a['product']['retailerProductId'], $a['product']['label']], $farine['accepted']));
        $this->assertSame(['value' => 1000, 'unit' => 'g'], $farine['accepted'][0]['product']['content']);
        $this->assertSame(['productId', 'retailerProductId', 'ean', 'label', 'content', 'packSize'], array_keys($farine['accepted'][0]['product']));
        $this->assertNull($farine['accepted'][0]['product']['packSize']);
        $this->assertSame(['999000'], array_column($farine['rejected'], 'retailerProductId'));
        $this->assertSame(['sel', true, [], []], [$this->jsonBody()['member'][1]['ingredientSlug'], $this->jsonBody()['member'][1]['haveAtHome'], $this->jsonBody()['member'][1]['accepted'], $this->jsonBody()['member'][1]['rejected']]);

        // When : second passage, un nouveau préféré décale les autres ; le refusé redevient accepté ; le sel s'achète de nouveau
        $this->put($user, [
            $this->add('farine', 'PRIMARY', '777888', 'Farine bio T55 1 kg'),
            $this->add('farine', 'FALLBACK', '999000', 'Farine complète bio 1 kg'),
            $this->add('sel', 'PRIMARY', '333444', 'Sel fin 1 kg'),
        ]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->get($user);
        $farine = $this->jsonBody()['member'][0];
        $this->assertSame(['777888', '555111', '222333', '999000'], array_map(static fn(array $a): string => $a['product']['retailerProductId'], $farine['accepted']));
        $this->assertSame([], $farine['rejected']);
        $this->assertFalse($this->jsonBody()['member'][1]['haveAtHome']);
        $this->assertSame(['333444'], array_map(static fn(array $a): string => $a['product']['retailerProductId'], $this->jsonBody()['member'][1]['accepted']));
    }

    public function testTheRayonAndTheFamilyReportedByTheAgentAreKeptOnTheProductAndNeverRewritten(): void
    {
        // Given
        $user = $this->userWithAStore();

        // When : un agent récent donne le rayon et la famille du produit, un ancien agent n'en donne pas pour le sel
        $this->put($user, [
            $this->add('farine', 'PRIMARY', '1', 'Farine', rayonId: 4001, familyId: 4101),
            $this->add('sel', 'PRIMARY', '2', 'Sel'),
        ]);

        // Then : l'ancien client marche comme avant, la fiche retient le rayon
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['1' => [4001, 4101], '2' => [null, null]], $this->rayonsByProduct());

        // When : un autre utilisateur renvoie ces produits avec d'autres valeurs, puis sans rien ; le sel reçoit enfin son rayon
        $other = $this->userWithAStore(['farine-2', 'sel-2']);
        $this->put($other, [$this->add('farine-2', 'PRIMARY', '1', 'Farine', rayonId: 4999, familyId: 4999), $this->add('sel-2', 'PRIMARY', '2', 'Sel', rayonId: 4002)]);
        $this->put($user, [$this->add('farine', 'FALLBACK', '1', 'Farine')]);

        // Then : le rayon connu n'est ni remplacé ni effacé, celui qui manquait est rempli (la famille reste vide)
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['1' => [4001, 4101], '2' => [4002, null]], $this->rayonsByProduct());
    }

    public function testARayonOrAFamilyOutOfBoundsAnswers422AndSavesNothing(): void
    {
        // Given
        $user = $this->userWithAStore();

        // When / Then : zéro, négatif, trop grand pour un entier PostgreSQL
        foreach ([['rayonId' => 0], ['rayonId' => -1], ['rayonId' => 2147483648], ['familyId' => 0], ['familyId' => 2147483648]] as $ids) {
            $this->put($user, [['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '1', 'label' => 'Farine'] + $ids]]);
            $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        }
        $this->assertSame([], $this->countsBySlug());
        $this->assertSame([], $this->rayonsByProduct());
    }

    public function testThePriceOfAnAcceptedProductIsRefreshedWithoutTouchingTheChoice(): void
    {
        // Given
        $user = $this->userWithAStore();
        $this->put($user, [$this->add('farine', 'PRIMARY', '555111', 'Farine T55', null, ['cents' => 149])]);
        $this->get($user);
        $before = $this->jsonBody();

        // When : une ligne ADDED de l'agent porte le prix relu ; un produit qui n'est pas accepté pour cet ingrédient est ignoré
        $this->put($user, [
            ['ingredientSlug' => 'farine', 'action' => 'REFRESH_PRICE', 'product' => ['retailerProductId' => '555111', 'price' => ['cents' => 159, 'perMeasure' => ['cents' => 159, 'unit' => 'kg']]]],
            ['ingredientSlug' => 'sel', 'action' => 'REFRESH_PRICE', 'product' => ['retailerProductId' => '555111', 'price' => ['cents' => 1]]],
        ]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $price = $this->entityManager->getConnection()->fetchAssociative('SELECT price_cents, price_per_measure_cents, price_per_measure_unit FROM drive_product WHERE retailer_product_id = ?', ['555111']);
        $this->assertSame([159, 159, 'kg'], array_values($price));
        $this->get($user);
        $this->assertSame($before, $this->jsonBody());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice'));
    }

    public function testTheDailyLimitOfProductWritesAnswers422AndSavesNothing(): void
    {
        // Given : 4 999 écritures de produits déjà envoyées aujourd'hui (jour UTC)
        $user = $this->savedUser();
        $this->ownIngredients($user, ['farine', 'sel']);
        static::getContainer()->get(DriveSettingsRepository::class)->save(
            new DriveSettingsBuilder()->withUserId($user->getId())->withProductWrites(new DateTimeImmutable('today', new DateTimeZone('UTC')), 4999)->build(),
        );

        // When : la 5 000e passe
        $this->put($user, [$this->add('farine', 'PRIMARY', '1', 'Farine')]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When : la 5 001e est refusée
        $this->put($user, [$this->add('sel', 'PRIMARY', '2', 'Sel')]);

        // Then : 422 DRIVE_LIMIT_REACHED, rien n'est enregistré
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_LIMIT_REACHED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->assertSame(['farine' => 1], $this->countsBySlug());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));

        // When : ce qui n'écrit aucun produit reste permis (« j'en ai toujours », oubli, remplacement, lecture)
        $this->put($user, [['ingredientSlug' => 'sel', 'action' => 'HAVE_AT_HOME']]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->delete($user, 'sel');
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->get($user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testABatchIsAtomicAndOutOfBoundsValuesAreRefused(): void
    {
        // Given
        $user = $this->userWithAStore();
        $this->put($user, array_map(fn(int $i) => $this->add('farine', 'FALLBACK', (string) $i, "Farine $i"), range(1, 5)));

        // When : un sixième produit accepté, dans un lot qui crée aussi du sel
        $this->put($user, [$this->add('sel', 'PRIMARY', '9', 'Sel'), $this->add('farine', 'FALLBACK', '6', 'Farine 6')]);

        // Then : rien n'est enregistré, ni préférence ni produit
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_CHOICE_INVALID']], $this->jsonBody());
        $this->assertSame(['farine' => 1], $this->countsBySlug());
        $this->assertSame(5, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));

        // When : un identifiant de produit qui n'est pas numérique, un libellé vide, un libellé qui inverse le sens de lecture
        foreach ([$this->add('sel', 'PRIMARY', 'abc', 'Sel'), $this->add('sel', 'PRIMARY', '9', ''), $this->add('sel', 'PRIMARY', '9', "Sel \u{202E}gk")] as $intent) {
            $this->put($user, [$intent]);

            // Then
            $this->assertSame(422, $this->client->getResponse()->getStatusCode());
            $this->assertSame(['error' => ['message' => 'DRIVE_CHOICE_INVALID']], $this->jsonBody());
        }
        $this->assertSame(['farine' => 1], $this->countsBySlug());
    }

    public function testABatchForAnotherStoreOrWithoutStoreIsRefusedAndNothingIsSaved(): void
    {
        // Given
        $user = $this->savedUser();

        // When : pas de magasin enregistré
        $this->put($user, [$this->add('farine', 'PRIMARY', '1', 'Farine')]);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_NOT_CONFIGURED']], $this->jsonBody());

        // Given
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());

        // When : le magasin du lot n'est pas le magasin courant
        $this->put($user, [$this->add('farine', 'PRIMARY', '1', 'Farine')], '654321');

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_STORE_MISMATCH']], $this->jsonBody());
        $this->assertSame([], $this->countsBySlug());
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));
    }

    public function testAStoreWithAnIdOf36CharactersHostsTheProductsAndTheChoicesOfTheUser(): void
    {
        // Given : un magasin tel qu'une enseigne à identifiant explicite le donne (un UUID inventé, 36 caractères), posé tel que la base le restitue
        $store = '0b5f8a2e-3c1d-4e7f-9a6b-123456789abc';
        $sibling = '0b5f8a2e-3c1d-4e7f-9a6b-123456789abd';
        $user = $this->savedUser();
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->withStoreAsRead($store, 'Ville-Test', 'https://www.exemple.invalid/')->build());
        $this->ownIngredients($user, ['farine', 'sel']);

        // When : le lot de l'agent pour ce magasin (le corps accepte 40 caractères), puis la lecture des réglages
        $this->put($user, [$this->add('farine', 'PRIMARY', '1', 'Farine'), $this->add('sel', 'PRIMARY', '2', 'Sel')], $store);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/drive/settings', $user);
        $this->assertSame($store, $this->jsonBody()['store']['id']);
        $this->get($user);
        $this->assertSame(['farine', 'sel'], array_column($this->jsonBody()['member'], 'ingredientSlug'));
        $this->assertSame([$store, $store], $this->entityManager->getConnection()->fetchFirstColumn('SELECT DISTINCT store_id FROM drive_product UNION ALL SELECT DISTINCT store_id FROM drive_choice'));

        // When : le magasin voisin (mêmes 35 premiers caractères) n'est pas le magasin courant
        $this->put($user, [$this->add('farine', 'FALLBACK', '3', 'Farine bis')], $sibling);

        // Then : c'est l'identifiant entier qui est comparé
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_STORE_MISMATCH']], $this->jsonBody());

        // When : la préférence est remplacée, puis oubliée, avec l'identifiant de 36 caractères
        $this->get($user);
        $productId = $this->jsonBody()['member'][1]['accepted'][0]['product']['productId'];
        $this->replace($user, 'sel', false, [$productId], [], $store);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->delete($user, 'farine', $store);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->get($user);
        $this->assertSame(['sel'], array_column($this->jsonBody()['member'], 'ingredientSlug'));
    }

    public function testAnIdOfMoreThan40CharactersIsRefusedBeforeAnyRead(): void
    {
        // Given
        $user = $this->userWithAStore();

        // When
        $this->put($user, [$this->add('farine', 'PRIMARY', '1', 'Farine')], str_repeat('a', 41));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->countsBySlug());
    }

    public function testForgettingAChoiceRemovesItAndKeepsTheProducts(): void
    {
        // Given
        $user = $this->userWithAStore();
        $this->put($user, [$this->add('farine', 'PRIMARY', '1', 'Farine'), $this->add('sel', 'PRIMARY', '2', 'Sel'), ['ingredientSlug' => 'farine', 'action' => 'REJECT_PRODUCT', 'product' => ['retailerProductId' => '3', 'label' => 'Farine complète']]]);

        // When
        $this->delete($user, 'farine');

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->get($user);
        $this->assertSame(['sel'], array_column($this->jsonBody()['member'], 'ingredientSlug'));
        $this->assertSame(3, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));

        // When : l'oublier une seconde fois, un ingrédient sans préférence, un autre magasin
        $this->delete($user, 'farine');

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_CHOICE_NOT_FOUND']], $this->jsonBody());

        // When
        $this->delete($user, 'sel', '654321');

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_STORE_MISMATCH']], $this->jsonBody());
    }

    public function testTwoUsersShareTheProductAndNeverSeeOrForgetEachOthersChoices(): void
    {
        // Given
        $alice = $this->userWithAStore();
        $bob = $this->userWithAStore(['farine-bob']);

        // When : chacun a sa farine, le produit est le même
        $this->put($alice, [$this->add('farine', 'PRIMARY', '555111', 'Farine T55 1 kg')]);
        $this->put($bob, [$this->add('farine-bob', 'FALLBACK', '555111', 'Farine T55 1 kg'), $this->add('farine-bob', 'PRIMARY', '222333', 'Farine T45 1 kg')]);

        // Then : une seule ligne de produit par produit, chacun ses préférences
        $this->assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));
        $this->get($alice);
        $this->assertSame(['555111'], array_map(static fn(array $a): string => $a['product']['retailerProductId'], $this->jsonBody()['member'][0]['accepted']));
        $this->get($bob);
        $this->assertSame(['222333', '555111'], array_map(static fn(array $a): string => $a['product']['retailerProductId'], $this->jsonBody()['member'][0]['accepted']));

        // When : un troisième utilisateur n'a rien, et ne peut rien oublier
        $carol = $this->userWithAStore([]);
        $this->get($carol);
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
        $this->delete($carol, 'farine');

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice'));
    }

    public function testEachAccountListsTheLabelItReadAndTheSharedSheetOnlyServesAsFallback(): void
    {
        // Given : le premier compte fixe la fiche commune ; le second lit le même produit sous un autre libellé et le refuse pour une autre farine
        $alice = $this->userWithAStore();
        $bob = $this->userWithAStore(['farine-bob', 'semoule-bob']);
        $this->put($alice, [$this->add('farine', 'PRIMARY', '555111', 'Farine T55 1 kg', null, ['cents' => 149]), ['ingredientSlug' => 'farine', 'action' => 'REJECT_PRODUCT', 'product' => ['retailerProductId' => '999000', 'label' => 'Farine complète bio']]]);
        $this->put($bob, [$this->add('farine-bob', 'PRIMARY', '555111', 'Farine T55 – lot de 2', null, ['cents' => 129]), ['ingredientSlug' => 'farine-bob', 'action' => 'REJECT_PRODUCT', 'product' => ['retailerProductId' => '999000', 'label' => 'Farine complète – ancien libellé']]]);

        // When
        $this->get($alice);
        $aliceBody = $this->jsonBody()['member'][0];
        $this->get($bob);
        $bobBody = $this->jsonBody()['member'][0];

        // Then : chacun relit son libellé, accepté comme refusé ; la forme de la réponse ne change pas (pas de prix)
        $this->assertSame(['Farine T55 1 kg', 'Farine complète bio'], [$aliceBody['accepted'][0]['product']['label'], $aliceBody['rejected'][0]['label']]);
        $this->assertSame(['Farine T55 – lot de 2', 'Farine complète – ancien libellé'], [$bobBody['accepted'][0]['product']['label'], $bobBody['rejected'][0]['label']]);
        $this->assertSame(['productId', 'retailerProductId', 'ean', 'label', 'content', 'packSize'], array_keys($bobBody['accepted'][0]['product']));
        // La fiche commune a été créée par le premier compte et n'a pas été réécrite par le second
        $this->assertSame(
            [['555111', 'Farine T55 1 kg', 149], ['999000', 'Farine complète bio', null]],
            array_map(static fn(array $row): array => [$row['retailer_product_id'], $row['label'], $row['price_cents'] === null ? null : (int) $row['price_cents']], $this->entityManager->getConnection()->fetchAllAssociative('SELECT retailer_product_id, label, price_cents FROM drive_product ORDER BY retailer_product_id')),
        );

        // When : Alice lit un autre produit ; Bob le range parmi ses produits acceptés depuis l'écran de gestion, sans l'avoir jamais lu
        $this->put($alice, [$this->add('sel', 'PRIMARY', '777888', 'Sel fin 1 kg')]);
        $unread = Product::idFor(Retailer::LECLERC, '123456', '777888');
        $this->replace($bob, 'farine-bob', false, [$bobBody['accepted'][0]['product']['productId'], $unread], []);
        $this->get($bob);

        // Then : le produit qu'il a lu garde son libellé, celui qu'il n'a jamais lu retombe sur la fiche commune
        $this->assertSame(['Farine T55 – lot de 2', 'Sel fin 1 kg'], array_map(static fn(array $accepted): string => $accepted['product']['label'], $this->jsonBody()['member'][0]['accepted']));
    }

    public function testReplacingThePreferenceOfAnIngredientFromTheManagementScreen(): void
    {
        // Given : farine avec deux produits classés et un refus, lus par GET comme le fait l'écran
        $user = $this->userWithAStore();
        $this->put($user, [
            $this->add('farine', 'PRIMARY', '555111', 'Farine T55 1 kg'),
            $this->add('farine', 'FALLBACK', '222333', 'Farine T45 1 kg'),
            ['ingredientSlug' => 'farine', 'action' => 'REJECT_PRODUCT', 'product' => ['retailerProductId' => '999000', 'label' => 'Farine complète bio']],
        ]);
        $this->get($user);
        $ids = [];
        foreach ([...$this->jsonBody()['member'][0]['accepted'], ...array_map(static fn(array $product): array => ['product' => $product], $this->jsonBody()['member'][0]['rejected'])] as $line) {
            $ids[$line['product']['retailerProductId']] = $line['product']['productId'];
        }

        // When : l'ordre s'inverse
        $this->replace($user, 'farine', false, [$ids['222333'], $ids['555111']], [$ids['999000']]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([['222333', '555111'], ['999000']], $this->readFarine($user));

        // When : 222333 est retiré, 999000 est accepté de nouveau, 222333 devient refusé
        $this->replace($user, 'farine', false, [$ids['555111'], $ids['999000']], [$ids['222333']]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([['555111', '999000'], ['222333']], $this->readFarine($user));

        // When : une demande invalide (produit inconnu, doublon, à la fois accepté et refusé, « j'en ai toujours » avec produits)
        foreach ([
            [false, ['inconnu'], []],
            [false, [$ids['555111'], $ids['555111']], []],
            [false, [$ids['555111']], [$ids['555111']]],
            [true, [$ids['555111']], []],
        ] as [$haveAtHome, $accepted, $rejected]) {
            $this->replace($user, 'farine', $haveAtHome, $accepted, $rejected);

            // Then : rien n'est enregistré
            $this->assertSame([422, ['error' => ['message' => 'DRIVE_CHOICE_INVALID']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        }
        $this->assertSame([['555111', '999000'], ['222333']], $this->readFarine($user));

        // When : un autre magasin
        $this->replace($user, 'farine', false, [], [], '654321');

        // Then
        $this->assertSame([409, ['error' => ['message' => 'DRIVE_STORE_MISMATCH']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);

        // When : « j'en ai toujours », puis plus rien
        $this->replace($user, 'farine', true, [], []);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->get($user);
        $this->assertSame([true, [], []], [$this->jsonBody()['member'][0]['haveAtHome'], $this->jsonBody()['member'][0]['accepted'], $this->jsonBody()['member'][0]['rejected']]);
        $this->replace($user, 'farine', false, [], []);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->get($user);
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
        $this->assertSame(3, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));
    }


    public function testAUserOnlyReplacesHisOwnPreferences(): void
    {
        // Given : deux utilisateurs avec la même farine, le produit est partagé
        $alice = $this->userWithAStore();
        $bob = $this->userWithAStore(['farine-bob']);
        $this->put($alice, [$this->add('farine', 'PRIMARY', '555111', 'Farine T55 1 kg'), $this->add('farine', 'FALLBACK', '222333', 'Farine T45 1 kg')]);
        $this->put($bob, [$this->add('farine-bob', 'PRIMARY', '555111', 'Farine T55 1 kg')]);
        $this->get($alice);
        $aliceProducts = array_map(static fn(array $accepted): string => $accepted['product']['productId'], $this->jsonBody()['member'][0]['accepted']);

        // When : Bob remplace sa farine par les produits d'Alice, dans l'autre ordre
        $this->replace($bob, 'farine-bob', false, array_reverse($aliceProducts), []);

        // Then : Alice n'a pas changé
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->get($alice);
        $this->assertSame($aliceProducts, array_map(static fn(array $accepted): string => $accepted['product']['productId'], $this->jsonBody()['member'][0]['accepted']));
        $this->get($bob);
        $this->assertSame(array_reverse($aliceProducts), array_map(static fn(array $accepted): string => $accepted['product']['productId'], $this->jsonBody()['member'][0]['accepted']));
    }


    public function testListsSentAsJsonObjectsAreCheckedLikeListsAndNeverEndInAServerError(): void
    {
        // Given : un produit du magasin de l'utilisateur et un produit d'un autre magasin
        $user = $this->userWithAStore();
        $this->put($user, [$this->add('farine', 'PRIMARY', '555111', 'Farine T55 1 kg')]);
        $own = Product::idFor(Retailer::LECLERC, '123456', '555111');
        $otherStore = new ProductBuilder()->withStoreId('654321')->withRetailerProductId('777888')->build();
        static::getContainer()->get(ProductRepository::class)->save($otherStore);

        // When : `{"a": …}` dans les deux listes, avec la même clé : le même produit accepté et refusé, puis un produit d'un autre magasin
        foreach ([
            "{\"accepted\":{\"a\":\"$own\"},\"rejected\":{\"a\":\"$own\"}}",
            "{\"accepted\":{\"a\":\"{$otherStore->getId()}\"},\"rejected\":{\"a\":\"$own\"}}",
            "{\"accepted\":{\"0\":\"$own\",\"1\":\"$own\"},\"rejected\":[]}",
        ] as $lists) {
            $this->request('PUT', '/api/v1/drive/choices/farine', $user, '{"retailer":"leclerc","storeId":"123456","haveAtHome":false,' . substr($lists, 1));

            // Then : refusé comme avec des listes, et rien n'est enregistré
            $this->assertSame([422, ['error' => ['message' => 'DRIVE_CHOICE_INVALID']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()], $lists);
            $this->assertSame([['555111'], []], $this->readFarine($user), $lists);
        }
    }

    public function testTheOwnerOfAChoiceComesFromTheTokenNeverFromTheBody(): void
    {
        // Given : Alice a un magasin et une farine ; Mallory a les siens, et vise Alice dans le corps de ses demandes
        $alice = $this->userWithAStore();
        $mallory = $this->userWithAStore(['farine-mallory', 'sel-mallory']);
        $this->put($alice, [$this->add('farine', 'PRIMARY', '555111', 'Farine T55 1 kg')]);
        $body = static fn(array $extra): string => json_encode($extra + ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'sel-mallory', 'action' => 'HAVE_AT_HOME', 'userId' => $alice->getId()],
            ['ingredientSlug' => 'farine-mallory', 'action' => 'HAVE_AT_HOME', 'userId' => $alice->getId()],
        ]], JSON_THROW_ON_ERROR);

        // When : les champs `userId` et `user` du corps sont ignorés
        $this->request('PUT', '/api/v1/drive/choices', $mallory, $body(['userId' => $alice->getId(), 'user' => $alice->getId()]));
        $this->request('PUT', '/api/v1/drive/choices/farine-mallory', $mallory, json_encode(['userId' => $alice->getId(), 'retailer' => 'leclerc', 'storeId' => '123456', 'haveAtHome' => true, 'accepted' => [], 'rejected' => []], JSON_THROW_ON_ERROR));
        $this->request('DELETE', '/api/v1/drive/choices/farine-mallory?retailer=leclerc&storeId=123456&userId=' . $alice->getId(), $mallory);

        // Then : les choix de Mallory ont bougé, ceux d'Alice jamais
        $this->get($alice);
        $this->assertSame([['farine', false, ['555111']]], array_map(static fn(array $c): array => [$c['ingredientSlug'], $c['haveAtHome'], array_map(static fn(array $a): string => $a['product']['retailerProductId'], $c['accepted'])], $this->jsonBody()['member']));
        $this->get($mallory);
        $this->assertSame(['sel-mallory'], array_column($this->jsonBody()['member'], 'ingredientSlug'));
    }

    public function testAChoiceIsNeverSavedOnTheIngredientOfAnotherUser(): void
    {
        // Given : Alice a sa farine et un choix dessus ; Bob a un magasin et ses propres ingrédients
        $alice = $this->userWithAStore();
        $bob = $this->userWithAStore(['sel-bob']);
        $this->put($alice, [$this->add('farine', 'PRIMARY', '555111', 'Farine T55 1 kg')]);
        $before = $this->choiceRows();

        // When : Bob pose un choix sur le slug de la farine d'Alice, par le lot de l'agent puis par l'écran de gestion
        $this->put($bob, [$this->add('sel-bob', 'PRIMARY', '1', 'Sel'), $this->add('farine', 'PRIMARY', '555111', 'Farine T55 1 kg')]);

        // Then : introuvable, comme un slug qui n'existe pas ; le lot entier est refusé, le sel de Bob aussi
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_INGREDIENT_NOT_FOUND']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->put($bob, [['ingredientSlug' => 'farine-inconnue', 'action' => 'HAVE_AT_HOME']]);
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_INGREDIENT_NOT_FOUND']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->replace($bob, 'farine', true, [], []);
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_INGREDIENT_NOT_FOUND']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->assertSame($before, $this->choiceRows());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'));

        // When : Bob n'efface rien d'Alice non plus
        $this->replace($bob, 'farine', false, [], []);
        $this->delete($bob, 'farine');

        // Then : vider une préférence qu'il n'a pas ne change rien, l'oublier est introuvable ; la farine d'Alice est intacte
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame($before, $this->choiceRows());
    }

    public function testAChoiceLeftOnASlugThatIsNoLongerTheUsersCanStillBeEmptiedAndForgotten(): void
    {
        // Given : une préférence ancienne sur un slug qui n'est (plus) à personne de l'utilisateur
        $user = $this->userWithAStore();
        $this->entityManager->getConnection()->insert('drive_choice', [
            'id' => 'a0000000-0000-4000-8000-000000000001', 'user_id' => $user->getId(), 'retailer' => 'leclerc', 'store_id' => '123456',
            'ingredient_slug' => 'ancien-slug', 'kind' => 'PRODUCTS', 'updated_at' => '2026-10-01 10:00:00',
        ]);
        $this->entityManager->getConnection()->insert('drive_choice', [
            'id' => 'a0000000-0000-4000-8000-000000000002', 'user_id' => $user->getId(), 'retailer' => 'leclerc', 'store_id' => '123456',
            'ingredient_slug' => 'ancien-slug-2', 'kind' => 'PRODUCTS', 'updated_at' => '2026-10-01 10:00:00',
        ]);

        // When : l'écran la vide, puis l'autre est oubliée
        $this->replace($user, 'ancien-slug', false, [], []);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->delete($user, 'ancien-slug-2');

        // Then : ces lignes sont les siennes, il peut les effacer
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->countsBySlug());
    }

    public function testListingWithoutStoreIsEmpty(): void
    {
        // When
        $this->get($this->savedUser());

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testWithTheSwitchOffTheRoutesAnswer404(): void
    {
        // Given : le noyau est gardé entre les requêtes, sinon l'interrupteur posé serait perdu au redémarrage
        $user = $this->savedUser();
        $this->client->disableReboot();
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));

        // When / Then
        $this->get($user);
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_DISABLED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->put($user, []);
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_DISABLED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->delete($user, 'farine');
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_DISABLED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
    }

    /**
     * @param array<string, mixed>|null $content
     * @param array<string, mixed>|null $price
     *
     * @return array<string, mixed>
     */
    private function add(string $slug, string $placement, string $retailerProductId, string $label, ?array $content = null, ?array $price = null, ?int $rayonId = null, ?int $familyId = null): array
    {
        return [
            'ingredientSlug' => $slug,
            'action' => 'ADD_PRODUCT',
            'placement' => $placement,
            'product' => array_filter(['retailerProductId' => $retailerProductId, 'label' => $label, 'content' => $content, 'price' => $price, 'rayonId' => $rayonId, 'familyId' => $familyId], static fn($value): bool => $value !== null),
        ];
    }

    /**
     * @return array<string, array{?int, ?int}> rayon et famille mémorisés de chaque produit, par identifiant chez l'enseigne
     */
    private function rayonsByProduct(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT retailer_product_id, rayon_id, family_id FROM drive_product ORDER BY retailer_product_id');

        return array_combine(
            array_column($rows, 'retailer_product_id'),
            array_map(static fn(array $row): array => [$row['rayon_id'] === null ? null : (int) $row['rayon_id'], $row['family_id'] === null ? null : (int) $row['family_id']], $rows),
        );
    }

    /**
     * @param list<array<string, mixed>> $choices
     */
    private function put(User $user, array $choices, string $storeId = '123456'): void
    {
        $this->request('PUT', '/api/v1/drive/choices', $user, json_encode(['retailer' => 'leclerc', 'storeId' => $storeId, 'choices' => $choices], JSON_THROW_ON_ERROR));
    }

    private function get(User $user): void
    {
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $user);
    }

    private function delete(User $user, string $slug, string $storeId = '123456'): void
    {
        $this->request('DELETE', "/api/v1/drive/choices/$slug?retailer=leclerc&storeId=$storeId", $user);
    }

    private function request(string $method, string $uri, User $user, ?string $content = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $content, server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $content = $this->client->getResponse()->getContent();

        return $content === '' ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>} tout ce que la base garde des préférences (choix, puis produits), dans un ordre fixe
     */
    private function choiceRows(): array
    {
        $connection = $this->entityManager->getConnection();

        return [
            $connection->fetchAllAssociative('SELECT * FROM drive_choice ORDER BY id'),
            $connection->fetchAllAssociative('SELECT * FROM drive_choice_product ORDER BY choice_id, product_id'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countsBySlug(): array
    {
        return array_map('intval', $this->entityManager->getConnection()->fetchAllKeyValue('SELECT ingredient_slug, COUNT(*) FROM drive_choice GROUP BY ingredient_slug'));
    }

    /**
     * @return array{list<string>, list<string>} retailerProductId des produits acceptés (dans l'ordre) puis refusés de la farine
     */
    private function readFarine(User $user): array
    {
        $this->get($user);
        $farine = $this->jsonBody()['member'][0];

        return [
            array_map(static fn(array $accepted): string => $accepted['product']['retailerProductId'], $farine['accepted']),
            array_map(static fn(array $product): string => $product['retailerProductId'], $farine['rejected']),
        ];
    }

    /**
     * @param list<string> $accepted
     * @param list<string> $rejected
     */
    private function replace(User $user, string $slug, bool $haveAtHome, array $accepted, array $rejected, string $storeId = '123456'): void
    {
        $this->request('PUT', "/api/v1/drive/choices/$slug", $user, json_encode(['retailer' => 'leclerc', 'storeId' => $storeId, 'haveAtHome' => $haveAtHome, 'accepted' => $accepted, 'rejected' => $rejected], JSON_THROW_ON_ERROR));
    }

    /**
     * Un utilisateur avec un magasin et ses ingrédients : un choix ne se pose que sur un ingrédient du compte, et un slug n'appartient
     * qu'à un compte (deux utilisateurs ont donc des slugs différents).
     *
     * @param list<string> $ingredients
     */
    private function userWithAStore(array $ingredients = ['farine', 'sel']): User
    {
        $user = $this->savedUser();
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        $this->ownIngredients($user, $ingredients);

        return $user;
    }

    /**
     * @param list<string> $slugs
     */
    private function ownIngredients(User $user, array $slugs): void
    {
        $persister = new DataPersister(static::getContainer());
        foreach ($slugs as $slug) {
            $persister->saveOwnedIngredient($user, $slug);
        }
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }
}
