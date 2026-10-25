#!/usr/bin/env node
// Génère docs/voice/skill/interaction-model.fr-FR.json (ADR 0029, VOX-205 et VOX-303). Node pur, aucune dépendance, aucun appel réseau.
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

const INVOCATION_NAME = 'mes courses';

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
};

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

function rankValues() {
  return RANKS.map((rank) => ({ id: rank.id, name: { value: rank.name, synonyms: rank.synonyms } }));
}

// ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
// Intentions
// ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

function addItemSamples() {
  const samples = BARE_FORMS ? ['{item}'] : [];
  // Les réponses brèves à « Que veux-tu ajouter ? »
  samples.push('du {item}', 'de la {item}', 'des {item}', 'un {item}', 'une {item}');
  for (const verb of ['ajoute', 'ajouter', 'mets', 'mettre', 'rajoute', 'rajouter', 'note']) {
    for (const article of ['', 'du', 'de la', 'des', 'un', 'une']) {
      const phrase = [verb, article, '{item}'].filter(Boolean).join(' ');
      samples.push(phrase, `${phrase} à la liste`);
    }
  }

  return samples;
}

function withBare(samples, bare) {
  return BARE_FORMS ? [bare, ...samples] : samples;
}

function intents() {
  return [
    { name: 'AMAZON.CancelIntent', samples: [] },
    { name: 'AMAZON.HelpIntent', samples: [] },
    { name: 'AMAZON.StopIntent', samples: [] },
    { name: 'AMAZON.FallbackIntent', samples: [] },
    { name: 'AMAZON.YesIntent', samples: [] },
    { name: 'AMAZON.NoIntent', samples: [] },
    { name: 'AddItemIntent', slots: [{ name: 'item', type: 'INGREDIENT' }], samples: addItemSamples() },
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
      name: 'AnswerDepartmentIntent',
      slots: [{ name: 'department', type: 'DEPARTMENT' }],
      samples: withBare(['rayon {department}', 'le rayon {department}', 'dans le rayon {department}', 'au rayon {department}', 'dans {department}'], '{department}'),
    },
    {
      name: 'AnswerUnitIntent',
      slots: [{ name: 'unit', type: 'UNIT' }],
      samples: withBare(['en {unit}', 'au {unit}', 'des {unit}', 'un {unit}', 'une {unit}', 'à la {unit}'], '{unit}'),
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
