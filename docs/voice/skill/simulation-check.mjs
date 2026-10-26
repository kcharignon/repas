#!/usr/bin/env node
// Rejoue des DIALOGUES de bout en bout contre le skill Jarvis réel, EN LECTURE SEULE (VOX-308, ADR 0029). Node pur, aucune dépendance npm.
//
//   ALEXA_SKILL_ID=amzn1.ask.skill.… node docs/voice/skill/simulation-check.mjs              rejoue tous les scénarios
//   node docs/voice/skill/simulation-check.mjs --skill-id amzn1.ask.skill.… --only lancement   un ou plusieurs scénarios (identifiants séparés par des virgules)
//   node docs/voice/skill/simulation-check.mjs --dry-run                                        liste les commandes sans rien lancer
//   node docs/voice/skill/simulation-check.mjs --json                                           écrit le détail en JSON, SANS jeton (aucun rapport)
//
// Pour chaque tour de `simulation-scenarios.fr-FR.json`, le script lance la CLI officielle d'Amazon : `ask smapi simulate-skill` (Amazon simule ce que dit le client
// et appelle NOTRE VRAI WEBHOOK avec le compte lié du propriétaire), puis `ask smapi get-skill-simulation` (le résultat : ce que notre serveur a reçu et ce qu'il a
// répondu). Une seule demande à la fois (Amazon ne gère pas les demandes concurrentes). Le script ne fait qu'exécuter `ask` : il ne lit JAMAIS les fichiers de
// connexion (`~/.ask`), et l'identifiant du skill n'est écrit dans aucun fichier (`ALEXA_SKILL_ID` ou `--skill-id`).
//
// CE REJEU APPELLE LE VRAI SERVEUR : il ne joue que des scénarios qui n'écrivent RIEN dans la liste du propriétaire, et il le garantit par quatre gardes :
//   (a) un scénario dont `writes` n'est pas exactement `false` est REFUSÉ (sauf `--allow-writes`, réservé à un compte de test) ;
//   (b) AVANT chaque tour (hors lancement) le profileur d'énoncés (`ask smapi profile-nlu`, lecture : n'appelle ni notre serveur ni le skill) dit quelle intention Amazon
//       retient pour la phrase ; si ce n'est pas une intention de lecture seule (`READ_ONLY_INTENTS`), le tour est BLOQUÉ et aucune simulation n'est lancée ;
//   (c) APRÈS chaque simulation, l'intention que NOTRE serveur a réellement reçue doit être de lecture seule : sinon ARRÊT IMMÉDIAT (code 3) en le disant très fort ;
//   (d) APRÈS chaque simulation, notre réponse ne doit porter aucun état de dialogue (une question ouverte pourrait faire écrire le tour suivant) : sinon ARRÊT IMMÉDIAT.
// Le jeton d'accès que porte la requête reçue par notre serveur n'est JAMAIS affiché, journalisé ni écrit : le script ne garde de la requête que son type, son
// intention, les valeurs de ses créneaux et `session.new`, et masque dans toute sortie les valeurs secrètes qu'il a croisées.
//
// Environnement : ASK_BIN (la commande `ask`, défaut `ask`), ALEXA_SKILL_ID. Options : --stage (development), --locale (fr-FR), --scenarios <fichier>, --pause <ms>
// (attente entre deux demandes, 1500 par défaut), --allow-writes. Sortie : 0 tout va bien, 1 au moins un écart, 2 le script ne peut pas travailler (pas de CLI, pas
// connecté, pas d'identifiant, scénario qui écrit, aucun scénario choisi), 3 ARRÊT DE SÉCURITÉ (le serveur a reçu une intention qui n'est pas de lecture seule, ou un
// dialogue s'est ouvert). Jamais de boucle sans fin : 4 reprises au plus par commande (attente qui double, seulement sur « trop de demandes » et les pannes d'Amazon),
// 20 relectures au plus d'un résultat de simulation.

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { extractJson, fatalReason, isRetryable, normalize, run, sleep } from './nlu-check.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const MAX_RETRIES = 4;
const BACKOFF_MS = Number(process.env.SIM_CHECK_BACKOFF_MS ?? 2000);
const POLL_MS = Number(process.env.SIM_CHECK_POLL_MS ?? 1500);
const MAX_POLLS = 20;

