<?php

namespace Repas\Tests\Shared\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de l'ADR 0035 : aucune requête SQL d'un contexte ne nomme une table d'un autre contexte (`"user"` est à `User`,
 * `ingredient` à `Repas`, `drive_*` à `Drive`, `voice_*` à `Voice`). Deptrac ne voit pas le SQL : ce test lit les tables déclarées par les
 * entités Doctrine de chaque contexte, puis cherche leur nom après `FROM`, `JOIN`, `UPDATE`, `INTO` ou `TABLE` (et dans les `->from('…')`
 * du QueryBuilder) dans le code des autres contextes, commentaires exclus.
 *
 * `LEGACY` est un cliquet : les accès d'avant la règle, qui ne font que diminuer (le test échoue aussi quand une entrée n'a plus lieu
 * d'être, pour qu'on la retire).
 */
class ContextsDoNotShareTablesTest extends TestCase
{
    /** @var array<string, list<string>> fichier => tables d'un autre contexte qu'il lit encore */
    private const array LEGACY = [];

    public function testNoContextQueriesTheTablesOfAnother(): void
    {
        // Given
        $tables = self::tablesByContext();

        // When
        $found = [];
        foreach (SourceFiles::all() as $path => $source) {
            $context = SourceFiles::contextOf($path);
            if ($context === null) {
                continue;
            }
            $foreign = self::foreignTablesIn($source, $context, $tables);
            if ($foreign !== []) {
                $found[$path] = $foreign;
            }
        }

        // Then
        $this->assertSame(self::LEGACY, $found, 'Une table appartient à un seul contexte : les autres passent par son Open Host Service (ADR 0035).');
    }

    public function testEveryContextOwnsTablesAndNoTableIsOwnedTwice(): void
    {
        // When
        $tables = self::tablesByContext();

        // Then : le test lit bien les entités, sinon il ne vérifierait plus rien
        foreach (SourceFiles::CONTEXTS as $context) {
            $this->assertNotEmpty($tables[$context] ?? [], "Aucune table lue pour $context.");
        }
        $this->assertContains('user', $tables['User']);
        $this->assertContains('ingredient', $tables['Repas']);
        $this->assertContains('drive_choice', $tables['Drive']);
        $this->assertContains('voice_link', $tables['Voice']);
        $all = array_merge(...array_values($tables));
        $this->assertSame($all, array_values(array_unique($all)), 'Une table est déclarée par deux contextes.');
    }

    public function testTheAnalyzerSeesWhatItForbids(): void
    {
        // Given
        $tables = ['Repas' => ['ingredient', 'unit'], 'User' => ['user'], 'Drive' => ['drive_choice'], 'Voice' => ['voice_link']];

        // Then
        $this->assertSame(['user'], self::foreignTablesIn('$c->fetchOne(\'SELECT 1 FROM "user" WHERE id = ?\');', 'Repas', $tables));
        $this->assertSame(['user'], self::foreignTablesIn('$sql = "SELECT * FROM `user` u JOIN ingredient i ON i.creator = u.id";', 'Repas', $tables));
        $this->assertSame(['ingredient'], self::foreignTablesIn('$sql = \'SELECT 1 FROM drive_choice c JOIN ingredient i ON 1=1\';', 'Drive', $tables));
        $this->assertSame(['unit'], self::foreignTablesIn('$qb->from(\'unit\', \'u\');', 'Drive', $tables));
        $this->assertSame(['user'], self::foreignTablesIn('$c->executeStatement(\'UPDATE "user" SET last_login_at = :at\');', 'Voice', $tables));
        // Ses propres tables, un commentaire et un mot courant ne sont pas des accès
        $this->assertSame([], self::foreignTablesIn('$c->fetchOne(\'SELECT 1 FROM "user"\');', 'User', $tables));
        $this->assertSame([], self::foreignTablesIn("// SELECT 1 FROM \"user\"\n/** UPDATE ingredient */", 'Voice', $tables));
        $this->assertSame([], self::foreignTablesIn('$message = "Lis ce qui vient de unit tests";', 'Voice', $tables));
    }

    /** @return array<string, list<string>> */
    private static function tablesByContext(): array
    {
        $tables = [];
        foreach (SourceFiles::matching('#^src/[A-Za-z]+/Infrastructure/Entity/#') as $path => $source) {
            $context = SourceFiles::contextOf($path);
            if ($context !== null && preg_match('/ORM\\\\Table\(name:\s*[\'"`]+([a-z_]+)[\'"`]+/', $source, $matches) === 1) {
                $tables[$context][] = $matches[1];
            }
        }

        return $tables;
    }

    /**
     * @param array<string, list<string>> $tables
     *
     * @return list<string>
     */
    private static function foreignTablesIn(string $source, string $context, array $tables): array
    {
        $foreign = [];
        foreach ($tables as $owner => $names) {
            if ($owner !== $context) {
                array_push($foreign, ...$names);
            }
        }
        $code = SourceFiles::code("<?php\n" . $source);

        $found = [];
        preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO|TABLE)\s+(?:ONLY\s+)?["`]?([a-z_]+)["`]?/i', $code, $sql);
        preg_match_all('/->(?:from|join|leftJoin|innerJoin|update|insert|delete)\(\s*[\'"]([a-z_]+)[\'"]/', $code, $builder);
        foreach ([...$sql[1], ...$builder[1]] as $name) {
            $name = strtolower($name);
            if (in_array($name, $foreign, true) && !in_array($name, $found, true)) {
                $found[] = $name;
            }
        }
        sort($found);

        return $found;
    }
}
