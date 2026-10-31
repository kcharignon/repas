<?php

namespace Repas\Repas\Infrastructure\Http\Api\AddIngredientToShoppingList;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AddIngredientToShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/active/ingredients/{slug}/increase', name: 'api_increase_ingredient', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(summary: 'Ajoute 1 à la quantité d\'un ingrédient dans la liste de sa destination (le choix fait sur le produit, sinon le type de son rayon, sinon la liste principale ; ADR 0036), ouverte à la demande pour un type sans liste en cours. Un ancien slug (ancien commun dont l\'utilisateur a reçu une copie, ingrédient fusionné) désigne son ingrédient actuel, qui est celui de la liste ; l\'ingrédient d\'un autre utilisateur est inconnu')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Quantité augmentée')]
    #[OA\Response(response: 404, description: "Aucune liste principale en cours (quand le produit y va), ou ingrédient inconnu ou personnalisé d'un autre utilisateur")]
    #[OA\Response(response: 412, description: "La version de la liste principale n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture (ignoré pour la liste d'un type)")]
    public function __invoke(string $slug, #[CurrentUser] AuthenticatedUser $user, Request $request): Response
    {
        $this->commandBus->dispatch(new AddIngredientToShoppingListCommand($user->getId(), $slug, EntityVersion::expected($request)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
