<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListMergeCandidates;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListMergeCandidates\ListMergeCandidatesQuery;
use Repas\Repas\Application\Query\ListMergeCandidates\MergeCandidateDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le contrôle de propriété est fait par la lecture (l'ingrédient doit être à l'utilisateur du jeton), pas par le voter : voir
 * `GetMergePreviewApiController`.
 */
final class ListMergeCandidatesApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/merge-candidates', name: 'api_list_merge_candidates', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Les ingrédients de l'utilisateur (les siens, jamais un commun) avec lesquels l'ingrédient de l'URL peut être fusionné, 30 au plus : ceux du même rayon d'abord, les plus ressemblants en tête, puis les autres rayons. Choix de l'ingrédient à garder")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Parameter(name: 'q', in: 'query', required: false, description: 'Ne garde que les ingrédients dont le nom contient ce texte (sans tenir compte de la casse ni des accents), 100 caractères au plus', schema: new OA\Schema(type: 'string', maxLength: 100), example: 'tom')]
    #[OA\Response(
        response: 200,
        description: 'Candidats à la fusion',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: MergeCandidateDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 12),
        ]),
    )]
    #[OA\Response(response: 404, description: "L'ingrédient est inconnu ou appartient à un autre utilisateur (même pour un administrateur)")]
    #[OA\Response(response: 422, description: "Paramètre `q` invalide (trop long), ou `CANNOT_MERGE_COMMON_INGREDIENT` : l'ingrédient est un commun")]
    public function __invoke(string $slug, #[CurrentUser] AuthenticatedUser $user, #[MapQueryString(validationFailedStatusCode: 422)] ListMergeCandidatesRequestQuery $filter = new ListMergeCandidatesRequestQuery()): JsonResponse
    {
        /** @var list<MergeCandidateDTO> $candidates */
        $candidates = $this->queryBus->ask(new ListMergeCandidatesQuery($user->getId(), $slug, $filter->q));

        return new JsonResponse(['member' => $candidates, 'totalItems' => count($candidates)]);
    }
}
