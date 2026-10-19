# DRV-623 — Un seul geste pour enregistrer son magasin

- **Dépôt** : front (`drive-agent/src/flow/setup.ts`, `drive-agent/src/ui/`, `src/features/drive/DriveSettingsView.vue`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : DRV-320, DRV-621 (mots)
- **Statut** : fait (2026-10-19, repas-front#140)
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (règles 3 et 6), [`audit-simplicite.md`](../audit-simplicite.md) (F1, § 4), [`DRV-320`](DRV-320-lecture-magasin-depuis-application.md), [`DRV-307`](DRV-307-mode-configuration.md), skills `repas-po`, `repas-front-dev`, `repas-agent-dev`.

## Contexte

Pour enregistrer son magasin, l'utilisateur touche deux boutons qui se ressemblent : « Enregistrer » dans l'écran de l'agent (« Enregistrer ce magasin dans Repas ? »), puis « Enregistrer mon magasin » dans Repas (après « Magasin …X lu dans l'application. Vérifiez, puis enregistrez. »). À la recette réelle, le deuxième geste a été oublié et rien n'était enregistré côté serveur. Règle du produit : ne pas demander deux fois la même chose.

## Portée

1. **Dans l'agent** : l'écran du mode `setup` demande une seule fois « Utiliser ce magasin ? » avec le nom du magasin ; le bouton « Utiliser ce magasin » renvoie le résultat à Repas.
2. **Dans Repas** (`DriveSettingsView.vue`) : à la réception du magasin lu, **Repas l'enregistre tout seul** (`PUT /drive/settings`) et affiche « Magasin …X enregistré. » avec un bouton « Changer de magasin ». Plus de bouton « Enregistrer mon magasin » pour un magasin lu par l'application.
3. **Changement de magasin** : si un magasin différent est déjà enregistré, Repas **demande confirmation** avant d'enregistrer (« C'est un autre magasin : vos produits retenus ne seront plus proposés. » [Changer de magasin] [Garder l'ancien]) : c'est le seul cas à deux gestes, et il est voulu.
4. **Saisie à la main** : l'adresse saisie à la main reste possible, rangée sous « Saisir l'adresse à la main » (repliée) ; elle garde son bouton « Enregistrer mon magasin ».
5. Les textes `readDone`, `readHelp`, `changeWarning` sont réécrits (glossaire, trois phrases).

## Hors portée

Le protocole (`store` du résultat `setup` est inchangé), le back (aucun changement : `PUT /drive/settings` valide déjà l'hôte), la lecture du magasin pendant un envoi.

## Critères d'acceptation

- Premier enregistrement : « Lire mon magasin dans l'application » → choisir le magasin sur Leclerc → un seul bouton « Utiliser ce magasin » → de retour dans Repas, « Magasin …X enregistré ».
- Même magasin relu : aucun écran de plus.
- Autre magasin : confirmation explicite avec l'avertissement sur les produits retenus.
- Une lecture refusée par le serveur (`DRIVE_STORE_INVALID`) affiche un message en trois phrases, rien n'est enregistré.

## Tests exigés

Composant (premier enregistrement automatique, même magasin, autre magasin, erreur du serveur), test de l'agent (écran unique), e2e émulateur (parcours `setup`), captures lues.

## Definition of Done

Celle du dépôt front, avec les touches avant / après dans la PR. Incrémenter la version de l'agent et recalculer l'empreinte.

## Pièges connus

- Le résultat vient de la page de Leclerc (même monde JavaScript que l'agent) : il peut être falsifié. Ce qu'il peut produire de pire : enregistrer un autre magasin **valide** (l'hôte est vérifié par le back comme à la saisie à la main), ce que l'utilisateur voit dans « Moi » et corrige (décision du PO, `audit-simplicite.md` § 4). Ne jamais enregistrer sans passer par le même `PUT /drive/settings`.
- Ne pas enregistrer deux fois si la page reçoit deux fois le même résultat (le message de l'application peut être rejoué au rechargement).

## Réalisation (2026-10-19, PR repas-front#140, agent 0.17.0)

Fait avec le point 4 de [DRV-622](DRV-622-ecrans-envoi-et-recapitulatif-simples.md) (magasin manquant = bouton), qui lui a été confié.

- **Touches du premier enregistrement** (hors gestes sur Leclerc, inchangés) : depuis les réglages, 3 avant (« Lire mon magasin », « Enregistrer » dans l'agent, « Enregistrer mon magasin ») contre **2** après (« Lire mon magasin », « Utiliser ce magasin ») ; depuis une liste sans magasin, jusqu'à la confirmation de l'envoi : 8 avant (Envoyer au drive, Lire, Enregistrer, Enregistrer mon magasin, Moi, Liste, Envoyer au drive, Confirmer) contre **4** après (Envoyer au drive, Choisir mon magasin, Utiliser ce magasin, Confirmer).
- **Agent** : un seul écran « Utiliser ce magasin ? » (le numéro ou le nom du magasin en gras, « Repas le retiendra pour vos envois. Seul le magasin est transmis : rien de votre compte Leclerc ne quitte cette page. »), bouton « Utiliser ce magasin », « Annuler » discret. Le protocole ne change pas : un ancien agent affiche encore « Enregistrer » et Repas enregistre quand même tout seul ; aucun ordre de publication à respecter.
- **Repas** : le magasin lu est enregistré tout de suite par `PUT /drive/settings` (même validation d'hôte qu'à la saisie à la main) et la page dit « Magasin …X enregistré. » avec « Changer de magasin » ; un résultat reçu deux fois ne fait qu'un seul `PUT` (test avec le vrai pont). Autre magasin déjà enregistré : confirmation « Utiliser le magasin …X ? » avec l'avertissement sur les produits habituels, boutons « Changer de magasin » et « Garder l'ancien » (`cancelLabel` ajouté à `UiConfirmDialog`). Saisie à la main sous « Saisir l'adresse à la main » (bouton « Enregistrer mon magasin » inchangé) ; hors de l'application elle reste dépliée (sans bouton de lecture, la replier ajouterait une touche), et elle s'ouvre toute seule quand la lecture échoue. Les cinq erreurs de lecture et le refus du serveur (`DRIVE_STORE_INVALID`) sont en trois phrases avec « Rien n'est enregistré. ».
- **Magasin manquant** (point 4 de DRV-622) : « Envoyer au drive » sans magasin menait à `/drive/reglages?magasin=requis` (message sans bouton, pas de retour à la liste). Désormais l'écran « Choisissez votre magasin » ne garde que le bouton « Choisir mon magasin » (lecture directe), puis revient à la liste (`/liste?envoyer=1`, qui rouvre la confirmation d'envoi) ou à l'écran de l'envoi d'une liste déjà envoyée. `errors.driveNotConfigured` reste pour le `409` brut du serveur.
- **Libellés ancien → nouveau** : écran de l'agent « Enregistrer ce magasin dans Repas ? » → « Utiliser ce magasin ? » ; bouton « Enregistrer » → « Utiliser ce magasin » ; `readHelp` → « L'application ouvre Leclerc. Choisissez votre magasin, puis touchez « Utiliser ce magasin ». » ; `readDone` « Magasin …X lu dans l'application. Vérifiez, puis enregistrez. » → « Magasin …X enregistré. » ; `changeWarning` → « Ce n'est pas votre magasin actuel. Les produits habituels de l'ancien magasin ne seront plus proposés. Vous les choisirez de nouveau. » ; nouveaux : « Choisissez votre magasin », « Choisir mon magasin », « Changer de magasin », « Garder l'ancien », « Saisir l'adresse à la main », lien de retour « Retour à la liste » ; supprimé : `storeRequired`.
- **Vérifié** : `make check` (2538 tests), `make quality`, `make agent-e2e` (53), `make android-test`, Playwright web, `make android-e2e` (139 contrôles, au second passage : le scénario 12, instable, a échoué trois contrôles au premier sans lien avec le magasin), captures à 360 px en clair et en sombre lues.
