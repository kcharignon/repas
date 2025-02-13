<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Infrastructure\Http\Form\UpdateDepartmentType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminUpdateDepartmentViewController extends AbstractController
{
    public function __construct(
        private readonly DepartmentRepository $departmentRepository,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/admin/department/{slug}/update', name: 'view_admin_department_update', methods: ['GET', 'POST'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(string $slug, Request $request): Response
    {
        $department = $this->departmentRepository->findOneBySlug($slug);

        $form = $this->createForm(UpdateDepartmentType::class, $department);
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
