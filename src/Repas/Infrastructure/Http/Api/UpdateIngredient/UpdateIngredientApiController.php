<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateIngredient;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Infrastructure\Http\Api\CreateIngredient\IngredientPayload;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateIngredientApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}', name: 'api_update_ingredient', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('INGREDIENT_OWNER', 'slug')]
    #[OA\Put(summary: "Remplace un ingrédient de l'utilisateur (un ingrédient commun n'est modifiable que par un administrateur) ; son image est conservée")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: IngredientPayload::class)))]
    #[OA\Response(response: 204, description: 'Ingrédient modifié')]
    #[OA\Response(response: 403, description: "L'ingrédient appartient à un autre utilisateur, ou il est commun")]
    #[OA\Response(response: 404, description: 'Ingrédient, rayon ou unité inconnu')]
    #[OA\Response(response: 422, description: 'Corps invalide, ou coefficient manquant alors que les unités diffèrent')]
    public function __invoke(string $slug, #[MapRequestPayload] IngredientPayload $payload): Response
    {
        $this->commandBus->dispatch(new UpdateIngredientCommand(
            slug: $slug,
            name: $payload->name,
            image: null,
            departmentSlug: $payload->departmentSlug,
            defaultCookingUnitSlug: $payload->defaultCookingUnitSlug,
            defaultPurchaseUnitSlug: $payload->defaultPurchaseUnitSlug,
            coefficient: $payload->coefficient,
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
