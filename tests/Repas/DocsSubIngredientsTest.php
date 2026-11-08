<?php

namespace Repas\Tests\Repas;


use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Voice\Domain\Model\AddedLine;
use Repas\Voice\Domain\Model\ListLine;
use Repas\Voice\Domain\Model\ParentLine;
use Repas\Voice\Domain\Model\RemovedLine;
use Repas\Voice\Domain\Model\VoiceReplies;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le document d'exploitation des sous-ingrédients (`docs/ingredients/sous-ingredients-exploitation.md`, SUB-006, ADR 0038 décisions 18, 22, 32 et 34) est
 * d'accord avec le code qu'il met entre les mains de l'humain, SANS jamais toucher la production : les routes et les codes d'erreur qu'il cite existent
 * (le contrat `docs/api/openapi.json` ou le code), le script et les options qu'il cite sont ceux du script `amorcer-ingredients.sh` et de la commande
 * `repas:ingredients:seed`, les phrases d'Alexa qu'il cite sont celles de `VoiceReplies`, les fichiers et migrations qu'il nomme existent, et le SQL
 * qu'il publie est EXÉCUTÉ ici, tel qu'il est écrit, sur le vrai schéma (le texte testé est le texte publié). Le document ne contient ni adresse
 * e-mail ni identifiant de compte (dépôt public).
 */
class DocsSubIngredientsTest extends DatabaseTestCase
{
    private const string DOCUMENT = '/docs/ingredients/sous-ingredients-exploitation.md';
    private const string SCRIPT = '/.claude/skills/repas-ops/references/amorcer-ingredients.sh';
    private const string OWNER = 'owner';
    private const string ACCOUNT_A = 'account-a';
    private const string ACCOUNT_B = 'account-b';
    private const string ACCOUNT_C = 'account-c';

    private static ?string $document = null;

    public function testTheRoutesTheDocumentCitesAreInTheContract(): void
    {
        // Given : les routes citées entre accents graves, « METHODE /api/… »
        preg_match_all('#`(GET|POST|PUT|PATCH|DELETE) (/api/[^`\s?]+)`#', $this->document(), $matches, PREG_SET_ORDER);
        $contract = json_decode((string) file_get_contents($this->root() . '/docs/api/openapi.json'), true, 512, JSON_THROW_ON_ERROR);

        // Then : le document en cite (sans quoi ce test ne garde rien) et chacune existe, avec cette méthode
        $this->assertGreaterThanOrEqual(8, count($matches), 'le document cite les routes derrière les écrans');
        foreach ($matches as [, $method, $path]) {
            $this->assertArrayHasKey($path, $contract['paths'], "$method $path n'est pas dans docs/api/openapi.json");
            $this->assertArrayHasKey(strtolower($method), $contract['paths'][$path], "$method $path : cette méthode n'est pas dans docs/api/openapi.json");
        }
        foreach (['/api/v1/ingredients/{slug}/parent' => ['put', 'delete'], '/api/v1/ingredients' => ['post'], '/api/v1/ingredients/{slug}/merge' => ['post'], '/api/v1/shopping-lists/{id}/send' => ['post'], '/api/v1/shopping-lists/{id}/reopen' => ['post'], '/api/v1/recipes/import/marmiton' => ['post']] as $path => $methods) {
            foreach ($methods as $method) {
                $this->assertStringContainsString('`' . strtoupper($method) . ' ' . $path . '`', $this->document(), "le document doit citer " . strtoupper($method) . " $path");
            }
        }
    }

    public function testTheErrorCodesAndVariablesTheDocumentCitesExistInTheCode(): void
    {
        // Given : tous les codes en majuscules cités entre accents graves (codes d'erreur, variables d'environnement)
        preg_match_all('/`([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)`/', $this->document(), $matches);
        $cited = array_values(array_unique($matches[1]));
        $sources = $this->sourcesText();

        // Then
        $this->assertContains('MERGE_PART_CONFLICT', $cited);
        $this->assertContains('MERGE_PART_IN_LIST', $cited);
        $this->assertContains('INGREDIENT_PART_IN_LIST', $cited);
        $this->assertContains('INGREDIENT_NAME_ALREADY_USED', $cited);
        $this->assertContains('INGREDIENT_BASE_FORBIDDEN', $cited);
        $this->assertContains('INGREDIENT_SEEDING_ENABLED', $cited);
        foreach ($cited as $code) {
            $this->assertStringContainsString($code, $sources, "« $code » est cité par le document mais n'existe ni dans src/, config/, deploy/ ni dans le contrat");
        }
    }

