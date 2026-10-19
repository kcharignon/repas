<?php

namespace Repas\Tests\Drive\Infrastructure\Http;


use OpenApi\Attributes as OA;
use ReflectionClass;
use Repas\Drive\Infrastructure\Http\DriveRateLimit;
use Repas\Drive\Infrastructure\Http\RateLimitedPerUser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Garde-fou du plafond de débit par utilisateur (DRV-615, constat C2) : toute route du drive est soit plafonnée par le compteur
 * attendu, soit dans la liste des exceptions ci-dessous. Une route ajoutée sans l'un ni l'autre fait échouer le test : plafonner
 * ou ne pas plafonner se décide. Une route plafonnée documente aussi sa réponse `429` dans OpenAPI.
 */
class DriveRoutesAreRateLimitedTest extends KernelTestCase
{
    /**
     * Routes plafonnées et leur compteur : les écritures du drive (un compteur commun) et les lectures qui coûtent 4 ou 5 requêtes SQL.
     *
     * @var array<string, DriveRateLimit>
     */
    private const array LIMITED = [
        'api_save_drive_settings' => DriveRateLimit::WRITE,
        'api_save_product_choices' => DriveRateLimit::WRITE,
        'api_replace_ingredient_preference' => DriveRateLimit::WRITE,
        'api_forget_choice' => DriveRateLimit::WRITE,
        'api_get_drive_plan' => DriveRateLimit::READ,
        'api_get_drive_estimate' => DriveRateLimit::READ,
    ];

    /**
     * Routes du drive sans plafond, et pourquoi :
     * - `GET /drive/settings` : une ligne lue, le front la relit pour savoir si la fonction est allumée ;
     * - `GET /drive/choices` : deux requêtes SQL, toujours bornées par les préférences de l'utilisateur lui-même.
     *
     * @var list<string>
     */
    private const array UNLIMITED = ['api_get_drive_settings', 'api_list_choices'];

    public function testEveryDriveRouteIsLimitedByTheExpectedCounterOrListedAsAnException(): void
    {
        // Given
        self::bootKernel();
        $routes = $this->driveRoutes();

        // When
        $actual = [];
        foreach ($routes as $name => $controller) {
            $actual[$name] = $this->limitOf($controller);
        }

        // Then : les routes connues sont toutes vues (huit routes du drive), chacune a le compteur attendu
        $this->assertGreaterThanOrEqual(8, count($routes), implode(', ', array_keys($routes)));
        $unknown = array_diff(array_keys($routes), array_keys(self::LIMITED), self::UNLIMITED);
        $this->assertSame([], array_values($unknown), 'Route du drive ni plafonnée ni listée en exception (#[RateLimitedPerUser]) : ' . implode(', ', $unknown));
        foreach (self::LIMITED as $name => $expected) {
            $this->assertArrayHasKey($name, $routes, "La route plafonnée $name n'existe plus");
            $this->assertSame($expected, $actual[$name], "Compteur de $name");
        }
        foreach (self::UNLIMITED as $name) {
            $this->assertArrayHasKey($name, $routes, "L'exception $name ne désigne plus une route du drive");
            $this->assertNull($actual[$name], "$name est listée comme exception mais porte #[RateLimitedPerUser]");
        }
    }

    public function testEveryLimitedRouteDocumentsItsTooManyRequestsAnswer(): void
    {
        // Given
        self::bootKernel();

        // When
        $undocumented = [];
        foreach ($this->driveRoutes() as $name => $controller) {
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
    private function driveRoutes(): array
    {
        $routes = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (str_starts_with($controller, 'Repas\\Drive\\Infrastructure\\Http\\Api\\') || str_starts_with($route->getPath(), '/api/v1/drive/')) {
                $routes[$name] = str_contains($controller, '::') ? $controller : $controller . '::__invoke';
            }
        }

        return $routes;
    }

    private function limitOf(string $controller): ?DriveRateLimit
    {
        [$class, $method] = explode('::', $controller);
        $reflection = new ReflectionClass($class);
        $attributes = [
            ...$reflection->getAttributes(RateLimitedPerUser::class),
            ...($reflection->hasMethod($method) ? $reflection->getMethod($method)->getAttributes(RateLimitedPerUser::class) : []),
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
