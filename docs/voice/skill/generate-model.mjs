#!/usr/bin/env node
// Génère docs/voice/skill/interaction-model.fr-FR.json (ADR 0029, VOX-205, VOX-303 et VOX-305). Node pur, aucune dépendance, aucun appel réseau.
//
//   node docs/voice/skill/generate-model.mjs           écrit le modèle
//   node docs/voice/skill/generate-model.mjs --check   n'écrit rien, sort en erreur si le fichier n'est pas celui que le script produirait
//
// Sources, toutes versionnées dans le dépôt (le modèle n'a donc jamais de valeur « à la main ») :
//   - docs/ingredients/base-ingredients.tsv                        les noms des ingrédients « de base » (type INGREDIENT)
//   - src/Repas/Infrastructure/DataFixture/Data/department.json    les rayons (type DEPARTMENT, id = slug)
//   - src/Repas/Infrastructure/DataFixture/Data/unit.json          les unités (type UNIT, id = slug)
//
// Un rayon ou une unité du catalogue sans entrée dans les tables de synonymes ci-dessous fait échouer le script : on ajoute ses
// synonymes ici, puis on relance. `tests/Voice/DocsVoiceSkillTest.php` vérifie, côté PHP, que les ids du modèle sont ceux des fixtures et
// que les intentions du modèle sont celles de `AlexaIntent`.
//
// Règles d'Amazon (console) respectées ici : aucune valeur de type personnalisé ne contient de chiffre, de parenthèse ni de majuscule ;
// les valeurs et leurs synonymes sont uniques dans un même type. Les identifiants (`id`) peuvent être des chiffres.

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const modelPath = join(root, 'docs', 'voice', 'skill', 'interaction-model.fr-FR.json');

/** Les formes réduites à un créneau seul (« {item} », « {department} »…) : à retirer si la console les refuse (`--no-bare`). */
const BARE_FORMS = !process.argv.includes('--no-bare');

const INVOCATION_NAME = 'jarvis';

// ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
// Ingrédients : les noms du catalogue de base, écrits comme on les dit
// ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

const SPOKEN_NUMBERS = { '1er': 'premier', '2e': 'deuxième', '3e': 'troisième', '3': 'trois', '4': 'quatre', '5': 'cinq', '6': 'six' };

/** « couche (taille 4) » devient « couche taille quatre » ; `null` si le nom garde un chiffre ou une parenthèse (« cone 3d »). */
function spoken(name) {
  const words = name
    .toLowerCase()
    .replace(/[()]/g, ' ')
    .split(/\s+/)
    .filter(Boolean)
    .map((word) => SPOKEN_NUMBERS[word] ?? word);
  const result = words.join(' ');

  return /[\d()]/.test(result) ? null : result;
}

function ingredientValues() {
  const lines = readFileSync(join(root, 'docs', 'ingredients', 'base-ingredients.tsv'), 'utf8').split('\n').filter(Boolean).slice(1);
  const names = lines.map((line) => spoken(line.split('\t')[1])).filter((name) => name !== null);

  return [...new Set(names)].sort((first, second) => first.localeCompare(second, 'fr')).map((name) => ({ name: { value: name } }));
}

// ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
// Rayons et unités : un identifiant (le slug du catalogue), un nom, des façons de le dire
// ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

