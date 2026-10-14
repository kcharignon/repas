<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateRecipe;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CreateRecipeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/recipes', name: 'api_create_recipe', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(summary: "Crée une recette de l'utilisateur connecté")]
    #[OA\Tag(name: 'Recipes')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RecipePayload::class)))]
    #[OA\Response(response: 201, description: 'Recette créée', headers: [new OA\Header(header: 'Location', description: 'URL de la recette créée', schema: new OA\Schema(type: 'string'))])]
    #[OA\Response(response: 404, description: "Type de recette, ingrédient ou unité inconnu (un ingrédient personnalisé d'un autre utilisateur l'est aussi)")]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    public function __invoke(#[MapRequestPayload] RecipePayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $id = UuidGenerator::new();
        $this->commandBus->dispatch(new CreateRecipeCommand(
            id: $id,
            name: $payload->name,
            serving: $payload->serving,
            authorId: $user->getId(),
            rows: new Tab(
                array_map(static fn(RecipeRowPayload $row) => new CreateRecipeRowSubCommand($row->ingredientSlug, $row->unitSlug, $row->quantity), $payload->rows),
                CreateRecipeRowSubCommand::class,
            ),
            typeSlug: $payload->typeSlug,
        ));

        return new Response(status: Response::HTTP_CREATED, headers: ['Location' => $this->generateUrl('api_get_recipe', ['id' => $id])]);
    }
}