/**
 * Les intentions qui n'écrivent RIEN, avec pourquoi (la liste est vérifiée par `tests/Voice/DocsVoiceSimulationScenariosTest.php` : un scénario ne cite que celles-ci) :
 * - CheckItemIntent : une lecture (`GetSpokenItemInList`) ;
 * - AMAZON.HelpIntent, AMAZON.FallbackIntent : une phrase fixe (`AlexaDialogue`) ;
 * - AMAZON.StopIntent, AMAZON.CancelIntent : « À bientôt. », même au milieu d'un dialogue ;
 * - AMAZON.YesIntent, AMAZON.NoIntent : sans question en cours (aucun de ces scénarios n'en ouvre : la garde (d) l'impose), le webhook invite à ajouter ou dit au revoir.
 * Le lancement n'est pas une intention : une requête `LaunchRequest` répond la bienvenue.
 */
export const READ_ONLY_INTENTS = new Set([
  'CheckItemIntent',
  'AMAZON.HelpIntent',
  'AMAZON.FallbackIntent',
  'AMAZON.StopIntent',
  'AMAZON.CancelIntent',
  'AMAZON.YesIntent',
  'AMAZON.NoIntent',
]);
const LAUNCH = 'LaunchRequest';
const SESSION_ENDED = 'SessionEndedRequest';

/** Les valeurs secrètes croisées (jeton d'accès, identifiants) : masquées dans TOUTE sortie. */
const secrets = new Set();

function scrub(text) {
  let out = String(text);
  for (const secret of secrets) out = out.split(secret).join('[masqué]');

  return out;
}

const say = (stream, text) => stream.write(`${scrub(text)}\n`);

function parseArgs(argv) {
  const args = { stage: 'development', locale: 'fr-FR', pause: 1500, only: null, json: false, dryRun: false, allowWrites: false, scenarios: join(here, 'simulation-scenarios.fr-FR.json'), skillId: process.env.ALEXA_SKILL_ID ?? '' };
  const valueOf = (i, name) => {
    if (i + 1 >= argv.length) throw new Error(`${name} demande une valeur`);
    return argv[i + 1];
  };
  for (let i = 0; i < argv.length; i++) {
    switch (argv[i]) {
      case '--skill-id': args.skillId = valueOf(i++, '--skill-id'); break;
      case '--stage': args.stage = valueOf(i++, '--stage'); break;
      case '--locale': args.locale = valueOf(i++, '--locale'); break;
      case '--scenarios': args.scenarios = valueOf(i++, '--scenarios'); break;
      case '--pause': args.pause = Number(valueOf(i++, '--pause')); break;
      case '--only': args.only = valueOf(i++, '--only').split(',').map((s) => s.trim()).filter(Boolean); break;
      case '--json': args.json = true; break;
      case '--dry-run': args.dryRun = true; break;
      case '--allow-writes': args.allowWrites = true; break;
      default: throw new Error(`option inconnue : ${argv[i]}`);
    }
  }
  if (!Number.isFinite(args.pause) || args.pause < 0) throw new Error('--pause demande un nombre de millisecondes');

  return args;
}

/** Les commandes, sans interpréteur de commandes (aucune injection possible par une phrase). */
const profileCommand = (askBin, args, heard) => [askBin, ['smapi', 'profile-nlu', '-u', heard, '-s', args.skillId || '<ALEXA_SKILL_ID>', '-g', args.stage, '-l', args.locale]];
const simulateCommand = (askBin, args, turn) => [askBin, ['smapi', 'simulate-skill', '-s', args.skillId || '<ALEXA_SKILL_ID>', '-g', args.stage, '--input-content', turn.say, '--device-locale', args.locale, '--session-mode', turn.sessionMode]];
const resultCommand = (askBin, args, id) => [askBin, ['smapi', 'get-skill-simulation', '-s', args.skillId || '<ALEXA_SKILL_ID>', '-g', args.stage, '-i', id]];

