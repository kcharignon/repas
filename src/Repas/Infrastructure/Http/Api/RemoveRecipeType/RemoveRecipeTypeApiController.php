<?php

namespace Repas\Repas\Infrastructure\Http\Api\RemoveRecipeType;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\RemoveRecipeType\RemoveRecipeTypeCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RemoveRecipeTypeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/recipe-types/{slug}', name: 'api_remove_recipe_type', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Delete(
        summary: 'Supprime le type de recette (administrateur) après avoir passé toutes ses recettes dans un autre type',
        description: "Les recettes de tous les utilisateurs qui portent le type supprimé passent dans le type `transferTo` (leur version augmente), puis le type disparaît ; une seule transaction. Il reste donc toujours au moins un type.",
    )]
    #[OA\Tag(name: 'Recipe types')]
    #[OA\Parameter(name: 'transferTo', in: 'query', required: true, description: 'Slug du type de recette qui reçoit les recettes du type supprimé', schema: new OA\Schema(type: 'string', maxLength: 255), example: 'plat')]
    #[OA\Response(response: 204, description: 'Supprimé, ses recettes sont dans le type de transfert')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 404, description: 'Type à supprimer ou type de transfert inconnu')]
    #[OA\Response(response: 422, description: '`transferTo` absent, ou identique au type supprimé')]
    public function __invoke(string $slug, #[MapQueryString(validationFailedStatusCode: 422)] RemoveRecipeTypeRequestQuery $query): Response
    {
        $this->commandBus->dispatch(new RemoveRecipeTypeCommand(slug: $slug, transferToSlug: $query->transferTo));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
