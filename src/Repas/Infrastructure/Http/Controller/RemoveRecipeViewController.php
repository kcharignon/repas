<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\RemoveRecipe\RemoveRecipeCommand;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RemoveRecipeViewController extends AbstractController
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/recipe/{id}', name: 'view_recipe_remove', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('RECIPE_OWNER', 'id')]
    public function __invoke(string $id): JsonResponse
    {
        $command = new RemoveRecipeCommand($id);
        try {
            $this->commandBus->dispatch($command);
        } catch (RecipeException) {
            return new JsonResponse([
                "status" => "error",
                "alerts" => [[
                    "status" => "danger",
                    "message" => "Recette utilisée dans une liste de course !",
                ]]
            ]);
        }

        return new JsonResponse([
            "status" => "success",
            "views" => [[
                "selector" => "#recipe_$id",
                "html" => "",
            ]]
        ]);
    }
}
