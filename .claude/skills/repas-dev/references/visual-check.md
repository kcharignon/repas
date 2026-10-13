# Test visuel d'une page dans un vrai navigateur

À faire pour chaque slice qui touche une page Twig (Definition of Done, [ADR 0009](../../../../docs/adr/0009-strategie-de-migration.md) §6) : Chromium headless via Playwright, connexion réelle, captures d'écran **lues** (pas seulement générées).

## Prérequis (une fois par machine)

- Stack dev démarrée (https://localhost:8443, voir `README`) et base `repas` remplie : `make fixtures-load-dev`.
- Bibliothèques système de Chromium : `sudo env "PATH=$PATH" npx playwright install-deps chromium` (le `env "PATH=$PATH"` est nécessaire si `node` vient de nvm, car `sudo` réinitialise le `PATH`).
- Projet Node **hors du dépôt** (dossier temporaire ou scratchpad) :
  ```bash
  mkdir -p /tmp/repas-e2e && cd /tmp/repas-e2e
  npm init -y && npm install playwright@1.49.1 && npx playwright install chromium
  ```

## Script

`/tmp/repas-e2e/check.mjs` : `node check.mjs <dossier-captures> /recipes /admin/recipe-type …`

```js
import { chromium } from 'playwright';

const base = 'https://localhost:8443';
const [out, ...paths] = process.argv.slice(2);

const browser = await chromium.launch();
const page = await (await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } })).newPage();

const problems = [];
page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`); });
page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
page.on('response', (r) => { if (r.status() >= 400) problems.push(`HTTP ${r.status()} ${r.url()}`); });

await page.goto(`${base}/login`);
await page.fill('#loginEmail', process.env.E2E_EMAIL);
await page.fill('#loginPassword', process.env.E2E_PASSWORD);
await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login')), page.click('button[type=submit]')]);

for (const path of paths) {
  const res = await page.goto(`${base}${path}`, { waitUntil: 'networkidle' });
  const broken = await page.evaluate(() => [...document.images].filter((i) => !(i.complete && i.naturalWidth > 0)).map((i) => i.getAttribute('src')));
  console.log(`${path} -> ${res.status()} | title: ${await page.title()} | broken images: ${broken.length}`);
  broken.forEach((src) => console.log(`   broken: ${src}`));
  await page.screenshot({ path: `${out}/${path.replace(/\W+/g, '-').replace(/^-|-$/g, '') || 'home'}.png`, fullPage: true });
}

console.log(problems.length ? `problems:\n  ${problems.join('\n  ')}` : 'problems: none');
await browser.close();
```

Lancer avec les identifiants en variables d'environnement, **sans jamais les afficher** (admin : `kantincharignon@gmail.com`, mot de passe lu dans le conteneur) :

```bash
export E2E_EMAIL=kantincharignon@gmail.com
export E2E_PASSWORD="$(docker compose exec -T php printenv ADMIN_PASSWORD | tr -d '\r\n')"
mkdir -p shots && node check.mjs shots /recipes /admin/recipe-type
```

## Ce qu'il faut contrôler

- Statut 200 (ou la redirection attendue), bon titre, aucune entrée dans `problems`, aucune image cassée.
- **Lire chaque capture** : contenu, ordre, libellés, mise en forme comparables à la version d'avant la slice.
- Les actions de la page : cliquer au moins un lien d'édition / bouton d'action et vérifier la page cible (les liens construits depuis le DTO sont le point de régression le plus probable).
- Page réservée à un rôle : tester aussi l'accès refusé avec un utilisateur sans ce rôle.
- Barre du profiler Symfony (en bas des captures) : nombre de requêtes SQL, il doit baisser ou rester borné.

Le HTML peut aussi être comparé avec `curl` avant/après (même HTML à la logique d'affichage près), mais ça ne remplace pas la capture : seule celle-ci prouve le rendu.
