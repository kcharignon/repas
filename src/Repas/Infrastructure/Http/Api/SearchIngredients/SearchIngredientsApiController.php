<?php

namespace Repas\Repas\Infrastructure\Http\Api\SearchIngredients;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\SearchIngredients\IngredientSearchResultDTO;
use Repas\Repas\Application\Query\SearchIngredients\SearchIngredientsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SearchIngredientsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    // Priorité : sans elle, `GET /api/v1/ingredients/{slug}` capterait « search »
    #[Route(path: '/api/v1/ingredients/search', name: 'api_search_ingredients', methods: ['GET'], priority: 10)]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Recherche d'ingrédients par nom, du plus pertinent au moins pertinent, avec leur rayon : ceux de l'utilisateur authentifié et les communs qu'il n'a pas adoptés (un commun adopté est remplacé par sa copie, qui est à lui)")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Parameter(name: 'q', in: 'query', required: true, description: 'Texte recherché, 100 caractères au plus ; ni la casse ni les accents ne comptent et une faute de frappe est tolérée (Jaro-Winkler)', schema: new OA\Schema(type: 'string', maxLength: 100), example: 'tomtae')]
    #[OA\Response(
        response: 200,
        description: 'Ingrédients trouvés (30 au plus), le plus pertinent d\'abord',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: IngredientSearchResultDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 3),
        ]),
    )]
    #[OA\Response(response: 422, description: 'Paramètre `q` absent, vide ou trop long')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapQueryString(validationFailedStatusCode: 422)] SearchIngredientsRequestQuery $filter): JsonResponse
    {
        /** @var list<IngredientSearchResultDTO> $ingredients */
        $ingredients = $this->queryBus->ask(new SearchIngredientsQuery($filter->q, $user->getId(), $this->isGranted('ROLE_ADMIN')));

        return new JsonResponse(['member' => $ingredients, 'totalItems' => count($ingredients)]);
    }
}
