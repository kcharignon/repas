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

Première passe du 2026-10-17 (ordinateur, Chrome 155, hôte `fd7`, produit « Farine de blé T45 Tablier Blanc », identifiant 1955). **Le contenu du champ `d` envoyé par le site n'a pas encore été relevé** (l'onglet « Charge utile » de la *requête* n'a pas été copié ; ce qui a été collé comme « payload » est la *réponse*). Reste à faire : copier ce champ.

| Élément | Ajout (+) | Retrait (−) |
|---|---|---|
| Méthode et adresse complète | `POST /magasin-[masqué]/panier.aspx?op=1` (même hôte `fd7`) | identique |
| Code HTTP | 200 | 200 |
| `Content-Type` de la requête | `application/x-www-form-urlencoded; charset=UTF-8` | identique |
| `Content-Length` du corps | **317** octets | **124** octets |
| En-têtes propres au site | `X-Requested-With: XMLHttpRequest`, `Origin` (même hôte), `Sec-Fetch-Site: same-origin`, `Accept: */*`. Aucun en-tête personnalisé `X-…` autre que celui-là. | identique |
| `Referer` (chemin seulement) | `/magasin-[masqué]/recherche.aspx?TexteRecherche=farine` | identique |
| Corps : champ(s) de formulaire | non relevé (très probablement `d`) | non relevé |
| Corps : contenu du champ `d` décodé | **non relevé** | **non relevé** |
| Réponse | JSON, tableau de 4 évènements (`Content-Encoding: gzip`, 2 243 octets) : produit (`eTypeEvenement` 101), rayon (501), panier (1) et paniers complets (4) | idem avec 102 et 502 ; 2 220 octets |
| Le banc d'essai avec « objet d'analytique vide » coché : code HTTP | non essayé | — |

## Ce que la réponse apprend (déjà exploitable)

- **Chaque écriture renvoie l'état complet du panier** : `lstProduitsLight` (par produit : `iIdProduit`, `iQtePanier`, `iQteDisponible`, `fQuantiteMax`, `rTotalAPayer`), `iQuantitePanier` (nombre d'articles), `sTotalAPayer`, `iNbProduitIndisponible`. Le produit renvoie `iQuantitePanier` (0 après retrait), `iQteDisponible`, `iQteMinPanier`/`iQteMaxPanier` (0 et 1000), `eDisponibilite`, `fAfficherTexteProduitIndispo`. L'agent peut donc **vérifier chaque écriture** sans lire une page.
- **Contenu net structuré** dans le produit : `sUniteMesure` (`KG`), `nrContenanceUnitaire` (1,0), `nrContenanceTotale` (1,0), `sUniteMesureTotale` (`kg`). À vérifier : ces champs existent-ils aussi dans les résultats de recherche (à comparer avec le libellé et le prix au kilo de DRV-002 ligne 17).
- **Page produit** : `fiche-produits-{id}-{nom}.aspx` sous le chemin du magasin (champ `sUrlPageProduit`). Piste pour l'EAN (ligne 16), non regardée.
- **En-têtes de réponse** : `X-Frame-Options: DENY` (explique le refus du cadre), `X-DataDome: protected`, `Access-Control-Allow-Origin` limité à l'hôte du magasin (nos pages ne peuvent donc pas appeler Leclerc : le script doit tourner dans la page), `Access-Control-Allow-Headers` cite un en-tête `zcwb` (inconnu, non envoyé ici), `Accept-CH` demande des indications sur l'appareil (Sec-CH-UA…), beaucoup de `Set-Cookie` (cookies de session et anti-robot, jamais touchés par le script).
- **Le banc a envoyé quelque chose de plus court** : corps de 118 octets pour l'ajout du banc, 317 pour l'ajout du site. Le corps du **retrait** du site (124 octets) a exactement la taille du corps du banc plus 6 octets, soit les deux guillemets d'un identifiant de produit écrit **en texte** (`"1955"`) au lieu d'un nombre. Le corps de l'**ajout** du site (317 octets) a exactement la taille de l'exemple de la source tierce (avec identifiant en texte **et** objet `objContexteProvenanceArticle` rempli : origine, type de page, texte de recherche, vue, informations complémentaires). **Hypothèse (non prouvée tant que `d` n'est pas relevé)** : le 404 vient de l'identifiant en nombre et/ou de l'objet de contexte manquant à l'ajout.

## Conclusion (pour DRV-005)

- Différence trouvée entre la requête du banc et celle du site : hypothèse ci-dessus ; à confirmer avec le champ `d`.
- L'ajout par script est possible avec la requête relevée : à tester (le site lui-même fait la même requête, même méthode, mêmes en-têtes, sans jeton visible dans les en-têtes).
- Présence d'un jeton de page ou d'un en-tête à reproduire (nom seulement) : aucun relevé dans les en-têtes de requête (hors cookies, non copiés).
