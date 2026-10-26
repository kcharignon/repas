// Auto-test de push-model.mjs avec un FAUX `ask` (aucun appel chez Amazon, aucun identifiant) : `node --test docs/voice/skill/test/push-model.selftest.mjs`
import test from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { chmodSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const script = join(here, '..', 'push-model.mjs');

/** Un faux `ask` : `set-interaction-model` réussit ou échoue (FAKE_PUSH), `get-skill-status` rend les états de FAKE_STATES (séparés par des virgules), puis le dernier. */
function fakeAsk() {
  const dir = mkdtempSync(join(tmpdir(), 'fake-ask-'));
  const bin = join(dir, 'ask');
  writeFileSync(bin, `#!/usr/bin/env node
const fs = require('node:fs');
const [, , group, op] = process.argv;
const counter = '${dir}/count';
if (op === 'set-interaction-model') { process.exit(process.env.FAKE_PUSH === 'fail' ? 1 : 0); }
if (op === 'get-skill-status') {
  const states = (process.env.FAKE_STATES || 'SUCCEEDED').split(',');
  let n = 0; try { n = Number(fs.readFileSync(counter, 'utf8')); } catch {}
  fs.writeFileSync(counter, String(n + 1));
  console.log(JSON.stringify({ interactionModel: { 'fr-FR': { lastUpdateRequest: { status: states[Math.min(n, states.length - 1)] } } } }));
  process.exit(0);
}
process.exit(1);
`);
  chmodSync(bin, 0o755);

  return bin;
}

function run(env) {
  return new Promise((resolve) => {
    execFile('node', [script], { env: { ...process.env, PUSH_MODEL_POLL_MS: '10', ...env }, encoding: 'utf8' }, (error, stdout, stderr) => resolve({ code: error ? error.code : 0, stdout, stderr }));
  });
}

test('le modèle est envoyé puis le build réussit après quelques sondages', async () => {
  const result = await run({ ASK_BIN: fakeAsk(), ALEXA_SKILL_ID: 'amzn1.ask.skill.test', FAKE_STATES: 'IN_PROGRESS,IN_PROGRESS,SUCCEEDED' });
  assert.equal(result.code, 0);
  assert.match(result.stdout, /Build terminé/);
});

test('un build en échec sort avec 1', async () => {
  const result = await run({ ASK_BIN: fakeAsk(), ALEXA_SKILL_ID: 'amzn1.ask.skill.test', FAKE_STATES: 'IN_PROGRESS,FAILED' });
  assert.equal(result.code, 1);
  assert.match(result.stderr, /build du modèle a échoué/);
});

test('un envoi refusé par la CLI sort avec 2, sans sonder', async () => {
  const result = await run({ ASK_BIN: fakeAsk(), ALEXA_SKILL_ID: 'amzn1.ask.skill.test', FAKE_PUSH: 'fail' });
  assert.equal(result.code, 2);
  assert.match(result.stderr, /envoi du modèle a échoué/);
});

test('sans identifiant du skill, rien n\'est lancé', async () => {
  const result = await run({ ASK_BIN: fakeAsk(), ALEXA_SKILL_ID: '' });
  assert.equal(result.code, 2);
  assert.match(result.stderr, /identifiant du skill/);
});
