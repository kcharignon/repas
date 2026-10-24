<?php

namespace Repas\Drive\Infrastructure\Http\Api\ReplaceIngredientPreference;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\ReplaceIngredientPreference\ReplaceIngredientPreferenceCommand;
use Repas\Drive\Infrastructure\Http\DriveRateLimit;
use Repas\Drive\Infrastructure\Http\RateLimitedPerUser;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
#[RateLimitedPerUser(DriveRateLimit::WRITE)]
final class ReplaceIngredientPreferenceApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/choices/{ingredientSlug}', name: 'api_replace_ingredient_preference', requirements: ['ingredientSlug' => '[a-z0-9-]{1,255}'], methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(summary: "Remplace d'un coup la préférence de l'utilisateur connecté pour un ingrédient : produits acceptés dans l'ordre, produits refusés, ou « j'en ai toujours » (sans produit ni « j'en ai toujours », la préférence est supprimée). Un ancien slug (ancien commun dont l'utilisateur a reçu une copie, ingrédient fusionné) désigne son ingrédient actuel : la préférence est toujours celle de l'ingrédient actuel")]
    #[OA\Tag(name: 'Drive')]
    #[OA\Parameter(name: 'ingredientSlug', in: 'path', required: true, description: "Slug de l'ingrédient", schema: new OA\Schema(type: 'string', pattern: '^[a-z0-9-]{1,255}$'), example: 'farine')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ReplaceIngredientPreferencePayload::class)))]
    #[OA\Response(response: 204, description: 'Préférence remplacée (créée si elle n\'existait pas, supprimée si le corps est vide)')]
    #[OA\Response(response: 404, description: 'Fonction drive coupée (message `DRIVE_DISABLED`)')]
    #[OA\Response(response: 409, description: "Pas de magasin enregistré (message `DRIVE_NOT_CONFIGURED`), ou `storeId` n'est pas le magasin courant (message `DRIVE_STORE_MISMATCH`)")]
    #[OA\Response(response: 422, description: "Corps invalide, ou préférence refusée (message `DRIVE_CHOICE_INVALID`) : produit inconnu ou d'un autre magasin, doublon, produit à la fois accepté et refusé, plus de 5 produits acceptés ou 20 refusés, « j'en ai toujours » avec des produits ; ou plafond atteint (message `DRIVE_LIMIT_REACHED`) : la création dépasserait 1 000 préférences dans le magasin")]
    #[OA\Response(response: 429, ref: '#/components/responses/DriveWriteRateLimited')]
    public function __invoke(
        #[CurrentUser] AuthenticatedUser $user,
        string $ingredientSlug,
        #[MapRequestPayload] ReplaceIngredientPreferencePayload $payload,
    ): Response {
        $this->commandBus->dispatch(new ReplaceIngredientPreferenceCommand(
            userId: $user->getId(),
            retailer: $payload->retailer,
            storeId: $payload->storeId,
            ingredientSlug: $ingredientSlug,
            haveAtHome: $payload->haveAtHome,
            acceptedProductIds: $payload->accepted,
            rejectedProductIds: $payload->rejected,
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
