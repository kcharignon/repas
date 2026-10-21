<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListDuplicateIngredients;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientDTO;
use Repas\Repas\Application\Query\ListDuplicateIngredients\ListDuplicateIngredientsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pas de voter : la lecture ne porte que sur les ingrédients de l'utilisateur du jeton, sans exception pour un administrateur (il n'a
 * aucun ingrédient personnel : sa liste est vide). La route précède `GET /ingredients/{slug}` (`priority`), sinon « duplicates » serait
 * lu comme un slug.
 */
final class ListDuplicateIngredientsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/duplicates', name: 'api_list_duplicate_ingredients', methods: ['GET'], priority: 10)]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Les doublons possibles de l'utilisateur : des paires de SES ingrédients (jamais ceux d'un autre, un administrateur compris), du même rayon, dont les noms se ressemblent (même nom une fois normalisé, à une faute de frappe près, ou assez proche : score de 0,90 au moins) et qu'il n'a pas écartées par « Pas le même ». Triées de la plus probable à la moins probable, 50 au plus, le même ordre à chaque appel. Chaque paire porte de quoi afficher sa carte (les deux ingrédients avec leurs nombres de recettes et de listes, l'ingrédient à garder par défaut). Vide pour un compte qui n'a pas deux ingrédients. Ce que la fusion ferait exactement : `GET …/merge-preview`")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Response(
        response: 200,
        description: 'Doublons possibles',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: DuplicateIngredientDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 3),
        ]),
    )]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var list<DuplicateIngredientDTO> $duplicates */
        $duplicates = $this->queryBus->ask(new ListDuplicateIngredientsQuery($user->getId()));

        return new JsonResponse(['member' => $duplicates, 'totalItems' => count($duplicates)]);
    }
}
