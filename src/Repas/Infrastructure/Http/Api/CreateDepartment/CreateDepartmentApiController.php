<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateDepartment;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\CreateDepartment\CreateDepartmentCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CreateDepartmentApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/departments', name: 'api_create_department', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(summary: "Crée un rayon (administrateur)")]
    #[OA\Tag(name: 'Departments')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: DepartmentPayload::class)))]
    #[OA\Response(response: 201, description: 'Créé', headers: [new OA\Header(header: 'Location', description: 'URL de la ressource créée', schema: new OA\Schema(type: 'string'))])]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 409, description: 'Un élément de même slug existe déjà (le slug dérive du nom)')]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    public function __invoke(#[MapRequestPayload] DepartmentPayload $payload): Response
    {
        /** @var string $slug le handler renvoie le slug, qui dérive du nom */
        $slug = $this->commandBus->dispatch(new CreateDepartmentCommand(name: $payload->name, image: $payload->image));

        return new Response(status: Response::HTTP_CREATED, headers: ['Location' => $this->generateUrl('api_get_department', ['slug' => $slug])]);
    }
}
