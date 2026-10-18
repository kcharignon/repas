# DRV-623 — Un seul geste pour enregistrer son magasin

- **Dépôt** : front (`drive-agent/src/flow/setup.ts`, `drive-agent/src/ui/`, `src/features/drive/DriveSettingsView.vue`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : DRV-320, DRV-621 (mots)
- **Statut** : à faire
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
