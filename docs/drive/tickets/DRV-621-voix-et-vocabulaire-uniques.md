# DRV-621 — Une seule voix et un seul vocabulaire, partout

- **Dépôt** : front (`src/i18n/`, `drive-agent/`, `android/`) ; l'aide et la note de vie privée sont dans le front, leur source dans le dépôt back est mise à jour par l'assistant
- **Jalon** : M6 Simplicité d'usage (audit du 2026-10-19)
- **Taille** : M
- **Dépend de** : — (à lancer quand aucun autre agent ne touche `fr.ts`, `strings.ts` ou `strings.xml`)
- **Statut** : fait (2026-10-19, repas-front#139)
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

## Réalisation (2026-10-19, PR repas-front#139, agent 0.16.0)

- **Vouvoiement** dans `drive-agent/src/ui/strings.ts`, `leclerc/errors.ts` et tout `strings.xml` ; le web vouvoyait déjà. **Glossaire** appliqué à `fr.ts`, `strings.ts` et `strings.xml` (mots seulement, aucun écran restructuré). « Mon compte » devient « Moi » partout.
- **Test de glossaire** `src/i18n/__tests__/voice-and-glossary.spec.ts` (70 tests) : lit `fr.ts`, `strings.ts`, `errors.ts` (arbre syntaxique) et `strings.xml`, refuse les mots interdits de [`principes-produit.md`](../principes-produit.md) § 5 et le tutoiement (liste finie de verbes à l'impératif : la relecture reste la barrière pour un verbe nouveau). Exemptions **nommées** (glossaire seulement, jamais le vouvoiement) : diagnostic et test automatique de l'agent, étiquettes de variantes du diagnostic, messages du résultat jamais affichés tels quels ; dans `strings.xml` : `menu_diagnostic`, `menu_debug_plan`, `menu_selftest`, `agent_ready`, `result_title`, `selftest_*`, `report_copied`. Un test échoue si une exemption cite quelque chose qui n'existe plus. `android/__tests__/menu.spec.ts` (6 tests) : « Test automatique » et « Plan d'essai » n'existent qu'en débogage.
- **Ménage** : renvois au banc d'essai retirés de l'agent ; `android/PUBLICATION.md` : règle « site web avant application » quand le protocole change.
- **Libellés changés** (ancien → nouveau), repris dans `recette.md` à la fin du jalon M6 : voir le rapport de la PR ; points notables : onglet de la liste « Ingrédients · N » → « Articles · N » (une ligne à revenir en arrière si le PO le refuse) ; « Passer cette fois » → « Pas cette fois » ; « Déjà à la maison » → « J'en ai toujours » ; « Retour du drive » → « Récapitulatif » ; « Passage arrêté » → « Envoi arrêté » ; « Préféré/Secours » → « Habituel/Remplacement » ; « Ne plus jamais prendre » → « Ne plus proposer » ; « Se déconnecter de Leclerc et effacer les données du drive » → « Se déconnecter de Leclerc » ; « mode assisté » → « chercher à la main » ; « Mon compte » → « Moi ».

### Points laissés (à décider par le PO)

1. **« Diagnostic de l'agent » reste dans l'application signée** : le ticket le supposait caché, mais le runbook § 3.1 le dit « toujours disponible » et la recette § 11.1 s'en sert. Seuls « Plan d'essai » et « Test automatique » sont réservés au débogage. Le cacher (une ligne, `menu_diagnostic` sous `BuildConfig.DEBUG`) demanderait d'adapter runbook et recette : décision du PO au moment de DRV-622/624, sans urgence.
2. « agent » et « WebView » subsistent dans quelques textes d'erreur Android et dans « Diagnostic de l'agent » ; « Accepter de nouveau », « Code : {code} », « Échecs », « Prix lus » et les compteurs à zéro restent : DRV-622 et DRV-624.
3. Mise à jour du jalon : `recette.md` est réaligné **après** DRV-622 à 624 (une seule passe, plusieurs textes de la recette changent encore).
