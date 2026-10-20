<?php

namespace Repas\Tests\Helper;


use DateTimeImmutable;
use Repas\Repas\Application\Command\AdoptCommonIngredient\AdoptCommonIngredientHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;

/**
 * Monte le vrai `IngredientSlugResolver` (et le vrai cas d'usage d'adoption qu'il appelle) sur des doubles en mémoire, pour les tests
 * des handlers d'écriture qui reçoivent un slug d'ingrédient (ADR 0028, ING-002) : aucun alias au départ, donc aucun changement de
 * comportement pour le handler testé, sauf si le test en programme.
 */
final class IngredientSlugResolverFactory
{
    public static function create(
        IngredientRepository $ingredients,
        ?IngredientAliasInMemoryRepository $aliases = null,
        ?ConversionRepository $conversions = null,
        string $now = '2026-10-21 03:00:00',
    ): IngredientSlugResolver {
        $aliases ??= new IngredientAliasInMemoryRepository();

        return new IngredientSlugResolver(
            $aliases,
            $ingredients,
            new AdoptCommonIngredientHandler(
                $ingredients,
                $conversions ?? new ConversionInMemoryRepository(),
                $aliases,
                new IngredientSlugPolicy(),
                new UuidGenerator(),
                new FrozenClock(new DateTimeImmutable($now)),
            ),
        );
    }
}
