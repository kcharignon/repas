# DRV-752 — Auchan : le panier est illisible après la connexion (`CART_NOT_JSON`)

- **Dépôt** : front (`drive-agent/`) ; la doc de l'agent est dans le back (`docs/drive/architecture.md`, `docs/drive/hypotheses-site-reel-auchan.md`)
- **Jalon** : M7 Plusieurs enseignes (constat de la recette du 2026-10-31, hors ordre)
- **Taille** : M
- **Dépend de** : DRV-724 et suivants (adaptateur Auchan), DRV-726 (recette réelle d'Auchan du 2026-10-30)
- **Statut** : à faire. Effort recommandé : `high`.
- **Lire d'abord** : `drive-agent/src/auchan/` (`cart.ts` : `readCart` ; `cart-writer.ts` : `read()` et l'écriture `/cart/update` ; `adapter.ts` : `readSession`, la reconnaissance d'une page de connexion) ; `drive-agent/src/leclerc/adapter.ts` (le cas `CART_NOT_JSON` + `looksLikeLoginPage`, ligne 1057 environ : Leclerc le traite comme `NOT_LOGGED_IN`) ; `drive-agent/src/retailers/causes.ts` et `errors.ts` ; le diagnostic de l'agent (`drive-agent/src/diagnostic/`) ; `docs/drive/hypotheses-site-reel-auchan.md` et `docs/drive/spikes/DRV-726-rapport.md` du back.

## Contexte

Recette réelle du 2026-10-31 sur Auchan (téléphone de test, application 1.3.1, agent 0.55.0, compte de test, magasin Auchan Drive choisi, **connecté** : le diagnostic dit « Connexion : connecté (signes : STATE_FLAG_ON) »). Le passage arrive aux choix des produits ; la personne choisit « Citron jaune » ; l'envoi s'arrête aussitôt : **« Auchan a changé son site, l'envoi automatique est arrêté »**, détail `CONTRACT_CHANGED (CART_NOT_JSON)`, les deux lignes « Aucun produit disponible : rien n'a été mis au panier ». La recette du 2026-10-30 (application 1.2.0) avait marché.

Le diagnostic de l'agent, lu en entier sur le téléphone : tout est OK pour la recherche (« sel » : 15 produits lus, tous les champs, contenu net 15 sur 15 ; HTTP 200, `text/html`, non redirigée), le magasin est lu, le cookie de protection anti-robot est présent (`x-datadome` dans les en-têtes de réponse), **mais aucune des sondes ne lit le panier** (« Aucune écriture au panier ») : on ne sait donc pas ce que le site répond à la lecture du panier, ni son statut, ni son type de contenu, ni sa forme. L'erreur `CART_NOT_JSON` dit seulement que la réponse n'est pas du JSON.

Causes plausibles, **aucune mesurée** : (a) la réponse est une page HTML de connexion ou de consentement (session valable pour les pages mais pas pour l'API du panier) ; (b) un défi de l'anti-robot servi avec un statut 200 ; (c) le site a changé le chemin ou le format du panier ; (d) un compte neuf sans panier existant ; (e) une régression de l'agent (0.54.0 ou 0.55.0). Ce ticket sert à **mesurer**, pas à deviner.

## À faire

1. **Rendre l'échec lisible** : toute réponse du panier non JSON (`CART_NOT_JSON`) garde, dans l'erreur et dans le diagnostic, des **sondes de forme** sans aucune valeur du site : statut HTTP, type de contenu, taille, `redirected` ou non, premiers mots reconnus parmi une liste fermée (page de connexion, page de consentement, défi de l'anti-robot, page d'erreur, JSON vide), noms des en-têtes (jamais leurs valeurs). Mêmes règles de confidentialité que le rapport actuel (ni cookie, ni identifiant, ni adresse, ni nom, ni numéro de magasin complet).
2. **Une sonde de lecture du panier dans le diagnostic** de l'agent (Auchan, et seulement une lecture : `GET`, jamais `/cart/update`), pour qu'on puisse la lancer sur le téléphone sans rien ajouter au panier, avec son résultat de forme.
3. **Classer plutôt qu'accuser le site** : une réponse non JSON qui est une page de connexion devient `NOT_LOGGED_IN` (comme Leclerc), un défi de l'anti-robot `BLOCKED`, avec le conseil adapté ; `CONTRACT_CHANGED` n'est dit que quand rien d'autre ne l'explique. Le texte « Auchan a changé son site » ne doit plus s'afficher pour un cas de connexion ou de blocage.
4. **Chercher la cause réelle dans le code** avant de coder : lire `cart-writer.ts` (`read()` : quelle requête, quels en-têtes, quel consentement `consentId`), comparer avec ce que `docs/drive/spikes/DRV-726-rapport.md` a relevé le 2026-10-30, et dire dans la PR si une régression de 0.54.0 / 0.55.0 est possible (écrire un test qui le prouve ou l'écarte, sur le faux site d'Auchan). Ne rien corriger « au hasard » : si la cause n'est pas démontrable sans le vrai site, livrer 1 à 3 et dire précisément ce que la prochaine recette doit lire.
5. Tests : faux site d'Auchan (panier : page de connexion en 200, défi, JSON vide, HTML inconnu, JSON normal), classement des causes, sonde du diagnostic en lecture seule (aucune requête d'écriture), `AGENT_VERSION` suivante, empreinte `android/agent.js.sha384`, docs de l'agent dans le même commit (les docs du back sont mises à jour à part).

## Ordre de livraison

L'agent change : **application Android** (nouvelle version embarquée). Puis une recette réelle sur le téléphone de test, Auchan : le diagnostic dit la forme de la réponse du panier ; selon ce qu'il montre, un ticket de correction suit.

## Hors périmètre

Contourner l'anti-robot ; écrire dans le panier pour tester ; toute valeur du site ou du compte dans un rapport.
