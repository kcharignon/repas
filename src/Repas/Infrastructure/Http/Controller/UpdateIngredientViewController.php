<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Infrastructure\Http\Form\CreateIngredientType;
use Repas\Repas\Infrastructure\Http\Form\UpdateIngredientType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UpdateIngredientViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly IngredientRepository $ingredientRepository,
    ) {
    }

    #[Route(path: '/ingredient/{slug}', name: 'view_ingredient_update', methods: ['GET', 'POST'])]
    public function __invoke(string $slug, Request $request): Response
    {
        $ingredient = $this->ingredientRepository->findOneBySlug($slug);
        $command = $this->initiateCommand($ingredient);
        $form = $this->createForm(UpdateIngredientType::class, $command);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UpdateIngredientCommand $command */
            $command = $form->getData();
            $this->commandBus->dispatch($command);

            return $this->redirectToRoute('view_department', ['slug' => $command->departmentSlug]);
        }

        return $this->render('@Repas/Ingredient/_ingredient_form.html.twig', [
            'form' => $form->createView(),
            'ingredient' => $ingredient,
        ]);
    }

    private function initiateCommand(Ingredient $ingredient): UpdateIngredientCommand
    {
        return new UpdateIngredientCommand(
            $ingredient->getSlug(),
            $ingredient->getName(),
            $ingredient->getImage(),
            $ingredient->getDepartment()->getSlug(),
            $ingredient->getDefaultCookingUnit()->getSlug(),
            $ingredient->getDefaultPurchaseUnit()->getSlug(),
        );
    }
}
