<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListIngredientsToFix;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListIngredientsToFix\IngredientsToFixDTO;
use Repas\Repas\Application\Query\ListIngredientsToFix\ListIngredientsToFixQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pas de voter : la lecture ne porte que sur les ingrédients de l'utilisateur du jeton, sans exception pour un administrateur. La route précède
 * `GET /ingredients/{slug}` (`priority`), sinon « to-fix » serait lu comme un slug.
 */
final class ListIngredientsToFixApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/to-fix', name: 'api_list_ingredients_to_fix', methods: ['GET'], priority: 10)]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        summary: "Les ingrédients de l'utilisateur à régler : ceux dont l'unité des recettes ne se convertit pas en l'unité d'achat",
        description: "Seuls SES ingrédients sont jugés (jamais ceux d'un autre, un administrateur compris). Un ingrédient est à régler quand aucune conversion, propre ou universelle, ni aucune suite de conversions ne relie son unité des recettes à son unité d'achat, le même calcul que `convertible` d'une ligne de recette. Triés par nom, 200 au plus (`totalItems` donne le vrai nombre), le même ordre à chaque appel. Pour en régler un : `POST …/ingredients/{slug}/conversions`.",
    )]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Response(
        response: 200,
        description: 'Ingrédients à régler',
        content: new OA\JsonContent(ref: new Model(type: IngredientsToFixDTO::class)),
    )]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var IngredientsToFixDTO $toFix */
        $toFix = $this->queryBus->ask(new ListIngredientsToFixQuery($user->getId()));

        return new JsonResponse($toFix);
    }
}