/** Une commande de la CLI, avec reprises bornées. Rend { json } ou { problem } ou { fatal } ou { gone } (404 : le résultat n'existe plus). */
async function ask(bin, binArgs, { missingIsGone = false } = {}) {
  for (let attempt = 0; ; attempt++) {
    const { error, stdout, stderr } = await run(bin, binArgs);
    if (error?.code === 'ENOENT') return { fatal: `La commande « ${bin} » est introuvable. Installe la CLI d'Amazon (voir docs/voice/skill/README.md) ou donne son chemin dans ASK_BIN.` };
    const text = `${stdout}\n${stderr}`;
    if (!error) {
      const json = extractJson(stdout);

      return json === null ? { problem: 'sortie illisible (la CLI n\'a pas rendu de JSON)' } : { json };
    }
    if (missingIsGone && /\b404\b|not found|does not exist/i.test(text)) return { gone: true };
    const fatal = fatalReason(text);
    if (fatal !== null) return { fatal };
    if (isRetryable(text) && attempt < MAX_RETRIES) {
      await sleep(BACKOFF_MS * 2 ** attempt);
      continue;
    }
    const detail = text.trim().split('\n').filter(Boolean).slice(-1)[0] ?? 'aucun message';

    return { problem: `la CLI a échoué : ${detail.slice(0, 160)}` };
  }
}

/**
 * Ce qu'on garde d'un résultat de simulation : JAMAIS la requête entière (elle porte le jeton d'accès), seulement son type, son intention, les valeurs de ses créneaux
 * et `session.new`, plus la réponse de notre serveur. Les valeurs secrètes croisées en chemin sont notées pour être masquées.
 */
export function sanitize(result) {
  const invocations = Array.isArray(result?.skillExecutionInfo?.invocations) ? result.skillExecutionInfo.invocations : [];
  const keep = (value) => {
    if (typeof value === 'string' && value.length >= 6) secrets.add(value);
  };
  const requests = [];
  let main = null;
  for (const invocation of invocations) {
    const body = invocation?.invocationRequest?.body ?? {};
    keep(body.session?.user?.accessToken);
    keep(body.context?.System?.user?.accessToken);
    keep(body.context?.System?.apiAccessToken);
    keep(body.session?.user?.userId);
    keep(body.context?.System?.user?.userId);
    keep(body.context?.System?.device?.deviceId);
    const request = body.request ?? {};
    const entry = {
      type: typeof request.type === 'string' ? request.type : null,
      intent: typeof request.intent?.name === 'string' ? request.intent.name : null,
      slots: Object.fromEntries(Object.entries(request.intent?.slots ?? {}).map(([name, slot]) => [name, slot?.value ?? null])),
      sessionNew: typeof body.session?.new === 'boolean' ? body.session.new : null,
    };
    requests.push(entry);
    if (main === null && entry.type !== SESSION_ENDED) {
      const response = invocation?.invocationResponse?.body?.response ?? {};
      const attributes = invocation?.invocationResponse?.body?.sessionAttributes;
      main = {
        request: entry,
        reply: typeof response.outputSpeech?.text === 'string' ? response.outputSpeech.text : null,
        sessionEnds: typeof response.shouldEndSession === 'boolean' ? response.shouldEndSession : null,
        hasReprompt: response.reprompt?.outputSpeech !== undefined && response.reprompt?.outputSpeech !== null,
        dialogueOpen: attributes?.dialogue !== undefined && attributes.dialogue !== null && Object.keys(attributes.dialogue).length > 0,
        ms: invocation?.metrics?.skillExecutionTimeInMilliseconds ?? null,
      };
    }
  }
  const caption = result?.alexaExecutionInfo?.alexaResponses?.find((r) => r?.type === 'Speech')?.content?.caption;

  return { requests, main, caption: typeof caption === 'string' ? caption : null };
}

