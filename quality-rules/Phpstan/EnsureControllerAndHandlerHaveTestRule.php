<?php

declare(strict_types=1);

namespace PHPStanRules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Un QueryHandler (Application\Query) ou un ApiController (Infrastructure\Http\Api) doit avoir
 * un fichier de test {NomCourt}Test.php quelque part sous tests/ (ADR 0010 et 0011).
 *
 * Volontairement limitée aux nouveaux dossiers : les handlers d'écriture
 * existants ne sont pas concernés.
 *
 * @implements Rule<InClassNode>
 */
final class EnsureControllerAndHandlerHaveTestRule implements Rule
{
    /** @var array<string, true>|null */
    private ?array $knownTestClassShortNames = null;

    /**
     * @param list<string> $testDirectories
     */
    public function __construct(
        private readonly array $testDirectories,
    ) {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @param InClassNode $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        if ($classReflection->isAbstract()) {
            return [];
        }

        $className = $classReflection->getName();
        if (!$this->mustHaveDedicatedTest($className)) {
            return [];
        }

        $shortClassName = $classReflection->getNativeReflection()->getShortName();
        $expectedTestClassShortName = $shortClassName . 'Test';

        if (isset($this->getKnownTestClassShortNames()[$expectedTestClassShortName])) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Missing test for %s. Expected a test class named %s.',
                $className,
                $expectedTestClassShortName
            ))
                ->identifier('tests.missingForApiControllerOrQueryHandler')
                ->build(),
        ];
    }

    private function mustHaveDedicatedTest(string $className): bool
    {
        return (str_contains($className, '\\Application\\Query\\') && str_ends_with($className, 'QueryHandler'))
            || (str_contains($className, '\\Infrastructure\\Http\\Api\\') && str_ends_with($className, 'ApiController'));
    }

    /**
     * @return array<string, true>
     */
    private function getKnownTestClassShortNames(): array
    {
        if ($this->knownTestClassShortNames !== null) {
            return $this->knownTestClassShortNames;
        }

        $index = [];

        foreach ($this->testDirectories as $testDirectory) {
            if (!is_dir($testDirectory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testDirectory));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $filename = $file->getFilename();
                if (!str_ends_with($filename, 'Test.php')) {
                    continue;
                }

                $index[substr($filename, 0, -4)] = true;
            }
        }

        $this->knownTestClassShortNames = $index;

        return $this->knownTestClassShortNames;
    }
}
