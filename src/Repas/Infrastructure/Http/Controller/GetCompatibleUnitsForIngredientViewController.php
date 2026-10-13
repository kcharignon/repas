<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\ListCompatibleUnitsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetCompatibleUnitsForIngredientViewController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path:'/ingredients/{slug}/units', name: 'view_ingredient_compatible_units', methods: ['GET'])]
    #[IsGranted("ROLE_USER")]
    public function __invoke(string $slug): JsonResponse
    {
        /** @var list<CompatibleUnitDTO> $compatibleUnits */
        $compatibleUnits = $this->queryBus->ask(new ListCompatibleUnitsQuery($slug));

        return new JsonResponse([
            'units' => array_map(
                static fn(CompatibleUnitDTO $unit): array => ['name' => $unit->name, 'slug' => $unit->slug],
                $compatibleUnits,
            ),
        ]);
    }
}
