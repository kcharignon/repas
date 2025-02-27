<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\CopyRecipe\CopyRecipeCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CopyRecipeViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/recipe/{id}/copy', name: 'view_recipe_copy', methods: ['POST'])]
    #[IsGranted("ROLE_USER")]
    public function __invoke(string $id): JsonResponse
    {
        $userConnected = $this->getUser();
        assert($userConnected instanceof User);

        $command = new CopyRecipeCommand($id, $userConnected->getId());
        try {
            $this->commandBus->dispatch($command);
        } catch (DomainException) {
            return new JsonResponse([
                "status" => "error",
                "alerts" => [
                    [
                        "status" => "danger",
                        "message" => "Une erreur est survenue durant l'ajout de la recette",
                    ]
                ],

            ]);
        }


        return new JsonResponse([
            "status" => "success",
            "alerts" => [
                [
                "status" => "success",
                "message" => "Recette ajoutée",
                ]
            ],
            "views" => [
                [
                    "selector" => "#recipe-$id",
                    "html" => "",
                ]
            ]
        ]);
    }
}
