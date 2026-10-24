<?php

/**
 * Expérience de seuil du rapprochement d'ingrédients (docs/ingredients/rapprochement.md). Sans base de données, sans réseau.
 *
 * Elle répond à : « avec quelle normalisation, quelles garde-fous et quel seuil Jaro-Winkler se brancher sur un ingrédient qui existe
 * déjà (même rayon) sans jamais fusionner à tort deux produits différents ? »
 *
 *   php docs/ingredients/experiments/evaluate.php                      # vérité terrain synthétique + leave-one-out sur les communs publics
 *   php docs/ingredients/experiments/evaluate.php --prod=FICHIER.tsv   # en plus : mesure locale sur un export (slug, nom, rayon, commun t/f)
 *   php docs/ingredients/experiments/evaluate.php --prod=F.tsv --dump=PAIRES.txt   # écrit les paires (noms !) dans un fichier LOCAL
 *
 * L'option --prod lit des noms d'utilisateurs : elle n'imprime que des COMPTES, et --dump écrit des noms dans un fichier qui ne doit
 * jamais être versionné. Le fichier de production n'est jamais dans le dépôt.
 *
 * Dépendances : celles du projet (JaroWinkler et StringTool du dépôt, symfony/string pour le slugify), donc à lancer là où `vendor/` existe
 * (`docker compose exec php php docs/ingredients/experiments/evaluate.php`). Variable AUTOLOAD pour pointer un autre autoload.php.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 3);
foreach ([getenv('AUTOLOAD') ?: '', $root . '/vendor/autoload.php'] as $autoload) {
    if ($autoload !== '' && is_file($autoload)) {
        require_once $autoload;
        break;
    }
}
require_once $root . '/src/Shared/Domain/Tool/JaroWinkler.php';
require_once $root . '/src/Shared/Domain/Tool/StringTool.php';

use Repas\Shared\Domain\Tool\JaroWinkler;
use Repas\Shared\Domain\Tool\StringTool;

$options = getopt('', ['prod::', 'dump::', 'explain::']);

// ---------------------------------------------------------------------------------------------------------------------
// Normalisations (N0 est ce que fait déjà `StringTool::slugify`, utilisé par la recherche de l'ADR 0019)
// ---------------------------------------------------------------------------------------------------------------------
const STOPWORDS = ['de', 'du', 'des', 'd', 'l', 'la', 'le', 'les', 'un', 'une', 'a', 'au', 'aux', 'en', 'et', 'pour'];

/** @return list<string> */
function tokens(string $name, string $normalization): array
{
    $tokens = array_values(array_filter(explode('-', StringTool::slugify($name)), static fn(string $t): bool => $t !== ''));
    if ($normalization === 'N0') {
        return $tokens;
    }
    // N1 : pluriels repliés (un « s » ou un « x » final tombe sur les mots de plus de trois lettres)
    $folded = array_map(static fn(string $t): string => strlen($t) > 3 && preg_match('/[sx]$/', $t) === 1 ? substr($t, 0, -1) : $t, $tokens);
    if ($normalization === 'N1') {
        return $folded;
    }
    // N2 : N1 sans mots vides (« pomme de terre » = « pomme terre »), sauf si le nom n'est fait que de mots vides
    $meaningful = array_values(array_filter($folded, static fn(string $t): bool => !in_array($t, STOPWORDS, true)));

    return $meaningful === [] ? $folded : $meaningful;
}

// ---------------------------------------------------------------------------------------------------------------------
// Garde-fous : le Jaro-Winkler d'une phrase entière est dominé par sa partie commune (« couche-taille-4 » ~ « couche-taille-5 »)
// ---------------------------------------------------------------------------------------------------------------------

