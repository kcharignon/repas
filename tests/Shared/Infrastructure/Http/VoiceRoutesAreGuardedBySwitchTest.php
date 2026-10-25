<?php

namespace Repas\Tests\Shared\Infrastructure\Http;


use ReflectionClass;
use Repas\Shared\Infrastructure\Http\RequiresVoice;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Garde-fou de l'interrupteur `ALEXA_ENABLED` (ADR 0029 décision 8) : une route de la commande vocale qui oublie `#[RequiresVoice]`
 * reste ouverte quand la fonction est coupée, et rien d'autre ne le dirait. Toute route de `/api/voice/*`, de `/api/v1/voice/*` et du
 * contexte `Voice` doit porter l'attribut, sans exception. Il n'en existe encore aucune (VOX-202 et VOX-204 les ajoutent) : le test
 * passe à vide et échoue dès qu'une route est ajoutée sans l'attribut.
 */
class VoiceRoutesAreGuardedBySwitchTest extends KernelTestCase
{
    public function testEveryVoiceRouteIsSwitchedOffWithTheFunction(): void
    {
        // Given
        self::bootKernel();
        $voiceRoutes = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (
                str_starts_with($controller, 'Repas\\Voice\\Infrastructure\\Http\\')
                || str_starts_with($route->getPath(), '/api/voice/')
                || str_starts_with($route->getPath(), '/api/v1/voice/')
            ) {
                $voiceRoutes[$name] = $controller;
            }
        }

        // When
        $unguarded = [];
        foreach ($voiceRoutes as $name => $controller) {
            if (!$this->isGuarded($controller)) {
                $unguarded[] = $name;
            }
        }

        // Then
        $this->assertSame([], $unguarded, 'Routes de la commande vocale sans #[RequiresVoice] : ' . implode(', ', $unguarded));
    }

    /**
     * L'attribut est lu comme le fait `VoiceEnabledListener` (`ControllerEvent::getAttributes()`) : sur la classe ou sur la méthode.
     */
    private function isGuarded(string $controller): bool
    {
        [$class, $method] = str_contains($controller, '::') ? explode('::', $controller) : [$controller, '__invoke'];
        $reflection = new ReflectionClass($class);

        return $reflection->getAttributes(RequiresVoice::class) !== []
            || ($reflection->hasMethod($method) && $reflection->getMethod($method)->getAttributes(RequiresVoice::class) !== []);
    }
}
