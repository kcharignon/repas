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

Relevé du 2026-10-17 (ordinateur, Chrome 155, hôte `fd7`, produit « Farine de blé T45 Tablier Blanc », identifiant 1955), en deux temps : en-têtes et réponses, puis le champ `d` des deux requêtes. **Relevé complet.**

| Élément | Ajout (+) | Retrait (−) |
|---|---|---|
| Méthode et adresse complète | `POST /magasin-[masqué]/panier.aspx?op=1` (même hôte `fd7`) | identique |
| Code HTTP | 200 | 200 |
| `Content-Type` de la requête | `application/x-www-form-urlencoded; charset=UTF-8` | identique |
| `Content-Length` du corps | **317** octets | **124** octets |
| En-têtes propres au site | **`zcwb: ajax-manager`** (relevé ensuite par 'Copier en tant que fetch', absent de la première copie des en-têtes), `X-Requested-With: XMLHttpRequest`, `Origin` (même hôte), `Sec-Fetch-Site: same-origin`, `Accept: */*`. Seul en-tête personnalisé : `zcwb`. | identique |
| `Referer` (chemin seulement) | `/magasin-[masqué]/recherche.aspx?TexteRecherche=farine` | identique |
| Corps : champ(s) de formulaire | `d` (JSON, un seul champ) | `d` |
| Corps : contenu du champ `d` décodé | `{"eTypeAction":1,"iIdProduit":"1955","iQuantite":1,"sNoPointLivraison":"[masqué]","objContexteProvenanceArticle":{"eOrigine":4,"eTypePage":3,"sTexteRecherche":"farine","eVue":0,"sInformationsComplementaires":"uni-2"}}` | `{"eTypeAction":2,"iIdProduit":"1955","iQuantite":0,"sNoPointLivraison":"[masqué]"}` |
| Réponse | JSON, tableau de 4 évènements (`Content-Encoding: gzip`, 2 243 octets) : produit (`eTypeEvenement` 101), rayon (501), panier (1) et paniers complets (4) | idem avec 102 et 502 ; 2 220 octets |
| Le banc d'essai avec « objet d'analytique vide » coché : code HTTP | non essayé | — |

## Ce que la réponse apprend (déjà exploitable)

- **Chaque écriture renvoie l'état complet du panier** : `lstProduitsLight` (par produit : `iIdProduit`, `iQtePanier`, `iQteDisponible`, `fQuantiteMax`, `rTotalAPayer`), `iQuantitePanier` (nombre d'articles), `sTotalAPayer`, `iNbProduitIndisponible`. Le produit renvoie `iQuantitePanier` (0 après retrait), `iQteDisponible`, `iQteMinPanier`/`iQteMaxPanier` (0 et 1000), `eDisponibilite`, `fAfficherTexteProduitIndispo`. L'agent peut donc **vérifier chaque écriture** sans lire une page.
- **Contenu net structuré** dans le produit : `sUniteMesure` (`KG`), `nrContenanceUnitaire` (1,0), `nrContenanceTotale` (1,0), `sUniteMesureTotale` (`kg`). À vérifier : ces champs existent-ils aussi dans les résultats de recherche (à comparer avec le libellé et le prix au kilo de DRV-002 ligne 17).
- **Page produit** : `fiche-produits-{id}-{nom}.aspx` sous le chemin du magasin (champ `sUrlPageProduit`). Piste pour l'EAN (ligne 16), non regardée.
- **En-têtes de réponse** : `X-Frame-Options: DENY` (explique le refus du cadre), `X-DataDome: protected`, `Access-Control-Allow-Origin` limité à l'hôte du magasin (nos pages ne peuvent donc pas appeler Leclerc : le script doit tourner dans la page), `Access-Control-Allow-Headers` cite un en-tête `zcwb` (inconnu, non envoyé ici), `Accept-CH` demande des indications sur l'appareil (Sec-CH-UA…), beaucoup de `Set-Cookie` (cookies de session et anti-robot, jamais touchés par le script).
- **Pourquoi le banc avait un 404 (hypothèse confirmée par le champ `d`)** : le banc envoyait l'identifiant de produit en **nombre** (`1955`) et, à l'ajout, **sans** l'objet `objContexteProvenanceArticle`. Le site envoie l'identifiant en **texte** (`"1955"`) et joint l'objet de contexte à l'ajout (origine 4, type de page 3, texte de la recherche en cours, vue 0, informations `uni-2`, probablement « univers 2 » = drive). Le retrait du site n'a pas de contexte. Les tailles de corps (317 et 124 octets) correspondaient exactement. On ne sait pas encore lequel des deux écarts (ou les deux) provoque le 404 : DRV-008 le dira.

## Conclusion (pour DRV-005)

- Différence trouvée entre la requête du banc et celle du site : identifiant en texte et objet de contexte à l'ajout (voir ci-dessus).
- L'ajout par script est possible avec la requête relevée : **à tester** (DRV-008). Le site fait la même requête, même méthode et mêmes en-têtes, sans jeton visible dans les en-têtes.
- Présence d'un jeton de page ou d'un en-tête à reproduire (nom seulement) : aucun relevé dans les en-têtes de requête (hors cookies, non copiés).

## Complément (2026-10-17, soir) : la cause du 404

La requête rejouée dans la console avec « Copier en tant que fetch » répond **200** (la farine est revenue dans le panier). Elle diffère de celle du banc par deux points :

1. **`zcwb: ajax-manager`** : en-tête personnalisé envoyé par le site, que le banc ne posait pas (il n'apparaissait pas dans la première copie des en-têtes ; `Access-Control-Allow-Headers` le cite).
2. **Adresse du panier sans suffixe de ville** : les pages du magasin ont une adresse `/magasin-<id>-<id>-<Ville>/…` ; la requête du panier part de `/magasin-<id>-<id>/panier.aspx?op=1`. Le banc reprenait le segment complet de la page, ville comprise.

Le corps (`d=…`, 317 octets pour l'ajout) était déjà identique, `credentials` et `referrer` n'ont pas d'importance a priori (le site met le `referrer` de la page de recherche). Correction demandée dans le banc : voir DRV-008 (suite). Le contenu complet de la requête réelle est dans [`../recherche.md`](../recherche.md).

- Ajout par script depuis la page : **possible** (200 en rejouant la requête du site depuis la console).
- Reste à confirmer avec le banc corrigé que les deux écarts expliquent bien le 404.
