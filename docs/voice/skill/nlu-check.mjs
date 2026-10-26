#!/usr/bin/env node
// Vérifie ce qu'Amazon COMPREND des phrases du skill Jarvis (VOX-307, ADR 0029). Node pur, aucune dépendance npm.
//
//   ALEXA_SKILL_ID=amzn1.ask.skill.… node docs/voice/skill/nlu-check.mjs                 rejoue tous les cas
//   node docs/voice/skill/nlu-check.mjs --skill-id amzn1.ask.skill.… --only AddMealIntent   une intention (ou un identifiant de cas, séparés par des virgules)
//   node docs/voice/skill/nlu-check.mjs --dry-run                                           liste les commandes sans rien lancer
//   node docs/voice/skill/nlu-check.mjs --json                                              écrit les résultats bruts en JSON (aucun rapport)
//
// Pour chaque cas de `nlu-cases.fr-FR.json`, le script lance la CLI officielle d'Amazon : `ask smapi profile-nlu -u <phrase> -s <skill> -g development -l fr-FR`
// (le « profileur d'énoncés » : Amazon dit quelle intention et quels créneaux il retient pour la phrase). Le profileur n'appelle NI notre serveur NI le skill,
// n'écrit RIEN chez Amazon et ne touche à aucune liste : c'est une lecture. Le script ne fait qu'exécuter `ask` ; il ne lit JAMAIS les fichiers de connexion
// (`~/.ask`) et l'identifiant du skill n'est écrit dans aucun fichier : il vient de l'environnement (`ALEXA_SKILL_ID`) ou de `--skill-id`.
//
// Environnement : ASK_BIN (la commande `ask`, défaut `ask`), ALEXA_SKILL_ID. Options : --stage (development), --locale (fr-FR), --cases <fichier>, --pause <ms>
// (attente entre deux demandes, 700 par défaut). Sortie : 0 tout va bien (les écarts connus, `knownGap`, sont tolérés), 1 au moins un échec, 2 le script ne
// peut pas travailler (pas de CLI, pas connecté, pas d'identifiant, aucun cas choisi). Le script ne boucle jamais sans fin : 4 reprises au plus par phrase, avec
// une attente qui double, seulement sur « trop de demandes » (429) et les pannes d'Amazon (5xx).
//
// Comparaison d'un créneau attendu (`slots` d'un cas ; les créneaux non listés sont libres) :
//   {"value":"croquettes"}   la valeur DITE (ce que l'utilisateur a prononcé, sans casse ni espaces en trop)
//   {"id":"2"}               l'identifiant RÉSOLU d'un type personnalisé (première résolution « ER_SUCCESS_MATCH »)
//   null                     le créneau doit rester vide
// `alsoIntents` : d'autres intentions que le webhook comprend aussi (il lit la phrase d'après l'état du dialogue) ; si Amazon en choisit une, le cas passe et seule
// l'intention est jugée. `knownGap: true` : échec connu et toléré, compté à part ; s'il réussit, le rapport le signale (retirer alors `knownGap`).

import { execFile } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const MAX_RETRIES = 4;
const BACKOFF_MS = Number(process.env.NLU_CHECK_BACKOFF_MS ?? 2000);
const CALL_TIMEOUT_MS = 60_000;

