<?php

namespace Repas\Tests\Shared\Application;


use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Repas\Shared\Application\Interface\QueryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Garde-fou de l'ADR 0006 : un QueryHandler mal déclaré (sans `bus: 'query.bus'`) serait aussi enregistré sur
 * `command.bus` et le code continuerait de fonctionner. BusWiringTest prouve le mécanisme avec des handlers factices ;
 * ce test vérifie la convention sur les vrais handlers de src/.
 */
class QueryConventionTest extends TestCase
{
    /**
     * @return Generator<string, array{class-string}>
     */
    public static function queryHandlers(): Generator
    {
        foreach (self::classesMatching('*QueryHandler.php') as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @return Generator<string, array{class-string}>
     */
    public static function queries(): Generator
    {
        foreach (self::classesMatching('*Query.php') as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $handlerClass
     */
    #[DataProvider('queryHandlers')]
    public function testQueryHandlerIsDeclaredOnTheQueryBusOnly(string $handlerClass): void
    {
        // Given
        $attributes = new ReflectionClass($handlerClass)->getAttributes(AsMessageHandler::class);

        // Then
        $this->assertCount(1, $attributes, "$handlerClass doit porter #[AsMessageHandler(bus: 'query.bus')].");
        $this->assertSame('query.bus', $attributes[0]->newInstance()->bus, "$handlerClass doit être déclaré sur query.bus uniquement.");
    }

    /**
     * @param class-string $queryClass
     */
    #[DataProvider('queries')]
    public function testQueryHasAHandlerThatAcceptsIt(string $queryClass): void
    {
        // Given
        $handlerClass = $queryClass . 'Handler';

        // Then
        $this->assertTrue(is_subclass_of($queryClass, QueryInterface::class), "$queryClass doit implémenter QueryInterface.");
        $this->assertTrue(class_exists($handlerClass), "Pas de $handlerClass pour $queryClass.");
        $type = (new ReflectionClass($handlerClass))->getMethod('__invoke')->getParameters()[0]?->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type, "$handlerClass::__invoke() doit typer son paramètre.");
        $this->assertSame($queryClass, $type->getName(), "$handlerClass::__invoke() doit accepter $queryClass.");
    }

    /**
     * @return Generator<class-string>
     */
    private static function classesMatching(string $filePattern): Generator
    {
        $sourceDirectory = dirname(__DIR__, 3) . '/src';

        foreach (glob("$sourceDirectory/*/Application/Query/*/$filePattern") ?: [] as $file) {
            $relativePath = substr($file, strlen($sourceDirectory) + 1, -strlen('.php'));

            yield 'Repas\\' . str_replace('/', '\\', $relativePath);
        }
    }
}
