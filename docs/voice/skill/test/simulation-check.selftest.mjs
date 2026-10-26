// Auto-test de simulation-check.mjs (VOX-308) avec un FAUX `ask` : aucun appel chez Amazon, aucun identifiant. `make voice-sim-selftest` (node --test).
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const script = join(here, '..', 'simulation-check.mjs');
const fakeAsk = join(here, 'fake-ask-sim.mjs');

// Des valeurs secrètes reconnaissables : le test prouve qu'aucune n'apparaît dans AUCUNE sortie
const TOKEN = 'Atza|SECRET-TOKEN-0123456789abcdef';
const API_TOKEN = 'eyJ0eXAi.SECRET-API-ACCESS-TOKEN.signature';
const USER_ID = 'amzn1.ask.account.SECRETUSERID0123456789';
const DEVICE_ID = 'amzn1.ask.device.SECRETDEVICEID0123456789';
const SECRETS = [TOKEN, API_TOKEN, USER_ID, DEVICE_ID];

/** La réponse du profileur : l'intention retenue. */
const profiler = (intent) => JSON.stringify({ sessionEnded: false, selectedIntent: { name: intent, confirmationStatus: 'NONE', slots: {} }, consideredIntents: [] });

/** La réponse de `simulate-skill` : la simulation démarre. */
const started = (id) => JSON.stringify({ id, status: 'IN_PROGRESS' });

/** La réponse de `get-skill-simulation` d'une simulation finie, avec une requête reçue par NOTRE serveur qui porte des secrets partout où Amazon en met. */
function finished({ type = 'IntentRequest', intent = null, slots = {}, reply = null, ends = true, reprompt = false, sessionNew = true, dialogue = null, caption = reply } = {}) {
  const request = type === 'IntentRequest'
    ? { type, requestId: 'amzn1.echo-api.request.x', locale: 'fr-FR', intent: { name: intent, slots: Object.fromEntries(Object.entries(slots).map(([name, value]) => [name, { name, value, confirmationStatus: 'NONE' }])) } }
    : { type, requestId: 'amzn1.echo-api.request.x', locale: 'fr-FR' };
  const response = { outputSpeech: { type: 'PlainText', text: reply }, shouldEndSession: ends };
  if (reprompt) response.reprompt = { outputSpeech: { type: 'PlainText', text: reply } };

  return JSON.stringify({
    id: 'sim',
    status: 'SUCCESSFUL',
    result: {
      alexaExecutionInfo: { alexaResponses: [{ type: 'Speech', content: { caption } }], consideredIntents: [] },
      skillExecutionInfo: {
        invocations: [{
          invocationRequest: {
            endpoint: 'https://kantin.example/api/voice/alexa',
            body: {
              version: '1.0',
              session: { new: sessionNew, sessionId: 'amzn1.echo-api.session.x', user: { userId: USER_ID, accessToken: TOKEN } },
              context: { System: { user: { userId: USER_ID, accessToken: TOKEN }, apiAccessToken: API_TOKEN, device: { deviceId: DEVICE_ID } } },
              request,
            },
          },
          invocationResponse: { body: { version: '1.0', response, ...(dialogue === null ? {} : { sessionAttributes: { dialogue } }) } },
          metrics: { skillExecutionTimeInMilliseconds: 12 },
        }],
      },
    },
  });
}

const LAUNCH = '^Que veux-tu ajouter \\?$';
const CHECK = "^(Oui, tu en as .+\\.|Non, il n'y a pas .+ dans ta liste\\.|Tu n'as pas de liste en cours\\.)$";
const launchTurn = { say: 'ouvre jarvis', sessionMode: 'FORCE_NEW_SESSION', launch: true, intent: 'LaunchRequest', replyMatches: LAUNCH, sessionEnds: false, reprompt: true };
const checkTurn = { say: "est ce qu'il y a du pain dans la liste", heard: "est ce qu'il y a du pain dans la liste", sessionMode: 'DEFAULT', sessionNew: false, intent: 'CheckItemIntent', slots: { item: 'pain' }, replyMatches: CHECK, sessionEnds: false, reprompt: false };
const scenario = (id, turns, writes = false) => ({ id, note: 'x', writes, turns });

