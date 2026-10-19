# DRV-624 — Choisir un produit sans jargon, avec « passer les suivants »

- **Dépôt** : front (`drive-agent/src/ui/`, `drive-agent/src/flow/phase-b.ts`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-306, DRV-208, DRV-621
- **Statut** : fait (2026-10-19, repas-front#142)
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md), [`audit-simplicite.md`](../audit-simplicite.md) (F7, F8), [`DRV-306`](DRV-306-choix-guide-phase-b.md), [`DRV-208`](DRV-208-remplacement-preference.md), skills `repas-po`, `repas-agent-dev`.

## Contexte

Le premier envoi demande un choix par nouvel article : c'est l'effort principal de l'utilisateur. Deux frictions : (1) quand le produit choisi n'est pas celui que Repas retient déjà, une fenêtre propose trois options (« Mon nouveau produit préféré », « Produit de secours », « Seulement cette fois ») qui exposent l'ordre de préférence ; (2) aucun moyen de dire « pas cette fois pour tout le reste » quand on est pressé.

## Portée

1. **Fenêtre de remplacement** (`placement`) : deux choix seulement, en boutons : « Toujours prendre celui-ci » (par défaut, premier bouton) et « Cette fois seulement » ; plus de « produit de secours » à l'écran. Le serveur garde son ordre de préférence : « Toujours prendre celui-ci » place le nouveau produit en tête et l'ancien le suit en remplacement (comportement de DRV-208, à vérifier et à décrire dans la PR). Le protocole v1 ne change pas ; l'agent cesse seulement d'émettre le placement « secours » (le décodeur du front l'accepte toujours).
2. **Écran de choix** (`chooser`) : un bouton « Passer les {n} suivants » (visible dès qu'il reste plus d'un article à choisir) qui traite tous les articles restants comme « Pas cette fois » ; il demande une confirmation d'une ligne si au moins un article restant est déjà « à choisir » pour la première fois (rien n'est retenu pour eux). Les boutons existants (« Choisir », « Pas cette fois », « J'en ai toujours », « Annuler l'envoi ») sont reclassés : « Choisir » en principal, « Pas cette fois » et « J'en ai toujours » en secondaires, « Annuler l'envoi » discret en bas.
3. **Aucun choix demandé pour ce que Repas sait déjà** : vérifier qu'un article dont le produit retenu est disponible n'ouvre jamais l'écran de choix (la phase A le traite) ; décrire le résultat dans la PR.
4. Textes selon `principes-produit.md` (« Il vous faut : 800 g. », « 500 g par produit : 2 produits. »), en vouvoiement.

## Hors portée

Les suggestions automatiques (DRV-601), toute modification du back ou du protocole.

## Critères d'acceptation

- Remplacer un produit retenu pose **une** question à deux boutons, sans mot « secours » ni « préféré ».
- Un envoi de 4 nouveaux articles peut se terminer en 3 touches (choisir le premier, « Passer les 3 suivants », confirmer en une ligne), et en 2 quand aucun article passé n’est un premier choix (décision du PO du 2026-10-19 : la confirmation reste, voir « Réalisation »).
- Le serveur reçoit les mêmes messages qu'avant pour « Toujours » et « Cette fois seulement ».

## Tests exigés

Tests de l'agent (jsdom et faux site) : fenêtre à deux boutons, « passer les suivants » avec et sans confirmation, absence de l'écran de choix pour un produit retenu disponible ; e2e émulateur du parcours de la phase B ; captures de l'agent lues.

## Definition of Done

Celle du dépôt front ; version de l'agent incrémentée, empreinte recalculée ; touches avant / après dans la PR.

## Pièges connus

- « Passer les suivants » ne doit rien mémoriser (ni `HAVE_AT_HOME`, ni rejet) : c'est « Pas cette fois ».
- Le focus clavier et l'annonce `aria-live` du compteur (« 2 sur 4 ») doivent suivre la nouvelle disposition.

## Réalisation (2026-10-19, PR repas-front#142, agent 0.19.0)

Les quatre points de la portée. Le protocole ne change pas : aucun ordre de publication particulier entre le front et l'application.

- **Touches, premier envoi avec 4 nouveaux articles** : 4 avant (choisir le premier, puis « Pas cette fois » ×3), **3 après** (choisir, « Passer les 3 suivants », confirmation d'une ligne), et 2 quand aucun article passé n'est un premier choix (produit habituel indisponible, ou remplacement voulu). Choisir les 4 : 4 touches, inchangé. Remplacer un produit retenu : 2 touches avant et après (choisir + une réponse), mais **2 réponses possibles au lieu de 3**.
- **Fenêtre de remplacement** : « Toujours prendre ce produit ? » (« Vous avez choisi « X » pour Y. »), deux boutons, « Toujours prendre celui-ci » (par défaut, premier) et « Cette fois seulement » ; « Revenir à la liste » reste en lien discret. Plus de « produit de remplacement » à l'écran, ni d'aides sous les boutons.
- **Écran de choix** : « Passer les n suivants » apparaît dès qu'il reste plus d'un article, avec « Pas cette fois » et « J'en ai toujours » (devenus secondaires) ; `n` compte l'article affiché (au rang 2 sur 4, « Passer les 3 suivants » passe les articles 2, 3 et 4, ce qui permet « choisir le premier, passer les 3 suivants »). Confirmation d'une ligne, seulement quand un des articles passés n'a encore aucun produit retenu : « Ces n articles ne seront pas mis au panier. » avec « Passer les n suivants » et « Revenir au choix ». « Annuler l'envoi » devient un lien discret en bas ; « Choisir » est le seul bouton principal. Les « Ne plus proposer » cochés sur l'article affiché sont gardés ; sans coche, chaque article passé part en `SKIPPED` pur (ni « j'en ai toujours », ni refus).
- **Textes de la liste de produits** : « Il vous faut : 800 g. » (inchangé) ; « 500 g par produit : 2 produits. » (avant : « 800 g demandés, 500 g par produit : 2 ») ; « À mettre au panier : 2 produits. » (avant : « Demandé : 2 paquet. À mettre au panier : 2. »).
- **Comportement du serveur** (lu, non modifié) : « Toujours prendre celui-ci » envoie `placement: PRIMARY` ; `SaveProductChoicesHandler` appelle `Choice::addPrimary` : le nouveau produit prend le rang 1, les anciens descendent d'un rang, en remplacement. « Cette fois seulement » (`ONCE`) n'envoie rien. Cas limite existant, non traité : à 5 produits acceptés, un sixième donne `422 DRIVE_CHOICE_INVALID` pour tout le lot de `PUT /drive/choices`.
- **Un produit retenu et disponible n'ouvre jamais l'écran de choix** : seules les lignes en attente y vont (nouveaux articles, ou plus aucun produit retenu disponible). Seule exception, voulue : « Choisir un autre produit » demandé sur l'écran de l'envoi. Prouvé par 3 tests unitaires et 1 test sur le faux site.
- **Décision du PO : la confirmation reste.** Le critère « 2 touches pour 4 nouveaux articles » et la portée (confirmer quand un article passé n'a rien de retenu) se contredisaient ; l'agent a suivi la confirmation. Passer des articles jamais choisis les retire de la commande sans qu'on s'en aperçoive avant le retrait des courses, et un toucher de travers sur un bouton voisin le ferait : une ligne coûte un toucher de plus, seulement dans ce cas. Pour la retirer, une seule ligne : `confirmRest: left.some(isFirstChoice)` dans `drive-agent/src/flow/phase-b.ts`. Réversible.
- **Accessibilité** : un compteur placé dans un `span role=status` n'est pas exposé par la WebView d'Android ; le compteur est donc du texte de la ligne de l'article (« Poivre (2 sur 4) »), qui reçoit le focus à l'ouverture. Vérifié dans l'émulateur. Correction annexe : Tab ignore les parties cachées (`hidden`) ; sans cela la confirmation créait un piège clavier (le rapport du diagnostic avait le même défaut latent).
- **Vérifié** : `make check` (2615 tests), `make agent-verify` (construction reproductible), `make agent-e2e` (59), `e2e/agent-ui.spec.ts` (axe clair et sombre, 360 × 640, cibles de 44 px), `make android-test`, `make android-e2e` (nouveau scénario 13 : 4 nouveaux articles, choisir puis passer les 3 suivants ; le Bilan devient le 14 ; 14 scénarios, 161 contrôles, au second passage : l'émulateur était très lent au premier). Captures lues (écran de choix et confirmation, 360 × 640, clair et sombre, et dans la WebView de l'émulateur). Empreinte de l'agent 0.19.0 : `sha384-091F1hfjunxAz11f4yRSfVtSoxgE9OdF24BzPdubYOJeafDNSs8ifD77c16e2viB` (`android/agent.js.sha384`).

### Points laissés

- Le bouton « Valider » de l'écran de quantité n'est pas touché ; la règle des verbes précis voudrait « Mettre au panier » : à voir dans un ticket de balayage.
- ~~Si « suivants » prête à confusion à l'essai d'un proche, « restants » ou « derniers » sont des alternatives (une chaîne).~~ Tranché par la revue de parcours : « Passer ces n articles » (DRV-626, point 2), parce que « Passer les 4 suivants » laissait croire que l'article affiché n'était pas passé.
