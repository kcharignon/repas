<?php

namespace Repas\Tests\Repas\Infrastructure;


use LogicException;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Infrastructure\Catalog\BaseIngredientJsonClassification;

/**
 * Le classement des 261 anciens communs (ING-009) : une DONNÉE DE PRODUCTION VALIDÉE par le propriétaire du catalogue. Le fichier
 * `docs/ingredients/base-ingredients.tsv` en est l'enregistrement, sa copie `src/Repas/Infrastructure/Data/base-ingredients.json` (`docs/` n'est
 * pas dans l'image) est ce que la commande de passation lit : ce test échoue si les deux divergent, d'une virgule ou d'un marqueur. Les nombres et
 * les sommes de contrôle sont ceux de `docs/ingredients/sans-commun.md` § 5.
 */
class BaseIngredientsDataTest extends TestCase
{
    private const string TSV = '/docs/ingredients/base-ingredients.tsv';
    private const string JSON = '/src/Repas/Infrastructure/Data/base-ingredients.json';

    public function testTheClassificationIs261OldCommonsWith151BaseOnesAndTheDocumentedChecksums(): void
    {
        // Given
        $classification = $this->classification()->all();
        $slugs = array_keys($classification);
        $base = array_keys(array_filter($classification));
        sort($slugs, SORT_STRING);
        sort($base, SORT_STRING);

        // Then : 261 slugs uniques, 151 de base, 110 spécifiques, les sommes de contrôle du document
        $this->assertCount(261, $classification);
        $this->assertCount(151, $base);
        $this->assertCount(110, array_filter($classification, static fn(bool $isBase): bool => !$isBase));
        $this->assertSame('a43e401aaf59b6e65bd1f433f7347c7b', md5(implode(',', $slugs)));
        $this->assertSame('3776a0ad5d1fbc427f9431656c14b7e2', md5(implode(',', $base)));
    }

    public function testTheJsonAndTheValidatedTsvAreIdenticalLineByLine(): void
    {
        // Given : le TSV (slug, nom, rayon, de_base), le JSON (slug, name, department, base), même ordre
        $tsv = [];
        foreach (array_slice(file(dirname(__DIR__, 3) . self::TSV, FILE_IGNORE_NEW_LINES), 1) as $line) {
            [$slug, $name, $department, $base] = explode("\t", $line);
            $tsv[] = ['slug' => $slug, 'name' => $name, 'department' => $department, 'base' => $base === '1'];
        }
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 3) . self::JSON), true, 8, JSON_THROW_ON_ERROR);

        // Then : les deux fichiers disent la même chose, ligne pour ligne (une seule source de vérité)
        $this->assertSame($tsv, $json);
        $this->assertSame(array_column($tsv, 'base', 'slug'), $this->classification()->all());
    }

    public function testNoSlugEndsWithAnAccountIdentifierSoTheDataRollbackCanTellTheOldCommonsApart(): void
    {
        foreach (array_keys($this->classification()->all()) as $slug) {
            $this->assertSame(0, preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $slug), 'un ancien commun a un slug sans identifiant de compte');
        }
    }

    public function testAMalformedFileIsRefused(): void
    {
        foreach ([
            'un slug en double' => '[{"slug":"a","base":true},{"slug":"a","base":false}]',
            'un marqueur qui n\'est pas booléen' => '[{"slug":"a","base":"oui"}]',
            'un slug vide' => '[{"slug":"","base":true}]',
        ] as $label => $content) {
            $file = sys_get_temp_dir() . '/ing009-classification-' . bin2hex(random_bytes(4)) . '.json';
            file_put_contents($file, $content);
            try {
                new BaseIngredientJsonClassification($file)->all();
                $this->fail($label);
            } catch (LogicException $e) {
                $this->assertStringContainsString('invalide', $e->getMessage(), $label);
            } finally {
                unlink($file);
            }
        }

        $this->expectException(LogicException::class);
        new BaseIngredientJsonClassification('/nulle/part.json')->all();
    }

    private function classification(): BaseIngredientJsonClassification
    {
        return new BaseIngredientJsonClassification(dirname(__DIR__, 3) . self::JSON);
    }
}
