<?php

namespace Repas\Repas\Infrastructure\Http\Api\RemoveMealFromPlan;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RemoveMealFromPlanApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/active/recipes/{recipeId}', name: 'api_unplan_recipe', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Delete(
        summary: 'Retire une recette des listes de l\'utilisateur et déduit ses ingrédients',
        description: "Quand la recette a été planifiée dans plusieurs listes (ADR 0036), le retrait agit sur TOUT le groupe de repas, en une transaction, et chaque liste ne perd que les lignes que son repas y avait posées. Si une liste du groupe est envoyée au drive, tout est refusé (409 `SHOPPING_LIST_SENT`) et rien n'est écrit.",
    )]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Recette retirée')]
    #[OA\Response(response: 404, description: "Aucune liste en cours, ressource inconnue (ou recette secrète d'un autre, que seul son auteur voit)")]
    #[OA\Response(response: 409, description: "Une liste du groupe est envoyée au drive : elle n'est plus modifiable (message `SHOPPING_LIST_SENT`), rien n'est écrit")]
    #[OA\Response(response: 412, description: "La version de la liste principale n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture (ignoré pour les listes d'un type)")]
    public function __invoke(string $recipeId, #[CurrentUser] AuthenticatedUser $user, Request $request): Response
    {
        $this->commandBus->dispatch(new removeMealFromPlanCommand($user->getId(), $recipeId, EntityVersion::expected($request)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
