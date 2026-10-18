# DRV-621 — Une seule voix et un seul vocabulaire, partout

- **Dépôt** : front (`src/i18n/`, `drive-agent/`, `android/`) ; l'aide et la note de vie privée sont dans le front, leur source dans le dépôt back est mise à jour par l'assistant
- **Jalon** : M6 Simplicité d'usage (audit du 2026-10-19)
- **Taille** : M
- **Dépend de** : — (à lancer quand aucun autre agent ne touche `fr.ts`, `strings.ts` ou `strings.xml`)
- **Statut** : à faire
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (§ 3 et § 5 font foi), [`audit-simplicite.md`](../audit-simplicite.md) (F4, F5, F12, F13), skills `repas-po`, `repas-front-dev`, `repas-agent-dev`, `repas-android-dev`, `consignes-agents.md`.

## Contexte

Le même parcours parle de deux façons : « vous » dans Repas, « tu » dans l'agent et dans l'application Android ; et une même chose porte plusieurs noms (envoi / passage / plan, « J'en ai toujours » / « Déjà à la maison » / « Vous en avez déjà », préféré / secours…). Pour un proche non technique, c'est de l'hésitation à chaque écran. Décision du PO : vouvoiement partout, glossaire de `principes-produit.md` § 5.

## Portée

1. **Voix** : passer au vouvoiement tous les textes que l'utilisateur lit dans l'agent (`drive-agent/src/ui/strings.ts` : écrans de bienvenue, magasin, résumé, reprise, progression, quantité, choix, placement, fin) et dans l'application (`android/app/src/main/res/values/strings.xml`). Le diagnostic, le test automatique et les entrées de débogage (outils du mainteneur) sont **exemptés** du glossaire mais pas du vouvoiement de leurs phrases adressées à l'utilisateur. Corriger aussi l'en-tête de commentaire de `strings.ts` (« tutoiement »).
2. **Glossaire** : appliquer le tableau de `principes-produit.md` § 5 dans `src/i18n/fr.ts` (clés `shoppingList`, `driveProducts`, `errors`, `driveSend`, `driveReturn`, `driveSettings`, `driveAccount`, `driveHelp`, `drivePrivacy`, `driveEstimate`), dans `strings.ts` et dans `strings.xml`. Pas de changement de structure d'écran (c'est DRV-622 à 625) : on ne change que les mots.
3. **Nom de l'écran** : le titre de la page de l'onglet « Moi » devient « Moi » ; tous les renvois (« dans Mon compte… ») disent « Moi ».
4. **Menu de l'application** : « Se déconnecter de Leclerc et effacer les données du drive » devient « Se déconnecter de Leclerc » (la fenêtre de confirmation, elle, détaille ce qui est effacé) ; vérifier que « Diagnostic de l'agent », « Plan d'essai (débogage) » et « Test automatique (débogage) » n'apparaissent que dans les variantes de débogage, pas dans l'APK signé ; « Ouvrir dans le navigateur » reste (utile au dépannage) mais en dernier.
5. **Le test qui garde le glossaire** : un test (Vitest ou script du `make check`, au choix de l'agent) lit les trois sources de textes et échoue si un mot interdit du tableau réapparaît (liste des mots interdits écrite dans le test, avec la référence à `principes-produit.md`) ou si un pronom de tutoiement (`tu`, `ton`, `ta`, `tes`, `toi`) ou une forme impérative en « -e / -s » à la 2e personne du singulier apparaît dans les textes visibles. Les exemptions (diagnostic, test automatique, débogage) sont nommées dans le test.
6. Mettre à jour les textes que d'autres fichiers du front citent : tests Vitest, Playwright, `android/emulator/e2e.sh`, `drive-agent/fake-leclerc/`, aide (`driveHelp`), note de vie privée (`drivePrivacy`).
7. Version de l'agent : incrémenter la mineure, `make agent-verify`, `make android-agent-hash`.

## Hors portée

Toute autre modification de comportement, d'écran ou de message d'erreur (DRV-622) ; le contenu technique de l'aide.

## Critères d'acceptation

- Aucun « tu / ton / ta / tes » dans les textes visibles du web, de l'agent (hors outils du mainteneur) et de l'application.
- Aucun mot interdit du glossaire dans les textes visibles : le test le prouve.
- L'onglet et la page s'appellent « Moi » ; aucun renvoi à « Mon compte ».
- Les parcours e2e (web, émulateur) passent avec les nouveaux libellés.

## Tests exigés

Le test de glossaire (§ Portée 5), les tests unitaires qui comparaient des libellés, l'e2e web et `make android-e2e`.

## Definition of Done

Celle du dépôt front ; PR concise avec, dans « À relire », la liste des mots remplacés ; fusion seulement si tous les contrôles sont verts. **Après la fusion, l'assistant met à jour `recette.md`** (noms de boutons), `note-vie-privee.md` (vouvoiement) et `runbook.md` si besoin.

## Pièges connus

- Les libellés sont aussi des **sélecteurs** dans `android/emulator/e2e.sh`, `drive-agent/fake-leclerc/` et les tests Playwright : les chercher par `grep` avant de renommer.
- Les textes de l'agent sont figés par l'empreinte (`agent.js.sha384`) : toujours recalculer.
- Ne pas toucher aux clés techniques (`HAVE_AT_HOME`, `CONTRACT_CHANGED`…) ni au protocole : seuls les libellés changent.
