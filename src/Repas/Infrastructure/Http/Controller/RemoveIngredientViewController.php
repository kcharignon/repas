<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\RemoveIngredient\RemoveIngredientCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RemoveIngredientViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly IngredientRepository $ingredientRepository,
    ) {
    }

    #[Route(path: '/ingredient/{slug}/delete', name: 'view_ingredient_remove', methods: ['GET'])]
    #[IsGranted("INGREDIENT_OWNER", 'slug')]
    public function __invoke(string $slug): Response
    {
        $department = $this->ingredientRepository->findOneBySlug($slug)->getDepartment();

        $command = new RemoveIngredientCommand($slug);
        try {
            $this->commandBus->dispatch($command);
        } catch (IngredientException) {
            $this->addFlash("error", "L'ingrédient est present dans une recette ou une liste");
            return $this->redirectToRoute('view_department', ['slug' => $department->getSlug()]);
        }


        return $this->redirectToRoute('view_department', ['slug' => $department->getSlug()]);
    }

}