/** Distance d'édition « optimal string alignment » : insertion, oubli, substitution et permutation de deux lettres voisines valent 1. */
function osa(string $a, string $b): int
{
    $x = mb_str_split($a);
    $y = mb_str_split($b);
    $n = count($x);
    $m = count($y);
    $d = [];
    for ($i = 0; $i <= $n; $i++) {
        $d[$i][0] = $i;
    }
    for ($j = 0; $j <= $m; $j++) {
        $d[0][$j] = $j;
    }
    for ($i = 1; $i <= $n; $i++) {
        for ($j = 1; $j <= $m; $j++) {
            $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
            $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
            if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
            }
        }
    }

    return $d[$n][$m];
}

/**
 * @param list<string> $a
 * @param list<string> $b
 */
function passesGate(array $a, array $b, string $gate): bool
{
    if ($gate === 'G0') {
        return true;
    }
    if (count($a) !== count($b)) {
        return false; // « tomate » n'est pas « tomate cerise »
    }
    if ($gate === 'G1') {
        return true;
    }

    // G2 / G3 : chaque mot trouve son pareil. Mots courts ou chiffrés : identiques. G3 serre un peu plus (4 lettres, 0,90).
    // G4 / G5 : chaque mot est le même à une faute près (une seule opération d'édition ; G5 en tolère deux dans les mots de 9 lettres et plus).
    $exactUpTo = $gate === 'G2' ? 3 : 4;
    $tokenMinimum = $gate === 'G2' ? 0.88 : 0.90;
    $free = $b;
    foreach ($a as $tokenA) {
        $bestKey = null;
        $best = -1.0;
        foreach ($free as $key => $tokenB) {
            $score = $tokenA === $tokenB ? 1.0 : JaroWinkler::similarity($tokenA, $tokenB);
            if ($score > $best) {
                $best = $score;
                $bestKey = $key;
            }
        }
        if ($bestKey === null) {
            return false;
        }
        $tokenB = $free[$bestKey];
        unset($free[$bestKey]);
        if ($tokenA === $tokenB) {
            continue;
        }
        // G6 : comme G4 mais un mot de quatre lettres peut avoir une faute si l'autre en a cinq (« fras » / « frais »)
        $short = $gate === 'G6' ? max(strlen($tokenA), strlen($tokenB)) <= $exactUpTo : min(strlen($tokenA), strlen($tokenB)) <= $exactUpTo;
        if (preg_match('/\d/', $tokenA . $tokenB) === 1 || $short) {
            return false;
        }
        if ($gate === 'G4' || $gate === 'G5' || $gate === 'G6') {
            $allowed = $gate === 'G5' && min(strlen($tokenA), strlen($tokenB)) >= 9 ? 2 : 1;
            if (osa($tokenA, $tokenB) > $allowed) {
                return false;
            }
            continue;
        }
        if ($best < $tokenMinimum) {
            return false;
        }
    }

    return true;
}

/**
 * Candidats classés pour une requête : [[score, slug], …] décroissant, ceux qui passent le garde-fou seulement.
 *
 * @param list<string>                $query
 * @param array<string, list<string>> $candidates tokens par slug
 * @return list<array{float, string}>
 */
function ranked(array $query, array $candidates, string $gate): array
{
    $joined = implode('-', $query);
    $out = [];
    foreach ($candidates as $slug => $candidate) {
        if (!passesGate($query, $candidate, $gate)) {
            continue;
        }
        $out[] = [$joined === implode('-', $candidate) ? 1.0 : JaroWinkler::similarity($joined, implode('-', $candidate)), (string) $slug];
    }
    usort($out, static fn(array $x, array $y): int => [$y[0], $x[1]] <=> [$x[0], $y[1]]);

    return $out;
}

/**
 * Décision : le slug choisi ou null. Un nom identique une fois normalisé est toujours accepté ; sinon il faut atteindre le seuil et
 * ne pas avoir un second candidat presque aussi bon (ambiguïté : on ne choisit pas à la place de l'utilisateur).
 *
 * @param list<array{float, string}> $ranked
 */
