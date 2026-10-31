<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateShoppingListType;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\CreateShoppingListType\CreateShoppingListTypeCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CreateShoppingListTypeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-list-types', name: 'api_create_shopping_list_type', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        summary: "Crée un type de liste de courses pour l'utilisateur authentifié (10 au plus par compte)",
        description: "Le type naît sans rayon : on les rattache ensuite par `PUT /api/v1/shopping-list-types/{id}`. Le propriétaire est l'utilisateur du jeton, jamais le corps. `Location` est l'adresse du type créé.",
    )]
    #[OA\Tag(name: 'Shopping list types')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateShoppingListTypePayload::class)))]
    #[OA\Response(response: 201, description: 'Type créé', headers: [new OA\Header(header: 'Location', description: 'URL du type créé', schema: new OA\Schema(type: 'string'))])]
    #[OA\Response(response: 409, description: 'Un type de ce nom existe déjà pour ce compte, une fois le nom normalisé (`LIST_TYPE_NAME_ALREADY_USED`)')]
    #[OA\Response(response: 422, description: 'Corps invalide : nom vide, de plus de 40 caractères ou sans lettre ni chiffre (`LIST_TYPE_NAME_INVALID`), ou le compte a déjà 10 types (`LIST_TYPE_LIMIT_REACHED`)')]
    public function __invoke(#[MapRequestPayload] CreateShoppingListTypePayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $id = UuidGenerator::new();
        $this->commandBus->dispatch(new CreateShoppingListTypeCommand(id: $id, name: $payload->name, userId: $user->getId()));

        return new Response(status: Response::HTTP_CREATED, headers: ['Location' => $this->generateUrl('api_get_shopping_list_type', ['id' => $id])]);
    }
}
