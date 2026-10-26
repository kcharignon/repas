// Envoie le modèle d'interaction du dépôt au skill Jarvis EN DÉVELOPPEMENT et attend la fin du build (VOX-309) : plus de collage dans la console.
//
//   ALEXA_SKILL_ID=amzn1.ask.skill.… node docs/voice/skill/push-model.mjs      (ou : make voice-model-push)
//
// Il ne lance que `ask smapi set-interaction-model` (le fichier du dépôt, étape `development`) puis `ask smapi get-skill-status` : jamais de publication, de
// certification ni de suppression. Le modèle envoyé est d'abord comparé à ce que `generate-model.mjs` produit (jamais un modèle périmé). Il ne lit pas les
// fichiers de jetons de la CLI (`~/.ask`) et l'identifiant du skill n'est écrit dans aucun fichier : il vient de l'environnement (`ALEXA_SKILL_ID`) ou de `--skill-id`.
//
// Environnement : ASK_BIN (la commande `ask`, défaut `ask`), ALEXA_SKILL_ID. Options : --skill-id, --locale (fr-FR), --dry-run. Sortie : 0 modèle envoyé et construit,
// 1 le build a échoué ou n'a pas fini, 2 le script ne peut pas travailler (modèle périmé, pas d'identifiant, CLI absente ou refusée).

import { execFile, execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const MODEL = join(here, 'interaction-model.fr-FR.json');
const MAX_POLLS = 40;
const POLL_MS = Number(process.env.PUSH_MODEL_POLL_MS ?? 6000);

function parseArgs(argv) {
  const args = { skillId: process.env.ALEXA_SKILL_ID ?? '', locale: 'fr-FR', dryRun: false };
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--skill-id') args.skillId = argv[++i] ?? '';
    else if (argv[i] === '--locale') args.locale = argv[++i] ?? args.locale;
    else if (argv[i] === '--dry-run') args.dryRun = true;
    else { console.error(`Option inconnue : ${argv[i]}`); process.exit(2); }
  }
  return args;
}

function ask(bin, args) {
  return new Promise((resolve) => {
    execFile(bin, args, { timeout: 120000, maxBuffer: 4 * 1024 * 1024, encoding: 'utf8' }, (error, stdout, stderr) => resolve({ error, stdout, stderr }));
  });
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const askBin = process.env.ASK_BIN || 'ask';

  try {
    execFileSync('node', [join(here, 'generate-model.mjs'), '--check'], { stdio: 'pipe' });
  } catch {
    console.error('Refusé : le modèle du dépôt n\'est pas celui que generate-model.mjs produit. Lance `make voice-model`, relis le diff et commite d\'abord.');
    return 2;
  }
  if (args.skillId === '') {
    console.error('Erreur : donne l\'identifiant du skill, avec ALEXA_SKILL_ID=… ou --skill-id … (il n\'est écrit dans aucun fichier du dépôt).');
    return 2;
  }

  const push = ['smapi', 'set-interaction-model', '-s', args.skillId, '-g', 'development', '-l', args.locale, '--interaction-model', `file:${MODEL}`];
  const status = ['smapi', 'get-skill-status', '-s', args.skillId, '--resource', 'interactionModel'];
  if (args.dryRun) {
    console.log([askBin, ...push].join(' '));
    console.log([askBin, ...status].join(' '));
    return 0;
  }

  const sent = await ask(askBin, push);
  if (sent.error) {
    console.error(`Erreur : l'envoi du modèle a échoué (${(sent.stderr || sent.stdout || sent.error.message).trim().split('\n')[0].slice(0, 200)}).`);
    return 2;
  }
  console.log('Modèle envoyé à Amazon, construction en cours…');

  for (let i = 0; i < MAX_POLLS; i++) {
    await sleep(POLL_MS);
    const polled = await ask(askBin, status);
    let state = null;
    try {
      state = JSON.parse(polled.stdout).interactionModel?.[args.locale]?.lastUpdateRequest?.status ?? null;
    } catch {
      state = null;
    }
    if (state === 'SUCCEEDED') {
      console.log('Build terminé : le modèle est en ligne sur le skill en développement.');
      return 0;
    }
    if (state === 'FAILED') {
      console.error('Le build du modèle a échoué : relis les erreurs dans la console Amazon (Build → Interaction Model).');
      return 1;
    }
  }
  console.error('Le build n\'a pas fini dans le temps imparti : regarde son état dans la console Amazon.');
  return 1;
}

process.exitCode = await main();
