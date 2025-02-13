<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Infrastructure\Http\Form\CreateDepartmentType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminCreateDepartmentViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/admin/department/create', name: 'view_admin_department_create', methods: ['GET', 'POST'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(CreateDepartmentType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            $this->commandBus->dispatch($command);
            return $this->redirectToRoute('view_admin_departments');
        }

        return $this->render('@Repas/Department/_admin_department_form.html.twig', [
            'form' => $form->createView(),
            "department" => null,
        ]);
    }

}
