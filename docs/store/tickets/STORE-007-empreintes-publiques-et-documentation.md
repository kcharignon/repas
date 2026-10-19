# STORE-007 — Empreintes publiques et documentation de signature après le choix de la clé

- **Dépôt** : front (`public/.well-known/assetlinks.json`, `android/PUBLICATION.md`, `android/README.md`, `android/build.sh` ou `.github/workflows/android-release.yml`, `android/__tests__/publication.spec.ts`) ; l'assistant met à jour `docs/auth/` du back après la fusion
- **Jalon** : M1 Clé de signature et construction
- **Taille** : S (cas A : documentation seule) à M (cas B)
- **Dépend de** : STORE-005 (cas A ou B connu)
- **Faisable sans téléphone ni compte Play** : oui (le code) ; les empreintes viennent de STORE-005
- **Bloquant pour AUTH-002 / la connexion Google** : **oui dans le cas B** (ou si la console affiche trois empreintes) : `assetlinks.json` et le client OAuth Android utilisent les mêmes empreintes
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) §§ 4.2, 4.3 et 8 ; `android/PUBLICATION.md` (étape 4 et « Si la clé est perdue ») ; `.github/workflows/android-release.yml` (étape « Empreinte du certificat attendu »).

## Contexte

Le workflow lit aujourd'hui l'empreinte attendue de l'APK **dans `assetlinks.json`**, ce qui suppose que ce fichier ne contienne que la clé de publication. Si Google Play signe avec une autre clé (cas B) ou ajoute des empreintes (signature hybride), il faut les déclarer à Android pour que les liens s'ouvrent dans l'application, **sans** que le contrôle de l'APK accepte pour autant ces clés.

## Portée

**Cas A (clé importée, une empreinte identique)** :

1. `PUBLICATION.md` : nouvelle section « Google Play » (la clé de publication est aussi la clé de signature détenue par Google ; ce qui change pour la perte de clé : Google en a une copie, la clé d'envoi se réinitialise) ; la mention « Play Protect » reste pour le canal de secours.
2. Aucun changement de `assetlinks.json` ni du workflow. Un test garde que `assetlinks.json` contient l'empreinte de la clé de publication.

**Cas B (empreinte différente, ou trois empreintes)** :

1. `assetlinks.json` : **ajouter** l'empreinte SHA-256 de chaque certificat affiché par la console (garder l'ancienne tant que l'APK GitHub existe) ; `package_name` inchangé.
2. Le workflow et `verify-release` ne doivent accepter pour l'APK que la clé de publication : l'empreinte attendue vient d'un fichier public dédié `android/release-cert.sha256` (nouvelle source), et le test vérifie que `assetlinks.json` **contient** cette empreinte (sans être limité à elle).
3. `PUBLICATION.md` : section « Google Play » plus « Si Google signe avec une autre clé » (le canal APK de la release ne s'installe plus par-dessus Play).

Dans les deux cas, l'assistant renvoie à AUTH-002 la ou les empreintes SHA-1 à saisir (cas A : celle de la clé actuelle, relevée sur la console).

## Hors portée

La création du client OAuth Android (AUTH-002, humain) ; toute étiquette ; le retrait du canal APK (STORE-020).

## Critères d'acceptation

- Étant donné `assetlinks.json`, quand on le lit, alors il contient l'empreinte de chaque certificat qui peut signer l'application installée (cas A : la même ; cas B : l'ancienne et celles de la console).
- Étant donné un APK signé d'une empreinte ajoutée par Google (cas B), quand `verify-release` le contrôle, alors il est refusé.
- Étant donné la page `https://kantin.eu/.well-known/assetlinks.json` après déploiement, alors `curl` donne `200`, `application/json`, aucune redirection (étape 4 de `PUBLICATION.md`).
- Étant donné un téléphone avec l'application installée depuis Google Play, alors `adb shell pm get-app-links eu.kantin.repas.drive` dit `kantin.eu: verified` (preuve à STORE-016).

## Tests exigés

`publication.spec.ts` (forme du fichier, paquet unique, empreinte de publication présente, **jamais** l'empreinte de la clé de débogage ni de la clé d'envoi) ; `make android-release-selftest` (cas B) ; `make check`.

## Definition of Done

Celle du dépôt front ; **site déployé avant** toute installation Play (App Links) : l'humain approuve le déploiement ; l'assistant met à jour `docs/auth/connexion-google.md` (empreinte) et coche la roadmap.

## Pièges connus

- Ne **jamais** déclarer l'empreinte de la clé d'envoi dans `assetlinks.json` : elle ne signe pas l'application installée.
- Ne pas déclarer l'empreinte de la clé de débogage (publique).
- Un site qui répond `200` avec `text/html` à la place de `assetlinks.json` est un fichier manquant (le Caddy du front retombe sur `index.html`).
- Les changements d'`assetlinks.json` mettent du temps à être relus par Android : vérifier avec `pm verify-app-links --re-verify`.