    public function testTheMigrationsTheScriptsAndTheFilesTheDocumentNamesExist(): void
    {
        $document = $this->document();

        // Les migrations citées
        preg_match_all('/`(Version\d{14})`/', $document, $migrations);
        $this->assertNotEmpty($migrations[1]);
        foreach ($migrations[1] as $migration) {
            $this->assertFileExists($this->root() . "/migrations/$migration.php");
        }

        // Le script cité, par son nom ou par son chemin (« …/amorcer-ingredients.sh »)
        preg_match_all('#([\w-]+\.sh)(?![\w])#u', $document, $scripts);
        $this->assertContains('amorcer-ingredients.sh', $scripts[1]);
        foreach (array_unique($scripts[1]) as $script) {
            $this->assertFileExists($this->root() . '/.claude/skills/repas-ops/references/' . $script, $script);
        }
        $this->assertFileExists($this->root() . self::SCRIPT);

        // Les fichiers du skill cités (« references/… .md »)
        preg_match_all('#references/([\w-]+\.md)#', $document, $references);
        $this->assertNotEmpty($references[1]);
        foreach (array_unique($references[1]) as $reference) {
            $this->assertFileExists($this->root() . '/.claude/skills/repas-ops/references/' . $reference);
        }

        // Les liens relatifs
        preg_match_all('/\]\(([^)#\s]+)(?:#[^)]*)?\)/', $document, $links);
        $this->assertNotEmpty($links[1]);
        foreach ($links[1] as $link) {
            if (str_starts_with($link, 'http')) {
                continue;
            }
            $this->assertFileExists($this->root() . '/docs/ingredients/' . $link, "lien mort : $link");
        }

        // Le document est lié du ticket et de la feuille de route
        $this->assertStringContainsString('sous-ingredients-exploitation.md', (string) file_get_contents($this->root() . '/docs/ingredients/tickets/SUB-006-donnees-de-reference-et-exploitation.md'));
        $this->assertStringContainsString('sous-ingredients-exploitation.md', (string) file_get_contents($this->root() . '/docs/ingredients/roadmap.md'));
    }

    public function testTheOptionsTheDocumentCitesAreThoseOfTheScriptAndTheCommand(): void
    {
        // Given : les options du script (ses `case`) et celles de la commande de console
        $script = (string) file_get_contents($this->root() . self::SCRIPT);
        preg_match_all('/^\s*--([a-z][a-z-]*)[)=]/m', $script, $scriptFlags);
        $command = new Application(self::$kernel)->find('repas:ingredients:seed');
        $commandOptions = array_keys($command->getDefinition()->getOptions());
        $pushScript = (string) file_get_contents($this->root() . '/docs/voice/skill/push-model.mjs');

        // Then : le script offre exactement ce que le document emploie, avec les noms français
        foreach (['appliquer', 'verifier', 'accepter-listes-envoyees', 'compte', 'sauf', 'complet', 'lancement'] as $flag) {
            $this->assertContains($flag, $scriptFlags[1], "le script n'a plus --$flag");
        }
        foreach (['apply', 'check', 'accept-sent', 'user', 'except', 'complete', 'source'] as $option) {
            $this->assertContains($option, $commandOptions, "la commande n'a plus --$option");
        }

        // Et chaque « --option » citée par le document existe quelque part (hors le séparateur « -- » de « bash -s -- »)
        preg_match_all('/(?<![\w-])--([a-z][a-z-]*)/', $this->document(), $cited);
        $this->assertContains('complet', $cited[1]);
        $this->assertContains('compte', $cited[1]);
        $this->assertContains('appliquer', $cited[1]);
        $this->assertContains('verifier', $cited[1]);
        foreach (array_unique($cited[1]) as $option) {
            $known = in_array($option, $scriptFlags[1], true) || in_array($option, $commandOptions, true) || str_contains($pushScript, "--$option");
            $this->assertTrue($known, "--$option est cité par le document mais n'est ni une option du script, ni de la commande, ni de l'envoi du modèle");
        }
    }

