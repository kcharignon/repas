<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Query\GetRecipe\GetRecipeQuery;
use Repas\Repas\Infrastructure\Http\Form\UpdateRecipeType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UpdateRecipeViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
    ) {}

    #[Route(path: '/recipe/{id}/update', name: 'view_update_recipe', methods: ['GET', 'POST'])]
    #[IsGranted("ROLE_USER")]
    #[IsGranted('RECIPE_OWNER', 'id')]
    public function __invoke(string $id, Request $request): Response
    {
        $recipe = $this->queryBus->ask(new GetRecipeQuery($id));

        $form = $this->createForm(UpdateRecipeType::class, $recipe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            $this->commandBus->dispatch($command);

            return $this->redirectToRoute('view_one_recipe_type', [
                'slug' => $command->typeSlug,
            ]);
        }

        return $this->render('@Repas/Recipe/form_recipe.html.twig', [
            'form' => $form->createView(),
            'recipe' => $recipe,
        ]);
    }
}
