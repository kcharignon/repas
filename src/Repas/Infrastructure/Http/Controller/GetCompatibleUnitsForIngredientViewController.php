<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\StringTool;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GetCompatibleUnitsForIngredientViewController extends AbstractController
{
    public function __construct(
        private readonly IngredientRepository $ingredientRepository,
    ) {
    }

    #[Route(path:'/ingredients/{slug}/units', name: 'view_ingredient_compatible_units', methods: ['GET'])]
    #[IsGranted("ROLE_USER")]
    public function __invoke(string $slug): JsonResponse
    {
        $ingredient = $this->ingredientRepository->findOneBySlug($slug);

        $compatibleUnits = $ingredient->getCompatibleUnits()->map(fn(Unit $unit) => [
            "name" => StringTool::upperCaseFirst($unit->getName()),
            "slug" => $unit->getSlug(),
        ]);

        return new JsonResponse([
            'units' => $compatibleUnits->toArray(),
        ]);
    }
}