    public function testTheRattrapageOfAnAlreadySeededAccountIsAccountByAccountAsTheDocumentSays(): void
    {
        // Given : le document dit que « --complet exige --compte= » (--complete exige --user pour la commande)
        $this->assertStringContainsString('**exige `--compte=`**', $this->document());
        $script = (string) file_get_contents($this->root() . self::SCRIPT);
        $this->assertStringContainsString('--complet exige --compte=<référence>', $script);
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:seed'));

        // When : la commande est lancée avec --complete et sans --user
        $status = $tester->execute(['--complete' => true]);

        // Then : refusée, et rien n'est écrit
        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('--complete exige --user', $tester->getDisplay());
    }

    public function testTheAlexaPhrasesTheDocumentQuotesAreThoseOfVoiceReplies(): void
    {
        // Given : le scénario du propriétaire, 3 œufs entiers dans la liste, puis un jaune (ADR 0038 décision 33)
        $egg = new ParentLine('Œuf', 4.0, 'unite', 'unité');
        $phrases = [
            VoiceReplies::added("jaune d'œuf", 'divers', true, false, false, new AddedLine(1.0, 4.0, 'unite', 'unité', existed: true, foldedInto: 'Œuf')),
            VoiceReplies::inList(new ListLine("jaune d'œuf", 'divers', 1.0, 'unite', 'unité', foldedInto: $egg)),
            VoiceReplies::removed("jaune d'œuf", new RemovedLine(1.0, 3.0, 'unite', 'unité', foldedInto: 'Œuf')),
            VoiceReplies::notInListAnswer("jaune d'œuf"),
            VoiceReplies::notInList("jaune d'œuf"),
            VoiceReplies::LIST_SENT,
            VoiceReplies::added("jaune d'œuf", 'divers', true, true, true, new AddedLine(1.0, 1.0, 'unite', 'unité', foldedInto: 'Œuf')),
        ];

        // Then : les phrases exactes d'Alexa, entre « »
        $this->assertSame("C'est ajouté. Ça fait 4 œufs dans ta liste.", $phrases[0]);
        $this->assertSame("Oui : 1 jaune d'œuf. Ça compte dans tes 4 œufs.", $phrases[1]);
        $this->assertSame("C'est retiré. Il te reste 3 œufs.", $phrases[2]);
        foreach ($phrases as $phrase) {
            $this->assertStringContainsString('« ' . $phrase . ' »', $this->document());
        }

        // …dont les trois dernières, dites sans partie dans la liste ou quand la liste est partie au drive
        $this->assertSame("Non, il n'y a pas de jaune d'œuf dans ta liste.", $phrases[3]);
        $this->assertSame("Il n'y a pas de jaune d'œuf dans ta liste.", $phrases[4]);
        $this->assertSame("J'ai commencé une nouvelle liste avec jaune d'œuf. Ta liste précédente est partie au drive.", $phrases[6]);
    }

    public function testTheSkillModelAlreadyHasBothPartsAndThePushTargetExists(): void
    {
        // Given : le document dit que le modèle contient DÉJÀ « jaune d'œuf » et « blanc d'œuf » et que l'envoi est `make voice-model-push`
        $model = json_decode((string) file_get_contents($this->root() . '/docs/voice/skill/interaction-model.fr-FR.json'), true, 512, JSON_THROW_ON_ERROR);
        $types = array_column($model['interactionModel']['languageModel']['types'], null, 'name');
        $values = array_map(static fn(array $value): string => $value['name']['value'], $types['INGREDIENT']['values']);

        // Then
        $this->assertContains("jaune d'œuf", $values);
        $this->assertContains("blanc d'œuf", $values);
        $this->assertStringContainsString('make voice-model-push', $this->document());
        $this->assertMatchesRegularExpression('/^voice-model-push:/m', (string) file_get_contents($this->root() . '/Makefile'));
    }