/** Une intention reçue par notre serveur qui n'est pas de lecture seule ? Rend le texte du danger, ou `null`. */
function danger(seen) {
  for (const request of seen.requests) {
    if (request.type === LAUNCH || request.type === SESSION_ENDED) continue;
    if (request.type === 'IntentRequest' && request.intent !== null && READ_ONLY_INTENTS.has(request.intent)) continue;

    return `NOTRE SERVEUR A REÇU « ${request.type ?? '?'}${request.intent !== null ? ` / ${request.intent}` : ''} », qui n'est pas de lecture seule : il a pu ÉCRIRE dans la liste du propriétaire. Vérifie la liste.`;
  }
  if (seen.main?.dialogueOpen === true) {
    return 'NOTRE SERVEUR A OUVERT UN DIALOGUE (une question est en attente) : un tour suivant pourrait écrire. Aucun autre tour n\'est lancé.';
  }

  return null;
}

/** Une expression régulière mal écrite ne fait pas planter le rejeu : elle ne reconnaît rien. */
function matches(pattern, text) {
  try {
    return new RegExp(pattern, 'u').test(text);
  } catch {
    return false;
  }
}

/** Les écarts entre le tour attendu et ce qui s'est passé. */
export function compareTurn(turn, seen) {
  const problems = [];
  const main = seen.main;
  if (main === null) {
    return [`Amazon n'a pas appelé le skill${seen.caption !== null ? ` (Alexa a dit : « ${seen.caption} »)` : ''}`];
  }
  const request = main.request;
  if (turn.intent === LAUNCH) {
    if (request.type !== LAUNCH) problems.push(`requête : attendu ${LAUNCH}, obtenu ${request.type}${request.intent !== null ? ` / ${request.intent}` : ''}`);
  } else if (request.type !== 'IntentRequest' || request.intent !== turn.intent) {
    problems.push(`intention reçue par notre serveur : attendu ${turn.intent}, obtenu ${request.intent ?? request.type}`);
  }
  for (const [name, value] of Object.entries(turn.slots ?? {})) {
    const actual = request.slots[name] ?? null;
    if (value === null) {
      if (actual !== null && actual !== '') problems.push(`créneau ${name} : attendu vide, obtenu « ${actual} »`);
    } else if (actual === null || normalize(actual) !== normalize(value)) {
      problems.push(`créneau ${name} : attendu « ${value} », obtenu ${actual === null ? 'vide' : `« ${actual} »`}`);
    }
  }
  if (turn.replyMatches !== undefined) {
    if (main.reply === null) problems.push('réponse : notre serveur n\'a rendu aucune phrase');
    else if (!matches(turn.replyMatches, main.reply)) problems.push(`réponse : attendu /${turn.replyMatches}/, obtenu « ${main.reply} »`);
  }
  if (turn.sessionEnds !== undefined && main.sessionEnds !== turn.sessionEnds) {
    problems.push(`séance : attendu ${turn.sessionEnds ? 'fermée' : 'ouverte'}, obtenu ${main.sessionEnds === null ? 'inconnu' : main.sessionEnds ? 'fermée' : 'ouverte'}`);
  }
  if (turn.reprompt !== undefined && main.hasReprompt !== turn.reprompt) {
    problems.push(`relance : attendu ${turn.reprompt ? 'une relance' : 'aucune relance'}, obtenu ${main.hasReprompt ? 'une relance' : 'aucune relance'}`);
  }
  if (turn.sessionNew !== undefined && request.sessionNew !== turn.sessionNew) {
    problems.push(`séance : attendu ${turn.sessionNew ? 'une nouvelle séance' : 'la séance déjà ouverte'}, obtenu ${request.sessionNew === null ? 'inconnu' : request.sessionNew ? 'une nouvelle séance' : 'la séance déjà ouverte'}`);
  }

  return problems;
}