const DEPARTMENT_SYNONYMS = {
  'fruit-et-legume': ['fruits et légumes', 'légumes', 'fruits', 'fruit', 'légume', 'fruits légumes', 'primeur'],
  fromage: ['fromages', 'crèmerie'],
  yaourt: ['yaourts', 'laitages', 'desserts'],
  gateau: ['gâteaux', 'gâteaux secs', 'biscuits', 'biscuiterie'],
  bebe: ['bébés', 'produits bébé', 'produits pour bébé'],
  aperitif: ['apéritifs', 'apéro'],
  boucherie: ['viande', 'viandes', 'la boucherie'],
  alcool: ['alcools', 'vin', 'vins', 'spiritueux'],
  boisson: ['boissons', 'jus', 'sodas'],
  surgele: ['surgelés', 'les surgelés', 'congelé', 'congelés'],
  conserve: ['conserves', 'boîtes de conserve'],
  'aide-patissier': ['aides pâtissiers', 'pâtisserie', 'produits de pâtisserie'],
  cereale: ['céréales', 'petit déjeuner'],
  traiteur: ['plats cuisinés', 'charcuterie'],
  'produit-menager': ['produits ménagers', 'ménager', 'entretien', 'ménage'],
  boulangerie: ['pain', 'viennoiserie'],
  sauce: ['sauces', 'condiments'],
  animal: ['animaux', 'animalerie', 'produits pour animaux'],
  divers: ['autre', 'autres', 'tout le reste'],
  'cuisine-du-monde': ['cuisines du monde', 'produits du monde', 'épicerie du monde'],
  hygiene: ['produits hygiène', 'salle de bain', 'beauté'],
};

const UNIT_SYNONYMS = {
  unite: ['unités', 'pièce', 'pièces'],
  kilo: ['kilos', 'kilogramme', 'kilogrammes'],
  gramme: ['grammes'],
  litre: ['litres'],
  millilitre: ['millilitres'],
  centilitre: ['centilitres'],
  'cuillere-a-soupe': ['cuillères à soupe', 'cuillerée à soupe', 'cuillerées à soupe'],
  'cuillere-a-cafe': ['cuillères à café', 'cuillerée à café', 'cuillerées à café'],
  'tasse-a-cafe': ['tasses à café', 'tasse', 'tasses'],
  verre: ['verres'],
  bol: ['bols'],
  plaquette: ['plaquettes'],
  tablette: ['tablettes'],
  tranche: ['tranches'],
  paquet: ['paquets'],
  boite: ['boîtes', 'boite', 'boites'],
  sachet: ['sachets'],
  bouteille: ['bouteilles'],
  conserve: ['conserves'],
  cube: ['cubes'],
  buche: ['bûches'],
  brique: ['briques'],
  pot: ['pots'],
  gousse: ['gousses'],
  flacon: ['flacons'],
  botte: ['bottes'],
};

/**
 * Des plats courants, dits comme on les dit : un INDICE pour la reconnaissance vocale d'Amazon (type RECIPE du créneau `recipe`, VOX-305), pas une limite.
 * Les recettes d'un utilisateur ne peuvent pas être dans le modèle (il n'y a pas de liste par utilisateur) : le créneau accepte aussi les autres noms, et le
 * webhook rapproche le texte entendu des recettes de l'utilisateur. Amazon refuse chiffres, parenthèses et majuscules dans une valeur.
 */
const DISHES = [
  'hachis parmentier', 'lasagnes', 'gratin dauphinois', 'pâtes carbonara', 'pâtes à la bolognaise', 'spaghetti bolognaise', 'blanquette de veau',
  'boeuf bourguignon', 'pot au feu', 'poulet rôti', 'poulet curry', 'poulet basquaise', 'poulet au citron', 'poulet à la crème', 'couscous', 'tajine de poulet',
  'ratatouille', 'quiche lorraine', 'tarte aux poireaux', 'tarte à la tomate', 'pizza maison', 'pizza margherita', 'croque monsieur', 'croque madame',
  'gratin de pâtes', 'gratin de courgettes', 'gratin de chou fleur', 'soupe de légumes', 'velouté de potiron', 'velouté de champignons', 'soupe à l\'oignon',
  'risotto aux champignons', 'risotto aux courgettes', 'paella', 'chili con carne', 'burger maison', 'galettes de blé noir', 'crêpes jambon fromage',
  'omelette aux champignons', 'oeuf au plat', 'oeufs durs', 'oeufs à la coque', 'saumon en papillote', 'filet de poisson', 'poisson pané', 'brandade de morue',
  'moules frites', 'steak frites', 'saucisses purée', 'purée de pommes de terre', 'rôti de porc', 'côtes de porc', 'escalope de dinde', 'escalope milanaise',
  'cordon bleu', 'nuggets maison', 'tartiflette', 'raclette', 'fondue savoyarde', 'croziflette', 'pâtes au pesto', 'pâtes au saumon', 'salade de pâtes',
  'salade niçoise', 'salade césar', 'taboulé', 'riz cantonais', 'nems', 'curry de légumes', 'dal de lentilles', 'falafels', 'wraps au poulet', 'tacos', 'fajitas',
  'endives au jambon', 'flamiche aux poireaux', 'clafoutis', 'far breton', 'gâteau au chocolat', 'tarte aux pommes', 'riz au lait', 'crêpes',
];

