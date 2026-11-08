<?php

namespace Repas\Repas\Infrastructure\Http\Api\ImportMarmitonRecipe;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\ImportMarmitonRecipe\ImportedMarmitonRecipe;
use Repas\Repas\Application\Command\ImportMarmitonRecipe\ImportMarmitonRecipeCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ImportMarmitonRecipeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/recipes/import/marmiton', name: 'api_import_marmiton_recipe', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        summary: "Copie une recette Marmiton chez l'utilisateur connecté, à partir du lien de sa page",
        description: "Seul l'identifiant de la recette est retenu du lien (`MarmitonUrl`) : la page lue est celle que l'application reconstruit, jamais l'adresse reçue. Si la recette n'a jamais été importée, sa page est lue une fois et une recette NEUTRE est créée pour tous (sans propriétaire, ordinaire, visible dans « Recettes des autres », qu'aucun compte ne modifie, administrateur compris) ; si elle existe déjà, Marmiton n'est pas appelé. L'utilisateur reçoit SA copie de la neutre, par le même rapprochement d'ingrédients que `POST /recipes/{id}/copy`, modifiable, avec le lien d'origine (`sourceUrl` de `GET /recipes/{id}`). Une ligne dont l'unité ne se convertit pas en l'unité d'achat n'empêche pas la copie : la fiche dit `convertible: false`. S'il a déjà une copie de cette recette, elle lui est rendue (`200`), rien n'est écrit.",
    )]
    #[OA\Tag(name: 'Recipes')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ImportMarmitonRecipePayload::class)))]
    #[OA\Response(response: 201, description: 'Copie créée', headers: [new OA\Header(header: 'Location', description: 'URL de la copie (`GET /api/v1/recipes/{id}`)', schema: new OA\Schema(type: 'string'))])]
    #[OA\Response(response: 200, description: "L'utilisateur avait déjà une copie de cette recette Marmiton : la sienne est rendue, rien n'est écrit", headers: [new OA\Header(header: 'Location', description: 'URL de la copie existante', schema: new OA\Schema(type: 'string'))])]
    #[OA\Response(response: 422, description: "Corps invalide (`url` n'est pas un texte de 2 000 caractères au plus), `MARMITON_URL_INVALID` (ce n'est pas l'adresse d'une recette Marmiton), `MARMITON_RECIPE_NOT_FOUND` (Marmiton ne connaît pas cet identifiant : 404 ou 410), `MARMITON_RECIPE_UNREADABLE` (la page n'a pas de recette lisible : aucun bloc recette, aucune ligne d'ingrédient lisible, nom vide) ou `RECIPE_LIMIT_REACHED` (le compte a déjà 2 000 recettes, copies comprises). Rien n'est écrit")]
    #[OA\Response(response: 429, description: "Plafond de lectures de pages atteint pour cet utilisateur (20 par heure ; une copie d'une recette déjà connue n'en consomme pas) : message `MARMITON_IMPORT_RATE_LIMITED`, aucune page lue, rien n'est écrit ; patienter au moins le délai de `Retry-After`. Les écritures de l'API (`repas_write`, 300 par minute) répondent `WRITE_RATE_LIMITED` de la même façon", headers: [new OA\Header(header: 'Retry-After', description: "Délai d'attente en secondes", schema: new OA\Schema(type: 'integer'))])]
    #[OA\Response(response: 502, description: "`MARMITON_UNAVAILABLE` : Marmiton n'a pas répondu comme il faut (délai dépassé, 5xx, refus du serveur, redirection hors de Marmiton) ; on ne distingue pas un blocage d'une panne. Rien n'est écrit")]
    public function __invoke(#[MapRequestPayload] ImportMarmitonRecipePayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        /** @var ImportedMarmitonRecipe $imported */
        $imported = $this->commandBus->dispatch(new ImportMarmitonRecipeCommand(
            // Le corps est déjà jugé un texte de 2 000 caractères au plus
            url: (string) $payload->url,
            userId: $user->getId(),
            newRecipeId: UuidGenerator::new(),
        ));

        return new Response(
            status: $imported->created ? Response::HTTP_CREATED : Response::HTTP_OK,
            headers: ['Location' => $this->generateUrl('api_get_recipe', ['id' => $imported->recipeId])],
        );
    }
}
