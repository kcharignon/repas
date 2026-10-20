<?php

/**
 * Construit `ground-truth.json` : la vérité terrain de l'expérience de seuil (ING-003, docs/ingredients/rapprochement.md).
 *
 * Sans base, sans donnée personnelle : les candidats sont les ingrédients COMMUNS du jeu de référence du dépôt
 * (src/Repas/Infrastructure/DataFixture/Data/ingredient.json, publics) et les requêtes sont des variantes synthétiques
 * (générées avec une graine fixe) ou écrites à la main ci-dessous.
 *
 * Une ligne de vérité terrain : « ce nom, tapé dans ce rayon, doit se brancher sur CET ingrédient » (`expected` = slug du commun)
 * ou « ne doit se brancher sur aucun » (`expected` = null : un doublon que l'utilisateur fusionnera vaut mieux qu'une fusion à tort).
 *
 * Usage : php docs/ingredients/experiments/build-ground-truth.php > docs/ingredients/experiments/ground-truth.json
 * (la sortie est versionnée ; la relancer donne le même fichier).
 */

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$commons = json_decode((string) file_get_contents($root . '/src/Repas/Infrastructure/DataFixture/Data/ingredient.json'), true, flags: JSON_THROW_ON_ERROR);

$bySlug = [];
$byNameInDepartment = [];
foreach ($commons as $c) {
    $bySlug[$c['slug']] = $c;
    $byNameInDepartment[$c['department'] . '|' . $c['name']] = $c['slug'];
}

$cases = [];
$add = static function (string $query, string $department, ?string $expected, string $category, string $origin) use (&$cases): void {
    $cases[] = ['query' => $query, 'department' => $department, 'expected' => $expected, 'category' => $category, 'origin' => $origin];
};

// ---------------------------------------------------------------------------------------------------------------------
// 1. Variantes générées (graine fixe) : l'attendu est l'ingrédient d'origine par construction.
// ---------------------------------------------------------------------------------------------------------------------
mt_srand(20261020);

const STOP_PLURAL = ['de', 'du', 'des', 'd', 'l', 'la', 'le', 'les', 'a', 'à', 'au', 'aux', 'en', 'et', 'pour', 'sur', 'sans'];

function pluralWord(string $word): string
{
    $lower = mb_strtolower($word);
    if (in_array($lower, STOP_PLURAL, true) || mb_strlen($word) < 3 || preg_match('/[sxz0-9()]$/u', $word) === 1) {
        return $word;
    }
    if (preg_match('/(eau|au|eu)$/u', $word) === 1) {
        return $word . 'x';
    }

    return $word . 's';
}

function pluralize(string $name): string
{
    return implode(' ', array_map('pluralWord', explode(' ', $name)));
}

function singularize(string $name): string
{
    $words = explode(' ', $name);
    $last = array_key_last($words);
    if (mb_strlen($words[$last]) > 3 && str_ends_with($words[$last], 's') && !in_array(mb_strtolower($words[$last]), STOP_PLURAL, true)) {
        $words[$last] = mb_substr($words[$last], 0, -1);
    }

    return implode(' ', $words);
}

function stripAccents(string $text): string
{
    return strtr($text, [
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u',
        'ç' => 'c', 'œ' => 'oe', 'É' => 'E',
    ]);
}

/** Voisins d'une touche sur un clavier AZERTY (substitution plausible). */
const NEIGHBOURS = [
    'a' => 'zq', 'z' => 'aes', 'e' => 'zrd', 'r' => 'etf', 't' => 'ryg', 'y' => 'tuh', 'u' => 'yij', 'i' => 'uok', 'o' => 'ipl', 'p' => 'om',
    'q' => 'asw', 's' => 'qdz', 'd' => 'sfe', 'f' => 'dgr', 'g' => 'fht', 'h' => 'gjy', 'j' => 'hku', 'k' => 'jli', 'l' => 'kmo', 'm' => 'lp',
    'w' => 'xqs', 'x' => 'wcs', 'c' => 'xvd', 'v' => 'cbf', 'b' => 'vng', 'n' => 'bhj',
];