/** Le résultat d'une simulation, relu jusqu'à ce qu'elle soit finie. Rend { result } ou { problem } ou { fatal }. */
async function simulate(askBin, args, turn) {
  const [bin, binArgs] = simulateCommand(askBin, args, turn);
  const started = await ask(bin, binArgs);
  if (started.fatal !== undefined) return started;
  if (started.problem !== undefined) return { problem: started.problem };
  const id = started.json?.id;
  if (typeof id !== 'string' || id === '') return { problem: 'la simulation n\'a pas rendu d\'identifiant' };

  for (let poll = 0; poll < MAX_POLLS; poll++) {
    await sleep(POLL_MS);
    const [rbin, rargs] = resultCommand(askBin, args, id);
    const got = await ask(rbin, rargs, { missingIsGone: true });
    if (got.fatal !== undefined) return got;
    if (got.gone === true) return { problem: 'le résultat de la simulation n\'existe plus (Amazon les garde dix minutes) ou est introuvable' };
    if (got.problem !== undefined) return { problem: got.problem };
    const status = got.json?.status;
    if (status === 'SUCCESSFUL') return { result: got.json.result ?? {} };
    if (status === 'FAILED') return { problem: `Amazon a échoué à simuler cette phrase${got.json?.result?.error?.message ? ` : ${String(got.json.result.error.message).slice(0, 120)}` : ''}` };
  }

  return { problem: `la simulation n'a pas fini après ${MAX_POLLS} relectures` };
}

/** Un scénario, tour après tour. Rend { id, status, turns, problems } ; `safety` ou `fatal` interrompent tout le rejeu. */
async function playScenario(askBin, args, scenario, state) {
  const outcome = { id: scenario.id, status: 'ok', problems: [], turns: [] };
  for (const [index, turn] of scenario.turns.entries()) {
    if (state.requests > 0 && args.pause > 0) await sleep(args.pause);
    const where = `tour ${index + 1} (« ${turn.say} »)`;
    const record = { say: turn.say, heard: turn.heard ?? null, sessionMode: turn.sessionMode };
    outcome.turns.push(record);

    // (b) le profileur d'énoncés, hors lancement : l'intention qu'Amazon retient pour ce qui est dit doit être de lecture seule
    if (turn.launch !== true) {
      const [pbin, pargs] = profileCommand(askBin, args, turn.heard);
      state.requests++;
      const profiled = await ask(pbin, pargs);
      if (profiled.fatal !== undefined) return { fatal: profiled.fatal };
      if (profiled.problem !== undefined) {
        outcome.status = 'error';
        outcome.problems.push(`${where} : profileur : ${profiled.problem}`);
        break;
      }
      const intent = profiled.json?.selectedIntent?.name ?? null;
      record.profiledIntent = intent;
      if (intent === null || !READ_ONLY_INTENTS.has(intent)) {
        outcome.status = 'blocked';
        outcome.problems.push(`${where} : bloqué : Amazon l'a rangé dans ${intent ?? 'aucune intention'}, aucune simulation lancée`);
        break;
      }
      await sleep(args.pause);
    }

    state.requests++;
    const simulated = await simulate(askBin, args, turn);
    if (simulated.fatal !== undefined) return { fatal: simulated.fatal };
    if (simulated.problem !== undefined) {
      outcome.status = 'error';
      outcome.problems.push(`${where} : ${simulated.problem}`);
      break;
    }
    const seen = sanitize(simulated.result);
    record.request = seen.main?.request ?? null;
    record.reply = seen.main?.reply ?? null;
    record.sessionEnds = seen.main?.sessionEnds ?? null;
    record.reprompt = seen.main?.hasReprompt ?? null;
    record.ms = seen.main?.ms ?? null;

    // (c) et (d) : arrêt immédiat
    const warning = danger(seen);
    if (warning !== null) return { safety: `${where} : ${warning}`, outcome };

    const problems = compareTurn(turn, seen);
    if (problems.length > 0) {
      outcome.status = 'fail';
      for (const problem of problems) outcome.problems.push(`${where} : ${problem}`);
      break;
    }
  }

  return outcome;
}

