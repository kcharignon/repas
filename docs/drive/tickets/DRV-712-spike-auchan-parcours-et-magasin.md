# DRV-712 — Spike Auchan A1 : parcours, mode drive ou livraison, magasin, site mobile ou ordinateur

- **Dépôt** : — (tâche **humaine** sur téléphone ; rapport dans le dépôt back, `docs/drive/spikes/DRV-712-rapport.md`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : — (peut se faire dès maintenant ; ne dépend d'aucun code)
- **Statut** : à faire. Pas d'effort de sous-agent : l'humain observe, un assistant relit le rapport.
- **Faisable sans téléphone** : **non, et jamais depuis un ordinateur ni un serveur** (voir ci-dessous).
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 11, 12 (inconnues U1 à U4) ; [`../spikes/DRV-002-rapport.md`](../spikes/DRV-002-rapport.md) (modèle de rapport) ; [`../recette.md`](../recette.md) (façon de procéder sur le téléphone).

## Contexte

On ne sait presque rien de sûr du parcours d'Auchan (`multi-enseignes.md` § 11.1) : le magasin se choisit apparemment par code postal et ne figure peut-être pas dans l'adresse ; plusieurs modes existent (Drive, Drive piéton, Click & Collect, livraison) et un forum dit que le stock diffère selon le mode. La forme du magasin décide de `Store`, de la lecture du magasin par l'agent et de la clé `storeId`.

## Règles de sécurité de l'essai

- **Sur le téléphone de l'utilisateur, avec son propre compte, à la main**, quelques minutes, à cadence humaine. **Jamais depuis un ordinateur, jamais depuis un serveur, jamais par un script ou un agent** : l'anti-robot de Leclerc a marqué l'adresse de l'utilisateur ; ne pas risquer de marquer aussi celle d'Auchan.
- **Ne rien commander, ne choisir aucun créneau, ne rien payer.** Ne pas vider un panier existant.
- **Rien d'identifiant dans le dépôt** : ni numéro de magasin, ni nom de ville, ni adresse complète, ni cookie, ni e-mail, ni HTML brut. Noter des **formes** (« l'adresse contient un identifiant à N chiffres après `…` »), des **noms de champs** et des **exemples inventés** (`123456`, `Ville-Test`).

## Portée (à noter dans le rapport, une ligne chacun : observé / non observé / pourquoi)

1. **Accueil** : adresse de l'accueil (domaine seulement), ce que la page demande en premier (choisir un magasin ? un mode ?), s'il y a un bouton équivalent à « Commencer mes courses ».
2. **Choix du magasin** : comment (code postal, géolocalisation, liste) ; combien de gestes ; **où le magasin choisi apparaît** (bandeau, en-tête) et sous quelle forme (libellé seulement ? un numéro ?) ; **l'adresse change-t-elle** (forme : même domaine ? sous-domaine ? chemin avec identifiant ?) ; le choix survit-il à la fermeture du navigateur et à la déconnexion ?
3. **Modes** : liste des modes proposés ; lequel convient à « remplir un panier de courses à retirer » ; le choix d'un mode change-t-il les produits, les prix ou le stock affichés ; le mode se choisit-il avant ou après le magasin ; l'envoi vers un autre mode est-il possible depuis le panier.
4. **Site mobile et site ordinateur** : le navigateur du téléphone reçoit-il un site mobile (adresse différente ? `m.` ? mise en page adaptée à la même adresse ?) ; en « version pour ordinateur » du navigateur, les adresses et les écrans changent-ils ; laquelle présente les routes de recherche et de panier ?
5. **Pages « introuvable »** : une adresse de magasin inventée ou tronquée donne-t-elle une page d'erreur avec un code 200 ? (À essayer **une seule fois**, à la main.)
6. **Connexion visible** : l'accueil montre-t-il un client connecté (nom, « Mon compte ») ? Quel mot sur le bouton de connexion ?
7. **Photos et prix** : les prix s'affichent-ils avant le choix d'un magasin (A3 dit non) ; une photo de produit vient-elle d'un autre domaine (domaine seulement) ?

## Hors portée

Les requêtes réseau et le panier (DRV-714), l'anti-robot et la connexion (DRV-713), les conditions d'utilisation (sujet clos), tout code.

## Critères d'acceptation

- Le rapport existe, chaque ligne de la portée est renseignée ou dit pourquoi non.
- Le rapport répond sans ambiguïté à U1 (où se lit le magasin), U2 (mobile ou ordinateur), U3 (modes) et U4 (entrée).
- Aucune donnée personnelle ni identifiant réel dans le rapport.

## Tests exigés

Aucun test de code. Relecture du rapport par l'assistant (liste des fuites : numéro, ville, e-mail).

## Definition of Done

Rapport fusionné dans `docs/drive/spikes/` (PR courte du back) ; roadmap cochée ; inconnues U1 à U4 mises à jour dans `multi-enseignes.md` § 11.3.

## Pièges connus

- Le site peut servir autre chose à un navigateur d'ordinateur ; ne pas généraliser d'un seul appareil.
- Un numéro de magasin peut se lire dans l'adresse : le remplacer par `123456` avant d'écrire.
- Ne pas confondre la page de choix du magasin du **drive** avec celle d'un magasin physique.
