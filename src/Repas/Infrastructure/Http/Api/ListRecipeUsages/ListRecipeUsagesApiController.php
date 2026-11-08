<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListRecipeUsages;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListRecipeUsages\ListRecipeUsagesQuery;
use Repas\Repas\Application\Query\ListRecipeUsages\RecipeUsagesDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListRecipeUsagesApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/recipes/{id}/used-in', name: 'api_list_recipe_usages', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('RECIPE_OWNER', 'id')]
    #[OA\Get(
        summary: 'Les recettes de son auteur qui utilisent cette recette',
        description: "Dit pourquoi une recette ne se supprime pas (`409` `RECIPE_USED_AS_COMPONENT`) et alimente la note de sa fiche (ADR 0040 décision 23). Triées par nom puis par identifiant. Une recette secrète n'est montrée qu'à son auteur.",
    )]
    #[OA\Tag(name: 'Recipes')]
    #[OA\Response(
        response: 200,
        description: 'Recettes qui utilisent celle-ci (vide quand aucune ne le fait)',
        content: new OA\JsonContent(ref: new Model(type: RecipeUsagesDTO::class)),
    )]
    #[OA\Response(response: 403, description: "La recette appartient à un autre utilisateur (ou n'a pas de propriétaire) et l'utilisateur n'est pas administrateur")]
    #[OA\Response(response: 404, description: "Recette inconnue, ou secrète et d'un autre (administrateur compris)")]
    public function __invoke(string $id, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var RecipeUsagesDTO $usages */
        $usages = $this->queryBus->ask(new ListRecipeUsagesQuery($id, $user->getId()));

        return new JsonResponse($usages);
    }
}