function recipeValues() {
  return [...new Set(DISHES)].sort((first, second) => first.localeCompare(second, 'fr')).map((name) => ({ name: { value: name } }));
}

/**
 * « une recette », « un produit » : aiguille un produit non compris vers les recettes, ou l'inverse (VOX-305). L'identifiant est ce que le webhook lit
 * (`meal` ou `item`) ; les valeurs sont des mots, sans chiffre.
 */
const KINDS = [
  { id: 'meal', name: 'recette', synonyms: ['recettes', 'repas'] },
  { id: 'item', name: 'produit', synonyms: ['produits', 'article', 'articles', 'ingrédient', 'ingrédients'] },
];

function kindValues() {
  return KINDS.map((kind) => ({ id: kind.id, name: { value: kind.name, synonyms: kind.synonyms } }));
}

/** Un rang parlé, de 1 à 3 : l'identifiant est le rang. Les articles sont dans les phrases d'exemple, pas dans les valeurs. */
const RANKS = [
  { id: '1', name: 'premier', synonyms: ['première', 'un', 'une'] },
  { id: '2', name: 'deuxième', synonyms: ['second', 'seconde', 'deux'] },
  { id: '3', name: 'troisième', synonyms: ['dernier', 'dernière', 'trois'] },
];

/** « fruit & légume » se dit « fruit et légume ». */
function spokenName(name) {
  return name.toLowerCase().replace(/\s*&\s*/g, ' et ');
}

function catalogueValues(file, synonymsTable, label) {
  const items = JSON.parse(readFileSync(join(root, 'src', 'Repas', 'Infrastructure', 'DataFixture', 'Data', file), 'utf8'));
  const slugs = items.map((item) => item.slug);

  const missing = slugs.filter((slug) => !(slug in synonymsTable));
  const unknown = Object.keys(synonymsTable).filter((slug) => !slugs.includes(slug));
  if (missing.length > 0 || unknown.length > 0) {
    throw new Error(`${label} : synonymes à écrire pour [${missing.join(', ')}], à retirer pour [${unknown.join(', ')}] (${file}).`);
  }

  return items.map((item) => {
    const value = spokenName(item.name);
    const synonyms = [...new Set(synonymsTable[item.slug].map((synonym) => synonym.toLowerCase()))].filter((synonym) => synonym !== value);

    return { id: item.slug, name: { value, synonyms } };
  });
}

const SMALL_NUMBERS = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize'];
const TENS = { 2: 'vingt', 3: 'trente', 4: 'quarante', 5: 'cinquante', 6: 'soixante' };

/** Un nombre de 1 à 99 en lettres, sans trait d'union (« vingt deux », « quatre vingt dix neuf ») : Amazon n'en veut pas dans une valeur. */
function frenchNumber(number) {
  if (number <= 16) {
    return SMALL_NUMBERS[number];
  }
  if (number < 20) {
    return `dix ${SMALL_NUMBERS[number - 10]}`;
  }
  if (number < 70) {
    const tens = TENS[Math.floor(number / 10)];
    const units = number % 10;

    return units === 0 ? tens : units === 1 ? `${tens} et un` : `${tens} ${SMALL_NUMBERS[units]}`;
  }
  if (number < 80) {
    return number === 71 ? 'soixante et onze' : `soixante ${frenchNumber(number - 60)}`;
  }

  return number === 80 ? 'quatre vingts' : `quatre vingt ${frenchNumber(number - 80)}`;
}

