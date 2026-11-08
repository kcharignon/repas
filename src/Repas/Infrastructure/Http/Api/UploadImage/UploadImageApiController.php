<?php

namespace Repas\Repas\Infrastructure\Http\Api\UploadImage;


use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\StoreUploadedImage\StoreUploadedImageCommand;
use Repas\Repas\Infrastructure\Image\UploadedImageReader;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UploadImageApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly UploadedImageReader $uploadedImageReader,
    ) {
    }

    #[Route(path: '/api/v1/images', name: 'api_upload_image', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(
        summary: "Envoie une image en fichier et rend le chemin à mettre dans le champ `image` d'un rayon ou d'un type de recette (administrateur)",
        description: "Corps `multipart/form-data`, champ `file`. Seul le CONTENU compte (ni le nom du fichier, ni son extension, ni le type annoncé par le client) : PNG, WebP ou SVG, 3 Mio au plus. Le JPEG est refusé (il ne gère pas la transparence). Un PNG ou un WebP (8 000 px de côté et 12 millions de pixels au plus) est décodé puis ré-encodé DANS SON FORMAT (aucune métadonnée n'est gardée, la transparence l'est) et ramené à 512 px de côté au plus (proportions gardées, jamais agrandi). Un SVG n'est JAMAIS gardé tel quel : il est assaini par une liste blanche d'éléments et d'attributs (jamais de script, d'image, de ressource distante ni de gestionnaire d'événement ; DOCTYPE et entités refusés ; 10 000 éléments et 32 niveaux au plus) puis ré-écrit depuis l'arbre assaini. Le fichier est nommé par l'empreinte SHA-256 du résultat et son extension (`.png`, `.webp`, `.svg`) : la même image rend toujours le même chemin, sans rien écrire de plus. Il est servi sous `/images/uploads/…` (un SVG avec `Content-Type: image/svg+xml`, `nosniff` et une politique `default-src 'none'; style-src 'unsafe-inline'; sandbox`). Les contrats des rayons et des types de recette ne changent pas : `image` reste une chaîne `images/…`. Un ancien fichier remplacé n'est pas supprimé.",
    )]
    #[OA\Tag(name: 'Images')]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file'],
                properties: [new OA\Property(property: 'file', type: 'string', format: 'binary', description: "L'image : PNG, WebP ou SVG, 3 Mio au plus")],
            ),
        ),
    )]
    #[OA\Response(
        response: 201,
        description: "Image gardée. `path` se met tel quel dans le champ `image` d'un rayon ou d'un type de recette",
        headers: [new OA\Header(header: 'Location', description: "Adresse de l'image, servie par le back (`/images/uploads/{empreinte}.png|webp|svg`)", schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(properties: [new OA\Property(property: 'path', type: 'string', example: 'images/uploads/3f5a0d1c9b7e4a2f8c6d1e0b9a8f7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0b.png')]),
    )]
    #[OA\Response(response: 401, description: 'Non authentifié')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 413, description: "`IMAGE_TOO_LARGE` : plus de 3 Mio, des dimensions trop grandes ou un SVG trop complexe (la bordure refuse aussi, sans corps JSON, un envoi bien plus gros). Rien n'est écrit")]
    #[OA\Response(response: 422, description: "`IMAGE_FILE_MISSING` (pas de champ `file`, ou envoi interrompu), `IMAGE_NOT_AN_IMAGE` (ce n'est pas une image lisible) ou `IMAGE_UNSUPPORTED` (pas PNG, WebP ni SVG : JPEG, GIF…, ou un SVG refusé : DOCTYPE, racine qui n'est pas un SVG, rien d'exploitable une fois assaini). Rien n'est écrit")]
    #[OA\Response(response: 500, description: "`IMAGE_PROCESSING_FAILED` (le serveur n'a pas pu transformer l'image) ou `IMAGE_STORAGE_FAILED` (le disque n'a pas pu la garder)")]
    public function __invoke(Request $request): Response
    {
        /** @var string $path */
        $path = $this->commandBus->dispatch(new StoreUploadedImageCommand($this->uploadedImageReader->read($request->files->get('file'))));

        return new JsonResponse(['path' => $path], Response::HTTP_CREATED, ['Location' => '/' . $path]);
    }
}
