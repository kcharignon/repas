// Auto-test de nlu-check.mjs (VOX-307) avec un FAUX `ask` : aucun appel chez Amazon, aucun identifiant. `make voice-nlu-selftest` (node --test).
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const script = join(here, '..', 'nlu-check.mjs');
const fakeAsk = join(here, 'fake-ask.mjs');

/** Une réponse du profileur : l'intention choisie et ses créneaux { nom: { value, id? } }. */
function profiler(intent, slots = {}, considered = []) {
  return JSON.stringify({
    sessionEnded: false,
    selectedIntent: {
      name: intent,
      confirmationStatus: 'NONE',
      slots: Object.fromEntries(Object.entries(slots).map(([name, slot]) => [name, {
        name,
        value: slot.value ?? null,
        confirmationStatus: 'NONE',
        resolutions: slot.id === undefined ? null : { resolutionsPerAuthority: [{ authority: `amzn1.er-authority.${name}`, status: { code: 'ER_SUCCESS_MATCH' }, values: [{ name: slot.value, id: slot.id }] }] },
      }])),
    },
    consideredIntents: considered.map((name) => ({ name, confirmationStatus: 'NONE', slots: {} })),
  });
}

/** Lance le script sur des cas et un scénario du faux `ask` ; rend { status, out, err, calls }. */
function check({ cases, scenario, args = [], env = {}, skillId = 'amzn1.ask.skill.test' }) {
  const dir = mkdtempSync(join(tmpdir(), 'nlu-check-'));
  const casesPath = join(dir, 'cases.json');
  const scenarioPath = join(dir, 'scenario.json');
  const statePath = join(dir, 'state.json');
  writeFileSync(casesPath, JSON.stringify({ locale: 'fr-FR', cases }));
  writeFileSync(scenarioPath, JSON.stringify(scenario));
  const result = spawnSync('node', [script, '--cases', casesPath, '--pause', '0', ...args], {
    encoding: 'utf8',
    env: { ...process.env, ASK_BIN: fakeAsk, FAKE_ASK_SCENARIO: scenarioPath, FAKE_ASK_STATE: statePath, NLU_CHECK_BACKOFF_MS: '1', ALEXA_SKILL_ID: skillId, ...env },
  });

  return { status: result.status, out: result.stdout, err: result.stderr, calls: existsSync(statePath) ? JSON.parse(readFileSync(statePath, 'utf8')) : {} };
}

const addCase = { id: 'add-deux-paquets', utterance: 'ajoute deux paquets de croquettes', intent: 'AddItemIntent', slots: { item: { value: 'croquettes' }, quantity: { id: '2' }, unit: { id: 'paquet' } }, note: 'x' };
const goodAnswer = profiler('AddItemIntent', { item: { value: 'croquettes' }, quantity: { value: 'deux', id: '2' }, unit: { value: 'paquets', id: 'paquet' } });

test('tout est conforme : code 0 et un rapport qui compte les cas', () => {
  const r = check({ cases: [addCase], scenario: { [addCase.utterance]: [{ stdout: goodAnswer }] } });

  assert.equal(r.status, 0, r.err);
  assert.match(r.out, /1 \/ 1 OK, 0 échec, 0 écart connu/);
});

test('la sortie de la CLI peut commencer par une ligne d\'information : le JSON est retrouvé', () => {
  const r = check({ cases: [addCase], scenario: { [addCase.utterance]: [{ stdout: `Profil : default\n${goodAnswer}\n` }] } });

  assert.equal(r.status, 0, r.err);
});

test('une réponse enveloppée par --full-response ({ body }) est lue aussi', () => {
  const r = check({ cases: [addCase], scenario: { [addCase.utterance]: [{ stdout: JSON.stringify({ status: 200, body: JSON.parse(goodAnswer) }) }] } });

  assert.equal(r.status, 0, r.err);
});

