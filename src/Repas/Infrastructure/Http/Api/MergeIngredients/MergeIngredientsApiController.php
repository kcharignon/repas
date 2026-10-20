<?php

namespace Repas\Repas\Infrastructure\Http\Api\MergeIngredients;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le contrôle de propriété est fait par le cas d'usage (les deux ingrédients doivent être à l'utilisateur du jeton), pas par le voter :
 * `OwnerVoter` laisse un administrateur modifier l'ingrédient d'un autre, la fusion n'a pas ce passe-droit (ADR 0028 décision 9).
 */
final class MergeIngredientsApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/merge', name: 'api_merge_ingredients', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(summary: "Fusionne deux ingrédients de l'utilisateur : celui de l'URL disparaît, `into` est gardé. Ses recettes, ses listes de courses et ses choix du drive qui utilisaient le premier utilisent le second ; les anciens slugs restent résolus. Les deux ingrédients doivent être à l'utilisateur, jamais un commun, même pour un administrateur. Rejouée après un succès, elle répond 204 sans rien faire. Aperçu sans écriture : `GET …/merge-preview`")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MergeIngredientsPayload::class)))]
    #[OA\Response(response: 204, description: 'Fusion faite, ou rejeu d\'une fusion déjà faite : rien de plus à faire')]
    #[OA\Response(response: 404, description: "Un des deux ingrédients est inconnu ou appartient à un autre utilisateur (même pour un administrateur), ou l'ingrédient de l'URL n'existe plus et n'a pas été fusionné dans `into`")]
    #[OA\Response(response: 409, description: "`INGREDIENT_IN_SENT_LIST` : une liste envoyée au drive utilise l'un des deux (la rouvrir ou la terminer d'abord) ; `INGREDIENT_USED_BY_ANOTHER_USER` : une ligne d'un autre utilisateur utilise l'ingrédient de l'URL (donnée héritée) ; `INGREDIENT_MERGE_CONFLICT` : une écriture concurrente a posé une référence sur l'ingrédient de l'URL, rien n'a changé, réessayer")]
    #[OA\Response(response: 422, description: "Corps invalide ; `CANNOT_MERGE_INTO_ITSELF` : les deux ingrédients sont le même ; `CANNOT_MERGE_COMMON_INGREDIENT` : l'un des deux est un commun ; `MERGE_UNIT_MISSING` : une unité de l'ingrédient de l'URL ne se convertit pas dans l'unité d'achat de `into`, rien n'a changé")]
    public function __invoke(string $slug, #[MapRequestPayload(validationFailedStatusCode: 422)] MergeIngredientsPayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new MergeIngredientsCommand($user->getId(), $slug, $payload->into));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