function report(outcomes, total) {
  const ok = outcomes.filter((o) => o.status === 'ok').length;
  const bad = outcomes.filter((o) => o.status !== 'ok');
  const lines = [`${ok} / ${total} scénarios OK${bad.length > 0 ? `, ${bad.length} avec un écart` : ''}`];
  if (bad.length > 0) {
    lines.push('');
    for (const outcome of bad) {
      lines.push(`  ${outcome.id} (${outcome.status === 'blocked' ? 'bloqué' : outcome.status === 'error' ? 'erreur' : 'écart'})`);
      for (const problem of outcome.problems) lines.push(`    - ${problem}`);
    }
  }

  return lines.join('\n');
}

async function main() {
  let args;
  try {
    args = parseArgs(process.argv.slice(2));
  } catch (error) {
    console.error(`Erreur : ${error.message}`);

    return 2;
  }
  const askBin = process.env.ASK_BIN || 'ask';
  let scenarios;
  try {
    scenarios = JSON.parse(readFileSync(args.scenarios, 'utf8')).scenarios;
    if (!Array.isArray(scenarios)) throw new Error('clé « scenarios » absente');
  } catch (error) {
    console.error(`Erreur : le fichier des scénarios est illisible (${error.message}).`);

    return 2;
  }
  const chosen = args.only === null ? scenarios : scenarios.filter((s) => args.only.includes(s.id));
  if (chosen.length === 0) {
    console.error('Aucun scénario ne correspond à --only.');

    return 2;
  }

  // (a) un scénario qui écrit est refusé avant toute commande
  const writers = chosen.filter((s) => s.writes !== false);
  if (writers.length > 0 && !args.allowWrites) {
    console.error(`Refusé : ${writers.map((s) => s.id).join(', ')} ${writers.length > 1 ? 'ne sont pas déclarés' : 'n\'est pas déclaré'} « writes: false ». Ce rejeu appelle le vrai serveur du propriétaire : il ne joue que des scénarios qui n'écrivent rien (--allow-writes est réservé à un compte de test).`);

    return 2;
  }

  if (args.dryRun) {
    const show = ([bin, binArgs]) => [bin, ...binArgs.map((a) => (/^[\w.:<>@/-]+$/.test(a) ? a : JSON.stringify(a)))].join(' ');
    for (const scenario of chosen) {
      for (const turn of scenario.turns) {
        if (turn.launch !== true) console.log(show(profileCommand(askBin, args, turn.heard)));
        console.log(show(simulateCommand(askBin, args, turn)));
        console.log(show(resultCommand(askBin, args, '<id de la simulation>')));
      }
    }
    console.error(`${chosen.length} scénario${chosen.length > 1 ? 's' : ''} (rien n'a été lancé).`);

    return 0;
  }
  if (args.skillId === '') {
    console.error('Erreur : donne l\'identifiant du skill, avec ALEXA_SKILL_ID=… ou --skill-id … (il n\'est écrit dans aucun fichier du dépôt).');

    return 2;
  }

  const state = { requests: 0 };
  const outcomes = [];
  for (const scenario of chosen) {
    const played = await playScenario(askBin, args, scenario, state);
    if (played.fatal !== undefined) {
      say(process.stderr, `Erreur : ${played.fatal}`);

      return 2;
    }
    if (played.safety !== undefined) {
      say(process.stderr, '');
      say(process.stderr, '########################################################################');
      say(process.stderr, `ARRÊT IMMÉDIAT (scénario ${scenario.id}) : ${played.safety}`);
      say(process.stderr, 'Aucun autre scénario n\'a été lancé.');
      say(process.stderr, '########################################################################');

      return 3;
    }
    outcomes.push(played);
    if (!args.json) process.stderr.write(played.status === 'ok' ? '.' : 'F');
  }
  if (!args.json) process.stderr.write('\n');

  if (args.json) {
    console.log(scrub(JSON.stringify(outcomes, null, 2)));
  } else {
    say(process.stdout, report(outcomes, chosen.length));
  }

  return outcomes.some((o) => o.status !== 'ok') ? 1 : 0;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  process.exitCode = await main();
}
