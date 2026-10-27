<?php

namespace Repas\Repas\Infrastructure\Http\Api\CopyRecipe;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CopyRecipeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/recipes/{id}/copy', name: 'api_copy_recipe', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        summary: "Copie une recette dans celles de l'utilisateur connecté",
        description: "Chaque ingrédient de la recette est rapproché de ceux que l'utilisateur possède déjà (ce qu'il a adopté, puis le même nom dans le même rayon, avec une unité compatible) ; à défaut, il reçoit une copie de l'ingrédient avec ses conversions propres. Un ingrédient existant de l'utilisateur n'est jamais modifié.",
    )]
    #[OA\Tag(name: 'Recipes')]
    #[OA\Response(response: 201, description: 'Copie créée', headers: [new OA\Header(header: 'Location', description: 'URL de la copie', schema: new OA\Schema(type: 'string'))])]
    #[OA\Response(response: 404, description: 'Recette inconnue')]
    #[OA\Response(response: 422, description: "L'utilisateur a déjà 2 000 recettes, copies comprises (`RECIPE_LIMIT_REACHED`)")]
    public function __invoke(string $id, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $copyId = UuidGenerator::new();
        $this->commandBus->dispatch(new CopyRecipeCommand($id, $user->getId(), $copyId));

        return new Response(status: Response::HTTP_CREATED, headers: ['Location' => $this->generateUrl('api_get_recipe', ['id' => $copyId])]);
    }
}
