<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateIngredient;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CreateIngredientApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients', name: 'api_create_ingredient', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(summary: "Crée un ingrédient : personnalisé pour un utilisateur, commun à tous pour un administrateur")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: IngredientPayload::class)))]
    #[OA\Response(response: 201, description: 'Ingrédient créé', headers: [new OA\Header(header: 'Location', description: "URL de l'ingrédient créé", schema: new OA\Schema(type: 'string'))])]
    #[OA\Response(response: 404, description: 'Rayon ou unité inconnu')]
    #[OA\Response(response: 409, description: 'Un ingrédient de ce nom existe déjà')]
    #[OA\Response(response: 422, description: 'Corps invalide, ou coefficient manquant alors que les unités diffèrent sans conversion connue')]
    public function __invoke(#[MapRequestPayload] IngredientPayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        /** @var string $slug le handler renvoie le slug, qui dérive du nom et du créateur */
        $slug = $this->commandBus->dispatch(new CreateIngredientCommand(
            name: $payload->name,
            image: '',
            departmentSlug: $payload->departmentSlug,
            defaultCookingUnitSlug: $payload->defaultCookingUnitSlug,
            defaultPurchaseUnitSlug: $payload->defaultPurchaseUnitSlug,
            userId: $user->getId(),
            isAdmin: $user->isAdmin(),
            coefficient: $payload->coefficient,
        ));

        return new Response(status: Response::HTTP_CREATED, headers: ['Location' => $this->generateUrl('api_get_ingredient', ['slug' => $slug])]);
    }
}