/** Les réponses programmées d'un scénario « lancement puis consultation » qui réussit. */
function goodFake(extra = {}) {
  return {
    'profile-nlu': { [checkTurn.heard]: [{ stdout: profiler('CheckItemIntent') }] },
    'simulate-skill': { 'ouvre jarvis': [{ stdout: started('sim-1') }], [checkTurn.say]: [{ stdout: started('sim-2') }] },
    'get-skill-simulation': {
      'sim-1': [{ stdout: finished({ type: 'LaunchRequest', reply: 'Que veux-tu ajouter ?', ends: false, reprompt: true }) }],
      'sim-2': [{ stdout: finished({ intent: 'CheckItemIntent', slots: { item: 'pain' }, reply: 'Oui, tu en as 5,5.', ends: false, sessionNew: false }) }],
    },
    ...extra,
  };
}

/** Lance le script sur des scénarios et un faux `ask` ; rend { status, out, err, calls }. */
function check({ scenarios, fake, args = [], env = {}, skillId = 'amzn1.ask.skill.test' }) {
  const dir = mkdtempSync(join(tmpdir(), 'simulation-check-'));
  const scenariosPath = join(dir, 'scenarios.json');
  const fakePath = join(dir, 'fake.json');
  const statePath = join(dir, 'state.json');
  writeFileSync(scenariosPath, JSON.stringify({ locale: 'fr-FR', scenarios }));
  writeFileSync(fakePath, JSON.stringify(fake));
  const result = spawnSync('node', [script, '--scenarios', scenariosPath, '--pause', '0', ...args], {
    encoding: 'utf8',
    env: { ...process.env, ASK_BIN: fakeAsk, FAKE_ASK_SCENARIO: fakePath, FAKE_ASK_STATE: statePath, SIM_CHECK_BACKOFF_MS: '1', SIM_CHECK_POLL_MS: '1', ALEXA_SKILL_ID: skillId, ...env },
  });
  const state = existsSync(statePath) ? JSON.parse(readFileSync(statePath, 'utf8')) : { counts: {}, calls: [] };

  return { status: result.status, out: result.stdout, err: result.stderr, calls: state.calls, counts: state.counts };
}

/** Aucune valeur secrète dans AUCUNE sortie. */
function assertNoSecret(r) {
  for (const secret of SECRETS) {
    assert.ok(!r.out.includes(secret), `un secret est sur la sortie standard : ${secret}`);
    assert.ok(!r.err.includes(secret), `un secret est sur la sortie d'erreur : ${secret}`);
  }
}

const callsOf = (r, cmd) => r.calls.filter((c) => c.cmd === cmd);

test('tout est conforme : code 0, le rapport compte les scénarios, le lancement n\'est pas profilé et les séances s\'enchaînent', () => {
  const r = check({ scenarios: [scenario('lancement-puis-consulter', [launchTurn, checkTurn])], fake: goodFake() });

  assert.equal(r.status, 0, r.err);
  assert.match(r.out, /1 \/ 1 scénarios OK/);
  // Le profileur n'est lancé que pour la phrase qui n'est pas un lancement
  assert.deepEqual(callsOf(r, 'profile-nlu').map((c) => c.key), [checkTurn.heard]);
  // La première simulation démarre une séance, la suivante la continue
  const sessionModes = callsOf(r, 'simulate-skill').map((c) => c.argv[c.argv.indexOf('--session-mode') + 1]);
  assert.deepEqual(sessionModes, ['FORCE_NEW_SESSION', 'DEFAULT']);
  assert.ok(callsOf(r, 'simulate-skill').every((c) => c.argv.includes('--device-locale') && c.argv.includes('fr-FR') && c.argv.includes('development')));
  assertNoSecret(r);
});

