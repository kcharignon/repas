<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Infrastructure\Http\Form\CreateRecipeTypeType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminCreateRecipeTypeViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/admin/recipe-type/create', name: 'view_admin_recipe_type_create', methods: ['GET', 'POST'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(CreateRecipeTypeType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            $this->commandBus->dispatch($command);

            return $this->redirectToRoute('view_admin_recipe_types');
        }

        return $this->render('@Repas/RecipeType/admin_recipe_type_form.html.twig', [
            'recipeTypeForm' => $form->createView(),
        ]);
    }

}