/**
 * Les quantités dites, de 1 à 99 : un type à nous plutôt que `AMAZON.NUMBER`, qu'Amazon laissait vide quand le nombre précédait une unité (« deux paquets
 * de croquettes », essai réel du 2026-10-25). Amazon refuse les chiffres dans une valeur : la valeur est le nombre en lettres, l'identifiant est le nombre
 * lui-même (les `id` peuvent être des chiffres) et le webhook le lit tel quel.
 */
function quantityValues() {
  return Array.from({ length: 99 }, (_, index) => {
    const number = index + 1;

    return { id: String(number), name: { value: frenchNumber(number), synonyms: number === 1 ? ['une'] : [] } };
  });
}

function rankValues() {
  return RANKS.map((rank) => ({ id: rank.id, name: { value: rank.name, synonyms: rank.synonyms } }));
}

// ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
// Intentions
// ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

const ADD_VERBS = ['ajoute', 'ajouter', 'mets', 'mettre', 'rajoute', 'rajouter', 'note'];

function addItemSamples() {
  const samples = BARE_FORMS ? ['{item}'] : [];
  // Les réponses brèves à « Que veux-tu ajouter ? ». Jamais « un » ni « une » comme simple mot de liaison : ce sont des nombres, et en en faisant de
  // la décoration on apprend à Amazon que « deux » et « trois » en sont aussi (essai réel du 2026-10-25 : le créneau `quantity` restait vide).
  // « un pain » se lit par le créneau `quantity` (1), ce qui revient au même pour le webhook.
  samples.push('du {item}', 'de la {item}', 'des {item}');
  for (const verb of ADD_VERBS) {
    for (const article of ['', 'du', 'de la', 'des']) {
      const phrase = [verb, article, '{item}'].filter(Boolean).join(' ');
      samples.push(phrase, `${phrase} à la liste`);
    }
  }

  // La quantité dite (« trois croquettes », décision 5 de l'ADR 0029, amendée le 2026-10-25), avec ou sans unité. Amazon veut une espace après
  // l'apostrophe (« d' {item} »). Le créneau `quantity` est du type `QUANTITY` (1 à 99, identifiant = le nombre). Sans verbe, la quantité ouvre la phrase.
  const quantified = ['{quantity} {item}', '{quantity} de {item}', '{quantity} {unit} de {item}', "{quantity} {unit} d' {item}"];
  for (const verb of ADD_VERBS) {
    for (const form of quantified) {
      const phrase = `${verb} ${form}`;
      samples.push(phrase, `${phrase} à la liste`);
    }
  }
  samples.push('{quantity} {item}', '{quantity} {unit} de {item}', "{quantity} {unit} d' {item}");

  return samples;
}

// Retirer de la liste (décision 12 de l'ADR 0029) : les mêmes formes que l'ajout (un produit, avec ou sans article, avec ou sans quantité et unité),
// avec les verbes de la demande du propriétaire. Jamais « un » ni « une » comme mot de liaison (ce sont des nombres, voir `addItemSamples`).
const REMOVE_VERBS = ['enlève', 'enlever', 'retire', 'retirer', 'déduis', 'déduire', 'supprime', 'supprimer', 'ôte', 'ôter'];
const REMOVE_ENDINGS = ['', 'de la liste', 'de ma liste'];

function removeItemSamples() {
  const forms = [
    ...['', 'du', 'de la', 'des'].map((article) => [article, '{item}'].filter(Boolean).join(' ')),
    '{quantity} {item}',
    '{quantity} de {item}',
    '{quantity} {unit} de {item}',
    "{quantity} {unit} d' {item}",
  ];
  const samples = [];
  for (const verb of REMOVE_VERBS) {
    for (const form of forms) {
      for (const ending of REMOVE_ENDINGS) {
        samples.push([verb, form, ending].filter(Boolean).join(' '));
      }
    }
  }

  return samples;
}

