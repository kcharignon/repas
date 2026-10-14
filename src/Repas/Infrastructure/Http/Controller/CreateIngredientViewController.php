<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Infrastructure\Http\Form\CreateIngredientType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Repas\Repas\Domain\Exception\IngredientException;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CreateIngredientViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/ingredient', name: 'view_ingredient_create', methods: ['GET', 'POST'])]
    #[IsGranted("ROLE_USER")]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(CreateIngredientType::class);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CreateIngredientCommand $command */
            $command = $form->getData();
            try {
                $this->commandBus->dispatch($command);

                return $this->redirectToRoute('view_department', ['slug' => $command->departmentSlug]);
            } catch (IngredientException $exception) {
                if ($exception->getCode() !== Response::HTTP_CONFLICT) {
                    throw $exception;
                }
                $form->get('name')->addError(new FormError('Un ingrédient de ce nom existe déjà.'));
            }
        }

        return $this->render('@Repas/Ingredient/_ingredient_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
