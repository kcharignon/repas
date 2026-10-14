<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Command\RemoveIngredient\RemoveIngredientCommand;
use Repas\Repas\Application\Query\GetIngredient\GetIngredientQuery;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RemoveIngredientViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/ingredient/{slug}/delete', name: 'view_ingredient_remove', methods: ['POST'])]
    #[IsGranted("INGREDIENT_OWNER", 'slug')]
    public function __invoke(string $slug, Request $request): Response
    {
        // Suppression : jamais par un simple lien (GET), toujours par un formulaire protégé contre le CSRF
        if (!$this->isCsrfTokenValid('remove-ingredient', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide');
        }

        $connectedUser = $this->getUser();
        assert($connectedUser instanceof AuthenticatedUser);
        $departmentSlug = $this->queryBus->ask(new GetIngredientQuery($slug, $connectedUser->getId(), $this->isGranted('ROLE_ADMIN')))->departmentSlug;

        $command = new RemoveIngredientCommand($slug);
        try {
            $this->commandBus->dispatch($command);
        } catch (IngredientException) {
            $this->addFlash("error", "L'ingrédient est present dans une recette ou une liste");
            return $this->redirectToRoute('view_department', ['slug' => $departmentSlug]);
        }


        return $this->redirectToRoute('view_department', ['slug' => $departmentSlug]);
    }

}
