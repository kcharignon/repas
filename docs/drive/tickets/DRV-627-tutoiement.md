# DRV-627 — Passer toute la voix de Repas au tutoiement

- **Dépôt** : front (`src/i18n/fr.ts`, `drive-agent/src/ui/strings.ts`, `android/`) ; le volet back (e-mails) est le ticket [DRV-630](DRV-630-emails-du-back-au-tutoiement.md)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M (balayage mécanique, volumineux)
- **Dépend de** : DRV-628 (pour ne réécrire qu'une fois les textes restants)
- **Statut** : fait (2026-10-19, PR repas-front#150, agent 0.23.0, empreinte `sha384-nojm3/tAq3Q2EVQbTsvHEmLkhXgrUtze9sL4fe/TC2GkC88kVsnKnZ2Sq+0aj0Ge`) ; décision de l'utilisateur du 2026-10-19 : « Je préfère le tutoiement »
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (§ 3), skills `repas-po`, `repas-front-dev`, `repas-agent-dev`, `repas-android-dev`.

## Contexte

DRV-621 avait fixé le vouvoiement (décision réversible du PO). L'utilisateur préfère le tutoiement : la décision s'inverse. Toute l'application Repas, pas seulement le drive, parle de la même voix.

## Portée

1. Tous les textes visibles : `fr.ts` (web), `strings.ts` (agent), textes de l'application Android, messages d'erreur affichés, page d'aide et conditions d'utilisation.
2. Impératifs : « Choisissez » → « Choisis », « Ouvrez » → « Ouvre », « Touchez » → « Touche ». Possessifs : « votre » → « ton / ta / tes », « vos » → « tes ». Pronom : « vous » → « tu ».
3. Le test de voix `voice-and-glossary.spec.ts` s'inverse : il interdit « vous », « votre », « vos », « -ez » à l'impératif dans les textes de Repas (sauf citations de Leclerc), et accepte le tutoiement.
4. Accord des phrases courtes : toujours 15 mots au plus.
5. À vérifier côté back : e-mails de vérification d'adresse, messages de l'API que le front affiche tels quels.
6. Les pages de Leclerc que l'agent recouvre restent au vouvoiement de Leclerc : la voix de l'agent change quand même (c'est Repas qui parle).

## Hors portée

Le glossaire (les mots ne changent pas), la structure des écrans, le protocole. `docs/drive/principes-produit.md`, `recette.md` et le skill `repas-po` sont mis à jour par le PO dans la foulée.

## Critères d'acceptation

- Aucun « vous / votre / vos » ni impératif en « -ez » dans les textes de Repas (test).
- Captures lues à 360 px : pas de texte tronqué ni de ligne qui dépasse (le tutoiement est plus court, mais les accords changent).
- Agent : version incrémentée, empreinte recalculée ; une seule version par PR.

## Definition of Done

Celle du dépôt front ; liste ancien → nouveau pour les textes les plus vus (récapitulatif, choix de produit, erreurs) dans « À relire ».

## Réalisation (2026-10-19, PR repas-front#150, agent 0.23.0)

Fusionné dans le front `main` (`97eb0e0`) ; **rien n'est publié**. Empreinte de l'agent 0.23.0 : `sha384-nojm3/tAq3Q2EVQbTsvHEmLkhXgrUtze9sL4fe/TC2GkC88kVsnKnZ2Sq+0aj0Ge` (`android/agent.js.sha384`).

- **Toute la voix de Repas** : `src/i18n/fr.ts` en entier (web, aide, conditions d'utilisation du drive, erreurs affichées), l'interface de l'agent (`drive-agent/src/ui/strings.ts`, `drive-agent/src/leclerc/errors.ts`) et les textes de l'application Android (`android/app/src/main/res/values/strings.xml`). Textes de test, e2e Playwright, scénarios de l'émulateur et README alignés ; `CLAUDE.md` et les skills du front (`repas-po`, `repas-front-dev`) disent « tutoiement ».
- **Test de voix inversé** : `voice-and-glossary.spec.ts` interdit « vous », « votre », « vos », « vôtre » et les impératifs en « -ez » (« ouvrez », « faites »), et accepte le tutoiement. La liste `LECLERC_QUOTES` (citations exactes de Leclerc qui pourraient garder la voix de l'enseigne) est **vide** aujourd'hui. Le glossaire et le garde-fou des mots d'alerte (`warningWords.ts`) sont **inchangés**.
- **Agent 0.23.0**, empreinte recalculée ; **le protocole ne change pas** (l'ordre de publication front puis application n'est pas contraint, mais l'APK embarque le nouvel agent).
- **Quelques phrases ont changé de forme, pas seulement de personne** : accueil de l'agent (« Pour remplir ton panier, choisis ta liste dans Repas, puis envoie-la. »), cause « site mobile » coupée en deux phrases, texte d'installation (« Android te dit peut-être que ton téléphone n'a pas le droit d'installer des applications inconnues »), et quelques tournures de l'écran « Mes produits » (« retire un produit avant d'en accepter un autre »).
- **Exemples ancien → nouveau** : « Votre liste est prête pour le drive » → « Ta liste est prête pour le drive » ; « Il vous faut : 80 g. » → « Il te faut : 80 g. » ; « Vérifiez la quantité » → « Vérifie la quantité » ; « Prêt à remplir votre panier » → « Prêt à remplir ton panier » ; « Choisissez-en un autre. » → « Choisis-en un autre. » ; « Attendez une minute, puis réessayez. » → « Attends une minute, puis réessaie. ».
- **Vérifié** : `make check` (2762 tests), `make quality`, `make android-test`, `make agent-e2e` (66), `make e2e` (235 réussis, 5 ignorés), `make android-e2e` (vert à la deuxième exécution : la première a échoué sur un scénario instable connu). Captures lues à 360 × 640, clair et sombre : aucun texte tronqué ni débordement.
- **Non fait, volontairement** : les phrases de plus de 15 mots de l'aide n'ont pas été réécrites (balayage de voix seulement).
- **Les e-mails du back sont encore au vouvoiement (non modifiés)** : `MailerEmailVerificationNotifier.php` (objet « Confirmez votre adresse email », corps texte et HTML) et `UserChecker.php` (« Votre compte est désactivé. »). Petit ticket back : [DRV-630](DRV-630-emails-du-back-au-tutoiement.md).
- **Documents du dépôt back** alignés par la PR de documentation qui suit : `recette.md` (citations d'écran), `note-vie-privee.md` (texte des conditions), `principes-produit.md`, `audit-simplicite.md`, skill `repas-po`, `CLAUDE.md`, roadmap. Les tickets DRV-621 à 628 gardent leurs textes d'origine (vouvoiement) : ce sont des faits datés.
