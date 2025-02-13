<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Domain\Interface\DepartmentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminGetAllDepartmentViewController extends AbstractController
{
    public function __construct(
        private readonly DepartmentRepository $departmentRepository,
    ) {
    }

    #[Route(path: '/admin/department', name: 'view_admin_departments', methods: ['GET'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(): Response
    {
        $departments = $this->departmentRepository->findAll();
        return $this->render('@Repas/Department/admin_departments.html.twig', [
            'departments' => $departments,
        ]);
    }
}
