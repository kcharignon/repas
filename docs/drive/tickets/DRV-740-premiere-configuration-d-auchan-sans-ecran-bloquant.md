# DRV-740 — Première configuration d'Auchan : plus d'écran d'erreur bloquant pendant le choix du magasin

- **Dépôt** : front (`drive-agent/src/flow/`, `drive-agent/src/ui/`, `drive-agent/src/auchan/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-717, DRV-738
- **Statut** : fait (PR front #270, agent 0.51.4, 2026-10-30) ; l'application 1.2.1 embarque le nouvel agent.
- **Lire d'abord** : [`../hypotheses-site-reel-auchan.md`](../hypotheses-site-reel-auchan.md) A-1, A-4b, A-4c ; [`DRV-717`](DRV-717-adaptateur-auchan-magasin-et-connexion.md) (le magasin se lit au site) ; [`../principes-produit.md`](../principes-produit.md).

## Contexte

Défaut constaté en production le 2026-10-30 (application publiée 1.2.0). Dans « Moi → Réglages du drive », la personne touche « Choisir mon magasin Auchan » : l'application ouvre Auchan et, **tout de suite**, l'agent affiche un écran qui masque toute la page (« Choisis ton magasin », « Aucun magasin n'est choisi sur Auchan », bouton « Retourner dans Repas »). Elle est justement en train de choisir son magasin et ne peut rien faire : la première configuration d'Auchan est impossible.

Cause : chez Leclerc le magasin est dans l'adresse, et l'application n'envoie `setup` que lorsque l'adresse est celle d'un magasin (la personne l'a donc déjà choisi). Chez Auchan l'adresse ne change jamais (A-1) : l'application envoie `setup` dès l'ouverture, avant le choix, et l'agent traitait « aucun magasin choisi » (`STORE_NOT_CHOSEN`) comme une erreur.

## Portée

1. Une enseigne dont **le magasin se choisit sur la page** le dit par une méthode facultative de son adaptateur (`storeChosenOnPage()`, une lecture de la barre du magasin, aucune requête). Pas de test sur le nom d'une enseigne : Leclerc n'a pas cette méthode, son comportement ne change pas (mêmes écrans, mêmes messages).
2. En mode `setup`, quand la lecture du magasin dit `STORE_NOT_CHOSEN` **et** que la page ne dit pas non plus qu'un magasin est choisi : **pas d'écran d'erreur**. Un bandeau en bas de l'écran (« Choisis ton magasin sur Auchan. Repas le reprend ensuite. », bouton « Retourner dans Repas ») ; la page reste visible et touchable.
3. L'agent attend **sans fatiguer le site** : il lit la barre du magasin toutes les 2 secondes (le DOM seul, **aucune requête** tant que la barre dit « Choisir vos courses »). Quand elle dit qu'un magasin est choisi, **une seule** lecture du parcours (à la cadence), puis l'écran habituel « Utiliser ce magasin ? ».
4. Durée maximale de 15 minutes : le bandeau dit alors « Je ne vois pas de magasin choisi. » avec « Réessayer » (on regarde de nouveau, puis une nouvelle attente si besoin) et « Retourner dans Repas ». « Retourner dans Repas » rend un résultat en erreur (`UNSUPPORTED_PAGE`, cause `STORE_NOT_CHOSEN`), comme avant.
5. Les vrais échecs restent des écrans d'erreur : réseau, blocage, site changé, hôte différent, et « la barre dit qu'un magasin est choisi mais le site n'en voit pas ».
6. **Un envoi réel (plan) sans magasin choisi reste un arrêt avec son message** : la correction ne touche que `setup`.

Aucun changement Kotlin : l'application envoie `setup` à chaque page de l'enseigne qui a le pont (chez Auchan, aucune « page de magasin » à reconnaître) ; si la page est rechargée par le site après le choix, le nouvel agent reçoit le message et lit le magasin.

## Critères d'acceptation

- Tests unitaires du gestionnaire : attente puis proposition du magasin, une seule lecture ; aucune requête pendant l'attente ; arrêt à la durée maximale ; « Réessayer » ; « Retourner dans Repas » ; Leclerc (adaptateur sans la méthode) : écran d'erreur tout de suite, comme avant.
- Scénario de bout en bout de l'agent contre le faux Auchan : magasin d'abord absent, page utilisable, puis choisi en cours de page, « Utiliser ce magasin ? ».
- Scénario d'émulateur 21 (DRV-731) : sans magasin choisi, la configuration depuis Repas affiche le bandeau, sans écran bloquant ; la preuve que le pont n'existe pas sur `compte.auchan.fr` est inchangée.

## Points laissés

- Non mesuré : si le choix du magasin sur le vrai site recharge la page ou la met à jour en place. Les deux cas sont servis (l'agent suit la barre ; une page rechargée reçoit de nouveau le message `setup`). À regarder à la recette réelle.
- Le délai entre la mise à jour de la barre et celle du parcours côté site n'est pas mesuré : si la barre dit « Retrait : … » avant que `GET /journey` ne voie le magasin, l'agent affiche l'écran d'erreur ; « Retourner dans Repas » puis un nouvel essai règle le cas.
