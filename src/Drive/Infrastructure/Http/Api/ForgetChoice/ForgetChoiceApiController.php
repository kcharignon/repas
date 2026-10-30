<?php

namespace Repas\Drive\Infrastructure\Http\Api\ForgetChoice;


use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\ForgetChoice\ForgetChoiceCommand;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Http\DriveRateLimit;
use Repas\Drive\Infrastructure\Http\RateLimitedPerUser;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
#[RateLimitedPerUser(DriveRateLimit::WRITE)]
final class ForgetChoiceApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/choices/{ingredientSlug}', name: 'api_forget_choice', requirements: ['ingredientSlug' => '[a-z0-9-]{1,255}'], methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Delete(summary: "Oublie toute la préférence de l'utilisateur connecté pour un ingrédient : produits acceptés et refusés, ou « j'en ai toujours » (les produits restent connus). Un ancien slug (ancien commun dont l'utilisateur a reçu une copie, ingrédient fusionné) désigne son ingrédient actuel")]
    #[OA\Tag(name: 'Drive')]
    #[OA\Parameter(name: 'ingredientSlug', in: 'path', required: true, description: "Slug de l'ingrédient", schema: new OA\Schema(type: 'string', pattern: '^[a-z0-9-]{1,255}$'), example: 'farine')]
    #[OA\Parameter(name: 'retailer', in: 'query', required: true, description: 'Enseigne du drive', schema: new OA\Schema(type: 'string', enum: Retailer::IDS), example: 'leclerc')]
    #[OA\Parameter(name: 'storeId', in: 'query', required: true, description: 'Magasin de la préférence : doit être le magasin courant des réglages', schema: new OA\Schema(type: 'string', maxLength: 20), example: '123456')]
    #[OA\Response(response: 204, description: 'Préférence oubliée')]
    #[OA\Response(response: 404, description: "Fonction drive coupée (message `DRIVE_DISABLED`), ou pas de préférence pour cet ingrédient (message `DRIVE_CHOICE_NOT_FOUND`)")]
    #[OA\Response(response: 409, description: "Pas de magasin enregistré (message `DRIVE_NOT_CONFIGURED`), ou `storeId` n'est pas le magasin courant (message `DRIVE_STORE_MISMATCH`)")]
    #[OA\Response(response: 422, description: 'Paramètre `retailer` ou `storeId` absent ou invalide')]
    #[OA\Response(response: 429, ref: '#/components/responses/DriveWriteRateLimited')]
    public function __invoke(
        #[CurrentUser] AuthenticatedUser $user,
        string $ingredientSlug,
        #[MapQueryString(validationFailedStatusCode: 422)] ForgetChoiceRequestQuery $filter,
    ): Response {
        $this->commandBus->dispatch(new ForgetChoiceCommand($user->getId(), $filter->retailer, $filter->storeId, $ingredientSlug));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
