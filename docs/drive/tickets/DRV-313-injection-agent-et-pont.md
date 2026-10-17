# DRV-313 — Injection de l'agent et pont de messages

- **Dépôt** : front (`kcharignon/repas-front`), dossiers `android/` et `drive-agent/`
- **Jalon** : M3b Application Android
- **Taille** : L
- **Dépend de** : DRV-312, DRV-301, DRV-302
- **Statut** : à faire
- **Lire d'abord** : [`../pivot-application-android.md`](../pivot-application-android.md), [`../spikes/DRV-005-decision-g1.md`](../spikes/DRV-005-decision-g1.md), [`../spikes/DRV-011-rapport.md`](../spikes/DRV-011-rapport.md), `drive-agent/android-lab/` (le spike, `PROCEDURE.md` compris), `CLAUDE.md` du front, [`../architecture.md`](../architecture.md) § 8 (protocole), DRV-301, DRV-302, DRV-304, DRV-305

## Contexte

L'agent (JavaScript, DRV-301 à DRV-308) doit tourner dans les pages Leclerc de la WebView. Le plan lui vient de l'application (et non plus d'un fragment d'adresse) et son résultat repart vers l'application. Aucun objet natif ne doit être exposé aux pages Leclerc : seul un canal de messages restreint à `*.leclercdrive.fr`.

## Portée

1. L'agent construit par DRV-301 (`agent.js`, un seul fichier, version et empreinte SHA-384) devient un **actif de l'application** : copié à la construction, empreinte vérifiée (la construction échoue si elle diffère de celle attendue).
2. Injection au chargement des documents dont l'origine est `https://*.leclercdrive.fr` par `WebViewCompat.addDocumentStartJavaScript` (liste d'origines autorisées, jamais `*`) ; l'agent s'initialise seulement sur une page de magasin.
3. Canal : `WebViewCompat.addWebMessageListener` (nom `repasHost`, origines autorisées `https://*.leclercdrive.fr`) pour les messages agent → application ; messages application → agent par `WebMessagePort` ou `postWebMessage` vers la même liste d'origines. Pas de `addJavascriptInterface`.
4. Messages portés (JSON, protocole v1 de DRV-302) : application → agent : `plan`, `setup` ; agent → application : `progress` (facultatif), `result`, `log` (texte court sans donnée de compte). L'application ne les interprète pas, sauf `result` pour la suite (DRV-314) ; elle borne leur taille (64 Ko) et refuse tout message d'une origine non autorisée.
5. Adaptation de l'agent : DRV-302 prévoit un **transport** interchangeable (fragment d'adresse / pont de l'application) ; ici le transport est le pont. L'interface de l'agent (Shadow DOM, DRV-304) s'affiche dans la page Leclerc.
6. Tant qu'aucun plan n'est présent, l'agent reste silencieux (aucune bannière sur les pages Leclerc).

## Hors portée

Réception du plan depuis Repas et retour (DRV-314).

## Critères d'acceptation

- Étant donné un plan envoyé par l'application sur une page de magasin, quand l'agent démarre, alors son récapitulatif s'affiche et son résultat est reçu par l'application.
- Étant donné un message venant d'une page hors `*.leclercdrive.fr`, quand il arrive, alors il est ignoré.
- La construction échoue si l'empreinte de `agent.js` ne correspond pas.

## Tests exigés

Tests Kotlin (bornes, origines, empreinte) ; tests JavaScript de l'adaptateur de transport de l'agent ; essai de bout en bout contre le faux site Leclerc de DRV-309 dans Chromium (hors application), puis essai réel sur téléphone par l'utilisateur.

## Definition of Done

Celle du dépôt front ; PR concise ; fusion si CI verte.

## Pièges connus

- `addDocumentStartJavaScript` demande une WebView récente (vérifier `WebViewFeature`) ; sinon, repli par `evaluateJavascript` après `onPageFinished`, sans changer la règle d'origine.
- Le défi anti-robot est un cadre d'un autre site : ne pas injecter l'agent dans ces cadres.

## Laissé par DRV-301 (2026-10-17)

`drive-agent/dist/agent.js` et `dist/manifest.json` (`sha384-…`) sont produits par `make agent-build`. Il faudra un fichier d'empreinte attendue (comme `lab.js.sha384` du spike) et un test de cohérence entre ce fichier, `manifest.json` et l'actif de l'application.