test('un créneau de mauvaise valeur, sans identifiant résolu, ou qui devait rester vide fait échouer et le dit', () => {
  const cases = [
    { id: 'valeur', utterance: 'ajoute du pain', intent: 'AddItemIntent', slots: { item: { value: 'pain' } }, note: 'x' },
    { id: 'sans-id', utterance: 'ajoute trois croquettes', intent: 'AddItemIntent', slots: { quantity: { id: '3' } }, note: 'x' },
    { id: 'vide', utterance: 'enlève du pain', intent: 'RemoveItemIntent', slots: { quantity: null }, note: 'x' },
  ];
  const scenario = {
    'ajoute du pain': [{ stdout: profiler('AddItemIntent', { item: { value: 'du pain' } }) }],
    'ajoute trois croquettes': [{ stdout: profiler('AddItemIntent', { quantity: { value: 'trois' } }) }],
    'enlève du pain': [{ stdout: profiler('RemoveItemIntent', { item: { value: 'pain' }, quantity: { value: 'deux', id: '2' } }) }],
  };

  const r = check({ cases, scenario });

  assert.equal(r.status, 1);
  assert.match(r.out, /0 \/ 3 OK, 3 échecs/);
  assert.match(r.out, /créneau item : attendu « pain », obtenu « du pain »/);
  assert.match(r.out, /créneau quantity : attendu id 3, obtenu « trois » \(aucun identifiant résolu\)/);
  assert.match(r.out, /créneau quantity : attendu vide, obtenu « deux » \(id 2\)/);
});

test('une mauvaise intention fait échouer et liste les intentions envisagées', () => {
  const r = check({
    cases: [{ id: 'fondue', utterance: 'planifier une fondue', intent: 'AddMealIntent', slots: { recipe: { value: 'fondue' } }, note: 'x' }],
    scenario: { 'planifier une fondue': [{ stdout: profiler('AMAZON.FallbackIntent', {}, ['AddItemIntent', 'AddMealIntent']) }] },
  });

  assert.equal(r.status, 1);
  assert.match(r.out, /intention : attendu AddMealIntent, obtenu AMAZON.FallbackIntent/);
  assert.match(r.out, /envisagées : AddItemIntent, AddMealIntent/);
});

test('une autre intention admise (alsoIntents) passe, et seule l\'intention est jugée', () => {
  const r = check({
    cases: [{ id: 'deux', utterance: 'deux', intent: 'ChooseOptionIntent', alsoIntents: ['AddItemIntent'], slots: { rank: { id: '2' } }, note: 'x' }],
    scenario: { deux: [{ stdout: profiler('AddItemIntent', { item: { value: 'deux' } }) }] },
  });

  assert.equal(r.status, 0, r.out);
  assert.match(r.out, /1 \/ 1 OK/);
  assert.match(r.out, /1 cas passent par une autre intention admise/);
});

test('un écart connu est toléré et compté à part ; devenu bon, il est signalé', () => {
  const cases = [
    { id: 'connu', utterance: 'ajoute 10 croquettes', intent: 'AddItemIntent', slots: { quantity: { id: '10' } }, knownGap: true, note: 'x' },
    { id: 'resolu', utterance: 'ajoute du pain', intent: 'AddItemIntent', slots: { item: { value: 'pain' } }, knownGap: true, note: 'x' },
  ];
  const scenario = {
    'ajoute 10 croquettes': [{ stdout: profiler('AddItemIntent', { item: { value: '10 croquettes' } }) }],
    'ajoute du pain': [{ stdout: profiler('AddItemIntent', { item: { value: 'pain' } }) }],
  };

  const r = check({ cases, scenario });

  assert.equal(r.status, 0, r.out);
  assert.match(r.out, /1 \/ 2 OK, 0 échec, 1 écart connu/);
  assert.match(r.out, /ÉCARTS CONNUS \(tolérés\)/);
  assert.match(r.out, /DEVENUS BONS \(retire `knownGap` de ces cas\) :\n  resolu/);
});

test('« trop de demandes » (429) puis une bonne réponse : le script attend et réussit', () => {
  const r = check({ cases: [addCase], scenario: { [addCase.utterance]: [{ exit: 1, stderr: '[Error]: SMAPI error code 429 - Too Many Requests' }, { stdout: goodAnswer }] } });

  assert.equal(r.status, 0, r.out + r.err);
  assert.equal(r.calls[addCase.utterance], 2);
});

test('un 429 qui ne cesse pas : quatre reprises au plus, puis un échec, jamais une boucle sans fin', () => {
  const r = check({ cases: [addCase], scenario: { [addCase.utterance]: [{ exit: 1, stderr: '[Error]: SMAPI error code 429 - Too Many Requests' }] } });

  assert.equal(r.status, 1);
  assert.equal(r.calls[addCase.utterance], 5);
  assert.match(r.out, /la CLI a échoué/);
});

