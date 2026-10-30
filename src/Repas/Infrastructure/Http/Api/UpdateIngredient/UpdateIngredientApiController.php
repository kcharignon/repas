<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateIngredient;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Infrastructure\Http\Api\CreateIngredient\IngredientPayload;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
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
    #[OA\Put(summary: "Remplace un ingrédient de l'utilisateur (seul son propriétaire le modifie, un administrateur compris) ; son image est conservée", description: "Le champ facultatif `isBase` pose le marqueur « ingrédient de base » : absent ou `null`, il reste inchangé. Seul le propriétaire du catalogue peut le changer, sur un ingrédient à lui : toute autre valeur que l'actuelle venant d'un autre compte donne `403` `INGREDIENT_BASE_FORBIDDEN`, et rien n'est écrit.")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: IngredientPayload::class)))]
    #[OA\Response(response: 204, description: 'Ingrédient modifié')]
    #[OA\Response(response: 403, description: "L'ingrédient appartient à un autre utilisateur (un administrateur n'a pas de passe-droit)")]
    #[OA\Response(response: 404, description: 'Ingrédient, rayon ou unité inconnu')]
    #[OA\Response(response: 409, description: "`INGREDIENT_NAME_ALREADY_USED` : le nom (une fois normalisé) ou le rayon change et l'utilisateur a déjà un autre ingrédient de ce nom dans ce rayon ; rien n'est écrit. Modifier un ingrédient sans changer ni son nom ni son rayon n'est jamais refusé pour ce motif")]
    #[OA\Response(response: 422, description: 'Corps invalide, ou coefficient manquant alors que les unités diffèrent sans conversion connue (le coefficient est facultatif quand le graphe de conversion de l\'ingrédient les relie déjà), ou unité des recettes qui ne se convertirait pas en unité d\'achat')]
    public function __invoke(string $slug, #[MapRequestPayload] IngredientPayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new UpdateIngredientCommand(
            slug: $slug,
            name: $payload->name,
            image: null,
            departmentSlug: $payload->departmentSlug,
            defaultCookingUnitSlug: $payload->defaultCookingUnitSlug,
            defaultPurchaseUnitSlug: $payload->defaultPurchaseUnitSlug,
            coefficient: $payload->coefficient,
            userId: $user->getId(),
            isBase: $payload->isBase,
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
