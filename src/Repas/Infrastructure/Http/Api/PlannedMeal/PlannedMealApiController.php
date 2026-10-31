<?php

namespace Repas\Repas\Infrastructure\Http\Api\PlannedMeal;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealList;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealResult;
use Repas\Repas\Infrastructure\Presentation\ShoppingListDateLabel;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PlannedMealApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/active/recipes/{recipeId}', name: 'api_plan_recipe', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(
        summary: 'Planifie une recette dans les listes en cours de l\'utilisateur et ajoute ses ingrédients (404 si la liste principale doit recevoir un produit et n\'est pas en cours)',
        description: "Chaque produit de la recette va dans la liste de SA destination (ADR 0036 : le choix fait sur le produit, sinon le type de son rayon, sinon la liste principale). Une liste de type sans liste en cours est ouverte à la demande, nommée comme le type. Chaque liste qui reçoit au moins un produit reçoit un repas ; une recette déjà dans l'une des listes visées n'est planifiée dans aucune (409, rien n'est écrit). Sans type de liste, la liste principale seule reçoit la recette, comme avant.",
    )]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(
        response: 200,
        description: 'Recette planifiée : les listes qui ont reçu quelque chose, la liste principale d\'abord',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'lists', type: 'array', items: new OA\Items(ref: new Model(type: PlannedMealListResponse::class))),
        ]),
    )]
    #[OA\Response(response: 404, description: "Aucune liste active, recette inconnue ou recette d'un autre utilisateur")]
    #[OA\Response(response: 409, description: "La recette est déjà dans une des listes visées : elle n'est planifiée dans aucune, rien n'est écrit")]
    #[OA\Response(response: 412, description: "La version de la liste principale n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture (ignoré pour les listes d'un type)")]
    public function __invoke(string $recipeId, #[CurrentUser] AuthenticatedUser $user, Request $request): JsonResponse
    {
        /** @var PlannedMealResult $result */
        $result = $this->commandBus->dispatch(new PlannedMealCommand($user->getId(), $recipeId, $user->getDefaultServing(), EntityVersion::expected($request)));

        return new JsonResponse(['lists' => array_map(
            static fn(PlannedMealList $list): PlannedMealListResponse => new PlannedMealListResponse($list->id, ShoppingListDateLabel::nameOf($list->name, $list->createdAt), $list->typeId),
            $result->lists,
        )]);
    }
}