test('une sortie illisible est un échec expliqué', () => {
  const r = check({ cases: [addCase], scenario: { [addCase.utterance]: [{ stdout: 'ceci n\'est pas du JSON' }] } });

  assert.equal(r.status, 1);
  assert.match(r.out, /sortie illisible/);
});

test('une panne d\'Amazon (503) est reprise comme un 429', () => {
  const r = check({ cases: [addCase], scenario: { [addCase.utterance]: [{ exit: 1, stderr: '[Error]: SMAPI error code 503 - Service Unavailable' }, { stdout: goodAnswer }] } });

  assert.equal(r.status, 0, r.out + r.err);
});

test('pas connecté (401) : le script s\'arrête tout de suite avec le remède, sans compter une rafale d\'échecs', () => {
  const second = { ...addCase, id: 'second', utterance: 'ajoute du pain' };
  const r = check({ cases: [addCase, second], scenario: { [addCase.utterance]: [{ exit: 1, stderr: '[Error]: SMAPI error code 401 - Unauthorized' }], [second.utterance]: [{ stdout: goodAnswer }] } });

  assert.equal(r.status, 2);
  assert.match(r.err, /ask configure/);
  assert.equal(r.calls[second.utterance], undefined, 'la deuxième phrase n\'est pas demandée');
});

test('la CLI introuvable, ou l\'identifiant du skill absent : code 2 et un message', () => {
  const missing = check({ cases: [addCase], scenario: {}, env: { ASK_BIN: join(tmpdir(), 'ask-qui-n-existe-pas') } });
  const noSkill = check({ cases: [addCase], scenario: { [addCase.utterance]: [{ stdout: goodAnswer }] }, env: { ALEXA_SKILL_ID: '' }, skillId: '' });

  assert.equal(missing.status, 2);
  assert.match(missing.err, /introuvable/);
  assert.equal(noSkill.status, 2);
  assert.match(noSkill.err, /identifiant du skill/);
  assert.deepEqual(noSkill.calls, {});
});

test('--only choisit un cas ou une intention, sinon code 2', () => {
  const other = { id: 'autre', utterance: 'enlève du pain', intent: 'RemoveItemIntent', slots: {}, note: 'x' };
  const scenario = { [addCase.utterance]: [{ stdout: goodAnswer }], [other.utterance]: [{ stdout: profiler('RemoveItemIntent') }] };

  const byIntent = check({ cases: [addCase, other], scenario, args: ['--only', 'RemoveItemIntent'] });
  const byId = check({ cases: [addCase, other], scenario, args: ['--only', 'add-deux-paquets'] });
  const none = check({ cases: [addCase, other], scenario, args: ['--only', 'inconnu'] });

  assert.match(byIntent.out, /1 \/ 1 OK/);
  assert.deepEqual(Object.keys(byIntent.calls), ['enlève du pain']);
  assert.deepEqual(Object.keys(byId.calls), [addCase.utterance]);
  assert.equal(none.status, 2);
});

test('--dry-run liste les commandes sans rien lancer, y compris sans identifiant', () => {
  const r = check({ cases: [addCase], scenario: {}, args: ['--dry-run'], env: { ALEXA_SKILL_ID: '' }, skillId: '' });

  assert.equal(r.status, 0);
  assert.match(r.out, /smapi profile-nlu -u "ajoute deux paquets de croquettes" -s <ALEXA_SKILL_ID> -g development -l fr-FR/);
  assert.deepEqual(r.calls, {});
});

test('--json rend les résultats bruts, sans rapport', () => {
  const r = check({ cases: [addCase], scenario: { [addCase.utterance]: [{ stdout: goodAnswer }] }, args: ['--json'] });

  assert.equal(r.status, 0, r.err);
  const results = JSON.parse(r.out);
  assert.equal(results[0].id, 'add-deux-paquets');
  assert.equal(results[0].status, 'ok');
  assert.equal(results[0].raw.selectedIntent.name, 'AddItemIntent');
});

test('le vrai fichier des cas est lu en entier par --dry-run', () => {
  const real = JSON.parse(readFileSync(join(here, '..', 'nlu-cases.fr-FR.json'), 'utf8')).cases.length;
  const r = spawnSync('node', [script, '--dry-run'], { encoding: 'utf8', env: { ...process.env, ALEXA_SKILL_ID: '', ASK_BIN: 'ask' } });

  assert.equal(r.status, 0);
  assert.equal(r.stdout.trim().split('\n').length, real);
});
