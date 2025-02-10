<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Infrastructure\Http\Form\RecipeType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
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
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof User);

        $command = new CreateRecipeCommand(
            id: UuidGenerator::new(),
            name: '',
            serving: 1,
            authorId: $connectedUser->getId(),
            rows: Tab::newEmptyTyped(CreateRecipeRowSubCommand::class),
            typeSlug: ''
        );

        $form = $this->createForm(RecipeType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            $this->commandBus->dispatch($command);

            $recipe = $this->recipeRepository->findOneById($command->id);

            return $this->redirectToRoute('view_one_recipe_type', [
                'slug' => $recipe->getType()->getSlug(),
            ]); // Change this to your recipe list route
        }

        return $this->render('@Repas/Recipe/create_recipe.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
