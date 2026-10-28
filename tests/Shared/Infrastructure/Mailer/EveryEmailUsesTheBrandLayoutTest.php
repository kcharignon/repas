<?php

namespace Repas\Tests\Shared\Infrastructure\Mailer;


use PHPUnit\Framework\TestCase;

/**
 * ADR 0031 : tout e-mail que le back envoie est composé par `BrandedEmailLayout`. Un notifier n'écrit jamais un corps à la main.
 */
class EveryEmailUsesTheBrandLayoutTest extends TestCase
{
    private const string LAYOUT = 'Shared/Infrastructure/Mailer/BrandedEmailLayout.php';

    public function testNoClassOutsideTheLayoutBuildsAnEmailByHand(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $path => $code) {
            if (!str_ends_with($path, self::LAYOUT) && 1 === preg_match('/new\s+(Templated)?Email\s*\(|\bTemplatedEmail\b|->html\s*\(|->htmlTemplate\s*\(/', $code)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'Un e-mail se compose par BrandedEmailLayout::compose() (ADR 0031).');
    }

    public function testEveryClassThatSendsAMailComposesItWithTheLayout(): void
    {
        $senders = [];
        foreach ($this->sourceFiles() as $path => $code) {
            if (str_contains($code, 'Symfony\Component\Mailer\MailerInterface')) {
                $senders[] = $path;
                $this->assertStringContainsString('$this->layout->compose(', $code, $path);
            }
        }

        $this->assertNotEmpty($senders);
    }

    /**
     * @return iterable<string, string> le chemin sous `src/` et le code de chaque classe
     */
    private function sourceFiles(): iterable
    {
        $root = (string) realpath(__DIR__ . '/../../../../src');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('php' === $file->getExtension()) {
                yield substr($file->getPathname(), strlen($root) + 1) => (string) file_get_contents($file->getPathname());
            }
        }
    }
}
