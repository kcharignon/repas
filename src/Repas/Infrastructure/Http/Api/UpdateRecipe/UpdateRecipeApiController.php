<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateRecipe;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Infrastructure\Http\Api\CreateRecipe\RecipeComponentCommands;
use Repas\Repas\Infrastructure\Http\Api\CreateRecipe\RecipePayload;
use Repas\Repas\Infrastructure\Http\Api\CreateRecipe\RecipeRowPayload;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateRecipeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/recipes/{id}', name: 'api_update_recipe', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('RECIPE_OWNER', 'id')]
    #[OA\Put(summary: "Remplace une recette de l'utilisateur : nom, personnes, type, lignes (les lignes absentes du corps sont supprimées) et, si le corps les donne, recettes utilisées. Un ancien slug est remplacé par celui de l'ingrédient actuel de l'auteur, comme à la création", description: "Le champ facultatif `secret` rend la recette secrète (seul son auteur la voit) ou de nouveau visible : absent ou `null`, il reste inchangé. Seul l'auteur le change. Les copies déjà faites par d'autres restent à eux. Une recette SANS PROPRIÉTAIRE (la recette Marmiton neutre : `GET /recipes/other-people`, `fromMarmiton`) n'est modifiée que par un administrateur, directement, pour corriger un import raté avant que d'autres la copient : les slugs d'ingrédients reçus sont ceux de l'administrateur (ses alias), traduits en ingrédients du propriétaire réservé (un ingrédient réservé déjà sur une ligne reste tel quel ; un réservé de même origine ou de même nom est réutilisé, sinon celui de l'administrateur est copié avec ses conversions propres) ; son tag Marmiton et son lien d'origine ne changent pas, sa version augmente, les copies déjà faites ne changent pas, et elle ne devient jamais secrète. Le champ facultatif `components` remplace les recettes que la recette utilise : absent ou `null`, elles restent ce qu'elles étaient (un ancien client qui renvoie `rows` seul n'en efface jamais) ; `[]` les retire toutes ; sinon remplacement complet (voir le corps). Une recette sans propriétaire n'en reçoit jamais (`422` `RECIPE_COMPONENT_OWNERLESS`). Une recette contient au moins une ligne ou une recette utilisée (`422` `RECIPE_EMPTY`). Rendre secrète une recette qu'une recette ordinaire utilise est refusé (`409` `RECIPE_SECRET_USED_BY_PUBLIC`), comme rendre visible une recette qui en utilise une secrète (`409` `RECIPE_COMPONENT_SECRET`).")]
    #[OA\Tag(name: 'Recipes')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RecipePayload::class)))]
    #[OA\Parameter(ref: '#/components/parameters/IfMatch')]
    #[OA\Response(response: 204, description: 'Recette modifiée')]
    #[OA\Response(response: 403, description: "La recette appartient à un autre utilisateur, ou n'a pas de propriétaire (recette Marmiton neutre) et l'utilisateur n'est pas administrateur (la recette secrète d'un autre, que son auteur seul voit, est inconnue pour tous, administrateur compris : `404`). `RECIPE_SECRET_FORBIDDEN` : un administrateur qui modifie la recette d'un autre (ou sans propriétaire) envoie un `secret` différent de l'actuel, rien n'est écrit")]
    #[OA\Response(response: 404, description: "Recette, type, ingrédient, unité ou recette utilisée inconnu (un ingrédient personnalisé d'un autre utilisateur l'est aussi ; une recette utilisée d'un autre auteur que la recette, ou sa recette secrète pour qui n'est pas l'auteur, administrateur compris, aussi)")]
    #[OA\Response(response: 409, description: "`RECIPE_COMPONENT_SECRET` (une recette secrète est utilisée par une recette qui ne l'est pas, ou on rend visible une recette qui en utilise une) ou `RECIPE_SECRET_USED_BY_PUBLIC` (on rend secrète une recette qu'une recette ordinaire utilise) ; ou une autre requête a enregistré la recette entre-temps. Rien n'est écrit")]
    #[OA\Response(response: 422, description: "Corps de la requête invalide (`secret` n'est pas un booléen JSON, un composant est mal formé) ou règle des recettes utilisées : `RECIPE_EMPTY`, `RECIPE_COMPONENT_SELF`, `_DUPLICATE`, `_MULTIPLIER_INVALID`, `_LIMIT_REACHED`, `_CHAIN`, `_OWNERLESS`")]
    #[OA\Response(response: 412, description: "La version de la recette n'est plus celle de `If-Match` : elle a été modifiée depuis sa lecture")]
    public function __invoke(string $id, #[MapRequestPayload] RecipePayload $payload, Request $request, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new UpdateRecipeCommand(
            id: $id,
            name: $payload->name,
            serving: $payload->serving,
            rows: new Tab(
                array_map(static fn(RecipeRowPayload $row) => new UpdateRecipeRowSubCommand($row->ingredientSlug, $row->unitSlug, $row->quantity), $payload->rows),
                UpdateRecipeRowSubCommand::class,
            ),
            typeSlug: $payload->typeSlug,
            expectedVersion: EntityVersion::expected($request),
            // Absent ou `null` : inchangé (un ancien client ne remet jamais le choix à zéro) ; le corps est déjà jugé un booléen ou `null`
            secret: is_bool($payload->secret) ? $payload->secret : null,
            userId: $user->getId(),
            // Seul un administrateur modifie une recette sans propriétaire
            byAdministrator: $this->isGranted('ROLE_ADMIN'),
            // Absent ou `null` : les recettes utilisées restent ce qu'elles étaient ; `[]` les retire toutes ; sinon remplacement complet (ADR 0040 décision 24)
            components: RecipeComponentCommands::from($payload),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
