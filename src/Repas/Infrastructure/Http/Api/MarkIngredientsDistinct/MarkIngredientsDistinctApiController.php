<?php

namespace Repas\Repas\Infrastructure\Http\Api\MarkIngredientsDistinct;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\MarkIngredientsDistinct\MarkIngredientsDistinctCommand;
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
 * `OwnerVoter` laisse un administrateur agir sur l'ingrédient d'un autre, « Pas le même » est une préférence privée sans ce passe-droit.
 */
final class MarkIngredientsDistinctApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/distinct-from', name: 'api_mark_ingredients_distinct', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(summary: "« Pas le même » : l'utilisateur dit que l'ingrédient de l'URL et `other` ne sont pas un doublon. La paire (dans un sens ou dans l'autre) n'est plus jamais proposée par `GET /ingredients/duplicates`, même après un renommage. Rien d'autre ne change : aucun ingrédient, aucune recette, aucune liste. Rejouée, elle répond 204 sans rien faire. Les deux ingrédients doivent être à l'utilisateur, même pour un administrateur")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MarkIngredientsDistinctPayload::class)))]
    #[OA\Response(response: 204, description: 'Paire mémorisée, ou déjà mémorisée')]
    #[OA\Response(response: 404, description: "Un des deux ingrédients est inconnu, ou appartient à un autre utilisateur (même pour un administrateur)")]
    #[OA\Response(response: 422, description: "Corps invalide, ou `CANNOT_BE_DISTINCT_FROM_ITSELF` : les deux ingrédients sont le même")]
    public function __invoke(string $slug, #[MapRequestPayload(validationFailedStatusCode: 422)] MarkIngredientsDistinctPayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new MarkIngredientsDistinctCommand($user->getId(), $slug, $payload->other));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
