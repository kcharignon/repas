<?php

namespace Repas\Repas\Infrastructure\Http\Api\StoreImageFromUrl;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\StoreImageFromUrl\StoreImageFromUrlCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class StoreImageFromUrlApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/images/from-url', name: 'api_store_image_from_url', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(
        summary: "Télécharge l'image d'un lien direct, la garde et rend le chemin à mettre dans le champ `image` d'un rayon ou d'un type de recette (administrateur)",
        description: "Le serveur télécharge lui-même l'image. Le lien doit être en `https`, sur un nom de domaine public (ni adresse IP, ni nom local) et le port 443. Le nom est résolu par le serveur et TOUTES ses adresses doivent être publiques (ni privée, ni locale, ni métadonnées d'un nuage) ; le serveur se connecte à l'adresse vérifiée ; trois redirections au plus, chacune jugée comme le premier lien ; 10 secondes en tout ; la réponse doit être d'un type `image/*` et de 3 Mio au plus. L'image reçoit ensuite EXACTEMENT le même traitement qu'un fichier de `POST /api/v1/images` (type reconnu par le contenu, jamais par l'extension du lien ni par le `Content-Type` : PNG ou WebP ré-encodé dans son format à 512 px au plus avec sa transparence, SVG assaini par liste blanche, JPEG refusé ; nommée par son empreinte). Plafond : 30 téléchargements par heure et par administrateur, en plus du plafond d'écriture. Aucun lien, aucune adresse ni aucun contenu téléchargé n'est renvoyé ni journalisé dans une erreur.",
    )]
    #[OA\Tag(name: 'Images')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: StoreImageFromUrlPayload::class)))]
    #[OA\Response(
        response: 201,
        description: "Image gardée. `path` se met tel quel dans le champ `image` d'un rayon ou d'un type de recette",
        headers: [new OA\Header(header: 'Location', description: "Adresse de l'image, servie par le back (`/images/uploads/{empreinte}.png|webp|svg`)", schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(properties: [new OA\Property(property: 'path', type: 'string', example: 'images/uploads/3f5a0d1c9b7e4a2f8c6d1e0b9a8f7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0b.png')]),
    )]
    #[OA\Response(response: 401, description: 'Non authentifié')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 413, description: "`IMAGE_TOO_LARGE` : plus de 3 Mio, des dimensions trop grandes ou un SVG trop complexe. Rien n'est écrit")]
    #[OA\Response(response: 422, description: "Corps invalide (`url` n'est pas un texte de 2 000 caractères au plus), `IMAGE_URL_REFUSED` (lien refusé : pas en https, adresse locale ou privée, redirection refusée ou trop nombreuse), `IMAGE_NOT_AN_IMAGE` (la réponse n'est pas une image lisible) ou `IMAGE_UNSUPPORTED` (pas PNG, WebP ni SVG : JPEG, GIF…, ou un SVG refusé). Rien n'est écrit")]
    #[OA\Response(response: 429, description: "Plafond de téléchargements atteint (30 par heure) : message `IMAGE_DOWNLOAD_RATE_LIMITED`, rien n'est téléchargé ; patienter au moins le délai de `Retry-After`. Les écritures de l'API (`repas_write`, 300 par minute) répondent `WRITE_RATE_LIMITED` de la même façon", headers: [new OA\Header(header: 'Retry-After', description: "Délai d'attente en secondes", schema: new OA\Schema(type: 'integer'))])]
    #[OA\Response(response: 500, description: "`IMAGE_PROCESSING_FAILED` ou `IMAGE_STORAGE_FAILED` : une panne du serveur")]
    #[OA\Response(response: 502, description: "`IMAGE_DOWNLOAD_FAILED` : le site du lien n'a pas répondu comme il faut (nom introuvable, délai dépassé, erreur réseau ou TLS, statut autre que 200). Rien n'est écrit")]
    public function __invoke(#[MapRequestPayload] StoreImageFromUrlPayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        /** @var string $path */
        $path = $this->commandBus->dispatch(new StoreImageFromUrlCommand(
            // Le corps est déjà jugé un texte de 2 000 caractères au plus
            url: (string) $payload->url,
            userId: $user->getId(),
        ));

        return new JsonResponse(['path' => $path], Response::HTTP_CREATED, ['Location' => '/' . $path]);
    }
}
