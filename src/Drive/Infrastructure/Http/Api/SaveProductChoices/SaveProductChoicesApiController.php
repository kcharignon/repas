<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveProductChoices;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\SaveProductChoices\SaveProductChoicesCommand;
use Repas\Drive\Infrastructure\Http\DriveRateLimit;
use Repas\Drive\Infrastructure\Http\RateLimitedPerUser;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
#[RateLimitedPerUser(DriveRateLimit::WRITE)]
final class SaveProductChoicesApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/choices', name: 'api_save_product_choices', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(summary: "Mémorise un lot d'intentions de l'agent (produits choisis, refusés, « j'en ai toujours ») dans les préférences de l'utilisateur connecté. La fiche d'un produit déjà connu (commune au magasin) n'est complétée que là où elle est vide : libellé, contenu et code-barres ne sont jamais réécrits ; le prix ne change que par `REFRESH_PRICE` ou par l'ajout d'un produit que l'utilisateur accepte déjà. Un `ADD_PRODUCT` peut porter la recherche qui a fait trouver le produit (`searchQuery`, mémorisée avec la préférence de cette enseigne et de ce magasin ; clé absente : gardée, `null` : effacée)")]
    #[OA\Tag(name: 'Drive')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveProductChoicesPayload::class)))]
    #[OA\Response(response: 204, description: 'Préférences enregistrées (tout ou rien)')]
    #[OA\Response(response: 404, description: 'Fonction drive coupée (message `DRIVE_DISABLED`)')]
    #[OA\Response(response: 409, description: "Pas de magasin enregistré (message `DRIVE_NOT_CONFIGURED`), ou `storeId` n'est pas le magasin courant (message `DRIVE_STORE_MISMATCH`)")]
    #[OA\Response(response: 422, description: "Corps invalide, ou intention refusée (message `DRIVE_CHOICE_INVALID`) : identifiant, libellé, contenu ou prix hors des bornes, plus de 200 intentions, plus de 5 produits acceptés ou 20 refusés pour un ingrédient, produit à la fois choisi et refusé pour un ingrédient, recherche mémorisée sur une autre action que `ADD_PRODUCT` ; ou recherche mémorisée refusée (message `DRIVE_SEARCH_QUERY_INVALID`) : moins de 2 ou plus de 80 caractères une fois les espaces réduites, caractère de contrôle ou invisible ; ou plafond atteint (message `DRIVE_LIMIT_REACHED`) : plus de 5 000 écritures de produits dans la journée UTC, ou plus de 1 000 préférences dans le magasin. Rien n'est enregistré")]
    #[OA\Response(response: 429, ref: '#/components/responses/DriveWriteRateLimited')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapRequestPayload] SaveProductChoicesPayload $payload, Request $request): Response
    {
        $this->commandBus->dispatch(new SaveProductChoicesCommand(
            userId: $user->getId(),
            retailer: $payload->retailer,
            storeId: $payload->storeId,
            intents: $payload->toIntents($request->getPayload()->all('choices')),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