function decide(array $ranked, float $threshold, float $margin): ?string
{
    if ($ranked === [] || $ranked[0][0] < $threshold) {
        return null;
    }
    if ($ranked[0][0] >= 1.0) {
        return $ranked[0][1];
    }
    if ($margin > 0 && isset($ranked[1]) && $ranked[1][0] >= $ranked[0][0] - $margin) {
        return null;
    }

    return $ranked[0][1];
}

// ---------------------------------------------------------------------------------------------------------------------
// Données publiques : les communs du dépôt et la vérité terrain
// ---------------------------------------------------------------------------------------------------------------------
$commons = json_decode((string) file_get_contents($root . '/src/Repas/Infrastructure/DataFixture/Data/ingredient.json'), true, flags: JSON_THROW_ON_ERROR);
$truth = json_decode((string) file_get_contents(__DIR__ . '/ground-truth.json'), true, flags: JSON_THROW_ON_ERROR)['cases'];

/** @return array<string, array<string, list<string>>> tokens par slug, par rayon */
function candidateIndex(array $commons, string $normalization): array
{
    $index = [];
    foreach ($commons as $c) {
        $index[$c['department']][$c['slug']] = tokens($c['name'], $normalization);
    }

    return $index;
}

/**
 * Résultats d'une configuration : pour chaque seuil, les comptes TP / mauvais lien / doublon créé / vrai négatif / faux lien.
 *
 * @return array<string, array<string, array<string, int>>> [seuil => [catégorie => compte]]
 */
function run(array $commons, array $truth, string $normalization, string $gate, float $margin, bool $sameDepartment = true): array
{
    $index = candidateIndex($commons, $normalization);
    $all = array_merge(...array_values($index));
    $thresholds = array_map(static fn(int $i): string => number_format($i / 100, 2), range(85, 99));
    $stats = [];

    $record = static function (string $category, ?string $expected, ?string $chosen, string $t) use (&$stats): void {
        $kind = match (true) {
            $expected !== null && $chosen === $expected => 'tp',
            $expected !== null && $chosen === null => 'fn',
            $expected !== null => 'wrong',
            $chosen === null => 'tn',
            default => 'fp',
        };
        $stats[$t][$category][$kind] = ($stats[$t][$category][$kind] ?? 0) + 1;
    };

    foreach ($truth as $case) {
        $candidates = $sameDepartment ? ($index[$case['department']] ?? []) : $all;
        $ranked = ranked(tokens($case['query'], $normalization), $candidates, $gate);
        foreach ($thresholds as $t) {
            $record($case['category'], $case['expected'], decide($ranked, (float) $t, $margin), $t);
        }
    }

    // Chaque commun contre les autres communs : aucun doublon dans le jeu de référence, donc tout lien est un faux lien
    foreach ($commons as $c) {
        $candidates = $sameDepartment ? ($index[$c['department']] ?? []) : $all;
        unset($candidates[$c['slug']]);
        $ranked = ranked(tokens($c['name'], $normalization), $candidates, $gate);
        foreach ($thresholds as $t) {
            $record('seul-contre-les-autres', null, decide($ranked, (float) $t, $margin), $t);
        }
    }

    return $stats;
}

/** @param array<string, array<string, int>> $byCategory */
function summary(array $byCategory, ?array $only = null, ?array $except = null): array
{
    $sum = ['tp' => 0, 'fn' => 0, 'wrong' => 0, 'tn' => 0, 'fp' => 0];
    foreach ($byCategory as $category => $counts) {
        if (($only !== null && !in_array($category, $only, true)) || ($except !== null && in_array($category, $except, true))) {
            continue;
        }
        foreach ($counts as $kind => $n) {
            $sum[$kind] += $n;
        }
    }
    $links = $sum['tp'] + $sum['wrong'] + $sum['fp'];
    $sum['faux'] = $sum['wrong'] + $sum['fp'];
    $sum['precision'] = $links === 0 ? 1.0 : $sum['tp'] / $links;
    $positives = $sum['tp'] + $sum['wrong'] + $sum['fn'];
    $sum['recall'] = $positives === 0 ? 0.0 : $sum['tp'] / $positives;
    $negatives = $sum['tn'] + $sum['fp'];
    $sum['fp_rate'] = $negatives === 0 ? 0.0 : $sum['fp'] / $negatives;
    $sum['negatives'] = $negatives;
    $sum['positives'] = $positives;

    return $sum;
}