test('une mauvaise réponse fait échouer, dit la phrase dite, l\'attendu et l\'obtenu, et ne montre aucun jeton', () => {
  const fake = goodFake();
  fake['get-skill-simulation']['sim-2'] = [{ stdout: finished({ intent: 'CheckItemIntent', slots: { item: 'pain' }, reply: 'Je n\'ai pas pu faire ça. Réessaie dans un instant.', ends: false, sessionNew: false }) }];
  const r = check({ scenarios: [scenario('echec', [launchTurn, checkTurn])], fake });

  assert.equal(r.status, 1);
  assert.match(r.out, /0 \/ 1 scénarios OK, 1 avec un écart/);
  assert.match(r.out, /tour 2/);
  assert.match(r.out, /est ce qu'il y a du pain dans la liste/);
  assert.match(r.out, /réponse : attendu/);
  assert.match(r.out, /Je n'ai pas pu faire ça/);
  assertNoSecret(r);
});

test('un créneau, une séance, une relance ou une séance neuve qui ne sont pas ceux attendus sont dits', () => {
  const fake = goodFake();
  fake['get-skill-simulation']['sim-2'] = [{ stdout: finished({ intent: 'CheckItemIntent', slots: { item: 'lait' }, reply: 'Oui, tu en as 2.', ends: true, reprompt: true, sessionNew: true }) }];
  const r = check({ scenarios: [scenario('ecarts', [launchTurn, checkTurn])], fake });

  assert.equal(r.status, 1);
  assert.match(r.out, /créneau item : attendu « pain », obtenu « lait »/);
  assert.match(r.out, /séance : attendu ouverte, obtenu fermée/);
  assert.match(r.out, /relance : attendu aucune relance, obtenu une relance/);
  assert.match(r.out, /séance : attendu la séance déjà ouverte, obtenu une nouvelle séance/);
  assertNoSecret(r);
});

test('GARDE (b) : un tour que le profileur range dans une intention qui écrit est BLOQUÉ et aucune simulation n\'est lancée', () => {
  const fake = goodFake();
  fake['profile-nlu'][checkTurn.heard] = [{ stdout: profiler('AddItemIntent') }];
  const r = check({ scenarios: [scenario('bloque', [launchTurn, checkTurn])], fake });

  assert.equal(r.status, 1);
  assert.match(r.out, /bloqué : Amazon l'a rangé dans AddItemIntent, aucune simulation lancée/);
  // Le lancement a été simulé, la phrase bloquée JAMAIS
  assert.deepEqual(callsOf(r, 'simulate-skill').map((c) => c.key), ['ouvre jarvis']);
  assertNoSecret(r);
});

test('GARDE (b) : un profileur qui ne retient aucune intention bloque aussi', () => {
  const fake = goodFake();
  fake['profile-nlu'][checkTurn.heard] = [{ stdout: JSON.stringify({ consideredIntents: [] }) }];
  const r = check({ scenarios: [scenario('rien', [checkTurn])], fake });

  assert.equal(r.status, 1);
  assert.match(r.out, /bloqué : Amazon l'a rangé dans aucune intention, aucune simulation lancée/);
  assert.equal(callsOf(r, 'simulate-skill').length, 0);
});

test('GARDE (c) : si NOTRE serveur reçoit une intention qui n\'est pas de lecture seule, ARRÊT IMMÉDIAT (code 3), les scénarios suivants ne sont pas lancés', () => {
  const fake = goodFake();
  fake['get-skill-simulation']['sim-2'] = [{ stdout: finished({ intent: 'AddItemIntent', slots: { item: 'pain' }, reply: 'C\'est noté : pain.', ends: false, sessionNew: false }) }];
  fake['simulate-skill']['ouvre jarvis'] = [{ stdout: started('sim-1') }];
  const second = scenario('suivant', [launchTurn]);
  const r = check({ scenarios: [scenario('danger', [launchTurn, checkTurn]), second], fake });

  assert.equal(r.status, 3);
  assert.match(r.err, /ARRÊT IMMÉDIAT \(scénario danger\)/);
  assert.match(r.err, /AddItemIntent/);
  assert.match(r.err, /ÉCRIRE dans la liste/);
  assert.match(r.err, /Aucun autre scénario n'a été lancé/);
  // Le second scénario n'a pas été joué : une seule simulation du lancement (celle du premier scénario) et une de la phrase dangereuse
  assert.equal(r.counts['simulate-skill|ouvre jarvis'], 1);
  assertNoSecret(r);
});

test('GARDE (c) : une requête de lancement ou de fin de séance est permise, une autre requête ne l\'est pas', () => {
  const fake = goodFake();
  fake['get-skill-simulation']['sim-1'] = [{ stdout: finished({ type: 'CanFulfillIntentRequest', reply: 'x' }) }];
  const r = check({ scenarios: [scenario('autre-requete', [launchTurn])], fake });

  assert.equal(r.status, 3);
  assert.match(r.err, /CanFulfillIntentRequest/);
  assertNoSecret(r);
});

test('GARDE (d) : un dialogue ouvert par notre réponse arrête tout (une question en attente pourrait faire écrire le tour suivant)', () => {
  const fake = goodFake();
  fake['get-skill-simulation']['sim-2'] = [{ stdout: finished({ intent: 'CheckItemIntent', slots: { item: 'pain' }, reply: 'Oui, tu en as 5,5.', ends: false, sessionNew: false, dialogue: { step: 'choose', phrase: 'pain' } }) }];
  const r = check({ scenarios: [scenario('dialogue', [launchTurn, checkTurn])], fake });

  assert.equal(r.status, 3);
  assert.match(r.err, /ARRÊT IMMÉDIAT/);
  assert.match(r.err, /OUVERT UN DIALOGUE/);
  assertNoSecret(r);
});

test('un état de dialogue vide ({}) n\'est pas un dialogue ouvert', () => {
  const fake = goodFake();
  fake['get-skill-simulation']['sim-2'] = [{ stdout: finished({ intent: 'CheckItemIntent', slots: { item: 'pain' }, reply: 'Oui, tu en as 5,5.', ends: false, sessionNew: false, dialogue: {} }) }];
  const r = check({ scenarios: [scenario('vide', [launchTurn, checkTurn])], fake });

  assert.equal(r.status, 0, r.err);
});

test('GARDE (a) : un scénario qui n\'est pas « writes: false » est REFUSÉ avant toute commande (code 2) ; --allow-writes le permet', () => {
  const refused = check({ scenarios: [scenario('ecrit', [launchTurn], true), scenario('lit', [launchTurn])], fake: goodFake() });

  assert.equal(refused.status, 2);
  assert.match(refused.err, /Refusé : ecrit/);
  assert.match(refused.err, /writes: false/);
  assert.equal(refused.calls.length, 0, 'aucune commande lancée');

  const missing = check({ scenarios: [{ id: 'sans-writes', note: 'x', turns: [launchTurn] }], fake: goodFake() });
  assert.equal(missing.status, 2);
  assert.equal(missing.calls.length, 0);

  const allowed = check({ scenarios: [scenario('ecrit', [launchTurn], true)], fake: goodFake(), args: ['--allow-writes'] });
  assert.equal(allowed.status, 0, allowed.err);
});

test('une simulation encore en cours est relue jusqu\'à ce qu\'elle soit finie', () => {
  const fake = goodFake();
  fake['get-skill-simulation']['sim-2'] = [{ stdout: JSON.stringify({ id: 'sim-2', status: 'IN_PROGRESS' }) }, { stdout: JSON.stringify({ id: 'sim-2', status: 'IN_PROGRESS' }) }, { stdout: finished({ intent: 'CheckItemIntent', slots: { item: 'pain' }, reply: 'Oui, tu en as 5,5.', ends: false, sessionNew: false }) }];
  const r = check({ scenarios: [scenario('lent', [launchTurn, checkTurn])], fake });

  assert.equal(r.status, 0, r.err);
  assert.equal(r.counts['get-skill-simulation|sim-2'], 3);
});

test('une simulation qui ne finit jamais s\'arrête après un nombre borné de relectures', () => {
  const fake = goodFake();
  fake['get-skill-simulation']['sim-1'] = [{ stdout: JSON.stringify({ id: 'sim-1', status: 'IN_PROGRESS' }) }];
  const r = check({ scenarios: [scenario('interminable', [launchTurn])], fake });

  assert.equal(r.status, 1);
  assert.match(r.out, /n'a pas fini après 20 relectures/);
  assert.equal(r.counts['get-skill-simulation|sim-1'], 20);
});

test('un résultat expiré (404) est un écart de ce scénario, pas un arrêt', () => {
  const fake = goodFake();
  fake['get-skill-simulation']['sim-1'] = [{ exit: 1, stderr: '[Error]: 404 Not Found: simulation does not exist\n' }];
  const r = check({ scenarios: [scenario('expire', [launchTurn]), scenario('suite', [launchTurn])], fake });

  assert.equal(r.status, 1);
  assert.match(r.out, /expire \(erreur\)/);
  assert.match(r.out, /le résultat de la simulation n'existe plus/);
});

test('une sortie illisible de la CLI est un écart, sans jeton', () => {
  const fake = goodFake();
  fake['simulate-skill']['ouvre jarvis'] = [{ stdout: 'ceci n\'est pas du JSON' }];
  const r = check({ scenarios: [scenario('illisible', [launchTurn])], fake });

  assert.equal(r.status, 1);
  assert.match(r.out, /sortie illisible/);
  assertNoSecret(r);
});

test('une simulation qui ne rend aucun identifiant, ou qu\'Amazon fait échouer, est un écart', () => {
  const noId = goodFake();
  noId['simulate-skill']['ouvre jarvis'] = [{ stdout: JSON.stringify({ status: 'IN_PROGRESS' }) }];
  const a = check({ scenarios: [scenario('sans-id', [launchTurn])], fake: noId });
  assert.equal(a.status, 1);
  assert.match(a.out, /n'a pas rendu d'identifiant/);

  const failed = goodFake();
  failed['get-skill-simulation']['sim-1'] = [{ stdout: JSON.stringify({ id: 'sim-1', status: 'FAILED', result: { error: { message: 'Le skill n\'est pas activé' } } }) }];
  const b = check({ scenarios: [scenario('echoue', [launchTurn])], fake: failed });
  assert.equal(b.status, 1);
  assert.match(b.out, /Amazon a échoué à simuler cette phrase : Le skill n'est pas activé/);
});

test('Amazon n\'appelle pas le skill : le dit, avec ce qu\'Alexa a répondu', () => {
  const fake = goodFake();
  fake['get-skill-simulation']['sim-1'] = [{ stdout: JSON.stringify({ id: 'sim-1', status: 'SUCCESSFUL', result: { alexaExecutionInfo: { alexaResponses: [{ type: 'Speech', content: { caption: 'Je ne connais pas ce skill.' } }] }, skillExecutionInfo: { invocations: [] } } }) }];
  const r = check({ scenarios: [scenario('muet', [launchTurn])], fake });

  assert.equal(r.status, 1);
  assert.match(r.out, /Amazon n'a pas appelé le skill \(Alexa a dit : « Je ne connais pas ce skill\. »\)/);
});

test('« trop de demandes » (429) est retenté puis réussit ; une panne qui dure s\'arrête après 4 reprises', () => {
  const retried = goodFake();
  retried['simulate-skill']['ouvre jarvis'] = [{ exit: 1, stderr: '[Error]: 429 Too Many Requests\n' }, { stdout: started('sim-1') }];
  const a = check({ scenarios: [scenario('retente', [launchTurn])], fake: retried });
  assert.equal(a.status, 0, a.err);
  assert.equal(a.counts['simulate-skill|ouvre jarvis'], 2);

  const stuck = goodFake();
  stuck['simulate-skill']['ouvre jarvis'] = [{ exit: 1, stderr: '[Error]: 503 Service Unavailable\n' }];
  const b = check({ scenarios: [scenario('panne', [launchTurn])], fake: stuck });
  assert.equal(b.status, 1);
  assert.equal(b.counts['simulate-skill|ouvre jarvis'], 5, 'une demande et 4 reprises');
  assert.match(b.out, /la CLI a échoué/);
});

test('une connexion refusée arrête tout tout de suite (code 2)', () => {
  const fake = goodFake();
  fake['simulate-skill']['ouvre jarvis'] = [{ exit: 1, stderr: '[Error]: 401 Unauthorized: invalid_grant\n' }];
  const r = check({ scenarios: [scenario('a', [launchTurn]), scenario('b', [launchTurn])], fake });

  assert.equal(r.status, 2);
  assert.match(r.err, /Amazon refuse la connexion de la CLI/);
  assert.equal(r.counts['simulate-skill|ouvre jarvis'], 1);
});

test('une CLI introuvable, un identifiant de skill absent, un --only vide : code 2 et un message', () => {
  const missingCli = check({ scenarios: [scenario('a', [launchTurn])], fake: goodFake(), env: { ASK_BIN: join(tmpdir(), 'ask-qui-n-existe-pas') } });
  assert.equal(missingCli.status, 2);
  assert.match(missingCli.err, /est introuvable/);

  const noId = check({ scenarios: [scenario('a', [launchTurn])], fake: goodFake(), skillId: '' });
  assert.equal(noId.status, 2);
  assert.match(noId.err, /ALEXA_SKILL_ID/);

  const none = check({ scenarios: [scenario('a', [launchTurn])], fake: goodFake(), args: ['--only', 'inconnu'] });
  assert.equal(none.status, 2);
  assert.match(none.err, /Aucun scénario/);
});

test('--only joue seulement les scénarios choisis', () => {
  const r = check({ scenarios: [scenario('a', [launchTurn]), scenario('b', [launchTurn])], fake: goodFake(), args: ['--only', 'b'] });

  assert.equal(r.status, 0, r.err);
  assert.match(r.out, /1 \/ 1 scénarios OK/);
  assert.equal(r.counts['simulate-skill|ouvre jarvis'], 1);
});

test('--dry-run liste les commandes sans rien lancer et ne montre aucun secret', () => {
  const r = check({ scenarios: [scenario('a', [launchTurn, checkTurn])], fake: goodFake(), args: ['--dry-run'], skillId: '' });

  assert.equal(r.status, 0, r.err);
  assert.equal(r.calls.length, 0);
  assert.match(r.out, /smapi profile-nlu -u "est ce qu'il y a du pain dans la liste"/);
  assert.match(r.out, /smapi simulate-skill .* --input-content "ouvre jarvis" .* --session-mode FORCE_NEW_SESSION/);
  assert.match(r.out, /smapi get-skill-simulation/);
  assert.match(r.err, /1 scénario \(rien n'a été lancé\)/);
});

test('--json écrit le détail SANS le jeton : seuls le type, l\'intention, les créneaux et la séance de la requête sont gardés', () => {
  const r = check({ scenarios: [scenario('json', [launchTurn, checkTurn])], fake: goodFake(), args: ['--json'] });

  assert.equal(r.status, 0, r.err);
  assertNoSecret(r);
  const parsed = JSON.parse(r.out);
  assert.equal(parsed[0].id, 'json');
  assert.equal(parsed[0].status, 'ok');
  assert.deepEqual(parsed[0].turns[1].request, { type: 'IntentRequest', intent: 'CheckItemIntent', slots: { item: 'pain' }, sessionNew: false });
  assert.equal(parsed[0].turns[1].reply, 'Oui, tu en as 5,5.');
  assert.doesNotMatch(r.out, /accessToken|apiAccessToken|userId|deviceId/);
});

test('un secret croisé plus tôt est masqué même s\'il revient dans une erreur de la CLI', () => {
  const fake = goodFake();
  fake['simulate-skill'][checkTurn.say] = [{ exit: 1, stderr: `[Error]: la requête a échoué avec le jeton ${TOKEN}\n` }];
  const r = check({ scenarios: [scenario('fuite', [launchTurn, checkTurn])], fake });

  assert.equal(r.status, 1);
  assert.match(r.out, /\[masqué\]/);
  assertNoSecret(r);
});

test('une expression régulière invalide ne fait pas planter le rejeu : elle ne reconnaît rien', () => {
  const broken = { ...launchTurn, replyMatches: '^(Que' };
  const r = check({ scenarios: [scenario('regex', [broken])], fake: goodFake() });

  assert.equal(r.status, 1);
  assert.match(r.out, /réponse : attendu/);
});