// Demander ce que la liste contient (décision 12) : « est-ce qu'il y a des croquettes dans la liste ? », « combien de croquettes dans la liste ».
// Écrit sans trait d'union (aucun exemple du modèle n'en a) ; seul le créneau `item`, la réponse dit combien il y en a.
const CHECK_STARTERS = ["est ce qu'il y a", "est ce qu'il y a encore", 'y a t il', 'il y a', "est ce que j'ai", "j'ai", 'ai je'];
const CHECK_ENDINGS = ['', 'dans la liste', 'dans ma liste', 'sur la liste', 'sur ma liste'];
const CHECK_COUNTS = ['combien de {item}', "combien d' {item}", "j'ai combien de {item}", "j'ai combien d' {item}", 'il y a combien de {item}', "il y a combien d' {item}"];

function checkItemSamples() {
  const samples = [];
  for (const starter of CHECK_STARTERS) {
    for (const article of ['', 'du', 'de la', 'des']) {
      for (const ending of CHECK_ENDINGS) {
        samples.push([starter, article, '{item}', ending].filter(Boolean).join(' '));
      }
    }
  }
  for (const count of CHECK_COUNTS) {
    for (const ending of ['', 'dans la liste', 'dans ma liste', 'sur la liste']) {
      samples.push([count, ending].filter(Boolean).join(' '));
    }
  }

  return samples;
}

// Planifier un repas (VOX-305, décision 13 de l'ADR 0029) : le MOT « repas » ou « recette », ou un verbe de planification, annonce une recette ; sans cela,
// « ajoute … » est un produit (AddItemIntent). Le nombre de personnes se dit en fin de phrase (« pour {servings} personnes »). Après un VERBE DE PLANIFICATION,
// l'article se dit (« planifie une fondue », essai réel du 2026-10-26 : sans lui Amazon ne comprenait pas) : « un » et « une » y sont des articles, sans risque
// de confusion avec une quantité puisque le seul nombre de la phrase vient après « pour » ; partout ailleurs ils restent bannis (voir `addItemSamples`). Le
// webhook retire l'article et le mot « repas » / « recette » du nom (`SpokenMealPhrase::parse`, `SpokenIngredientNamer`), reconnaît aussi « le repas » /
// « la recette » quand Amazon range la phrase dans AddItemIntent, et lit « pour six personnes » en queue de phrase.
const PLAN_VERBS = ['planifie', 'planifier', 'prévois', 'prévoir', 'prépare', 'préparer', 'mets au menu', 'mettre au menu'];
const PLAN_MARKERS = ['', 'le repas', 'la recette'];
const PLAN_ARTICLES = ['un', 'une', 'des', 'le', 'la'];
const MEAL_ADD_MARKERS = ['le repas', 'la recette', 'le repas de', 'la recette de'];
const MEAL_FORMS = ['{recipe}', '{recipe} pour {servings} personnes', '{recipe} pour {servings}'];

function addMealSamples() {
  const samples = [];
  for (const verb of PLAN_VERBS) {
    for (const marker of PLAN_MARKERS) {
      for (const form of MEAL_FORMS) {
        samples.push([verb, marker, form].filter(Boolean).join(' '));
      }
    }
    for (const article of PLAN_ARTICLES) {
      for (const form of MEAL_FORMS) {
        samples.push([verb, article, form].join(' '));
      }
    }
  }
  for (const verb of ADD_VERBS) {
    for (const marker of MEAL_ADD_MARKERS) {
      for (const form of MEAL_FORMS) {
        samples.push([verb, marker, form].join(' '));
      }
    }
  }

  return samples;
}

// Retirer un repas (VOX-306, décision 12 de l'ADR 0029) : toujours avec le MOT « repas » ou « recette » (sans lui, « enlève … » est un produit, RemoveItemIntent) ;
// les verbes de RemoveItemIntent, le nombre de personnes ENLEVÉ en fin de phrase (« pour {servings} personnes »). Le webhook reconnaît aussi « le repas » /
// « la recette » quand Amazon range la phrase dans RemoveItemIntent.
function removeMealSamples() {
  const samples = [];
  for (const verb of REMOVE_VERBS) {
    for (const marker of MEAL_ADD_MARKERS) {
      for (const form of MEAL_FORMS) {
        samples.push([verb, marker, form].join(' '));
      }
    }
  }

  return samples;
}

