<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Infrastructure\Http\Form\UpdateIngredientType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UpdateIngredientViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly IngredientRepository $ingredientRepository,
    ) {
    }

    #[Route(path: '/ingredient/{slug}', name: 'view_ingredient_update', methods: ['GET', 'POST'])]
    #[IsGranted("ROLE_USER")]
    #[IsGranted('INGREDIENT_OWNER', 'slug')]
    public function __invoke(string $slug, Request $request): Response
    {
        $ingredient = $this->ingredientRepository->findOneBySlug($slug);
        $form = $this->createForm(UpdateIngredientType::class, $ingredient);

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
}