function parseArgs(argv) {
  const args = { stage: 'development', locale: 'fr-FR', pause: 700, only: null, json: false, dryRun: false, cases: join(here, 'nlu-cases.fr-FR.json'), skillId: process.env.ALEXA_SKILL_ID ?? '' };
  const valueOf = (i, name) => {
    if (i + 1 >= argv.length) throw new Error(`${name} demande une valeur`);
    return argv[i + 1];
  };
  for (let i = 0; i < argv.length; i++) {
    switch (argv[i]) {
      case '--skill-id': args.skillId = valueOf(i++, '--skill-id'); break;
      case '--stage': args.stage = valueOf(i++, '--stage'); break;
      case '--locale': args.locale = valueOf(i++, '--locale'); break;
      case '--cases': args.cases = valueOf(i++, '--cases'); break;
      case '--pause': args.pause = Number(valueOf(i++, '--pause')); break;
      case '--only': args.only = valueOf(i++, '--only').split(',').map((s) => s.trim()).filter(Boolean); break;
      case '--json': args.json = true; break;
      case '--dry-run': args.dryRun = true; break;
      default: throw new Error(`option inconnue : ${argv[i]}`);
    }
  }
  if (!Number.isFinite(args.pause) || args.pause < 0) throw new Error('--pause demande un nombre de millisecondes');

  return args;
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const normalize = (text) => String(text ?? '').normalize('NFC').toLowerCase().replace(/[’‘]/g, "'").replace(/\s+/g, ' ').trim();

/** La commande de profilage d'une phrase, sans interpréteur de commandes (aucune injection possible par une phrase). */
function profileCommand(askBin, args, utterance) {
  return [askBin, ['smapi', 'profile-nlu', '-u', utterance, '-s', args.skillId || '<ALEXA_SKILL_ID>', '-g', args.stage, '-l', args.locale]];
}

function run(bin, binArgs) {
  return new Promise((resolve) => {
    execFile(bin, binArgs, { timeout: CALL_TIMEOUT_MS, maxBuffer: 4 * 1024 * 1024, encoding: 'utf8' }, (error, stdout, stderr) => {
      resolve({ error, stdout: stdout ?? '', stderr: stderr ?? '' });
    });
  });
}

/** Un incident qui justifie d'attendre et de réessayer : « trop de demandes » ou une panne d'Amazon. */
function isRetryable(text) {
  return /\b429\b|too many requests|throttl|\b5\d\d\b|service unavailable|ETIMEDOUT|ECONNRESET|EAI_AGAIN/i.test(text);
}

/** Un incident qui rend tout le travail impossible : on s'arrête tout de suite plutôt que de compter 133 échecs. */
function fatalReason(text) {
  if (/\b401\b|\b403\b|unauthori[sz]ed|forbidden|no profile|not configured|ask configure|invalid_grant|expired/i.test(text)) {
    return 'Amazon refuse la connexion de la CLI. Relance `ask configure` (c\'est toi qui te connectes), puis recommence.';
  }
  if (/\b404\b|not found|does not exist/i.test(text) && /skill/i.test(text)) {
    return 'Amazon ne trouve pas ce skill : vérifie l\'identifiant du skill et le stage (--stage development).';
  }

  return null;
}

/** Le JSON du profileur dans la sortie de la CLI (elle peut écrire une ligne d'information avant) ; `null` si illisible. */
function extractJson(stdout) {
  const start = stdout.indexOf('{');
  const end = stdout.lastIndexOf('}');
  if (start === -1 || end <= start) return null;
  try {
    const parsed = JSON.parse(stdout.slice(start, end + 1));
    // Avec `--full-response` la CLI enveloppe la réponse : { body, headers, status }
    if (parsed && typeof parsed === 'object' && parsed.selectedIntent === undefined && parsed.body && typeof parsed.body === 'object') return parsed.body;

    return parsed;
  } catch {
    return null;
  }
}

/** L'identifiant résolu d'un créneau de type personnalisé : la première résolution réussie, sinon `null`. */
function resolvedId(slot) {
  for (const authority of slot?.resolutions?.resolutionsPerAuthority ?? []) {
    if (authority?.status?.code === 'ER_SUCCESS_MATCH' && authority.values?.[0]?.value?.id !== undefined) return String(authority.values[0].value.id);
    if (authority?.status?.code === 'ER_SUCCESS_MATCH' && authority.values?.[0]?.id !== undefined) return String(authority.values[0].id);
  }

  return null;
}

const describeSlot = (slot) => {
  if (!slot || slot.value === null || slot.value === undefined || slot.value === '') return 'vide';
  const id = resolvedId(slot);

  return id === null ? `« ${slot.value} » (aucun identifiant résolu)` : `« ${slot.value} » (id ${id})`;
};

function describeExpected(spec) {
  if (spec === null) return 'vide';
  if (spec.id !== undefined) return `id ${spec.id}`;

  return `« ${spec.value} »`;
}

/** Les écarts d'un créneau attendu ; liste vide = conforme. */
function compareSlot(name, spec, slot) {
  const empty = !slot || slot.value === null || slot.value === undefined || slot.value === '';
  if (spec === null) return empty ? [] : [`créneau ${name} : attendu vide, obtenu ${describeSlot(slot)}`];
  if (spec.id !== undefined) {
    const id = resolvedId(slot);

    return id === String(spec.id) ? [] : [`créneau ${name} : attendu id ${spec.id}, obtenu ${describeSlot(slot)}`];
  }

  return !empty && normalize(slot.value) === normalize(spec.value) ? [] : [`créneau ${name} : attendu « ${spec.value} », obtenu ${describeSlot(slot)}`];
}

/** Juge un cas d'après la réponse du profileur. */
export function judge(testCase, response) {
  const selected = response?.selectedIntent;
  const actualIntent = selected?.name ?? null;
  const considered = (response?.consideredIntents ?? []).map((intent) => intent?.name).filter(Boolean);
  const result = { id: testCase.id, utterance: testCase.utterance, expectedIntent: testCase.intent, actualIntent, considered, problems: [], status: 'ok' };

  if (actualIntent === null) {
    result.problems.push('aucune intention retenue');
    result.status = 'fail';
  } else if (actualIntent !== testCase.intent) {
    if ((testCase.alsoIntents ?? []).includes(actualIntent)) {
      result.status = 'ok-alias';
    } else {
      result.problems.push(`intention : attendu ${testCase.intent}, obtenu ${actualIntent}`);
      result.status = 'fail';
    }
  } else {
    for (const [name, spec] of Object.entries(testCase.slots ?? {})) {
      result.problems.push(...compareSlot(name, spec, selected.slots?.[name]));
    }
    if (result.problems.length > 0) result.status = 'fail';
  }
  result.actualSlots = Object.fromEntries(Object.entries(selected?.slots ?? {}).map(([name, slot]) => [name, describeSlot(slot)]));

  return result;
}

/** Une phrase auprès d'Amazon, avec reprises. Rend { response } ou { problem } ou { fatal }. */
async function profile(askBin, args, utterance) {
  const [bin, binArgs] = profileCommand(askBin, args, utterance);
  for (let attempt = 0; ; attempt++) {
    const { error, stdout, stderr } = await run(bin, binArgs);
    if (error?.code === 'ENOENT') return { fatal: `La commande « ${bin} » est introuvable. Installe la CLI d'Amazon (voir docs/voice/skill/README.md) ou donne son chemin dans ASK_BIN.` };
    const text = `${stdout}\n${stderr}`;
    if (!error) {
      const response = extractJson(stdout);

      return response === null ? { problem: 'sortie illisible (la CLI n\'a pas rendu de JSON)' } : { response };
    }
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

function select(cases, only) {
  if (only === null) return cases;
  const chosen = cases.filter((c) => only.includes(c.id) || only.includes(c.intent));

  return chosen;
}

function report(results, total) {
  const ok = results.filter((r) => r.status === 'ok' || r.status === 'ok-alias' || r.status === 'gap-resolved').length;
  const failures = results.filter((r) => r.status === 'fail' || r.status === 'error');
  const gaps = results.filter((r) => r.status === 'gap');
  const resolved = results.filter((r) => r.status === 'gap-resolved');
  const lines = [`${ok} / ${total} OK, ${failures.length} échec${failures.length > 1 ? 's' : ''}, ${gaps.length} écart${gaps.length > 1 ? 's' : ''} connu${gaps.length > 1 ? 's' : ''}`];
  const aliases = results.filter((r) => r.status === 'ok-alias');
  if (aliases.length > 0) lines.push(`${aliases.length} cas passent par une autre intention admise (le webhook la comprend aussi).`);
  const show = (title, list) => {
    if (list.length === 0) return;
    lines.push('', title);
    for (const r of list) {
      lines.push(`  ${r.id} : « ${r.utterance} »`);
      for (const problem of r.problems) lines.push(`    - ${problem}`);
      if (r.actualIntent !== null && r.actualIntent !== undefined) lines.push(`    intention obtenue : ${r.actualIntent}${r.considered.length > 0 ? `, envisagées : ${r.considered.join(', ')}` : ''}`);
    }
  };
  show('ÉCHECS', failures);
  show('ÉCARTS CONNUS (tolérés)', gaps);
  if (resolved.length > 0) {
    lines.push('', 'ÉCARTS CONNUS DEVENUS BONS (retire `knownGap` de ces cas) :');
    for (const r of resolved) lines.push(`  ${r.id} : « ${r.utterance} »`);
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
  let cases;
  try {
    cases = JSON.parse(readFileSync(args.cases, 'utf8')).cases;
    if (!Array.isArray(cases)) throw new Error('clé « cases » absente');
  } catch (error) {
    console.error(`Erreur : le fichier des cas est illisible (${error.message}).`);

    return 2;
  }
  const chosen = select(cases, args.only);
  if (chosen.length === 0) {
    console.error('Aucun cas ne correspond à --only.');

    return 2;
  }

  if (args.dryRun) {
    for (const testCase of chosen) {
      const [bin, binArgs] = profileCommand(askBin, args, testCase.utterance);
      console.log([bin, ...binArgs.map((a) => (/^[\w.:<>@/-]+$/.test(a) ? a : JSON.stringify(a)))].join(' '));
    }
    console.error(`${chosen.length} commande${chosen.length > 1 ? 's' : ''} (rien n'a été lancé).`);

    return 0;
  }
  if (args.skillId === '') {
    console.error('Erreur : donne l\'identifiant du skill, avec ALEXA_SKILL_ID=… ou --skill-id … (il n\'est écrit dans aucun fichier du dépôt).');

    return 2;
  }

  const results = [];
  for (const [index, testCase] of chosen.entries()) {
    if (index > 0 && args.pause > 0) await sleep(args.pause);
    const outcome = await profile(askBin, args, testCase.utterance);
    if (outcome.fatal !== undefined) {
      console.error(`Erreur : ${outcome.fatal}`);

      return 2;
    }
    let result;
    if (outcome.problem !== undefined) {
      result = { id: testCase.id, utterance: testCase.utterance, expectedIntent: testCase.intent, actualIntent: null, considered: [], problems: [outcome.problem], status: 'error' };
    } else {
      result = judge(testCase, outcome.response);
      result.raw = outcome.response;
    }
    if (testCase.knownGap === true) {
      if (result.status === 'fail' || result.status === 'error') result.status = 'gap';
      else result.status = 'gap-resolved';
    }
    results.push(result);
    if (!args.json) process.stderr.write(['ok', 'ok-alias', 'gap-resolved'].includes(result.status) ? '.' : result.status === 'gap' ? 'o' : 'F');
  }
  if (!args.json) process.stderr.write('\n');

  if (args.json) {
    console.log(JSON.stringify(results, null, 2));
  } else {
    console.log(report(results, chosen.length));
  }

  return results.some((r) => r.status === 'fail' || r.status === 'error') ? 1 : 0;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  process.exitCode = await main();
}
