# DRV-501 — Intégration en développement et checklist de recette

- **Dépôt** : back + front
- **Jalon** : M5 Intégration et livraison
- **Taille** : M
- **Dépend de** : M2, M3, M4 terminés
- **Statut** : en cours (partie back faite, PR #156, 2026-10-18 ; partie front faite, repas-front#106 ; `recette.md` écrite le 2026-10-18 ; reste le parcours réel dans l'émulateur (DRV-319 partie 2) et `BASKET_PATH`, relevé à la recette)
- **Lire d'abord** : tout `docs/drive/` ; `docs/production.md` ; `make start` du back et `make up` du front (voir `CLAUDE.md`).

## Portée

1. Faire tourner ensemble, en développement, le back (avec `DRIVE_ENABLED=true`), le front, l'agent construit et le faux site Leclerc : un parcours complet du bouton au récapitulatif, avec un magasin enregistré par le mode `setup`.
2. Corriger les écarts de contrat entre les trois morceaux (formats, codes d'erreur, clés).
3. Écrire `docs/drive/recette.md` : checklist de recette sur le téléphone réel (pas à pas pour l'utilisateur) couvrant : installation du favori, enregistrement du magasin, première liste (tous les ingrédients nouveaux), deuxième liste (produits connus), « autre produit », « passer », « j'en ai toujours », produit indisponible, perte de réseau, blocage, liste rouverte, liste terminée, suppression du compte (purge).
4. Vérifier les budgets SQL, la couverture, Deptrac, PHPStan, l'absence de secret.
5. Mettre à jour `docs/drive/roadmap.md`.

## Critères d'acceptation

Un agent d'intégration déroule `recette.md` en développement sans écart non expliqué ; tous les contrôles qualité sont verts dans les deux dépôts.

## À vérifier à l'intégration (relevé par les agents du front, 2026-10-18)

- Fixer `BASKET_PATH` (`src/features/drive/leclerc.ts`, vide pour l'instant : page d'accueil du magasin) avec l'adresse de la page panier relevée à la recette ; confirmer le lien de recherche du mode assisté `${store.baseUrl}/recherche.aspx?TexteRecherche=…`.
- Vérifier côté application que `drive.result` arrive dans la page Repas au retour de `DriveActivity` (`useDriveReturn` écoute `repasNative` dès le démarrage et mène à `/drive/retour`) et qu'un passage abandonné renvoie un résultat `aborted`.
- Un vrai `PUT /drive/choices` avec identifiants et prix réels (bornes du back : contenu 0,001 à 100 000, prix 0 à 1 000 000 centimes, `retailerProductId` chaîne de 1 à 12 chiffres).
- `needed.unitSymbol` vide : le front omet `needed` pour la ligne (l'agent demande la quantité) ; le back renverra désormais le nom de l'unité à défaut de symbole (partie back de DRV-501) : retirer alors la rustine du front.
- Un `setup-result` (lecture du magasin) arrive dans `useDriveReturnStore().event` ; il manque un `startRun` pour un message `setup` dans le pont (DRV-403/314 : message `drive.setup`).
- Après un rechargement, les choix « autre produit » et « passer » ne sont pas gardés (le brouillon n'a volontairement aucune donnée personnelle).
- Le récapitulatif du retour est en mémoire seulement : une page rechargée avant l'enregistrement le perd, le brouillon reste.

## Réalisation partie back (2026-10-18, PR repas#156)

Objectif : que le front vérifie que le back et l'agent parlent la même langue **sans lancer toute la pile**.

- **Exemples versionnés** dans `docs/drive/samples/` : `plan.sample.json` (`GET /drive/plans/{id}`), `choices.sample.json` (`GET /drive/choices?retailer=leclerc`), `estimate.sample.json` (`GET /drive/estimates/{id}`), `settings.sample.json` (`GET /drive/settings`). Une même liste envoyée de 5 lignes triées par slug : `farine` (`KNOWN`, produit préféré avec EAN + produit de secours, `ESTIMATED`), `lait` (`KNOWN`, un produit sans prix, un refusé, `NO_PRICE`), `oeuf` (`NEW`, unité « unité » sans symbole, `NO_PRODUCT`), `sel` (`KNOWN`, cuillère à soupe sans dimension : `packs: null` / `TO_CHECK`), `sucre` (`HAVE_AT_HOME`). Valeurs inventées : magasin `123456` / `Ville-Test` / hôte `exemple.leclercdrive.fr`, produits `5551xx`, EAN `2000000000008`, utilisateur et liste en UUID fixes, horloge figée au 2026-10-18 (`generatedAt` 10:30 UTC).
- **Concordance** : `tests/Drive/Contract/DriveContractSamplesTest` (base de test, vrais bus) rejoue le parcours (réglage du magasin, `POST /send`, `PUT /drive/choices` avec prix, `REFRESH_PRICE`) puis compare chaque réponse à son exemple en aplatissant les deux en lignes `chemin: type valeur` (clés, ordre des clés et des lignes, types, valeurs ; entier et décimal sont tous deux des « nombres »). Il échoue, avec le chemin exact de l'écart, quand un DTO change sans que l'exemple soit régénéré. **Régénérer** : `make drive-samples` (réécrit les quatre fichiers depuis les réponses réelles, puis relire le diff : c'est le contrat que le front fige).
- **Unité sans symbole** (écart de contrat corrigé) : le plan renvoyait `needed.unitSymbol: ""` pour l'unité « unité » (les œufs), que `plan.schema.json` refuse (`minLength: 1`, `maxLength: 32`). Le plan renvoie désormais, à défaut de symbole (vide ou espaces), **le nom de l'unité** (« unité »), puis son slug, coupé à 32 caractères. Le port `Shared` `ShoppingListLineForDrive` gagne `unitName` ; la règle est dans `DrivePlanPostgreSQLLoader::unitLabel()` (test `testTheUnitIsNamedByItsNameWhenItHasNoSymbol`). La clé reste `unitSymbol` (le front fige le contrat) ; `GET /shopping-lists/{id}` n'est pas touché. Note dans `architecture.md` § 7.
- **Comment le front les utilise** : l'agent d'intégration du front lit `plan.sample.json` (copie ou lecture depuis le dépôt du back, jamais depuis un site), le traduit par `buildPlanMessage` (`src/features/drive/buildPlanMessage.ts` : `retailerProductId` devient `id`, `ingredientName` devient `name`; `needed` n'est gardé que si son symbole est utilisable, ce que l'unité « unité » devient avec ce correctif) et **valide le message obtenu contre `drive-agent/protocol/plan.schema.json`** (test Vitest à écrire côté front : un champ renommé ou retiré dans le DTO du back fait échouer ce test quand l'exemple est régénéré). Les trois autres exemples servent de réponses simulées aux tests des écrans (réglages, « Mes produits », estimation) et à vérifier le client OpenAPI généré.
- **Rustine du front à retirer** : `neededOf` de `buildPlanMessage.ts` omet `needed` quand `unitSymbol` est vide ; le plan ne le renvoie plus vide, le test de concordance du front doit donc voir `needed` conservé pour les œufs (`unité`).
- **Reste à faire pour fermer DRV-501** : le parcours bout en bout en développement avec le faux site Leclerc dans l'émulateur Android (DRV-319 partie 2), `BASKET_PATH` (adresse de la page panier, relevée à l'étape 8.4 de la recette), les contrôles qualité des deux dépôts. La case n'est pas cochée.

## Réalisation `recette.md` (2026-10-18)

[`../recette.md`](../recette.md) est écrit : 19 parties à dérouler par l'utilisateur sur son téléphone, chaque étape avec son but, son geste, son résultat attendu et que faire si ça diffère. Elle suit le véhicule réel (application Android « Repas », pré-version de test `android-dev-…` tant que l'APK signé n'existe pas, DRV-315) et non le favori du plan initial : prérequis (interrupteur coupé), installation, allumage par l'humain, lecture du magasin (DRV-320, fait ; la page de choix du magasin du vrai site reste à essayer), premier envoi (tout est nouveau, phase B), deuxième envoi (phase A puis B, changement de produit et rôle), panier Leclerc, « Mes produits », estimation du coût (seuil de 50 %), verrou, « Rouvrir » interrupteur allumé puis coupé, « Terminer », diagnostic et test automatique, points de vie privée, essais de robustesse facultatifs, blocage DataDome, retour arrière, critères de réussite, mesures et tableau de signature. Le favori, la suppression du compte (prouvée par `UserOwnedDataCoverageTest`) et le parcours dans l'émulateur n'en font pas partie. Écarts avec le texte du ticket : « installation du favori » devient l'installation de l'application ; « liste terminée » et « liste rouverte » sont couverts par la partie 10 ; la perte de réseau et le blocage sont les parties 13 et 14.
