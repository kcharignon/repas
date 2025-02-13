<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Infrastructure\Http\Form\CreateConversionType;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AdminCreateConversionViewController extends AbstractController
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: "/admin/conversion/create", name: "view_admin_conversion_create", methods: ["GET", "POST"])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(CreateConversionType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command = $form->getData();
            $this->commandBus->dispatch($command);

            return $this->redirectToRoute('view_admin_conversions');
        }

        return $this->render('@Repas/Conversion/_admin_conversion_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }

}
