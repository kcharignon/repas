<?php

namespace Repas\Repas\Infrastructure\Http\Api\GetIngredient;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\GetIngredient\GetIngredientQuery;
use Repas\Repas\Application\Query\GetIngredient\IngredientDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetIngredientApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}', name: 'api_get_ingredient', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: 'Détail d\'un ingrédient de l\'utilisateur authentifié. Celui d\'un autre utilisateur, un administrateur compris, est inconnu (404) ; un ancien slug (commun adopté, ingrédient fusionné) ne se lit plus')]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Response(
        response: 200,
        description: 'Détail de l\'ingrédient',
        content: new OA\JsonContent(ref: new Model(type: IngredientDTO::class)),
    )]
    #[OA\Response(response: 404, description: 'Ingrédient inconnu, ou à un autre utilisateur (un administrateur n\'a pas de passe-droit)')]
    public function __invoke(string $slug, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var IngredientDTO $ingredient */
        $ingredient = $this->queryBus->ask(new GetIngredientQuery($slug, $user->getId()));

        return new JsonResponse($ingredient);
    }
}
