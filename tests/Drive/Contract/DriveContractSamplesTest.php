<?php

namespace Repas\Tests\Drive\Contract;


use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Concordance entre les réponses réelles de l'API Drive et les exemples versionnés de `docs/drive/samples/` (DRV-501).
 *
 * Le front (et l'agent) lisent ces exemples sans lancer le back : `plan.sample.json` est traduit par `buildPlanMessage` puis validé
 * contre `drive-agent/protocol/plan.schema.json`. Ce test rejoue un vrai parcours (réglages, préférences et prix posés par
 * `PUT /drive/choices`, liste envoyée par `POST /shopping-lists/{id}/send`, horloge figée) puis compare chaque réponse à son
 * exemple champ par champ : clés, ordre des clés et des lignes, types, valeurs. Il échoue quand un DTO change sans que l'exemple
 * soit régénéré : `make drive-samples` réécrit les exemples depuis les réponses réelles, à relire dans le diff.
 *
 * Données inventées uniquement (magasin `123456`, `Ville-Test`, hôte factice, produits et prix imaginaires).
 */
final class DriveContractSamplesTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    private const string USER_ID = '6f1c2a54-3b7e-4d1a-8c0e-5a9d2b7e4f10';
    private const string LIST_ID = '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10';
    private const string STORE_URL = 'https://exemple.leclercdrive.fr/magasin-123456-123456';
    private const string REGENERATE = 'UPDATE_DRIVE_SAMPLES';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
    }

    /**
     * @return iterable<string, array{string, string}> fichier d'exemple => route lue
     */
    public static function samples(): iterable
    {
        yield 'réglages' => ['settings.sample.json', '/api/v1/drive/settings'];
        yield 'préférences' => ['choices.sample.json', '/api/v1/drive/choices?retailer=leclerc'];
        yield 'plan' => ['plan.sample.json', '/api/v1/drive/plans/' . self::LIST_ID];
        yield 'estimation' => ['estimate.sample.json', '/api/v1/drive/estimates/' . self::LIST_ID];
    }

    #[DataProvider('samples')]
    public function testTheSampleIsWhatTheApiAnswers(string $file, string $uri): void
    {
        // Given : un utilisateur qui a réglé son magasin, appris des produits et envoyé sa liste
        $user = $this->playTheJourney();
        self::mockTime(new DateTimeImmutable('2026-10-18T10:30:00+00:00'));

        // When
        $this->request('GET', $uri, $user);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $actual = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $path = static::getContainer()->getParameter('kernel.project_dir') . '/docs/drive/samples/' . $file;
        if (getenv(self::REGENERATE) === '1') {
            file_put_contents($path, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n");
        }
        $this->assertFileExists($path, "Exemple absent : lance `make drive-samples`.");
        $expected = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            self::flatten($expected),
            self::flatten($actual),
            "docs/drive/samples/$file ne correspond plus à la réponse de l'API : lance `make drive-samples` et relis le diff (le front fige ce contrat).",
        );
    }

    public function testThePlanNamesAUnitWithoutSymbolByItsName(): void
    {
        // Given / When
        $user = $this->playTheJourney();
        $this->request('GET', '/api/v1/drive/plans/' . self::LIST_ID, $user);

        // Then : les œufs sont comptés en « unité », qui n'a pas de symbole ; l'agent refuse un texte vide
        $lines = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['lines'];
        $this->assertSame(['g', 'l', 'unité', 'cuillère à soupe', 'kg'], array_map(static fn(array $line): string => $line['needed']['unitSymbol'], $lines));
    }

    /**
     * Aplatit une réponse en lignes `chemin: type valeur` dans l'ordre du document : un nombre entier et un nombre à virgule sont
     * tous deux des « nombres » en JSON, et le diff de PHPUnit montre le chemin exact de chaque écart.
     *
     * @return list<string>
     */
    private static function flatten(mixed $value, string $path = '$'): array
    {
        if (is_array($value)) {
            $lines = [sprintf('%s: %s(%d)', $path, array_is_list($value) ? 'list' : 'object', count($value))];
            foreach ($value as $key => $child) {
                array_push($lines, ...self::flatten($child, is_int($key) ? "{$path}[$key]" : "$path.$key"));
            }

            return $lines;
        }

        return [sprintf('%s: %s %s', $path, is_int($value) || is_float($value) ? 'number' : get_debug_type($value), json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
    }

    /**
     * Le parcours d'un utilisateur, par les vraies routes, à des heures figées :
     * 08:50 réglage du magasin ; 09:00 envoi de la liste ; 09:20 premier passage de l'agent (préférences et prix) ;
     * 10:00 l'agent rafraîchit le prix de la farine ; les exemples sont lus à 10:30.
     *
     * Liste (triée par slug) : farine (préférée + secours), lait (un produit sans prix, un refus), œufs (unité sans symbole,
     * aucun produit), sel (cuillère : contenu non convertible), sucre (« j'en ai toujours »).
     *
     * Rayons (DRV-642, identifiants inventés) : l'agent rapporte le rayon 4001 pour la farine (rayon de Repas « boulangerie », celui
     * du sucre aussi) et 4002 pour le lait (rayon « divers », celui des œufs aussi) ; il n'en rapporte pas pour le sel. Le plan
     * propose donc `[4001]` à la farine et au sucre, `[4002]` au lait et aux œufs, et `[]` au sel.
     *
     * Recherche mémorisée (DRV-650) : la farine est choisie avec la recherche « farine de blé t55 » ; le plan la fait chercher à la place du
     * nom de l'ingrédient ('Farine'), les autres lignes cherchent toujours leur nom, et `GET /drive/choices` la montre pour la farine
     * seulement (`null` ailleurs).
     *
     * Contenance mémorisée (DRV-652) : le produit de secours du sel (« Sel fin 250 g ») est choisi avec sa contenance, 14 cuillères à soupe
     * (réponse de la personne : la cuillère n'a pas de dimension, le contenu en grammes ne permet pas de calculer). Le plan en tire
     * 1 produit (2 cuillères pour 14), `COMPUTED`, alors que le produit préféré du sel, sans contenance, reste `null` / `TO_CHECK` ;
     * l'estimation (qui ne regarde que le produit préféré) ne change pas.
     */
    private function playTheJourney(): User
    {
        $user = new UserBuilder()->withId(self::USER_ID)->build();
        static::getContainer()->get(UserRepository::class)->save($user);
        $this->saveTheShoppingList($user);

        self::mockTime(new DateTimeImmutable('2026-10-18T08:50:00+00:00'));
        $this->request('PUT', '/api/v1/drive/settings', $user, ['retailer' => 'leclerc', 'store' => ['id' => '123456', 'label' => 'Ville-Test', 'baseUrl' => self::STORE_URL]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        self::mockTime(new DateTimeImmutable('2026-10-18T09:00:00+00:00'));
        $this->request('POST', '/api/v1/shopping-lists/' . self::LIST_ID . '/send', $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        self::mockTime(new DateTimeImmutable('2026-10-18T09:20:00+00:00'));
        $this->request('PUT', '/api/v1/drive/choices', $user, ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => [
                'retailerProductId' => '555101', 'ean' => '2000000000008', 'label' => 'Farine de blé T55 1 kg', 'content' => ['value' => 1, 'unit' => 'kg'],
                'price' => ['cents' => 195, 'perMeasure' => ['cents' => 195, 'unit' => 'kg']], 'rayonId' => 4001, 'familyId' => 4101,
            ], 'searchQuery' => 'farine de blé t55'],
            ['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'FALLBACK', 'product' => [
                'retailerProductId' => '555102', 'label' => 'Farine de blé T45 500 g', 'content' => ['value' => 500, 'unit' => 'g'],
                'price' => ['cents' => 109, 'perMeasure' => ['cents' => 218, 'unit' => 'kg']],
            ]],
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => [
                'retailerProductId' => '555201', 'label' => 'Lait demi-écrémé UHT 50 cl', 'content' => ['value' => 50, 'unit' => 'cl'], 'rayonId' => 4002,
            ]],
            ['ingredientSlug' => 'lait', 'action' => 'REJECT_PRODUCT', 'product' => [
                'retailerProductId' => '555299', 'label' => 'Lait entier UHT 1 l', 'content' => ['value' => 1, 'unit' => 'l'],
            ]],
            ['ingredientSlug' => 'sel', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => [
                'retailerProductId' => '555401', 'label' => 'Sel fin 1 kg', 'content' => ['value' => 1, 'unit' => 'kg'],
                'price' => ['cents' => 45],
            ]],
            ['ingredientSlug' => 'sel', 'action' => 'ADD_PRODUCT', 'placement' => 'FALLBACK', 'product' => [
                'retailerProductId' => '555402', 'label' => 'Sel fin 250 g', 'content' => ['value' => 250, 'unit' => 'g'],
                'packSize' => ['value' => 14, 'unitSlug' => 'cuillere-a-soupe'],
            ]],
            ['ingredientSlug' => 'sucre', 'action' => 'HAVE_AT_HOME'],
        ]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        self::mockTime(new DateTimeImmutable('2026-10-18T10:00:00+00:00'));
        $this->request('PUT', '/api/v1/drive/choices', $user, ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'farine', 'action' => 'REFRESH_PRICE', 'product' => ['retailerProductId' => '555101', 'price' => ['cents' => 199, 'perMeasure' => ['cents' => 199, 'unit' => 'kg']]]],
        ]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        return $user;
    }

    private function saveTheShoppingList(User $owner): void
    {
        $persister = new DataPersister(static::getContainer());
        $list = new ShoppingListBuilder()->withId(self::LIST_ID)->withOwner($owner)->withName('Courses de la semaine')->withCreatedAt(new DateTimeImmutable('2026-10-17T18:00:00+00:00'))->build();
        $lines = [
            [new IngredientBuilder()->isFlour()->withName('Farine')->withDefaultPurchaseUnit(new UnitBuilder()->isGramme()), 300],
            [new IngredientBuilder()->isMilk()->withName('Lait'), 1.5],
            [new IngredientBuilder()->isEgg()->withName('Œufs'), 6],
            [new IngredientBuilder()->withName('Sel')->withSlug('sel')->withDefaultCookingUnit(new UnitBuilder()->isSoupSpoon())->withDefaultPurchaseUnit(new UnitBuilder()->isSoupSpoon()), 2],
            [new IngredientBuilder()->isSugar()->withName('Sucre'), 1],
        ];
        foreach ($lines as [$builder, $quantity]) {
            $ingredient = $builder->build();
            $persister->saveIngredient($ingredient);
            $unit = $ingredient->getDefaultPurchaseUnit()->getSlug();
            $list->addIngredient($ingredient->getSlug(), $unit);
            $list->addRow($ingredient->getSlug(), $unit, $quantity);
        }
        static::getContainer()->get(ShoppingListRepository::class)->save($list);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, User $user, ?array $body = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);
    }
}
