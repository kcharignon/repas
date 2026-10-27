<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Command\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;

/**
 * Une conversion universelle (sans ingrédient : « kilo > gramme ») sert à tous les ingrédients de tous les comptes. Aucune suppression du
 * code ne l'atteint : ni celle d'un ingrédient, ni celle d'un compte, ni la fusion (qui passent toutes par ces deux méthodes du port),
 * et aucune route ne supprime une conversion.
 */
class UniversalConversionsAreNeverRemovedTest extends DatabaseTestCase
{
    private ConversionRepository $conversionRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversionRepository = static::getContainer()->get(ConversionRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testRemovingAnIngredientOrAnAccountKeepsTheUniversalConversions(): void
    {
        // Given : « kilo > gramme » universelle, et une conversion propre à l'ingrédient d'un utilisateur
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);
        $flour = new IngredientBuilder()->isFlour()->withCreator($user, refreshSlug: true)->build();
        $this->persister->saveIngredient($flour);
        $universal = new ConversionBuilder()->isKiloToGramme()->build();
        $own = new ConversionBuilder()->withIngredient($flour)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(10)->build();
        $this->persister->saveConversionDependencies($universal);
        $this->persister->saveConversionDependencies($own);
        $this->conversionRepository->save($universal);
        $this->conversionRepository->save($own);

        // When : suppression de l'ingrédient (suppression, fusion), puis du compte (purge)
        $this->conversionRepository->deleteByIngredient($flour);
        $this->assertNotNull($this->findInDatabase($universal->getId()));
        $this->assertNull($this->findInDatabase($own->getId()));

        $this->conversionRepository->deleteByIngredientCreator($user->getId());

        // Then : la conversion propre est partie, l'universelle est toujours là
        $this->assertNotNull($this->findInDatabase($universal->getId()));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NULL'));
    }

    public function testSavingAnIngredientWhoseUnitsAUniversalLineLinksNeverTakesTheLineOver(): void
    {
        // Given : « kilo > gramme » universelle, et la farine d'un utilisateur (recettes au gramme, achat au kilo)
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);
        $flour = new IngredientBuilder()->isFlour()->withCreator($user, refreshSlug: true)->build();
        $this->persister->saveIngredient($flour);
        $universal = new ConversionBuilder()->isKiloToGramme()->build();
        $this->persister->saveConversion($universal);
        $this->entityManager->clear();

        // When : il corrige le nom, le formulaire renvoie le coefficient (c'est ainsi que la ligne universelle disparaissait, prise pour la sienne)
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new UpdateIngredientCommand(
            $flour->getSlug(), 'Farine de blé', null, $flour->getDepartment()->getSlug(), 'gramme', 'kilo', 1000, $user->getId(),
        ));

        // Then : la ligne universelle est toujours universelle, et rien n'est doublé
        $connection = $this->entityManager->getConnection();
        $this->assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NULL AND start_unit = ? AND end_unit = ?', ['kilo', 'gramme']));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NOT NULL'));
        $this->assertSame($universal->getId(), $connection->fetchOne('SELECT id FROM conversion WHERE ingredient IS NULL'));
    }

    public function testNoRouteDeletesAConversion(): void
    {
        // When
        $routes = static::getContainer()->get('router')->getRouteCollection();

        // Then : aucune route de `/api/v1/conversions` n'accepte DELETE
        foreach ($routes as $name => $route) {
            if (str_starts_with($route->getPath(), '/api/v1/conversions')) {
                $this->assertNotContains('DELETE', $route->getMethods(), $name);
            }
        }
        $this->assertNotEmpty(array_filter(iterator_to_array($routes), static fn($route): bool => str_starts_with($route->getPath(), '/api/v1/conversions')));
    }

    private function findInDatabase(string $id): mixed
    {
        return $this->entityManager->getConnection()->fetchOne('SELECT id FROM conversion WHERE id = ?', [$id]) ?: null;
    }
}