    public function testTheDocumentHoldsNoEmailAndNoAccountIdentifier(): void
    {
        // Given : dépôt public ; l'adresse IP du serveur, déjà dans `docs/production.md` et le skill, est la seule « adresse » permise
        $text = str_replace('root@64.227.124.100', 'root@serveur', $this->document());

        // Then
        $this->assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.-]+/', $text, 'aucune adresse e-mail');
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $text, 'aucun identifiant de compte');
    }

    public function testTheCatalogueSurveyGivesTheNumbersTheDocumentAnnounces(): void
    {
        // Given : le catalogue du propriétaire d'après les étapes 1.3 à 1.5 : « Œuf » de base et deux parties de base, au ratio 1
        $this->catalogue();
        $sql = $this->sqlBlock('sous-ingredients-catalogue-sql');

        // When
        $row = $this->entityManager->getConnection()->fetchAssociative($sql);

        // Then : 1 œuf de base, 2 parties de base au ratio 1, mêmes rayon et unités, unité / unité
        $this->assertNotFalse($row);
        $this->assertSame([true, 2, 2, 2, 2, 'unite', 'unite'], [(bool) $row['oeuf_de_base'], (int) $row['parties'], (int) $row['parties_de_base'], (int) $row['au_ratio_1'], (int) $row['memes_rayon_et_unites'], $row['unite_des_recettes'], $row['unite_d_achat']]);

        // And : avant l'étape 1.3, aucune partie
        $this->entityManager->getConnection()->executeStatement("UPDATE ingredient SET parent_slug = NULL, parent_ratio = NULL, is_base = FALSE WHERE slug IN ('jaune-o', 'blanc-o')");
        $row = $this->entityManager->getConnection()->fetchAssociative($sql);
        $this->assertSame([0, 0, 0], [(int) $row['parties'], (int) $row['parties_de_base'], (int) $row['au_ratio_1']]);
    }

    public function testTheAccountsSurveyCountsTheAccountsThatAlreadyHaveTheirOwnYolkOrWhite(): void
    {
        // Given : un catalogue, un compte sans rien à lui (A), un compte (B) avec son « Jaune d'œuf » à lui relié à son œuf et une ligne dans une liste en cours,
        // un compte (C) avec son « Blanc d'œufs » dans une liste terminée, et le propriétaire lui-même (jamais compté)
        $this->catalogue();
        $this->put('oeuf-a', self::ACCOUNT_A, 'Œuf', 'oeuf', from: 'oeuf');
        $this->put('oeuf-b', self::ACCOUNT_B, 'Œuf', 'oeuf', from: 'oeuf');
        $this->put('jaune-b', self::ACCOUNT_B, "Jaune d'œuf", 'jaune-d-oeuf', parent: 'oeuf-b', ratio: 1.0);
        $this->put('blancs-c', self::ACCOUNT_C, "Blancs d'oeufs", 'blancs-d-oeufs', from: 'blanc-o');
        $this->put('autre-c', self::ACCOUNT_C, 'Farine', 'farine');
        $connection = $this->entityManager->getConnection();
        $connection->insert('shopping_list', ['id' => 'l-open', 'owner' => self::ACCOUNT_B, 'created_at' => '2026-11-01 10:00:00', 'status' => 'ACTIVE', 'name' => 'Ouverte', 'version' => 1]);
        $connection->insert('shopping_list_row', ['id' => 'r-open', 'shopping_list' => 'l-open', 'ingredient' => 'jaune-b', 'quantity' => 1, 'unit' => 'unite', 'checked' => 'false']);
        $connection->insert('shopping_list', ['id' => 'l-done', 'owner' => self::ACCOUNT_C, 'created_at' => '2026-11-01 10:00:00', 'status' => 'COMPLETED', 'name' => 'Finie', 'version' => 1]);
        $connection->insert('shopping_list_row', ['id' => 'r-done', 'shopping_list' => 'l-done', 'ingredient' => 'blancs-c', 'quantity' => 1, 'unit' => 'unite', 'checked' => 'true']);

        // When
        $row = $connection->fetchAssociative($this->sqlBlock('sous-ingredients-comptes-sql'));

        // Then : deux comptes (B et C), le propriétaire n'est jamais compté ; un seul déjà relié, une copie, une ligne dans une liste en cours (celle de B)
        $this->assertNotFalse($row);
        $this->assertSame(['comptes' => 2, 'ingredients' => 2, 'deja_relies' => 1, 'venus_d_une_copie' => 1, 'dans_une_liste_en_cours' => 1], array_map('intval', $row));
    }

    public function testTheLinkSurveyDistinguishesTheAccountsThatWereServedFromTheOnesThatWereNot(): void
    {
        // Given : A est servi (ses deux parties reliées), B ne l'est pas (une copie d'« Œuf », aucune partie), C a servi mais garde aussi sa propre partie
        $this->catalogue();
        $this->put('oeuf-a', self::ACCOUNT_A, 'Œuf', 'oeuf', from: 'oeuf');
        $this->put('jaune-a', self::ACCOUNT_A, "Jaune d'œuf", 'jaune-d-oeuf', from: 'jaune-o', parent: 'oeuf-a', ratio: 1.0);
        $this->put('blanc-a', self::ACCOUNT_A, "Blanc d'œuf", 'blanc-d-oeuf', from: 'blanc-o', parent: 'oeuf-a', ratio: 1.0);
        $this->put('oeuf-b', self::ACCOUNT_B, 'Œuf', 'oeuf', from: 'oeuf');
        $this->put('oeuf-c', self::ACCOUNT_C, 'Œuf', 'oeuf', from: 'oeuf');
        $this->put('jaune-c', self::ACCOUNT_C, "Jaune d'œuf", 'jaune-d-oeuf', from: 'jaune-o', parent: 'oeuf-c', ratio: 1.0);
        $this->put('blanc-c', self::ACCOUNT_C, "Blanc d'œuf", 'blanc-d-oeuf', from: 'blanc-o', parent: 'oeuf-c', ratio: 1.0);
        $this->put('jaune-c-2', self::ACCOUNT_C, "Jaune d'œuf", 'jaune-d-oeuf', parent: 'oeuf-c', ratio: 1.0);

        // When
        $rows = $this->entityManager->getConnection()->fetchAllAssociative($this->sqlBlock('sous-ingredients-liaison-sql'));

        // Then : un compte sans partie (B), un compte à deux parties (A), un compte à trois parties (C) ; le propriétaire n'a pas d'« Œuf » copié
        $this->assertSame([['parties' => 0, 'comptes' => 1], ['parties' => 2, 'comptes' => 1], ['parties' => 3, 'comptes' => 1]], array_map(static fn(array $row): array => array_map('intval', $row), $rows));
    }

    public function testTheRollbackSurveyAndTheLastResortUpdateDoWhatTheDocumentSays(): void
    {
        // Given : un catalogue découpé, un compte relié, une liste en cours qui porte des parties et une liste terminée qui en portait
        $this->catalogue();
        $this->put('oeuf-a', self::ACCOUNT_A, 'Œuf', 'oeuf', from: 'oeuf');
        $this->put('jaune-a', self::ACCOUNT_A, "Jaune d'œuf", 'jaune-d-oeuf', from: 'jaune-o', parent: 'oeuf-a', ratio: 1.0);
        $connection = $this->entityManager->getConnection();
        $connection->insert('shopping_list', ['id' => 'l-1', 'owner' => self::ACCOUNT_A, 'created_at' => '2026-11-01 10:00:00', 'status' => 'ACTIVE', 'name' => 'Une', 'version' => 1]);
        $connection->insert('shopping_list', ['id' => 'l-2', 'owner' => self::ACCOUNT_A, 'created_at' => '2026-11-01 10:00:00', 'status' => 'COMPLETED', 'name' => 'Deux', 'version' => 1]);
        $connection->insert('shopping_list', ['id' => 'l-3', 'owner' => self::ACCOUNT_A, 'created_at' => '2026-11-01 10:00:00', 'status' => 'ACTIVE', 'name' => 'Trois', 'version' => 1]);
        $connection->executeStatement("UPDATE shopping_list SET part_shares = CAST('{\"jaune-a\": {\"parent\": \"oeuf-a\", \"quantity\": 2, \"equivalent\": 2}}' AS jsonb) WHERE id IN ('l-1', 'l-2')");

        // When / Then : la lecture seule du retour arrière — 3 liens (2 chez le propriétaire, 1 chez A), 2 listes avec parties dont 1 en cours
        $survey = $connection->fetchAssociative($this->sqlBlock('sous-ingredients-retour-arriere-sql'));
        $this->assertSame(['liens' => 3, 'listes_avec_parties' => 2, 'listes_en_cours_avec_parties' => 1], array_map('intval', $survey));

        // When : la mise à jour du dernier recours (sans BEGIN ni COMMIT, la transaction est celle du test)
        $update = $this->sqlBlock('sous-ingredients-retour-arriere-ecriture-sql');
        $this->assertSame(3, (int) $connection->executeStatement($update), 'UPDATE n doit être égal au nombre de liens relevé');

        // Then : plus aucun lien, mais les ingrédients, les listes et leurs registres sont intacts
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE parent_slug IS NOT NULL OR parent_ratio IS NOT NULL'));
        $this->assertSame(5, (int) $connection->fetchOne('SELECT count(*) FROM ingredient'), 'aucun ingrédient supprimé (3 du catalogue, 2 du compte A)');
        $this->assertSame(2, (int) $connection->fetchOne('SELECT count(*) FROM shopping_list WHERE part_shares IS NOT NULL'), 'les registres des listes sont intacts');
        $this->assertSame(3, (int) $connection->fetchOne('SELECT count(*) FROM shopping_list'));
    }

    // ------------------------------------------------------------------ outils

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function document(): string
    {
        return self::$document ??= (string) file_get_contents($this->root() . self::DOCUMENT);
    }

    /**
     * Tout ce où un code d'erreur ou une variable peut être défini : le code, la configuration, le déploiement et le contrat.
     */
    private function sourcesText(): string
    {
        $text = (string) file_get_contents($this->root() . '/docs/api/openapi.json');
        foreach (['src', 'config', 'deploy'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root() . '/' . $directory, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'yaml', 'yml', 'sh', 'example', 'env', 'json'], true)) {
                    $text .= file_get_contents($file->getPathname());
                }
            }
        }
        $text .= (string) file_get_contents($this->root() . '/.env');

        return $text;
    }

    /**
     * Le SQL publié sous un marqueur (`<!-- marqueur -->` puis un bloc ```sql), sans ses lignes de commentaire, sans BEGIN ni la ponctuation finale
     * des transactions : une seule instruction, exécutée telle qu'elle est écrite.
     */
    private function sqlBlock(string $marker): string
    {
        $found = preg_match('/<!-- ' . preg_quote($marker, '/') . ' -->\R```sql\R(.*?)\R```/s', $this->document(), $matches);
        $this->assertSame(1, $found, "le bloc SQL « $marker » est introuvable dans le document");

        $lines = array_filter(
            preg_split('/\R/', $matches[1]) ?: [],
            static fn(string $line): bool => !str_starts_with(trim($line), '--') && trim($line) !== '' && !in_array(trim($line), ['BEGIN;', 'COMMIT;', 'ROLLBACK;'], true),
        );
        $sql = rtrim(trim(implode("\n", $lines)), ';');
        $this->assertNotSame('', $sql);

        return $sql;
    }

    /**
     * Le catalogue du propriétaire : « Œuf » (de base) et ses deux parties (de base, ratio 1), mêmes rayon et unités.
     */
    private function catalogue(): void
    {
        $this->put('oeuf', self::OWNER, 'Œuf', 'oeuf', base: true);
        $this->put('jaune-o', self::OWNER, "Jaune d'œuf", 'jaune-d-oeuf', parent: 'oeuf', ratio: 1.0, base: true);
        $this->put('blanc-o', self::OWNER, "Blanc d'œuf", 'blanc-d-oeuf', parent: 'oeuf', ratio: 1.0, base: true);
    }

    private function put(string $slug, string $creator, string $name, string $sluggedName, ?string $from = null, ?string $parent = null, ?float $ratio = null, bool $base = false): void
    {
        $this->entityManager->getConnection()->insert('ingredient', [
            'slug' => $slug,
            'name' => $name,
            'image' => '',
            'slugged_name' => $sluggedName,
            'department' => 'divers',
            'default_cooking_unit' => 'unite',
            'default_purchase_unit' => 'unite',
            'creator' => $creator,
            'compatible_units' => '["unite"]',
            'adopted_from' => $from,
            'is_base' => $base ? 1 : 0,
            'parent_slug' => $parent,
            'parent_ratio' => $ratio,
        ]);
    }
}