function withBare(samples, bare) {
  return BARE_FORMS ? [bare, ...samples] : samples;
}

/** Les façons de demander « lesquels ? » à voix haute, par famille. Amazon généralise un peu à partir des exemples, pas à l'infini. */
const LIST_CHOICES_SAMPLES = [
  // Lequel, laquelle, lesquels, lesquelles
  'lequel', 'laquelle', 'lesquels', 'lesquelles', 'lesquels sont possibles', 'lesquelles sont possibles',
  // La liste
  'la liste', 'liste', 'la liste des rayons', 'la liste des unités', 'liste les rayons', 'liste les unités', 'liste moi les rayons', 'liste moi les unités',
  // Rappelle-moi
  'rappelle les rayons', 'rappelle les unités', 'rappelle moi les rayons', 'rappelle moi les unités', 'rappelle moi les rayons qui existent',
  'rappelle moi les unités qui existent',
  // Quels sont
  'quels rayons', 'quelles unités', 'quels rayons existent', 'quelles unités existent', 'quels sont les rayons', 'quelles sont les unités',
  'quels sont les rayons possibles', 'quelles sont les unités possibles', 'quels sont les choix', 'quelles sont les options',
  // Dis-moi, donne-moi
  'dis moi les rayons', 'dis moi les unités', 'dis moi les choix', 'donne moi les rayons', 'donne moi les unités', 'donne moi les choix',
  'donne moi la liste des rayons', 'donne moi la liste des unités',
  // C'est quoi, qu'est-ce qu'il y a
  "c'est quoi les rayons", "c'est quoi les unités", "qu' est ce qu' il y a comme rayons", "qu' est ce qu' il y a comme unités",
  // Je ne sais pas
  'je ne sais pas', 'je ne sais plus', 'je ne me souviens plus', 'je ne connais pas les rayons', 'je ne connais pas les unités',
  // Énumère, répète
  'énumère', 'énumère les rayons', 'énumère les unités', 'énumère moi les choix', 'répète les choix', 'redis moi les choix', 'les choix possibles',
  'les choix', 'les options',
];

