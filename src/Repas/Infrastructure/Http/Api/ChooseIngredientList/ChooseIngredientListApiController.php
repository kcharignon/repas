<?php

namespace Repas\Repas\Infrastructure\Http\Api\ChooseIngredientList;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Command\ChooseIngredientList\ChooseIngredientListCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ChooseIngredientListApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/list-destination', name: 'api_choose_ingredient_list', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('INGREDIENT_OWNER', 'slug')]
    #[OA\Put(
        summary: "Règle la liste de courses où va un produit (« produit à part »)",
        description: "Seul le propriétaire du produit le règle, un administrateur compris (`403` pour le produit d'un autre). `default` efface le choix : le produit va dans la liste du type de son rayon, sinon dans la liste principale ; `main` l'envoie dans la liste principale même si son rayon est dans un type ; un identifiant de type l'envoie dans la liste de ce type. Le choix se fait à l'ajout et se fige : aucune ligne déjà dans une liste ne bouge. Le type d'un autre utilisateur est inconnu (`404`) et rien n'est écrit.",
    )]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ChooseIngredientListPayload::class)))]
    #[OA\Response(response: 204, description: 'Choix enregistré (ou déjà celui-là)')]
    #[OA\Response(response: 403, description: "Le produit appartient à un autre utilisateur (un administrateur n'a pas de passe-droit)")]
    #[OA\Response(response: 404, description: "Produit inconnu, ou type de liste inconnu ou d'un autre utilisateur")]
    #[OA\Response(response: 422, description: 'Corps invalide : `destination` absente, vide ou de plus de 36 caractères')]
    public function __invoke(string $slug, #[MapRequestPayload] ChooseIngredientListPayload $payload, #[CurrentUser] AuthenticatedUser $user): Response
    {
        $this->commandBus->dispatch(new ChooseIngredientListCommand(
            ingredientSlug: $slug,
            destination: $payload->destination,
            userId: $user->getId(),
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
