<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Infrastructure\Http\Form\CreateRecipeType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CreateRecipeViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly RecipeRepository $recipeRepository,
    ) {}

    #[Route(path: '/recipe', name: 'view_create_recipe', methods: ['GET', 'POST'])]
    #[IsGranted("ROLE_USER")]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(CreateRecipeType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            $this->commandBus->dispatch($command);

            $recipe = $this->recipeRepository->findOneById($command->id);

            return $this->redirectToRoute('view_one_recipe_type', [
                'slug' => $recipe->getType()->getSlug(),
            ]);
        }

        return $this->render('@Repas/Recipe/form_recipe.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
