<?php

namespace Repas\Tests\Drive\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Plan d'envoi avec les vrais bus, la vraie base, le voter et les listeners : les préférences de l'agent (`PUT /drive/choices`)
 * se retrouvent dans le plan d'une vraie liste envoyée. Les tests unitaires du controller doublent le bus ; celui-ci prouve le
 * câblage.
 */
class DrivePlanApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
    }

    public function testThePlanOfASentListReflectsThePreferencesOfTheUser(): void
    {
        // Given : du lait (litre) et des œufs (unité), le lait connu avec un produit de 50 cl et un refus
        $user = $this->savedUser();
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        $listId = $this->savedList($user, ShoppingListStatus::SENT);
        $this->request('PUT', '/api/v1/drive/choices', $user, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555111', 'label' => 'Lait demi-écrémé 50 cl', 'content' => ['value' => 50, 'unit' => 'cl']]],
            ['ingredientSlug' => 'lait', 'action' => 'REJECT_PRODUCT', 'product' => ['retailerProductId' => '999000', 'label' => 'Lait entier']],
        ]], JSON_THROW_ON_ERROR));

        // When
        $this->request('GET', "/api/v1/drive/plans/$listId", $user);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $plan = $this->jsonBody();
        $this->assertSame(['shoppingListId', 'listVersion', 'retailer', 'store', 'generatedAt', 'counts', 'lines'], array_keys($plan));
        $this->assertSame(['known' => 1, 'new' => 1, 'haveAtHome' => 0], $plan['counts']);
        $this->assertSame(['ingredientSlug', 'ingredientName', 'needed', 'status', 'products', 'rejected', 'searchQuery', 'rayonHints'], array_keys($plan['lines'][0]));
        $this->assertSame([[], []], array_column($plan['lines'], 'rayonHints'));
        $this->assertSame(['lait', 'oeuf'], array_column($plan['lines'], 'ingredientSlug'));
        $this->assertSame(['KNOWN', 'NEW'], array_column($plan['lines'], 'status'));
        $this->assertSame(['quantity' => 1, 'unitSymbol' => 'l', 'dimension' => 'VOLUME', 'baseFactor' => 1000, 'unitSlug' => 'litre', 'unitName' => 'litre'], $plan['lines'][0]['needed']);
        $this->assertSame(
            [['rank' => 1, 'retailerProductId' => '555111', 'ean' => null, 'label' => 'Lait demi-écrémé 50 cl', 'content' => ['value' => 50, 'unit' => 'cl'], 'packs' => 2, 'packsStatus' => 'COMPUTED', 'packSize' => null]],
            $plan['lines'][0]['products'],
        );
        $this->assertSame([['retailerProductId' => '999000', 'label' => 'Lait entier']], $plan['lines'][0]['rejected']);
    }

    /**
     * DRV-642 de bout en bout : le rayon que l'agent rapporte pour un produit choisi (`PUT /drive/choices`) apprend le rayon de
     * l'enseigne pour le rayon de Repas de l'ingrédient, et le plan (`GET /drive/plans/{id}`) le propose à toutes les lignes de ce
     * rayon de Repas, pour tous les utilisateurs. Le lait et l'œuf sont tous deux au rayon « divers ».
     */
    public function testTheRayonOfTheChosenProductsBecomesTheHintOfTheLinesOfTheSameDepartment(): void
    {
        // Given : deux utilisateurs avec un magasin, Alice avec une liste envoyée (lait et œuf)
        $alice = $this->savedUser();
        $bob = $this->savedUser();
        foreach ([$alice, $bob] as $user) {
            static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        }
        $listId = $this->savedList($alice, ShoppingListStatus::SENT);
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);
        $this->assertSame([[], []], array_column($this->jsonBody()['lines'], 'rayonHints'));

        // When : Alice choisit un lait du rayon 4001 ; Bob un lait et des œufs du rayon 4002 ; un ancien client choisit sans rayon
        $this->request('PUT', '/api/v1/drive/choices', $alice, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555111', 'label' => 'Lait demi-écrémé 50 cl', 'rayonId' => 4001, 'familyId' => 4101]],
        ]], JSON_THROW_ON_ERROR));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', '/api/v1/drive/choices', $bob, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555222', 'label' => 'Lait entier 1 l', 'rayonId' => 4002]],
            ['ingredientSlug' => 'oeuf', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555333', 'label' => 'Œufs x6', 'rayonId' => 4002]],
            ['ingredientSlug' => 'sel', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555444', 'label' => 'Sel fin']],
        ]], JSON_THROW_ON_ERROR));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : le plan d'Alice propose d'abord le rayon le plus choisi (4002, deux fois) puis 4001, pour les deux lignes du rayon
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $lines = $this->jsonBody()['lines'];
        $this->assertSame(['lait', 'oeuf'], array_column($lines, 'ingredientSlug'));
        $this->assertSame([[4002, 4001], [4002, 4001]], array_column($lines, 'rayonHints'));
        $this->assertSame(['KNOWN', 'NEW'], array_column($lines, 'status'));
        $this->assertEquals(
            [['department_slug' => 'divers', 'rayon_id' => 4001, 'chosen_count' => 1], ['department_slug' => 'divers', 'rayon_id' => 4002, 'chosen_count' => 2]],
            $this->entityManager->getConnection()->fetchAllAssociative('SELECT department_slug, rayon_id, chosen_count FROM drive_department_rayon ORDER BY rayon_id'),
        );
    }

    /**
     * DRV-650 de bout en bout : le texte qui a fait trouver un produit (`searchQuery` de `PUT /drive/choices`) est ce que le plan
     * (`GET /drive/plans/{id}`) fait chercher la fois suivante, et ce que « Mes produits » (`GET /drive/choices`) montre. Clé absente :
     * gardée ; `null` : effacée ; le nom affiché de l'ingrédient ne change jamais.
     */
    public function testTheSearchThatFoundTheProductIsWhatThePlanSearchesNextTime(): void
    {
        // Given : Alice, une liste envoyée de lait et d'œufs, et un lait choisi avec la recherche qui l'a fait trouver
        $alice = $this->savedUser();
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($alice->getId())->build());
        $listId = $this->savedList($alice, ShoppingListStatus::SENT);
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);
        $names = array_column($this->jsonBody()['lines'], 'ingredientName');
        $this->assertSame($names, array_column($this->jsonBody()['lines'], 'searchQuery'));
        $addMilk = static fn(array $more = []): string => json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555111', 'label' => 'Lait demi-écrémé 50 cl'], ...$more],
        ]], JSON_THROW_ON_ERROR);

        // When
        $this->request('PUT', '/api/v1/drive/choices', $alice, $addMilk(['searchQuery' => '  lait   demi écrémé uht ']));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);

        // Then : la recherche normalisée remplace le nom de l'ingrédient pour cette ligne seulement, le nom affiché reste
        $lines = $this->jsonBody()['lines'];
        $this->assertSame(['lait', 'oeuf'], array_column($lines, 'ingredientSlug'));
        $this->assertSame($names, array_column($lines, 'ingredientName'));
        $this->assertSame(['lait demi écrémé uht', $names[1]], array_column($lines, 'searchQuery'));
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $alice);
        $this->assertSame(['lait demi écrémé uht'], array_column($this->jsonBody()['member'], 'searchQuery'));

        // When : un client qui ne connaît pas le champ choisit un autre lait
        $this->request('PUT', '/api/v1/drive/choices', $alice, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555222', 'label' => 'Lait entier 1 l']],
        ]], JSON_THROW_ON_ERROR));
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);

        // Then : la recherche mémorisée est gardée
        $this->assertSame('lait demi écrémé uht', $this->jsonBody()['lines'][0]['searchQuery']);

        // When : `null` l'efface
        $this->request('PUT', '/api/v1/drive/choices', $alice, $addMilk(['searchQuery' => null]));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);

        // Then : le plan retombe sur le nom de l'ingrédient
        $this->assertSame($names, array_column($this->jsonBody()['lines'], 'searchQuery'));
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $alice);
        $this->assertSame([null], array_column($this->jsonBody()['member'], 'searchQuery'));
    }

    public function testASearchIsPrivateAndRefusedWhenMalformedOrOnAnythingButAnAddedProduct(): void
    {
        // Given : Alice et Bob ont chacun leur lait et leur recherche pour la même liste d'ingrédients
        $alice = $this->savedUser();
        $bob = $this->savedUser();
        foreach ([$alice, $bob] as $user) {
            static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        }
        $listId = $this->savedList($alice, ShoppingListStatus::SENT);
        $put = fn(User $user, array $intent) => $this->request('PUT', '/api/v1/drive/choices', $user, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [$intent]], JSON_THROW_ON_ERROR));
        $milk = ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555111', 'label' => 'Lait demi-écrémé 50 cl']];
        $put($alice, $milk + ['searchQuery' => 'lait pour alice']);
        $put($bob, $milk + ['searchQuery' => 'lait pour bob']);

        // When : une recherche mal formée, puis une recherche sur autre chose qu'un produit ajouté
        $put($alice, $milk + ['searchQuery' => "lait\nbrique"]);
        $malformed = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()];
        $put($alice, ['ingredientSlug' => 'lait', 'action' => 'HAVE_AT_HOME', 'searchQuery' => 'lait pour alice']);
        $onTheWrongAction = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()];
        $put($alice, ['ingredientSlug' => 'lait', 'action' => 'REJECT_PRODUCT', 'product' => ['retailerProductId' => '999000', 'label' => 'Lait entier'], 'searchQuery' => null]);
        $onARejection = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()];

        // Then : refusées avec leur code, rien n'a changé, et chacun garde la sienne
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_SEARCH_QUERY_INVALID']]], $malformed);
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_CHOICE_INVALID']]], $onTheWrongAction);
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_CHOICE_INVALID']]], $onARejection);
        foreach ([[$alice, 'lait pour alice'], [$bob, 'lait pour bob']] as [$user, $expected]) {
            $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $user);
            $this->assertSame([$expected], array_column($this->jsonBody()['member'], 'searchQuery'));
        }

        // When : « j'en ai toujours » efface la recherche d'Alice, pas celle de Bob
        $put($alice, ['ingredientSlug' => 'lait', 'action' => 'HAVE_AT_HOME']);
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $alice);

        // Then
        $this->assertSame([[true, null]], array_map(static fn(array $choice): array => [$choice['haveAtHome'], $choice['searchQuery']], $this->jsonBody()['member']));
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $bob);
        $this->assertSame(['lait pour bob'], array_column($this->jsonBody()['member'], 'searchQuery'));
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);
        $this->assertSame('HAVE_AT_HOME', $this->jsonBody()['lines'][0]['status']);
        $this->assertSame($this->jsonBody()['lines'][0]['ingredientName'], $this->jsonBody()['lines'][0]['searchQuery']);
    }

    /**
     * Constat C1 de la revue de sécurité : une fiche de produit est commune à tous les utilisateurs du magasin. Un second
     * utilisateur qui renvoie l'identifiant d'un produit connu avec un autre libellé, un autre contenu et un autre prix ne
     * change ni la fiche, ni le plan, ni l'estimation du premier (avant la correction : 10 produits au lieu d'1).
     */
    #[DataProvider('actionsOfTheSecondUser')]
    public function testASecondUserCannotRewriteTheProductOfTheFirst(string $action, ?string $placement): void
    {
        // Given : Alice mémorise un lait de 1 l à 1,50 € pour sa liste de 1 l
        $alice = $this->savedUser();
        $mallory = $this->savedUser();
        foreach ([$alice, $mallory] as $user) {
            static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        }
        $listId = $this->savedList($alice, ShoppingListStatus::SENT);
        $this->request('PUT', '/api/v1/drive/choices', $alice, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555222', 'ean' => '30000000', 'label' => 'Lait entier 1 l', 'content' => ['value' => 1, 'unit' => 'l'], 'price' => ['cents' => 150]]],
        ]], JSON_THROW_ON_ERROR));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When : Mallory, dans le même magasin, renvoie le même produit avec un autre libellé, un autre code, 10 cl et 1 centime
        $intent = ['ingredientSlug' => 'lait', 'action' => $action, 'product' => ['retailerProductId' => '555222', 'ean' => '40000000', 'label' => 'Texte trompeur', 'content' => ['value' => 10, 'unit' => 'cl'], 'price' => ['cents' => 1]]];
        $this->request('PUT', '/api/v1/drive/choices', $mallory, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            $placement === null ? $intent : $intent + ['placement' => $placement],
        ]], JSON_THROW_ON_ERROR));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : le plan et l'estimation d'Alice sont ceux qu'elle a mémorisés
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);
        $product = $this->jsonBody()['lines'][0]['products'][0];
        $this->assertSame(['555222', '30000000', 'Lait entier 1 l', ['value' => 1, 'unit' => 'l'], 1, 'COMPUTED'], [$product['retailerProductId'], $product['ean'], $product['label'], $product['content'], $product['packs'], $product['packsStatus']]);
        $this->request('GET', "/api/v1/drive/estimates/$listId", $alice);
        $line = $this->jsonBody()['lines'][0];
        $this->assertSame(['Lait entier 1 l', 1, 150, 150], [$line['productLabel'], $line['packs'], $line['unitPriceCents'], $line['subtotalCents']]);
    }

    /**
     * DRV-652 de bout en bout, le cas de l'utilisateur : « cheddar en tranche : 15 tranche » avec un produit de 340 g qui en contient 20.
     * La tranche n'a pas de dimension, le plan ne sait pas calculer et l'agent demande le nombre de produits à chaque envoi. Quand la
     * personne répond combien de tranches contient le produit (`packSize` de `PUT /drive/choices`), le plan calcule le nombre de
     * produits sans question, et « Mes produits » et l'estimation en profitent. Clé absente : gardée ; `null` : effacée ; un autre
     * produit n'hérite jamais de la contenance du premier.
     */
    public function testTheSizeInSlicesMakesThePlanComputeThePacksWithoutAQuestion(): void
    {
        // Given : Alice, une liste de 15 tranches de cheddar envoyée, un produit de 340 g (20 tranches) choisi sans contenance
        $alice = $this->savedUser();
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($alice->getId())->build());
        $listId = $this->sentListOfSlices($alice);
        $cheddar = static fn(string $id, array $more = []): array => ['retailerProductId' => $id, 'label' => 'Fromage Cheddar Burger 20 Tranches - 340g', 'content' => ['value' => 340, 'unit' => 'g'], 'price' => ['cents' => 299], ...$more];
        $put = function (array $choices) use ($alice): void {
            $this->request('PUT', '/api/v1/drive/choices', $alice, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => $choices], JSON_THROW_ON_ERROR));
        };
        $put([['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => $cheddar('555701')]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When / Then : sans contenance, rien ne se calcule (l'agent demande), mais l'unité de la ligne est donnée pour poser la question
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);
        $line = $this->jsonBody()['lines'][0];
        $this->assertSame(['quantity' => 15, 'unitSymbol' => 'tranche', 'dimension' => null, 'baseFactor' => null, 'unitSlug' => 'tranche', 'unitName' => 'tranche'], $line['needed']);
        $this->assertSame([null, 'TO_CHECK', null], [$line['products'][0]['packs'], $line['products'][0]['packsStatus'], $line['products'][0]['packSize']]);
        $this->request('GET', "/api/v1/drive/estimates/$listId", $alice);
        $this->assertSame('TO_CHECK', $this->jsonBody()['lines'][0]['status']);

        // When : la personne répond « 20 tranches dans ce produit »
        $put([['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => $cheddar('555701', ['packSize' => ['value' => 20, 'unitSlug' => 'tranche']])]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : 15 tranches pour 20 par produit font 1 produit, calculé, sans question
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);
        $product = $this->jsonBody()['lines'][0]['products'][0];
        $this->assertSame([1, 'COMPUTED', ['value' => 20, 'unitSlug' => 'tranche']], [$product['packs'], $product['packsStatus'], $product['packSize']]);
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $alice);
        $this->assertSame(['value' => 20, 'unitSlug' => 'tranche'], $this->jsonBody()['member'][0]['accepted'][0]['product']['packSize']);
        $this->request('GET', "/api/v1/drive/estimates/$listId", $alice);
        $this->assertSame(['ESTIMATED', 1, 299], [$this->jsonBody()['lines'][0]['status'], $this->jsonBody()['lines'][0]['packs'], $this->jsonBody()['lines'][0]['subtotalCents']]);

        // When : un ancien client redit le même produit sans parler de contenance, puis rafraîchit son prix
        $put([['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => $cheddar('555701')]]);
        $put([['ingredientSlug' => 'cheddar', 'action' => 'REFRESH_PRICE', 'product' => ['retailerProductId' => '555701', 'price' => ['cents' => 309]]]]);

        // Then : elle est gardée
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);
        $this->assertSame([1, 'COMPUTED'], [$this->jsonBody()['lines'][0]['products'][0]['packs'], $this->jsonBody()['lines'][0]['products'][0]['packsStatus']]);

        // When : la personne choisit un autre produit, sans contenance, qui devient le préféré
        $put([['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555702', 'label' => 'Cheddar en tranches fines', 'content' => ['value' => 150, 'unit' => 'g']]]]);

        // Then : le nouveau produit n'a pas la contenance de l'ancien (la question est posée pour lui), l'ancien garde la sienne
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);
        $products = $this->jsonBody()['lines'][0]['products'];
        $this->assertSame(['555702', null, 'TO_CHECK', null], [$products[0]['retailerProductId'], $products[0]['packs'], $products[0]['packsStatus'], $products[0]['packSize']]);
        $this->assertSame(['555701', 1, 'COMPUTED', ['value' => 20, 'unitSlug' => 'tranche']], [$products[1]['retailerProductId'], $products[1]['packs'], $products[1]['packsStatus'], $products[1]['packSize']]);

        // When : la personne efface sa réponse pour le premier produit (`null`)
        $put([['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'FALLBACK', 'product' => $cheddar('555701', ['packSize' => null])]]);

        // Then
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);
        $this->assertSame([null, 'TO_CHECK', null], [$this->jsonBody()['lines'][0]['products'][1]['packs'], $this->jsonBody()['lines'][0]['products'][1]['packsStatus'], $this->jsonBody()['lines'][0]['products'][1]['packSize']]);
    }

    public function testAnUnknownUnitOrAnOtherActionRefusesTheWholeBatchWithTheirOwnCode(): void
    {
        // Given
        $alice = $this->savedUser();
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($alice->getId())->build());
        $listId = $this->sentListOfSlices($alice);
        $product = static fn(array $more = []): array => ['retailerProductId' => '555701', 'label' => 'Fromage Cheddar Burger 20 Tranches - 340g', ...$more];

        // When : une unité qui n'existe pas dans Repas, avec un choix valide dans le même lot
        $this->request('PUT', '/api/v1/drive/choices', $alice, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'sel', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555999', 'label' => 'Sel fin']],
            ['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => $product(['packSize' => ['value' => 20, 'unitSlug' => 'parts-de-gateau']])],
        ]], JSON_THROW_ON_ERROR));

        // Then : tout ou rien
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_PACK_SIZE_INVALID']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $alice);
        $this->assertSame([], $this->jsonBody()['member']);

        // When : une contenance sur un refus
        $this->request('PUT', '/api/v1/drive/choices', $alice, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'cheddar', 'action' => 'REJECT_PRODUCT', 'product' => $product(['packSize' => ['value' => 20, 'unitSlug' => 'tranche']])],
        ]], JSON_THROW_ON_ERROR));

        // Then
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_CHOICE_INVALID']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);

        // When : une valeur hors des bornes
        $this->request('PUT', '/api/v1/drive/choices', $alice, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => $product(['packSize' => ['value' => 10000, 'unitSlug' => 'tranche']])],
        ]], JSON_THROW_ON_ERROR));

        // Then
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_PACK_SIZE_INVALID']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->request('GET', "/api/v1/drive/plans/$listId", $alice);
        $this->assertSame([], $this->jsonBody()['lines'][0]['products']);
    }

    public function testThePackSizeOfOneUserNeverReachesThePlanOfAnother(): void
    {
        // Given : Alice et Bob choisissent le même produit (une fiche commune), Alice donne sa contenance, Bob non
        $alice = $this->savedUser();
        $bob = $this->savedUser();
        foreach ([$alice, $bob] as $user) {
            static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        }
        $aliceList = $this->sentListOfSlices($alice);
        $bobList = $this->sentListOfSlices($bob);
        $intent = static fn(array $more = []): string => json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'cheddar', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555701', 'label' => 'Fromage Cheddar Burger 20 Tranches - 340g', ...$more]],
        ]], JSON_THROW_ON_ERROR);

        // When
        $this->request('PUT', '/api/v1/drive/choices', $alice, $intent(['packSize' => ['value' => 20, 'unitSlug' => 'tranche']]));
        $this->request('PUT', '/api/v1/drive/choices', $bob, $intent());

        // Then
        $this->request('GET', "/api/v1/drive/plans/$aliceList", $alice);
        $this->assertSame([1, 'COMPUTED'], [$this->jsonBody()['lines'][0]['products'][0]['packs'], $this->jsonBody()['lines'][0]['products'][0]['packsStatus']]);
        $this->request('GET', "/api/v1/drive/plans/$bobList", $bob);
        $this->assertSame([null, 'TO_CHECK', null], [$this->jsonBody()['lines'][0]['products'][0]['packs'], $this->jsonBody()['lines'][0]['products'][0]['packsStatus'], $this->jsonBody()['lines'][0]['products'][0]['packSize']]);
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $bob);
        $this->assertNull($this->jsonBody()['member'][0]['accepted'][0]['product']['packSize']);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function actionsOfTheSecondUser(): iterable
    {
        yield 'il accepte le produit' => ['ADD_PRODUCT', 'PRIMARY'];
        yield 'il le refuse' => ['REJECT_PRODUCT', null];
    }

    public function testRefusesTheListsThatTheUserCannotSendToTheDrive(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $sent = $this->savedList($other, ShoppingListStatus::SENT);
        $active = $this->savedList($user, ShoppingListStatus::ACTIVE);

        // When / Then : pas de magasin
        $this->request('GET', '/api/v1/drive/plans/' . $this->savedList($user, ShoppingListStatus::SENT), $user);
        $this->assertSame([409, ['error' => ['message' => 'DRIVE_NOT_CONFIGURED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);

        // Given
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());

        // When / Then : liste non envoyée, liste d'un autre, liste inconnue
        $this->request('GET', "/api/v1/drive/plans/$active", $user);
        $this->assertSame([409, ['error' => ['message' => 'SHOPPING_LIST_NOT_SENT']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->request('GET', "/api/v1/drive/plans/$sent", $user);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/drive/plans/liste-inconnue', $user);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAnAdministratorPassesTheVoterButNeverGetsThePlanOfAnotherUser(): void
    {
        // Given : un administrateur qui a un magasin, la liste envoyée d'un autre utilisateur
        $admin = new UserBuilder()->isAdmin()->build();
        static::getContainer()->get(UserRepository::class)->save($admin);
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($admin->getId())->build());
        $sent = $this->savedList($this->savedUser(), ShoppingListStatus::SENT);

        // When
        $this->request('GET', "/api/v1/drive/plans/$sent", $admin);

        // Then : le voter laisse passer l'administrateur, le loader refuse, et la réponse ne porte rien de la liste
        $this->assertSame([403, ['error' => ['message' => 'SHOPPING_LIST_FORBIDDEN']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function savedList(User $owner, ShoppingListStatus $status): string
    {
        $persister = new DataPersister(static::getContainer());
        $builder = new ShoppingListBuilder()->withOwner($owner)->withStatus($status);
        foreach ([new IngredientBuilder()->isMilk()->build(), new IngredientBuilder()->isEgg()->build()] as $ingredient) {
            $persister->saveIngredient($ingredient);
            $builder->addIngredient($ingredient);
        }
        $list = $builder->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list->getId();
    }

    /**
     * Une liste envoyée au drive de 15 tranches de cheddar : la tranche est une unité sans dimension (ni poids ni volume).
     */
    private function sentListOfSlices(User $owner): string
    {
        $ingredient = new IngredientBuilder()->withName('Cheddar')->withSlug('cheddar')->withDefaultCookingUnit(new UnitBuilder()->isSlice())->withDefaultPurchaseUnit(new UnitBuilder()->isSlice())->build();
        new DataPersister(static::getContainer())->saveIngredient($ingredient);
        $list = new ShoppingListBuilder()->withOwner($owner)->build();
        $list->addIngredient('cheddar', 'tranche');
        $list->addRow('cheddar', 'tranche', 15);
        static::getContainer()->get(ShoppingListRepository::class)->save($list);
        $this->request('POST', '/api/v1/shopping-lists/' . $list->getId() . '/send', $owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        return $list->getId();
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
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