/** @return array{string, string}|null [opération, variante] ou null si le nom est trop court pour une faute plausible */
function typo(string $name, int $variantNumber): ?array
{
    $chars = mb_str_split($name);
    $candidates = [];
    foreach ($chars as $i => $ch) {
        // on évite la première lettre (on se trompe rarement au début d'un mot) et les non-lettres
        if ($i === 0 || preg_match('/^\p{L}$/u', $ch) !== 1 || $chars[$i - 1] === ' ') {
            continue;
        }
        $candidates[] = $i;
    }
    if (count($candidates) < 3 || mb_strlen($name) < 5) {
        return null;
    }

    $i = $candidates[mt_rand(0, count($candidates) - 1)];
    $operations = ['transposition', 'oubli', 'doublon', 'voisine'];
    $operation = $operations[($variantNumber + mt_rand(0, 3)) % 4];
    $out = $chars;
    switch ($operation) {
        case 'transposition':
            if (!isset($out[$i + 1]) || preg_match('/^\p{L}$/u', $out[$i + 1]) !== 1) {
                return null;
            }
            [$out[$i], $out[$i + 1]] = [$out[$i + 1], $out[$i]];
            break;
        case 'oubli':
            unset($out[$i]);
            break;
        case 'doublon':
            array_splice($out, $i, 0, [$chars[$i]]);
            break;
        case 'voisine':
            $lower = mb_strtolower($chars[$i]);
            if (!isset(NEIGHBOURS[$lower])) {
                return null;
            }
            $out[$i] = NEIGHBOURS[$lower][mt_rand(0, strlen(NEIGHBOURS[$lower]) - 1)];
            break;
    }
    $variant = implode('', $out);

    return $variant === $name ? null : [$operation, $variant];
}

