<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateDepartment;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateDepartment\UpdateDepartmentCommand;
use Repas\Repas\Infrastructure\Http\Api\CreateDepartment\DepartmentPayload;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateDepartmentApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/departments/{slug}', name: 'api_update_department', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Put(summary: "Remplace le rayon (administrateur) ; son slug ne change pas")]
    #[OA\Tag(name: 'Departments')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: DepartmentPayload::class)))]
    #[OA\Response(response: 204, description: 'Modifié')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 404, description: 'Inconnu')]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    public function __invoke(string $slug, #[MapRequestPayload] DepartmentPayload $payload): Response
    {
        $this->commandBus->dispatch(new UpdateDepartmentCommand(slug: $slug, name: $payload->name, image: $payload->image));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
