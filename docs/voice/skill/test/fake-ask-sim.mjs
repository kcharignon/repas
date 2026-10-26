#!/usr/bin/env node
// Faux `ask` pour l'auto-test de simulation-check.mjs (VOX-308) : il ne contacte personne. Il rend les réponses programmées dans le fichier FAKE_ASK_SCENARIO :
//   { "profile-nlu": { "<phrase -u>": [étapes] }, "simulate-skill": { "<--input-content>": [étapes] }, "get-skill-simulation": { "<identifiant -i>": [étapes] } }
// où une étape est { "stdout": "…" } ou { "exit": 1, "stderr": "…" } ; une étape par appel de la même clé, la dernière se répétant.
// FAKE_ASK_STATE (un fichier) garde { counts, calls } : le nombre d'appels de chaque clé et la liste ordonnée des appels ({ cmd, key, argv }), pour que le test
// vérifie qu'une commande n'a PAS été lancée (par exemple aucune simulation d'un tour bloqué).
import { existsSync, readFileSync, writeFileSync } from 'node:fs';

const args = process.argv.slice(2);
const cmd = args[1];
const optionOf = { 'profile-nlu': '-u', 'simulate-skill': '--input-content', 'get-skill-simulation': '-i' }[cmd];
const key = optionOf === undefined ? '' : args[args.indexOf(optionOf) + 1];
const scenario = JSON.parse(readFileSync(process.env.FAKE_ASK_SCENARIO, 'utf8'));
const statePath = process.env.FAKE_ASK_STATE;
const state = existsSync(statePath) ? JSON.parse(readFileSync(statePath, 'utf8')) : { counts: {}, calls: [] };
const countKey = `${cmd}|${key}`;
const count = state.counts[countKey] ?? 0;
state.counts[countKey] = count + 1;
state.calls.push({ cmd, key, argv: args });
writeFileSync(statePath, JSON.stringify(state));

const steps = scenario[cmd]?.[key];
if (!Array.isArray(steps) || steps.length === 0) {
  process.stderr.write(`[Error]: scénario absent pour ${cmd} « ${key} »\n`);
  process.exit(1);
}
const step = steps[Math.min(count, steps.length - 1)];
if (step.stdout !== undefined) process.stdout.write(step.stdout);
if (step.stderr !== undefined) process.stderr.write(step.stderr);
process.exit(step.exit ?? 0);