function intents() {
  return [
    { name: 'AMAZON.CancelIntent', samples: [] },
    { name: 'AMAZON.HelpIntent', samples: [] },
    { name: 'AMAZON.StopIntent', samples: [] },
    { name: 'AMAZON.FallbackIntent', samples: [] },
    { name: 'AMAZON.YesIntent', samples: [] },
    { name: 'AMAZON.NoIntent', samples: [] },
    {
      name: 'AddItemIntent',
      slots: [{ name: 'item', type: 'INGREDIENT' }, { name: 'quantity', type: 'QUANTITY' }, { name: 'unit', type: 'UNIT' }],
      samples: addItemSamples(),
    },
    {
      name: 'RemoveItemIntent',
      slots: [{ name: 'item', type: 'INGREDIENT' }, { name: 'quantity', type: 'QUANTITY' }, { name: 'unit', type: 'UNIT' }],
      samples: removeItemSamples(),
    },
    {
      name: 'CheckItemIntent',
      slots: [{ name: 'item', type: 'INGREDIENT' }],
      samples: checkItemSamples(),
    },
    {
      name: 'AddMealIntent',
      slots: [{ name: 'recipe', type: 'RECIPE' }, { name: 'servings', type: 'QUANTITY' }],
      samples: addMealSamples(),
    },
    {
      name: 'RemoveMealIntent',
      slots: [{ name: 'recipe', type: 'RECIPE' }, { name: 'servings', type: 'QUANTITY' }],
      samples: removeMealSamples(),
    },
    {
      name: 'ChooseOptionIntent',
      slots: [{ name: 'rank', type: 'OPTION_RANK' }],
      samples: withBare(
        ['le {rank}', 'la {rank}', 'je veux le {rank}', 'je veux la {rank}', 'prends le {rank}', 'prends la {rank}', 'choisis le {rank}', 'ce sera le {rank}', 'numéro {rank}'],
        '{rank}',
      ),
    },
    {
      name: 'CreateItemIntent',
      samples: [
        'nouveau', 'nouveau produit', 'un nouveau produit', 'un nouveau', 'aucun', 'aucune', 'aucun des trois', 'crée le',
        'crée un nouveau produit', "ni l'un ni l'autre",
      ],
    },
    {
      // « Lesquels ? » aux questions « Dans quel rayon ? » et « Quelle unité ? » : sans créneau, le back lit la liste d'après l'état de la séance
      // (rayon ou unité : tous les choix ; ailleurs : la question répétée). Aucun chiffre ni ponctuation sauf l'apostrophe (suivie d'une espace
      // pour « qu' » ; dans un mot, « c'est »). Chaque phrase est unique dans tout le modèle (`DocsVoiceSkillTest`).
      name: 'ListChoicesIntent',
      samples: LIST_CHOICES_SAMPLES,
    },
    {
      name: 'AnswerDepartmentIntent',
      slots: [{ name: 'department', type: 'DEPARTMENT' }],
      samples: withBare(['rayon {department}', 'le rayon {department}', 'dans le rayon {department}', 'au rayon {department}', 'dans {department}'], '{department}'),
    },
    {
      name: 'AnswerUnitIntent',
      slots: [{ name: 'unit', type: 'UNIT' }],
      samples: withBare(['en {unit}', 'au {unit}', 'des {unit}', 'un {unit}', 'une {unit}', 'à la {unit}'], '{unit}'),
    },
    {
      // « Une recette » à la question d'un produit inconnu, « un produit » à celle d'une recette inconnue (aiguillage, VOX-305). Sans créneau compris, le
      // webhook lit le texte entendu : l'état de la séance décide, pas l'intention.
      name: 'ChooseKindIntent',
      slots: [{ name: 'kind', type: 'KIND' }],
      samples: withBare(['une {kind}', 'la {kind}', "c'est une {kind}", "c'est la {kind}", 'plutôt une {kind}', "c'est plutôt une {kind}", 'je veux dire une {kind}'], '{kind}'),
    },
    {
      // Le NOUVEAU TOTAL de personnes d'un repas déjà planifié (« six », « pour six personnes »)
      name: 'AnswerServingsIntent',
      slots: [{ name: 'servings', type: 'QUANTITY' }],
      samples: withBare(['{servings} personnes', 'pour {servings}', 'pour {servings} personnes', 'je le veux pour {servings}', 'je le veux pour {servings} personnes', 'ce sera pour {servings}', 'ce sera pour {servings} personnes'], '{servings}'),
    },
    {
      // « Rien » : le repas déjà planifié reste tel quel
      name: 'KeepMealIntent',
      samples: ['rien', 'ne change rien', 'ne rien changer', 'on ne change rien', 'laisse comme ça', 'laisse tel quel', 'on laisse comme ça', 'pareil', 'tel quel'],
    },
  ];
}

function model() {
  return {
    interactionModel: {
      languageModel: {
        invocationName: INVOCATION_NAME,
        intents: intents(),
        types: [
          { name: 'INGREDIENT', values: ingredientValues() },
          { name: 'DEPARTMENT', values: catalogueValues('department.json', DEPARTMENT_SYNONYMS, 'Rayons') },
          { name: 'UNIT', values: catalogueValues('unit.json', UNIT_SYNONYMS, 'Unités') },
          { name: 'QUANTITY', values: quantityValues() },
          { name: 'RECIPE', values: recipeValues() },
          { name: 'KIND', values: kindValues() },
          { name: 'OPTION_RANK', values: rankValues() },
        ],
      },
    },
  };
}

const generated = JSON.stringify(model(), null, 2) + '\n';

if (process.argv.includes('--check')) {
  const current = readFileSync(modelPath, 'utf8');
  if (current !== generated) {
    console.error('docs/voice/skill/interaction-model.fr-FR.json n\'est pas celui que generate-model.mjs produit : relance le script.');
    process.exit(1);
  }
  console.log('Le modèle d\'interaction est à jour.');
} else {
  writeFileSync(modelPath, generated);
  console.log(`Modèle écrit : ${modelPath}`);
}
