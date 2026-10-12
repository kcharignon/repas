<?php

namespace Repas\Tests\Helper\Http;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Route d'API protégée, chargée en environnement de test uniquement (config/routes/test/probes.yaml).
 * Sert à vérifier le firewall `api` : 401 sans token, 200 avec un token valide.
 */
final class WhoamiProbeController extends AbstractController
{
    #[Route(path: '/api/v1/_probe/whoami', name: 'test_api_probe_whoami', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['identifier' => $this->getUser()?->getUserIdentifier()]);
    }
}