foreach ($commons as $c) {
    $name = $c['name'];
    $department = $c['department'];
    $slug = $c['slug'];

    // casse et ponctuation : trivial une fois le nom normalisé
    $add(mb_strtoupper($name) . ' ', $department, $slug, 'casse', 'genere');
    $add(ucfirst(str_replace(' ', '-', $name)) . '.', $department, $slug, 'ponctuation', 'genere');

    // accents manquants
    $stripped = stripAccents($name);
    if ($stripped !== $name) {
        $add($stripped, $department, $slug, 'accent', 'genere');
    }

    // pluriel (ou singulier si le nom est déjà au pluriel)
    $plural = pluralize($name);
    if ($plural !== $name) {
        $add(ucfirst($plural), $department, $slug, 'pluriel', 'genere');
    } else {
        $singular = singularize($name);
        if ($singular !== $name) {
            $add($singular, $department, $slug, 'pluriel', 'genere');
        }
    }

    // fautes de frappe : deux variantes par nom quand il est assez long
    for ($v = 0; $v < 2; $v++) {
        $typo = typo($name, $v);
        if ($typo !== null) {
            $add($typo[1], $department, $slug, 'faute', 'genere');
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// 2. Cas écrits à la main : ce qu'un proche taperait vraiment, et les pièges que le jeu de référence contient.
//    Format : [requête, rayon, nom du commun attendu dans ce rayon ou null, catégorie]
// ---------------------------------------------------------------------------------------------------------------------
$hand = [
    // pluriels et ligatures courants
    ['Œufs', 'divers', 'œuf', 'pluriel'], ['Oeufs', 'divers', 'œuf', 'pluriel'], ['oeuf', 'divers', 'œuf', 'accent'], ['Oeuf', 'divers', 'œuf', 'accent'],
    ['Tomates', 'fruit-et-legume', 'tomate', 'pluriel'], ['Pommes de terre', 'fruit-et-legume', 'pomme de terre', 'pluriel'],
    ['Pomme-de-terre', 'fruit-et-legume', 'pomme de terre', 'ponctuation'], ['Carottes', 'fruit-et-legume', 'carotte', 'pluriel'],
    ['Oignons', 'fruit-et-legume', 'oignon', 'pluriel'], ['Oignons rouges', 'fruit-et-legume', 'oignon rouge', 'pluriel'],
    ['Courgettes', 'fruit-et-legume', 'courgette', 'pluriel'], ['Poireaux', 'fruit-et-legume', 'poireau', 'pluriel'],
    ['Champignons', 'fruit-et-legume', 'champignon', 'pluriel'], ['Poivrons', 'fruit-et-legume', 'poivron', 'pluriel'],
    ['Épinards', 'fruit-et-legume', 'épinard', 'pluriel'], ['Echalotes', 'fruit-et-legume', 'échalote', 'accent'],
    ['Choux-fleurs', 'fruit-et-legume', 'choux fleur', 'pluriel'], ['Tomates cerises', 'fruit-et-legume', 'tomate cerise', 'pluriel'],
    ['Salades', 'fruit-et-legume', 'salade', 'pluriel'], ['Pommes', 'fruit-et-legume', 'pomme', 'pluriel'],
    ['Lardons', 'boucherie', 'lardon', 'pluriel'], ['Saucisses', 'boucherie', 'saucisse', 'pluriel'], ['Boeuf', 'boucherie', 'bœuf', 'accent'],
    ['Boeuf bourguignon', 'boucherie', 'bœuf bourguignon', 'accent'], ['Merguez', 'boucherie', 'merguez', 'casse'], ['Cuisses de poulet', 'boucherie', 'cuisse de poulet', 'pluriel'],
    ['Pâtes', 'cereale', 'pate', 'pluriel'], ['Lentilles', 'cereale', 'lentille', 'pluriel'], ['Nouilles', 'cereale', 'nouille', 'pluriel'],
    ['Olives noires', 'conserve', 'olive noir', 'pluriel'], ['Olive noire', 'conserve', 'olive noir', 'faute'], ['Petits pois', 'conserve', 'petit pois', 'pluriel'],
    ['Maïs', 'conserve', 'maïs', 'casse'], ['Mais', 'conserve', 'maïs', 'accent'], ['Crème fraîche épaisse', 'fromage', 'crème fraiche épaisse', 'accent'],
    ['Creme fraiche liquide', 'fromage', 'crème fraiche liquide', 'accent'], ['Chevre', 'fromage', 'chèvre', 'accent'], ['Comte', 'fromage', 'comté', 'accent'],
    ['Levures boulangères', 'aide-patissier', 'levure boulangère', 'pluriel'], ['Maizena', 'aide-patissier', 'maïzena', 'accent'], ['Gelatine', 'aide-patissier', 'gélatine', 'accent'],
    ['Couches taille 4', 'bebe', 'couche (taille 4)', 'pluriel'], ['Couche taille 5', 'bebe', 'couche (taille 5)', 'ponctuation'], ['Couches', 'bebe', 'couche', 'pluriel'],
    ['Lingette bébé', 'bebe', 'lingettes pour bébé', 'pluriel'], ['Huile d\'olive', 'sauce', 'huile d\'olive', 'casse'], ['Huiles', 'sauce', 'huile', 'pluriel'],
    // fautes de frappe typiques, y compris sur les fautes que le jeu de référence contient lui-même
    ['Tomtae', 'fruit-et-legume', 'tomate', 'faute'], ['Carrote', 'fruit-et-legume', 'carotte', 'faute'], ['Courgete', 'fruit-et-legume', 'courgette', 'faute'],
    ['Aubergnie', 'fruit-et-legume', 'aubergine', 'faute'], ['Champignion', 'fruit-et-legume', 'champignon', 'faute'], ['Bananne', 'fruit-et-legume', 'banane', 'faute'],
    ['Pomme de tere', 'fruit-et-legume', 'pomme de terre', 'faute'], ['Betterave', 'fruit-et-legume', 'bettrave', 'faute'], ['Butternut', 'fruit-et-legume', 'betternut', 'faute'],
    ['Brocolli', 'fruit-et-legume', 'brocoli', 'faute'], ['Mozarella', 'fromage', 'mozzarella', 'faute'], ['Parmezan', 'fromage', 'parmesan', 'faute'],
    ['Mayonaise', 'sauce', 'mayonnaise', 'faute'], ['Spaghetti', 'cereale', 'spaguetti', 'faute'], ['Coquillette', 'cereale', 'coquilette', 'faute'],
    ['Gnocchi', 'traiteur', 'gnocci', 'faute'], ['Steak', 'boucherie', 'steack', 'faute'], ['Steak haché', 'surgele', 'steack haché', 'faute'],
    ['Boulghour', 'cereale', 'boulgour', 'faute'], ['Concombe', 'fruit-et-legume', 'concombre', 'faute'], ['Jambon crus', 'boucherie', 'jambon cru', 'faute'],
    ['Vinaigre balsamic', 'sauce', 'vinaigre balsamique', 'faute'], ['Bicarbonat', 'aide-patissier', 'bicarbonate', 'faute'], ['Chocola', 'aide-patissier', 'chocolat', 'faute'],

    // qualificatifs : un produit précisé n'est pas le produit nu (politique conservatrice : un doublon vaut mieux qu'une fusion à tort)
    ['Poivre noir', 'divers', null, 'qualificatif'], ['Poivre blanc', 'divers', null, 'qualificatif'], ['Sel fin', 'divers', null, 'qualificatif'],
    ['Sel de Guérande', 'divers', null, 'qualificatif'], ['Lait demi-écrémé', 'divers', null, 'qualificatif'], ['Lait d\'amande', 'divers', null, 'qualificatif'],
    ['Miel d\'acacia', 'divers', null, 'qualificatif'], ['Tomate grappe', 'fruit-et-legume', null, 'qualificatif'], ['Tomate ancienne', 'fruit-et-legume', null, 'qualificatif'],
    ['Oignon jaune', 'fruit-et-legume', null, 'qualificatif'], ['Pomme granny', 'fruit-et-legume', null, 'qualificatif'], ['Carotte râpée', 'fruit-et-legume', null, 'qualificatif'],
    ['Salade batavia', 'fruit-et-legume', null, 'qualificatif'], ['Haricots verts', 'fruit-et-legume', null, 'qualificatif'], ['Poireau bio', 'fruit-et-legume', null, 'qualificatif'],
    ['Jambon sec', 'boucherie', null, 'qualificatif'], ['Poulet fermier', 'boucherie', null, 'qualificatif'], ['Saumon fumé', 'boucherie', null, 'qualificatif'],
    ['Farine de blé', 'aide-patissier', null, 'qualificatif'], ['Farine complète', 'aide-patissier', null, 'qualificatif'], ['Sucre glace', 'aide-patissier', null, 'qualificatif'],
    ['Sucre en poudre', 'aide-patissier', null, 'qualificatif'], ['Chocolat noir', 'aide-patissier', null, 'qualificatif'], ['Beurre doux', 'fromage', null, 'qualificatif'],
    ['Beurre demi-sel', 'fromage', null, 'qualificatif'], ['Fromage râpé', 'fromage', null, 'qualificatif'], ['Huile de tournesol', 'sauce', null, 'qualificatif'],
    ['Vinaigre de cidre', 'sauce', null, 'qualificatif'], ['Riz basmati', 'cereale', null, 'qualificatif'], ['Pâte à tarte', 'traiteur', null, 'qualificatif'],
    ['Haricot vert', 'conserve', null, 'qualificatif'], ['Pain complet', 'boulangerie', null, 'qualificatif'], ['Yaourt grec', 'yaourt', null, 'qualificatif'],
    ['Couche taille 7', 'bebe', null, 'qualificatif'], ['Lait 4e âge', 'bebe', null, 'qualificatif'],

    // frères trompeurs : proches à l'écrit, produits différents, même rayon
    ['Poivre', 'fruit-et-legume', null, 'frere'], ['Chou', 'fruit-et-legume', null, 'frere'], ['Tomme', 'fruit-et-legume', null, 'frere'],
    ['Crème fraîche', 'fromage', null, 'frere'], ['Vin rouge', 'alcool', null, 'frere'], ['Vin blanc', 'alcool', null, 'frere'],
    ['Olive verte bio', 'conserve', null, 'frere'], ['Confiture de cerise', 'conserve', null, 'frere'], ['Confiture de fraises', 'conserve', 'confiture fraise', 'pluriel'],
    ['Pâte feuilletée', 'cereale', null, 'frere'], ['Pâte brisée', 'cereale', null, 'frere'], ['Levure', 'aide-patissier', null, 'frere'],
    ['Farine pâtissière', 'aide-patissier', null, 'frere'], ['Jambon fumé', 'boucherie', null, 'frere'], ['Bouillon de poule', 'traiteur', null, 'frere'],
    ['Compote de pomme', 'yaourt', null, 'frere'], ['Chips nature', 'aperitif', null, 'frere'], ['Chips', 'aperitif', 'chips', 'casse'],
    ['Pain de campagne', 'boulangerie', null, 'frere'], ['Pain au chocolat', 'boulangerie', null, 'frere'], ['Courgette longue', 'fruit-et-legume', null, 'frere'],
    ['Lait 2e âge', 'bebe', 'lait 2e âge', 'casse'], ['Lait 3e âge', 'bebe', 'lait 3e âge', 'casse'], ['Lait 1er âge', 'bebe', 'lait 1er âge', 'casse'],
    // pièges à une lettre près : le garde-fou « une faute par mot » les laisse passer, seul le Jaro-Winkler ou le rayon les arrête
    ['Poule', 'boucherie', null, 'frere'], ['Hache', 'boucherie', null, 'frere'], ['Cote', 'fromage', null, 'frere'],
    ['Boeuf', 'divers', null, 'frere'], ['Oeuf', 'boucherie', null, 'frere'], ['Laitière', 'animal', null, 'frere'], ['Litière', 'yaourt', null, 'frere'],
    ['Saucissons', 'boucherie', 'saucisson', 'pluriel'], ['Saucisse fumée', 'boucherie', null, 'qualificatif'], ['Bœuf haché', 'boucherie', null, 'qualificatif'],
    ['Oeuf dur', 'divers', null, 'qualificatif'], ['Blanc d\'œuf', 'divers', null, 'qualificatif'], ['Oeufs bio', 'divers', null, 'qualificatif'],
    ['Côte de porc', 'boucherie', 'cote de porc', 'accent'], ['Cotes de porc', 'boucherie', 'cote de porc', 'pluriel'],
];

foreach ($hand as [$query, $department, $expectedName, $category]) {
    $expected = null;
    if ($expectedName !== null) {
        $expected = $byNameInDepartment[$department . '|' . $expectedName] ?? null;
        if ($expected === null) {
            fwrite(STDERR, "Cas écrit à la main sans commun correspondant : « $expectedName » dans « $department »\n");
            exit(1);
        }
    }
    $add($query, $department, $expected, $category, 'main');
}

// ---------------------------------------------------------------------------------------------------------------------
// 3. Sortie : numérotée, stable.
// ---------------------------------------------------------------------------------------------------------------------
foreach ($cases as $n => &$case) {
    $case = ['id' => sprintf('c%04d', $n + 1)] + $case;
}
unset($case);

$meta = [
            'description' => "Vérité terrain synthétique du rapprochement d'ingrédients (docs/ingredients/rapprochement.md). Aucune donnée d'utilisateur : candidats = ingrédients communs du jeu de référence du dépôt.",
            'how' => "Pour chaque ligne : la requête est un nom tapé dans le rayon `department` ; `expected` est le slug du commun sur lequel il faut se brancher, ou null s'il ne faut se brancher sur aucun (un doublon que l'utilisateur fusionnera vaut mieux qu'une fusion à tort).",
            'categories' => [
                'casse' => 'majuscules, espaces', 'ponctuation' => 'tirets, point final', 'accent' => 'accent manquant ou œ écrit oe', 'pluriel' => 'pluriel ou singulier',
                'faute' => 'faute de frappe (transposition, oubli, doublon, touche voisine)', 'qualificatif' => 'produit précisé (attendu : pas de rapprochement)',
                'frere' => 'produit voisin mais différent (attendu : pas de rapprochement)',
            ],
            'leave_one_out' => "En plus de ces lignes, l'évaluation rejoue chaque commun comme requête contre les autres communs de son rayon : tout rapprochement est un faux rapprochement (aucun doublon dans le jeu de référence).",
            'seed' => 20261020,
            'count' => count($cases),
        ];
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
echo "{\n  \"meta\": " . json_encode($meta, $flags | JSON_PRETTY_PRINT) . ",\n  \"cases\": [\n";
echo implode(",\n", array_map(static fn(array $case): string => '    ' . json_encode($case, $flags), $cases)), "\n  ]\n}\n";
