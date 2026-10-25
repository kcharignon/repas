<?php

namespace Repas\Tests\Voice\Infrastructure\Http;


use OpenApi\Attributes as OA;
use ReflectionClass;
use Repas\Voice\Infrastructure\Http\VoiceRateLimit;
use Repas\Voice\Infrastructure\Http\VoiceRateLimited;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Garde-fou des plafonds de débit de la liaison de compte (VOX-202) : toute route de `/api/voice/*` et de `/api/v1/voice/*` est soit
 * plafonnée par le compteur attendu, soit dans la liste des exceptions ci-dessous. Une route ajoutée sans l'un ni l'autre fait échouer
 * le test : plafonner ou ne pas plafonner se décide. Une route plafonnée documente aussi sa réponse `429` dans OpenAPI.
 *
 * Toute route publique de `/api/voice/*` DOIT être plafonnée : le firewall est sans sécurité, le plafond est son seul garde-fou.
 */
class VoiceRoutesAreRateLimitedTest extends KernelTestCase
{
    /**
     * Routes plafonnées et leur compteur.
     *
     * @var array<string, VoiceRateLimit>
     */
    private const array LIMITED = [
        'api_voice_oauth_token' => VoiceRateLimit::TOKEN,
        'api_issue_voice_authorization_code' => VoiceRateLimit::AUTHORIZE,
    ];

    /**
     * Routes de la commande vocale sans plafond, et pourquoi :
     * - `GET /voice/links` : une requête SQL bornée par les liaisons de l'utilisateur lui-même, derrière son JWT ;
     * - `DELETE /voice/links/{id}` : idempotent, derrière son JWT, propriétaire seul.
     *
     * Aucune route PUBLIQUE ne figure ici.
     *
     * @var list<string>
     */
    private const array UNLIMITED = ['api_list_voice_links', 'api_revoke_voice_link'];

    public function testEveryVoiceRouteIsLimitedByTheExpectedCounterOrListedAsAnException(): void
    {
        // Given
        self::bootKernel();
        $routes = $this->voiceRoutes();

        // When
        $actual = [];
        foreach ($routes as $name => $controller) {
            $actual[$name] = $this->limitOf($controller);
        }

        // Then : les quatre routes connues sont toutes vues, chacune a le compteur attendu
        $this->assertGreaterThanOrEqual(4, count($routes), implode(', ', array_keys($routes)));
        $unknown = array_diff(array_keys($routes), array_keys(self::LIMITED), self::UNLIMITED);
        $this->assertSame([], array_values($unknown), 'Route de la commande vocale ni plafonnée ni listée en exception (#[VoiceRateLimited]) : ' . implode(', ', $unknown));
        foreach (self::LIMITED as $name => $expected) {
            $this->assertArrayHasKey($name, $routes, "La route plafonnée $name n'existe plus");
            $this->assertSame($expected, $actual[$name], "Compteur de $name");
        }
        foreach (self::UNLIMITED as $name) {
            $this->assertArrayHasKey($name, $routes, "L'exception $name ne désigne plus une route de la commande vocale");
            $this->assertNull($actual[$name], "$name est listée comme exception mais porte #[VoiceRateLimited]");
        }
    }

    public function testEveryRouteOfThePublicFirewallIsRateLimited(): void
    {
        // Given
        self::bootKernel();

        // When
        $unlimitedPublic = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (str_starts_with($route->getPath(), '/api/voice/') && $this->limitOf($this->controllerOf((string) $route->getDefault('_controller'))) === null) {
                $unlimitedPublic[] = $name;
            }
        }

        // Then : le firewall `/api/voice` est sans sécurité, le plafond est la protection de ses routes avec l'authentification du client
        $this->assertSame([], $unlimitedPublic, 'Routes publiques de /api/voice sans plafond : ' . implode(', ', $unlimitedPublic));
    }

    public function testEveryLimitedRouteDocumentsItsTooManyRequestsAnswer(): void
    {
        // Given
        self::bootKernel();

        // When
        $undocumented = [];
        foreach ($this->voiceRoutes() as $name => $controller) {
            if ($this->limitOf($controller) !== null && !$this->documentsA429($controller)) {
                $undocumented[] = $name;
            }
        }

        // Then
        $this->assertSame([], $undocumented, 'Routes plafonnées sans #[OA\Response(response: 429)] : ' . implode(', ', $undocumented));
    }

    /**
     * @return array<string, string> nom de route => `Classe::méthode`
     */
    private function voiceRoutes(): array
    {
        $routes = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (
                str_starts_with($controller, 'Repas\\Voice\\Infrastructure\\Http\\Api\\')
                || str_starts_with($route->getPath(), '/api/voice/')
                || str_starts_with($route->getPath(), '/api/v1/voice/')
            ) {
                $routes[$name] = $this->controllerOf($controller);
            }
        }

        return $routes;
    }

    private function controllerOf(string $controller): string
    {
        return str_contains($controller, '::') ? $controller : $controller . '::__invoke';
    }

    private function limitOf(string $controller): ?VoiceRateLimit
    {
        [$class, $method] = explode('::', $controller);
        $reflection = new ReflectionClass($class);
        $attributes = [
            ...$reflection->getAttributes(VoiceRateLimited::class),
            ...($reflection->hasMethod($method) ? $reflection->getMethod($method)->getAttributes(VoiceRateLimited::class) : []),
        ];

        return $attributes === [] ? null : $attributes[0]->newInstance()->limit;
    }

    private function documentsA429(string $controller): bool
    {
        [$class, $method] = explode('::', $controller);
        foreach (new ReflectionClass($class)->getMethod($method)->getAttributes(OA\Response::class) as $attribute) {
            if ($attribute->newInstance()->response === 429) {
                return true;
            }
        }

        return false;
    }
}
