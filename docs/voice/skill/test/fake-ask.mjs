#!/usr/bin/env node
// Faux `ask` pour l'auto-test de nlu-check.mjs (VOX-307) : il ne contacte personne. Il rend, pour chaque phrase demandée (`-u`), les réponses programmées
// dans le fichier FAKE_ASK_SCENARIO : { "<phrase>": [ { "stdout": "…" } | { "exit": 1, "stderr": "…" }, … ] }, une par appel, la dernière se répétant.
// FAKE_ASK_STATE (un fichier) compte les appels de chaque phrase, pour que le test vérifie les reprises.
import { existsSync, readFileSync, writeFileSync } from 'node:fs';

const args = process.argv.slice(2);
const utterance = args[args.indexOf('-u') + 1];
const scenario = JSON.parse(readFileSync(process.env.FAKE_ASK_SCENARIO, 'utf8'));
const statePath = process.env.FAKE_ASK_STATE;
const state = existsSync(statePath) ? JSON.parse(readFileSync(statePath, 'utf8')) : {};
const calls = state[utterance] ?? 0;
state[utterance] = calls + 1;
writeFileSync(statePath, JSON.stringify(state));

const steps = scenario[utterance];
if (!Array.isArray(steps) || steps.length === 0) {
  process.stderr.write(`[Error]: scénario absent pour « ${utterance} »\n`);
  process.exit(1);
}
const step = steps[Math.min(calls, steps.length - 1)];
if (step.stdout !== undefined) process.stdout.write(step.stdout);
if (step.stderr !== undefined) process.stderr.write(step.stderr);
process.exit(step.exit ?? 0);
