<?php

namespace Repas\Drive\Infrastructure\Http\Api\ListChoices;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Query\ListChoices\ChoiceDTO;
use Repas\Drive\Application\Query\ListChoices\ListChoicesQuery;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
final class ListChoicesApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/choices', name: 'api_list_choices', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Préférences de produits de l'utilisateur connecté dans son magasin courant, par ingrédient : produits acceptés classés, produits refusés, « j'en ai toujours »")]
    #[OA\Tag(name: 'Drive')]
    #[OA\Parameter(name: 'retailer', in: 'query', required: true, description: 'Enseigne du drive', schema: new OA\Schema(type: 'string', enum: Retailer::IDS), example: 'leclerc')]
    #[OA\Response(
        response: 200,
        description: "Les préférences de l'utilisateur (liste vide tant qu'il n'a pas de magasin ni de préférence), triées par slug d'ingrédient",
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: ChoiceDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 3),
        ]),
    )]
    #[OA\Response(response: 404, description: 'Fonction drive coupée (message `DRIVE_DISABLED`), ou enseigne éteinte (message `DRIVE_RETAILER_DISABLED`, `DRIVE_RETAILERS`)')]
    #[OA\Response(response: 422, description: 'Paramètre `retailer` absent ou inconnu')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapQueryString(validationFailedStatusCode: 422)] ListChoicesRequestQuery $filter): JsonResponse
    {
        /** @var list<ChoiceDTO> $choices */
        $choices = $this->queryBus->ask(new ListChoicesQuery($user->getId(), $filter->retailer));

        return new JsonResponse(['member' => $choices, 'totalItems' => count($choices)]);
    }
}
