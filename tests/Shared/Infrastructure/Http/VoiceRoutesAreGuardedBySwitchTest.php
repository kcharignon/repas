<?php

namespace Repas\Tests\Shared\Infrastructure\Http;


use ReflectionClass;
use Repas\Shared\Infrastructure\Http\RequiresVoice;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Garde-fou de l'interrupteur `ALEXA_ENABLED` (ADR 0029 décision 8) : une route de la commande vocale qui oublie `#[RequiresVoice]`
 * reste ouverte quand la fonction est coupée, et rien d'autre ne le dirait. Toute route de `/api/voice/*`, de `/api/v1/voice/*` et du
 * contexte `Voice` doit porter l'attribut, sans exception (VOX-202 : l'émission du code, l'échange des jetons, la liste et le retrait des
 * liaisons ; VOX-204 ajoutera le webhook). Le test échoue dès qu'une route est ajoutée sans l'attribut, et `testTheLinkingRoutesAreSeen`
 * échoue si les routes de la liaison de compte ne sont plus vues, car un test qui n'énumère plus rien passe à vide.
 */
class VoiceRoutesAreGuardedBySwitchTest extends KernelTestCase
{
    public function testTheLinkingRoutesAreSeen(): void
    {
        // Given / When
        self::bootKernel();
        $names = array_keys($this->voiceRoutes());

        // Then : un garde-fou qui ne voit rien passerait à vide
        foreach (['api_voice_oauth_token', 'api_issue_voice_authorization_code', 'api_list_voice_links', 'api_revoke_voice_link'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function testEveryVoiceRouteIsSwitchedOffWithTheFunction(): void
    {
        // Given
        self::bootKernel();
        $voiceRoutes = $this->voiceRoutes();

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
     * @return array<string, string> nom de route => contrôleur
     */
    private function voiceRoutes(): array
    {
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

        return $voiceRoutes;
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
