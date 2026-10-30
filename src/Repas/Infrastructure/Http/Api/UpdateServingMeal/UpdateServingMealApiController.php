<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateServingMeal;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateServingMealApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/meals/{id}/serving', name: 'api_update_meal_serving', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('MEAL_OWNER', 'id')]
    #[OA\Put(summary: 'Change le nombre de personnes d\'un repas ; les quantités de la liste sont recalculées')]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateServingMealPayload::class)))]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Portions modifiées')]
    #[OA\Response(response: 403, description: "La ressource appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: "Ressource inconnue (ou repas hérité d'une recette secrète d'un autre, que seul son auteur voit)")]
    #[OA\Response(response: 409, description: 'La liste est envoyée au drive : elle n\'est plus modifiable (message `SHOPPING_LIST_SENT`)')]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    #[OA\Response(response: 412, description: "La version de la liste n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    public function __invoke(string $id, #[MapRequestPayload] UpdateServingMealPayload $payload, Request $request): Response
    {
        $this->commandBus->dispatch(new UpdateServingMealCommand($id, $payload->serving, EntityVersion::expected($request)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