function pct(float $x): string
{
    return number_format($x * 100, 1, ',', '') . ' %';
}

function table(string $title, array $stats): void
{
    echo "\n### $title\n\n| seuil | bons liens | mauvais liens | doublons créés | liens à tort sur « aucun » | précision | rappel | faux liens / négatifs |\n|---|---|---|---|---|---|---|---|\n";
    foreach ($stats as $t => $byCategory) {
        $s = summary($byCategory);
        printf("| %s | %d | %d | %d | %d | %s | %s | %d / %d (%s) |\n", $t, $s['tp'], $s['wrong'], $s['fn'], $s['fp'], pct($s['precision']), pct($s['recall']), $s['faux'], $s['negatives'] + $s['wrong'], pct($s['faux'] / max(1, $s['negatives'] + $s['wrong'])));
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// --explain=N2,G3,0.02,0.93 : liste (données publiques) les faux liens et les liens manqués d'une configuration, puis s'arrête
// ---------------------------------------------------------------------------------------------------------------------
if (isset($options['explain']) && is_string($options['explain'])) {
    [$normalization, $gate, $margin, $threshold] = explode(',', $options['explain']);
    $index = candidateIndex($commons, $normalization);
    $names = array_column($commons, 'name', 'slug');
    $lines = ['FAUX LIENS' => [], 'LIENS MANQUÉS' => []];
    $judge = static function (string $label, string $category, string $query, ?string $expected, array $candidates) use (&$lines, $normalization, $gate, $margin, $threshold, $names): void {
        $ranked = ranked(tokens($query, $normalization), $candidates, $gate);
        $chosen = decide($ranked, (float) $threshold, (float) $margin);
        if ($chosen !== $expected) {
            $score = $ranked[0][0] ?? 0.0;
            $key = $chosen !== null ? 'FAUX LIENS' : 'LIENS MANQUÉS';
            $lines[$key][] = sprintf('[%s] « %s » -> %s (attendu : %s) score %.3f', $category, $query, $chosen !== null ? "« {$names[$chosen]} »" : 'aucun', $expected !== null ? "« {$names[$expected]} »" : 'aucun', $score);
        }
    };
    foreach ($truth as $case) {
        $judge('', $case['category'], $case['query'], $case['expected'], $index[$case['department']] ?? []);
    }
    foreach ($commons as $c) {
        $candidates = $index[$c['department']] ?? [];
        unset($candidates[$c['slug']]);
        $judge('', 'seul-contre-les-autres', $c['name'], null, $candidates);
    }
    foreach ($lines as $title => $list) {
        echo "\n## $title (" . count($list) . ")\n" . implode("\n", array_slice($list, 0, 80)) . "\n";
    }
    exit(0);
}

// ---------------------------------------------------------------------------------------------------------------------
// Rapport
// ---------------------------------------------------------------------------------------------------------------------
$byOrigin = ['générés' => 0, 'écrits à la main' => 0];
foreach ($truth as $case) {
    $byOrigin[$case['origin'] === 'genere' ? 'générés' : 'écrits à la main']++;
}
$positives = count(array_filter($truth, static fn(array $c): bool => $c['expected'] !== null));
echo "# Expérience de seuil du rapprochement\n\nLégende. Normalisations : N0 = slug actuel (casse, accents, ponctuation) ; N1 = N0 + pluriels repliés ; N2 = N1 + mots vides retirés. Garde-fous sur les mots du nom : G0 = aucun ; G1 = même nombre de mots ; G2 = G1 + mots identiques ou Jaro-Winkler >= 0,88 (identiques si 3 lettres ou moins ou s'ils contiennent un chiffre) ; G3 = idem à 0,90 et 4 lettres ; G4 = G1 + chaque mot est identique ou à une faute près (distance d'édition 1, mots de 5 lettres et plus) ; G6 = G4 mais un mot de 4 lettres peut avoir une faute si son pareil en a 5. Marge : un second candidat à moins de 0,02 du premier rend le lien ambigu (aucun lien).\n\n";
printf("%d communs publics ; vérité terrain : %d cas (%d générés, %d écrits à la main), dont %d attendent un lien et %d attendent « aucun » ; plus %d requêtes « chaque commun contre les autres » (attendu : aucun).\n",
    count($commons), count($truth), $byOrigin['générés'], $byOrigin['écrits à la main'], $positives, count($truth) - $positives, count($commons));

// 1. Courbe du seuil pour la base de comparaison (Jaro-Winkler sur le slug, sans garde-fou) et pour la règle recommandée
table('Base : N0 (slug) + G0 (aucun garde-fou) + sans marge', run($commons, $truth, 'N0', 'G0', 0.0));
table('Recommandé : N2 (pluriels, mots vides) + G6 (mots à une faute près) + marge 0,02', run($commons, $truth, 'N2', 'G6', 0.02));

// 2. Ce que change chaque règle, à un seuil donné
foreach (['0.90', '0.93', '0.95', '0.97'] as $t) {
    echo "\n### Effet de chaque règle au seuil $t\n\n| normalisation | garde-fou | marge | bons liens | mauvais liens | doublons créés | liens à tort | précision | rappel |\n|---|---|---|---|---|---|---|---|---|\n";
    foreach (['N0', 'N1', 'N2'] as $normalization) {
        foreach (['G0', 'G1', 'G2', 'G3', 'G4', 'G6'] as $gate) {
            foreach ([0.0, 0.02] as $margin) {
                $s = summary(run($commons, $truth, $normalization, $gate, $margin)[$t]);
                printf("| %s | %s | %s | %d | %d | %d | %d | %s | %s |\n", $normalization, $gate, $margin === 0.0 ? 'non' : '0,02', $s['tp'], $s['wrong'], $s['fn'], $s['fp'], pct($s['precision']), pct($s['recall']));
            }
        }
    }
}

// 3. Sans la règle du rayon : ce qu'elle évite
foreach ([['Base N0 + G0, tous rayons', 'N0', 'G0', 0.0], ['Recommandé N2 + G6 + marge, tous rayons', 'N2', 'G6', 0.02]] as [$label, $normalization, $gate, $margin]) {
    echo "\n### Même rayon ou non : $label\n\n| seuil | liens à tort, même rayon seulement | liens à tort, tous les rayons |\n|---|---|---|\n";
    $same = run($commons, $truth, $normalization, $gate, $margin, true);
    $any = run($commons, $truth, $normalization, $gate, $margin, false);
    foreach (['0.85', '0.90', '0.93', '0.95', '0.97'] as $t) {
        printf("| %s | %d | %d |\n", $t, summary($same[$t])['faux'], summary($any[$t])['faux']);
    }
}

// 4. Par catégorie, pour la règle recommandée
echo "\n### Par catégorie, règle recommandée (N2 + G6 + marge 0,02)\n\n";
$recommended = run($commons, $truth, 'N2', 'G6', 0.02);
$categories = array_keys($recommended['0.95']);
sort($categories);
echo '| catégorie | ' . implode(' | ', array_map(static fn(string $t): string => "tp/fn/faux à $t", ['0.90', '0.93', '0.95', '0.97'])) . " |\n|---|---|---|---|---|\n";
foreach ($categories as $category) {
    $row = [];
    foreach (['0.90', '0.93', '0.95', '0.97'] as $t) {
        $c = $recommended[$t][$category] ?? [];
        $row[] = sprintf('%d / %d / %d', $c['tp'] ?? 0, $c['fn'] ?? 0, ($c['wrong'] ?? 0) + ($c['fp'] ?? 0));
    }
    echo "| $category | " . implode(' | ', $row) . " |\n";
}

// 5. Fautes de frappe : le rappel dépend de la longueur du nom
echo "\n### Fautes de frappe : rappel selon la longueur du nom (règle recommandée)\n\n| longueur du nom | cas | liens trouvés à 0,93 | à 0,95 | à 0,97 |\n|---|---|---|---|---|\n";
$index = candidateIndex($commons, 'N2');
$bySlug = array_column($commons, null, 'slug');
$buckets = ['moins de 6 lettres' => [0, 5], '6 à 9 lettres' => [6, 9], '10 lettres et plus' => [10, 99]];
foreach ($buckets as $label => [$min, $max]) {
    $count = $found93 = $found95 = $found97 = 0;
    foreach ($truth as $case) {
        if ($case['category'] !== 'faute' || $case['expected'] === null) {
            continue;
        }
        $length = mb_strlen($bySlug[$case['expected']]['name']);
        if ($length < $min || $length > $max) {
            continue;
        }
        $count++;
        $ranked = ranked(tokens($case['query'], 'N2'), $index[$case['department']] ?? [], 'G6');
        $found93 += decide($ranked, 0.93, 0.02) === $case['expected'] ? 1 : 0;
        $found95 += decide($ranked, 0.95, 0.02) === $case['expected'] ? 1 : 0;
        $found97 += decide($ranked, 0.97, 0.02) === $case['expected'] ? 1 : 0;
    }
    printf("| %s | %d | %d (%s) | %d (%s) | %d (%s) |\n", $label, $count, $found93, pct($count ? $found93 / $count : 0), $found95, pct($count ? $found95 / $count : 0), $found97, pct($count ? $found97 / $count : 0));
}

// ---------------------------------------------------------------------------------------------------------------------
// Mesure locale sur un export de production : COMPTES seulement (noms jamais imprimés)
// ---------------------------------------------------------------------------------------------------------------------
if (isset($options['prod']) && is_string($options['prod'])) {
    $rows = [];
    foreach (file($options['prod'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $cols = explode("\t", $line);
        if (count($cols) >= 4) {
            $creator = preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/', $cols[0], $m) === 1 && $cols[3] === 'f' ? $m[1] : null;
            $rows[] = ['slug' => $cols[0], 'name' => $cols[1], 'department' => $cols[2], 'common' => $cols[3] === 't', 'creator' => $creator];
        }
    }
    $dump = isset($options['dump']) && is_string($options['dump']) ? fopen($options['dump'], 'w') : null;
    $personal = array_values(array_filter($rows, static fn(array $r): bool => !$r['common']));
    $creators = array_unique(array_filter(array_column($personal, 'creator')));
    printf("\n## Mesure locale sur l'export de production (comptes seulement)\n\n%d ingrédients dont %d communs et %d personnels, créés par %d comptes différents.\n", count($rows), count($rows) - count($personal), count($personal), count($creators));

    foreach (['N0', 'N2'] as $normalization) {
        $tokens = [];
        foreach ($rows as $r) {
            $tokens[$r['slug']] = tokens($r['name'], $normalization);
        }
        // Noms identiques une fois normalisés : dans le même rayon (ce qu'une copie de commun rencontrera), dans un autre rayon
        $sameDept = $otherDept = 0;
        foreach ($personal as $p) {
            $found = false;
            foreach ($rows as $r) {
                if (!$r['common'] || $tokens[$r['slug']] !== $tokens[$p['slug']]) {
                    continue;
                }
                if ($r['department'] === $p['department']) {
                    $sameDept++;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                foreach ($rows as $r) {
                    if ($r['common'] && $tokens[$r['slug']] === $tokens[$p['slug']]) {
                        $otherDept++;
                        break;
                    }
                }
            }
        }
        printf("\n### %s : ingrédients personnels dont le nom est celui d'un commun\n\n- même rayon : %d ; seulement dans un autre rayon : %d.\n", $normalization, $sameDept, $otherDept);
    }

    // Chaque ingrédient personnel (requête) contre les communs et les ingrédients personnels des autres comptes, même rayon, noms différents
    foreach ([['Base N0 + G0', 'N0', 'G0', 0.0], ['Recommandé N2 + G6 + marge', 'N2', 'G6', 0.02]] as [$label, $normalization, $gate, $margin]) {
        $tokens = [];
        foreach ($rows as $r) {
            $tokens[$r['slug']] = tokens($r['name'], $normalization);
        }
        echo "\n### Liens que ferait la copie d'une recette : $label\n\nUn ingrédient personnel (requête) contre les communs et les ingrédients des autres comptes du même rayon, hors nom identique.\n\n| seuil | requêtes liées | dont un commun | dont l'ingrédient d'un autre compte |\n|---|---|---|---|\n";
        foreach (['0.85', '0.90', '0.93', '0.95', '0.97'] as $t) {
            $linked = $toCommon = $toOther = 0;
            foreach ($personal as $p) {
                $candidates = [];
                foreach ($rows as $r) {
                    if ($r['department'] === $p['department'] && $r['slug'] !== $p['slug'] && ($r['common'] || $r['creator'] !== $p['creator']) && $tokens[$r['slug']] !== $tokens[$p['slug']]) {
                        $candidates[$r['slug']] = $tokens[$r['slug']];
                    }
                }
                $chosen = decide(ranked($tokens[$p['slug']], $candidates, $gate), (float) $t, $margin);
                if ($chosen !== null) {
                    $linked++;
                    $target = current(array_filter($rows, static fn(array $r): bool => $r['slug'] === $chosen));
                    $target['common'] ? $toCommon++ : $toOther++;
                    if ($dump !== null) {
                        fwrite($dump, sprintf("%s\t%s\t%s\t%s\t%s\n", $label, $t, $p['department'], $p['name'], $target['name']));
                    }
                }
            }
            printf("| %s | %d | %d | %d |\n", $t, $linked, $toCommon, $toOther);
        }
    }
    // Écran « doublons possibles » : dans le monde d'un compte (ses ingrédients + les communs, qu'il possédera en copie), les paires du même
    // rayon dont au moins un des deux est à lui. (a) même nom une fois normalisé ; (b) à une faute près (G6) et Jaro-Winkler >= 0,90 ;
    // (c) un nom précisé par des mots de plus que l'autre (« poivre noir » / « poivre ») : NON proposé, compté pour mémoire.
    $tokens = [];
    foreach ($rows as $r) {
        $tokens[$r['slug']] = tokens($r['name'], 'N2');
    }
    $a = $b = $c = 0;
    foreach ($creators as $creator) {
        $world = array_values(array_filter($rows, static fn(array $r): bool => $r['common'] || $r['creator'] === $creator));
        foreach ($world as $i => $x) {
            foreach ($world as $j => $y) {
                if ($j <= $i || $x['department'] !== $y['department'] || ($x['common'] && $y['common'])) {
                    continue;
                }
                if ($tokens[$x['slug']] === $tokens[$y['slug']]) {
                    $a++;
                } elseif (passesGate($tokens[$x['slug']], $tokens[$y['slug']], 'G6') && JaroWinkler::similarity(implode('-', $tokens[$x['slug']]), implode('-', $tokens[$y['slug']])) >= 0.90) {
                    $b++;
                } elseif (array_diff($tokens[$x['slug']], $tokens[$y['slug']]) === [] || array_diff($tokens[$y['slug']], $tokens[$x['slug']]) === []) {
                    $c++;
                }
            }
        }
    }
    printf("\n### Écran « doublons possibles » : paires proposées (sur tous les comptes réunis, communs inclus dans le monde de chacun)\n\n- (a) même nom normalisé : %d ; (b) à une faute près : %d ; (c) nom précisé, non proposé : %d.\n", $a, $b, $c);

    if ($dump !== null) {
        fclose($dump);
        echo "\n(Les paires, avec leurs noms, ont été écrites dans le fichier --dump : LOCAL, à ne jamais versionner.)\n";
    }
}
