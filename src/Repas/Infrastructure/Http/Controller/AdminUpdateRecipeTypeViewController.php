<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Infrastructure\Http\Form\UpdateRecipeTypeType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminUpdateRecipeTypeViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly RecipeTypeRepository $recipeTypeRepository,
    ) {
    }

    #[Route(path: '/admin/recipe-type/{id}/update', name: 'view_admin_recipe_type_update', methods: ['GET', 'POST'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(string $id, Request $request): Response
    {
        $recipeType = $this->recipeTypeRepository->findOneBySlug($id);
        $form = $this->createForm(UpdateRecipeTypeType::class, $recipeType);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            $this->commandBus->dispatch($command);

            return $this->redirectToRoute('view_admin_recipe_types');
        }

        return $this->render('@Repas/RecipeType/admin_recipe_type_form.html.twig', [
            'recipeTypeForm' => $form->createView(),
            'recipeType' => $recipeType,
        ]);
    }

}
