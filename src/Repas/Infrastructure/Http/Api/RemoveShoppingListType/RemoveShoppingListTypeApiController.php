<?php

namespace Repas\Repas\Infrastructure\Http\Api\RemoveShoppingListType;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\RemoveShoppingListType\RemoveShoppingListTypeCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RemoveShoppingListTypeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-list-types/{id}', name: 'api_remove_shopping_list_type', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('LIST_TYPE_OWNER', 'id')]
    #[OA\Delete(
        summary: "Supprime un type de liste de courses de l'utilisateur sans rien perdre",
        description: "Une seule transaction : les rayons rattachés au type sont détachés, les produits qui le désignaient redeviennent « comme leur rayon », ses listes n'ont plus de type et celle qui était en cours passe en pause (version +1), sous son nom, visible dans « Mes listes ». Aucune ligne n'est déplacée ni fusionnée. Seul le propriétaire du type le supprime, un administrateur compris.",
    )]
    #[OA\Tag(name: 'Shopping list types')]
    #[OA\Response(response: 204, description: 'Type supprimé, ses listes sont sans type')]
    #[OA\Response(response: 403, description: "Le type appartient à un autre utilisateur (un administrateur n'a pas de passe-droit)")]
    #[OA\Response(response: 404, description: 'Type inconnu')]
    public function __invoke(string $id, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new RemoveShoppingListTypeCommand(id: $id, userId: $user->getId()));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
