<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\ClassifyBaseIngredients\ClassifyBaseIngredientsCommand;
use Repas\Repas\Application\Command\ClassifyBaseIngredients\ClassifyBaseIngredientsHandler;
use Repas\Repas\Domain\Exception\IngredientCatalogException;
use Repas\Tests\Helper\BaseIngredientClassificationInMemory;
use Repas\Tests\Helper\InMemoryRepository\IngredientCatalogHandoverInMemoryRepository;

/**
 * Phase 1 de la passation (ING-009) sans base : le marqueur « de base » est posé d'après le fichier sur les ingrédients sans créateur, jamais sur
 * un autre, et l'écriture est REFUSÉE si l'ensemble des ingrédients sans créateur n'est pas exactement celui du fichier. Le SQL est prouvé sur la
 * vraie base (`IngredientCatalogHandoverRepositoryTest`).
 */
class ClassifyBaseIngredientsHandlerTest extends TestCase
{
    private const array FILE = ['beurre' => true, 'farine' => true, 'piment' => false, 'sauce' => false];

    private IngredientCatalogHandoverInMemoryRepository $repository;
    private ClassifyBaseIngredientsHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new IngredientCatalogHandoverInMemoryRepository();
        $this->handler = new ClassifyBaseIngredientsHandler($this->repository, new BaseIngredientClassificationInMemory(self::FILE));
    }

    public function testItMarksTheOldCommonsAsTheFileSaysAndCountsWhatItWrote(): void
    {
        // Given : quatre anciens communs sans marqueur
        $this->repository->withoutCreator(['beurre' => false, 'farine' => false, 'piment' => false, 'sauce' => false]);

        // When
        $result = ($this->handler)(new ClassifyBaseIngredientsCommand());

        // Then : deux de base, deux spécifiques ; deux marqueurs écrits (les deux autres étaient déjà conformes)
        $this->assertSame(['lock', 'classify'], $this->repository->calls);
        $this->assertSame([4, 2, 2, 2], [$result->classified, $result->base, $result->specific, $result->changed]);
        $this->assertSame(['beurre' => true, 'farine' => true, 'piment' => false, 'sauce' => false], array_map(static fn(array $i): bool => $i['base'], $this->repository->ingredients));
    }

    public function testReplayingItWritesNothing(): void
    {
        // Given : le classement est déjà posé
        $this->repository->withoutCreator(self::FILE);

        // When
        $result = ($this->handler)(new ClassifyBaseIngredientsCommand());

        // Then : rien n'est écrit
        $this->assertSame(0, $result->changed);
        $this->assertSame(4, $result->classified);
    }

    public function testItRefusesAnOldCommonThatIsNotInTheFileAndWritesNothing(): void
    {
        // Given : un commun que le fichier ne connaît pas
        $this->repository->withoutCreator(['beurre' => false, 'farine' => false, 'piment' => false, 'sauce' => false, 'inconnu' => false]);

        // Then
        $this->expectException(IngredientCatalogException::class);
        $this->expectExceptionMessage('1 ne sont pas dans le fichier, 0 du fichier n\'y sont pas');
        try {
            ($this->handler)(new ClassifyBaseIngredientsCommand());
        } finally {
            $this->assertNotContains('classify', $this->repository->calls, 'rien n\'est écrit');
            $this->assertFalse($this->repository->ingredients['beurre']['base']);
        }
    }

    public function testItRefusesAnIngredientOfTheFileThatIsMissingAndWritesNothing(): void
    {
        // Given : « sauce » manque
        $this->repository->withoutCreator(['beurre' => false, 'farine' => false, 'piment' => false]);

        // Then
        $this->expectException(IngredientCatalogException::class);
        $this->expectExceptionMessage('0 ne sont pas dans le fichier, 1 du fichier n\'y sont pas');
        try {
            ($this->handler)(new ClassifyBaseIngredientsCommand());
        } finally {
            $this->assertNotContains('classify', $this->repository->calls);
        }
    }

    public function testItNeverTouchesAnIngredientThatHasACreatorEvenWhenItsSlugIsInTheFile(): void
    {
        // Given : la passation est faite (les quatre ont un créateur) ; le propriétaire a retiré le marqueur de « beurre »
        $this->repository->ingredients = [
            'beurre' => ['creator' => 'owner-1', 'base' => false],
            'farine' => ['creator' => 'owner-1', 'base' => true],
            'piment' => ['creator' => 'owner-1', 'base' => false],
            'sauce' => ['creator' => 'owner-1', 'base' => false],
        ];

        // When
        $result = ($this->handler)(new ClassifyBaseIngredientsCommand());

        // Then : il n'y a plus rien à classer, le choix du propriétaire est respecté
        $this->assertSame([0, 0, 0, 0], [$result->classified, $result->base, $result->specific, $result->changed]);
        $this->assertNotContains('classify', $this->repository->calls);
        $this->assertFalse($this->repository->ingredients['beurre']['base']);
    }

    public function testTheRefusalMessageNamesNoIngredient(): void
    {
        $this->repository->withoutCreator(['une-marque-personnelle' => false]);

        try {
            ($this->handler)(new ClassifyBaseIngredientsCommand());
            $this->fail('refus attendu');
        } catch (IngredientCatalogException $e) {
            $this->assertStringNotContainsString('une-marque-personnelle', $e->getMessage());
        }
    }
}
