<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateShoppingListType;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateShoppingListType\UpdateShoppingListTypeCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateShoppingListTypeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-list-types/{id}', name: 'api_update_shopping_list_type', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('LIST_TYPE_OWNER', 'id')]
    #[OA\Put(
        summary: "Remplace le nom d'un type de liste de courses de l'utilisateur et le jeu complet de ses rayons",
        description: "Seul le propriétaire du type l'écrit, un administrateur compris (`403` pour le type d'un autre). Un rayon rattaché alors qu'un autre type de l'utilisateur l'avait est déplacé ; changer les rayons d'un type ne déplace aucune ligne des listes déjà remplies (le choix se fait à l'ajout et se fige). Rien n'est écrit en cas de refus.",
    )]
    #[OA\Tag(name: 'Shopping list types')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateShoppingListTypePayload::class)))]
    #[OA\Response(response: 204, description: 'Type enregistré')]
    #[OA\Response(response: 403, description: "Le type appartient à un autre utilisateur (un administrateur n'a pas de passe-droit)")]
    #[OA\Response(response: 404, description: 'Type inconnu, ou un des rayons est inconnu')]
    #[OA\Response(response: 409, description: 'Un autre type de ce compte porte déjà ce nom, une fois normalisé (`LIST_TYPE_NAME_ALREADY_USED`)')]
    #[OA\Response(response: 422, description: 'Corps invalide : nom vide, de plus de 40 caractères ou sans lettre ni chiffre (`LIST_TYPE_NAME_INVALID`), ou `departmentSlugs` qui n\'est pas une liste de textes')]
    public function __invoke(string $id, #[MapRequestPayload] UpdateShoppingListTypePayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new UpdateShoppingListTypeCommand(
            id: $id,
            name: $payload->name,
            departmentSlugs: array_values($payload->departmentSlugs),
            userId: $user->getId(),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
