<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateRecipeType;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateRecipeType\UpdateRecipeTypeCommand;
use Repas\Repas\Infrastructure\Http\Api\CreateRecipeType\RecipeTypePayload;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateRecipeTypeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/recipe-types/{slug}', name: 'api_update_recipe_type', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Put(summary: "Remplace le type de recette (administrateur) ; son slug ne change pas")]
    #[OA\Tag(name: 'Recipe types')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RecipeTypePayload::class)))]
    #[OA\Response(response: 204, description: 'Modifié')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 404, description: 'Inconnu')]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    public function __invoke(string $slug, #[MapRequestPayload] RecipeTypePayload $payload): Response
    {
        $this->commandBus->dispatch(new UpdateRecipeTypeCommand(id: $slug, name: $payload->name, image: $payload->image, order: $payload->order));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
