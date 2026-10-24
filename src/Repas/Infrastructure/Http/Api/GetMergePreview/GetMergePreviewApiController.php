<?php

namespace Repas\Repas\Infrastructure\Http\Api\GetMergePreview;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\GetMergePreview\GetMergePreviewQuery;
use Repas\Repas\Application\Query\GetMergePreview\MergePreviewDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le contrôle de propriété est fait par la lecture (les deux ingrédients doivent être à l'utilisateur du jeton), pas par le voter :
 * `OwnerVoter` laisse un administrateur voir l'ingrédient d'un autre, la fusion n'a pas ce passe-droit (ADR 0028 décision 9).
 */
final class GetMergePreviewApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/merge-preview', name: 'api_get_merge_preview', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Ce que la fusion de l'ingrédient de l'URL (absorbé) dans `into` (gardé) ferait, sans rien écrire : recettes, listes et choix du drive qui se déplacent, nombre de recettes et de listes qui utiliseront le gardé APRÈS la fusion, conversions que le gardé récupérerait, unités qui manqueraient encore, pourquoi elle serait refusée. Même verdict que `POST …/merge`. Toujours 200 quand les deux ingrédients sont à l'utilisateur : un refus de règle est dans `blockedBy`")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Parameter(name: 'into', in: 'query', required: true, description: "Slug de l'ingrédient à garder", schema: new OA\Schema(type: 'string', maxLength: 255), example: 'tomate-0a1b2c3d')]
    #[OA\Response(response: 200, description: 'Ce que la fusion ferait', content: new OA\JsonContent(ref: new Model(type: MergePreviewDTO::class)))]
    #[OA\Response(response: 404, description: "Un des deux ingrédients est inconnu ou appartient à un autre utilisateur (même pour un administrateur)")]
    #[OA\Response(response: 422, description: 'Paramètre `into` absent ou trop long')]
    public function __invoke(string $slug, #[MapQueryString(validationFailedStatusCode: 422)] GetMergePreviewRequestQuery $filter, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var MergePreviewDTO $preview */
        $preview = $this->queryBus->ask(new GetMergePreviewQuery($user->getId(), $slug, $filter->into));

        return new JsonResponse($preview);
    }
}
