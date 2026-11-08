<?php

namespace Repas\Repas\Infrastructure\Http\Api\SetIngredientParent;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\SetIngredientParent\SetIngredientParentCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SetIngredientParentApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/parent', name: 'api_set_ingredient_parent', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('INGREDIENT_OWNER', 'slug')]
    #[OA\Put(
        summary: "Découpe un ingrédient : il devient une PARTIE d'un autre (« Jaune d'œuf » est une partie d'« Œuf »), ou change de parent ou de ratio",
        description: "Seul le propriétaire de l'ingrédient le découpe, un administrateur compris (`403` pour l'ingrédient d'un autre) ; le parent est à l'utilisateur lui aussi, sinon il est inconnu (`404`). Une partie reste un ingrédient ordinaire (recettes, conversions) mais n'est jamais une ligne de la liste de courses : elle se plie dans son parent. Un seul parent, profondeur 1 : un parent n'est pas une partie et une partie n'a pas de partie ; dix parties au plus par parent. Idempotent : refaire le même appel, ou ne changer que le ratio, n'est jamais refusé. Rien n'est écrit sur un refus. Les listes en cours ne sont pas recalculées.",
    )]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SetIngredientParentPayload::class)))]
    #[OA\Response(response: 204, description: 'Ingrédient découpé (ou déjà découpé ainsi)')]
    #[OA\Response(response: 403, description: "L'ingrédient appartient à un autre utilisateur (un administrateur n'a pas de passe-droit)")]
    #[OA\Response(response: 404, description: "Ingrédient inconnu, ou parent inconnu ou à un autre utilisateur")]
    #[OA\Response(response: 409, description: "L'ingrédient a une ligne dans une liste de courses non terminée (`INGREDIENT_PART_IN_LIST`) : une partie n'est jamais une ligne de liste ; seulement quand le parent change")]
    #[OA\Response(response: 422, description: "Corps invalide, ou un cas métier : un ingrédient n'est pas sa propre partie (`INGREDIENT_PART_OF_ITSELF`) ; le parent est déjà une partie ou l'ingrédient a déjà des parties (`INGREDIENT_PART_CHAIN`) ; le parent a déjà dix parties (`INGREDIENT_PART_LIMIT_REACHED`) ; le ratio n'est pas un nombre de 0,000001 à 1 000 000 (`INGREDIENT_PART_RATIO_INVALID`, précédé de `ratio: ` quand c'est le corps qui le refuse)")]
    public function __invoke(string $slug, #[MapRequestPayload(validationFailedStatusCode: 422)] SetIngredientParentPayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new SetIngredientParentCommand(
            ingredientSlug: $slug,
            parentSlug: $payload->parent,
            ratio: (float) $payload->ratio,
            userId: $user->getId(),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
