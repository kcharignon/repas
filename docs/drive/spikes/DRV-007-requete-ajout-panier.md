# DRV-007 — Rapport : la vraie requête d'ajout au panier de leclercdrive.fr

Contexte : le banc d'essai (DRV-001) envoie `POST …/panier.aspx?op=1` avec un champ `d` en JSON, comme le décrit la source tierce ([`../recherche.md`](../recherche.md)), et reçoit **HTTP 404** (page d'erreur « ce service est momentanément indisponible ») au lieu d'ajouter le produit. Il faut lire la requête que le site lui-même envoie quand on clique sur « + » pour savoir ce qui diffère.

**Ne jamais copier** `Cookie`, `Set-Cookie`, `Authorization`, ni la valeur d'un jeton, ni ton numéro de magasin (remplace-le par `[masqué]`), ni tes données personnelles.

## Procédure (ordinateur, Chrome)

1. Ouvre une page de ton magasin sur leclercdrive.fr, connecté. Ouvre F12, onglet **Réseau**, filtre **Fetch/XHR**. Coche « Conserver le journal ».
2. Sur un produit, clique sur **+** pour l'ajouter au panier (c'est une vraie action de ton panier : tu pourras le retirer ensuite avec **−**).
3. Dans la liste, clique la requête qui contient `panier.aspx`. Relève les éléments du tableau ci-dessous (onglets **En-têtes**, **Charge utile**, **Réponse**).
4. Clique sur **−** pour retirer le produit et relève de même la requête de retrait.
5. Bonus rapide, avant ou après : relance le banc d'essai (essai 3), coche « avec objet d'analytique vide », et note le code HTTP.

## Relevé

| Élément | Ajout (+) | Retrait (−) |
|---|---|---|
| Méthode et adresse complète de la requête (numéro de magasin remplacé par `[masqué]`) | | |
| Code HTTP | | |
| `Content-Type` de la requête | | |
| `X-Requested-With` et autres en-têtes `X-…` ou propres au site (noms seulement, pas les valeurs de jeton) | | |
| `Referer` (chemin seulement) | | |
| Corps : nom du ou des champs de formulaire | | |
| Corps : contenu du champ `d` décodé (JSON ; numéro de magasin remplacé par `[masqué]`) | | |
| Réponse : début (300 premiers caractères, sans total ni donnée personnelle) | | |
| Le banc d'essai avec « objet d'analytique vide » coché : code HTTP | | |

## Conclusion (pour DRV-005)

- Différence trouvée entre la requête du banc et celle du site :
- L'ajout par script est possible avec la requête relevée : oui / non / à tester
- Présence d'un jeton de page ou d'un en-tête à reproduire (nom seulement) :
