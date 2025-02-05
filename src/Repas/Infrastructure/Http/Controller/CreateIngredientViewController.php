<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Infrastructure\Http\Form\IngredientType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CreateIngredientViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/ingredient', name: 'view_ingredient_create', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(IngredientType::class);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CreateIngredientCommand $command */
            $command = $form->getData();
            dump($command);
            $this->commandBus->dispatch($command);

            return $this->redirectToRoute('view_department', ['slug' => $command->departmentSlug]);
        }

        return $this->render('@Repas/Ingredient/_ingredient_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
