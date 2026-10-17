# DRV-009 — Chargeur court pour le banc d'essai (installation sur le téléphone)

- **Dépôt** : front (`kcharignon/repas-front`), dossier `drive-agent/lab/`
- **Jalon** : M0 Découverte
- **Taille** : S
- **Dépend de** : DRV-008 fusionné
- **Statut** : fait (PR [repas-front#65](https://github.com/kcharignon/repas-front/pull/65), 2026-10-17 ; chargeur de 500 caractères, fichier publié vérifié sur jsDelivr)
- **Lire d'abord** : [`../spikes/DRV-006-installation-favori.md`](../spikes/DRV-006-installation-favori.md), [`../spikes/DRV-004-entetes.md`](../spikes/DRV-004-entetes.md), [`../analyse.md`](../analyse.md) (D20), `drive-agent/lab/build.ts`, `drive-agent/lab/bookmarklet.ts`, `drive-agent/lab/PROCEDURE.md`, `CLAUDE.md` du front.

## Contexte

Le favori du banc d'essai (environ 50 Ko) ne s'installe pas sur le téléphone : un favori `javascript:` créé sur Chrome de bureau se synchronise vers Chrome Android jusqu'à 8 Ko mais pas à 16 Ko (DRV-006), et le collage direct sur le téléphone coupe la chaîne. Leclerc n'envoie aucune politique de sécurité (DRV-004), donc un script de la page peut charger du code depuis un autre site. On remplace le gros favori par un **chargeur** de moins de 1,5 Ko qui charge le banc depuis une adresse fixe : l'utilisateur peut ainsi faire sur son téléphone les essais restants de DRV-002. Ce chargeur sert aussi de premier essai réel de la piste « favori chargeur » (D20).

Pour ce banc **jetable**, le fichier est servi par le CDN public jsDelivr à partir du dépôt public (adresse figée sur un commit, immuable) et le navigateur vérifie son **empreinte** (attribut `integrity`, SHA-384) : si le fichier est différent de celui qui a été publié, il n'est pas exécuté. Le produit final n'utilisera pas jsDelivr (voir D20).

## Portée

1. Un dossier versionné `drive-agent/lab/published/` qui contient `lab.js` (le code du banc, tel que produit par `make lab`, avec sa mention « outil jetable »). Il est commité (il faut un fichier dans le dépôt pour que le CDN le serve) ; les mises à jour se font par une nouvelle PR.
2. Une cible `make lab-loader sha=<commit>` (dans Docker, comme `make lab`) qui écrit `drive-agent/lab/dist/lab-loader.txt` (ignoré par git) : la chaîne `javascript:…` du **chargeur**. Le chargeur : crée un élément `script`, adresse `https://cdn.jsdelivr.net/gh/kcharignon/repas-front@<sha complet de 40 caractères>/drive-agent/lab/published/lab.js`, `integrity` = `sha384-` + empreinte **calculée à la construction** à partir du fichier `published/lab.js`, `crossOrigin = 'anonymous'`, ajout au document ; en cas d'échec de chargement (`onerror`), `alert('Banc d'essai : chargement impossible (réseau, empreinte ou adresse).')`. Il ne doit contenir ni `%`, ni `#`, ni guillemet double, ni retour à la ligne ; il commence par `javascript:` et finit par `;void 0` ; 1 500 caractères au plus ; il refuse de se construire si `sha` n'est pas de 40 caractères hexadécimaux ou si le fichier `published/lab.js` ne correspond pas à ce que `make lab` produit maintenant (message : reconstruire et republier).
3. Après la fusion de la PR qui ajoute `published/lab.js` : prendre le sha du commit de fusion sur `main`, lancer `make lab-loader sha=<sha>`, **vérifier** (avec `curl`, uniquement vers `cdn.jsdelivr.net`) que l'adresse répond 200, avec `Access-Control-Allow-Origin: *` et un type JavaScript, et que l'empreinte SHA-384 du contenu téléchargé est identique à celle de la chaîne ; si le CDN n'a pas encore le fichier, réessayer quelques minutes (boucle `until`, pas de longue attente isolée).
4. `PROCEDURE.md` : une nouvelle partie A en français simple et par étapes numérotées : créer le favori **chargeur** (court) sur le PC avec `clip.exe < drive-agent/lab/dist/lab-loader.txt`, attendre la synchronisation, le lancer sur le téléphone ; l'ancienne méthode (gros favori) reste mentionnée comme repli pour l'ordinateur. Y noter l'adresse de la chaîne et la façon de la régénérer.
5. Tests Vitest : construction du chargeur (contient l'adresse et l'empreinte attendues, longueur, caractères interdits absents, refus d'un sha invalide), plus un test avec un faux document qui vérifie que le chargeur ajoute bien un script avec `src`, `integrity` et `crossOrigin` et que `onerror` appelle `alert`. Données synthétiques uniquement.

## Hors portée

Le vrai agent et son chargeur de production (DRV-301, DRV-310). Servir le fichier depuis l'origine de Repas (kantin.eu). Toute modification du comportement des essais.

## Critères d'acceptation

- Étant donné `published/lab.js` et un sha de 40 caractères, quand on lance `make lab-loader`, alors `dist/lab-loader.txt` contient une chaîne `javascript:…` d'au plus 1 500 caractères, sans `%`, `#` ni guillemet double, qui finit par `;void 0`.
- Étant donné cette chaîne, quand on la décode, alors elle contient l'adresse jsDelivr du sha donné et l'empreinte SHA-384 exacte de `published/lab.js`.
- Étant donné l'adresse publiée, quand on la télécharge, alors l'empreinte du contenu est celle du chargeur.
- `make check` est vert.

## Definition of Done

Celle du dépôt front (`make check`, PR concise sans pied d'attribution, fusion si la CI est verte). Mettre ensuite à jour la case DRV-009 de `docs/drive/roadmap.md` dans le dépôt back (petite PR docs). Rapport final : le sha utilisé, la taille de la chaîne, le chemin de `lab-loader.txt` et les étapes pour l'utilisateur.

## Interdits

- Ne contacter ni leclercdrive.fr ni aucun site Leclerc. Les seules requêtes réseau permises sont `git`/`gh` et `curl` vers `cdn.jsdelivr.net`.
- Aucun numéro de magasin, cookie ou jeton dans le code, les tests, la PR.

## Pièges connus

- jsDelivr ne sert un commit que s'il est poussé sur `kcharignon/repas-front` ; utiliser le sha complet (jamais `@main`, dont le cache est de plusieurs heures et qui contredirait l'empreinte).
- Une empreinte fausse fait refuser le script par le navigateur sans message : le `onerror` du chargeur est indispensable pour le diagnostic.
