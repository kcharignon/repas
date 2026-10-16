<?php

namespace Repas\Tests\Shared\Infrastructure\Http;


use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Chaque controller de l'API a au moins une route. Un attribut `#[Route]` d'une classe qui n'existe plus (l'ancien
 * `Routing\Annotation\Route` a disparu en Symfony 8) ne lève aucune erreur : la route disparaît sans bruit, et seul un
 * client qui l'appelle s'en aperçoit.
 */
class EveryControllerHasARouteTest extends KernelTestCase
{
    public function testEveryControllerIsRouted(): void
    {
        // Given
        self::bootKernel();
        $routed = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $route) {
            $routed[] = strstr((string) $route->getDefault('_controller'), '::', true) ?: (string) $route->getDefault('_controller');
        }

        // When
        $unrouted = [];
        foreach ($this->controllerClasses() as $class) {
            if (!in_array($class, $routed, true)) {
                $unrouted[] = $class;
            }
        }

        // Then
        $this->assertSame([], $unrouted, 'Controllers sans route : ' . implode(', ', $unrouted));
        $this->assertNotEmpty($this->controllerClasses());
    }

    /**
     * @return list<class-string>
     */
    private function controllerClasses(): array
    {
        $srcDir = dirname(__DIR__, 4) . '/src';
        $files = glob($srcDir . '/*/Infrastructure/Http/Api/*/*ApiController.php') ?: [];

        return array_map(
            static fn(string $file): string => 'Repas\\' . str_replace('/', '\\', substr($file, strlen($srcDir) + 1, -strlen('.php'))),
            $files,
        );
    }
}
