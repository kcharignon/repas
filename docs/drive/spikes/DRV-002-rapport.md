# DRV-002 — Rapport : banc d'essai du favori sur le téléphone

Gabarit à remplir après l'exécution du banc d'essai (DRV-001) sur le téléphone Android de l'utilisateur. Ne mettre ici **aucun identifiant, cookie, jeton, adresse e-mail ni numéro de magasin personnel**. Répondre « oui », « non » ou « non testé » et noter l'observation exacte (message affiché, code HTTP).

**Première passe sur ordinateur, 2026-10-17** : le favori du banc d'essai ne s'installe pas sur le téléphone de l'utilisateur (voir [`DRV-006-installation-favori.md`](DRV-006-installation-favori.md)). L'utilisateur a donc fait les essais **sur le site** avec Chrome de bureau. Les lignes propres au téléphone restent « non testé ». La reprise sur le téléphone aura lieu quand l'installation sera réglée.

- Date du test : 2026-10-17 (première passe, ordinateur)
- Téléphone (modèle) et version d'Android : non testé
- Navigateur et version (Chrome ?) : Chrome 155 sous Windows 10/11 (ordinateur), fenêtre 1278 × 910
- Magasin testé : un drive Leclerc de l'utilisateur, hôte `fd7-courses.leclercdrive.fr` (compte connecté, magasin choisi)

## Résultats

| # | Question | Résultat | Observation |
|---|---|---|---|
| 1 | Le favori se lance-t-il sur une page Leclerc (nom tapé dans la barre d'adresse) ? | oui (ordinateur) ; téléphone : non testé | Favori créé sur Chrome de bureau, lancé depuis la page d'accueil du magasin. |
| 2 | Le favori affiche-t-il son menu de test ? | oui (ordinateur) | Banc 1.0.0, menu affiché. |
| 3 | La sonde d'environnement voit-elle le magasin courant et l'hôte ? | oui | Hôte reconnu, magasin lu dans l'adresse, `sNoPointLivraison` trouvé dans la page (même valeur). Contexte sécurisé : oui. Wake Lock disponible : oui. Page dans un cadre : non. Aucune politique de sécurité dans une balise `<meta>`. Le cookie `datadome` est lisible par un script de la page (non protégé en lecture). |
| 4 | Une recherche même origine répond-elle 200 avec des produits analysables (combien) ? | oui | `recherche.aspx?TexteRecherche=farine` : HTTP 200, `text/html`, environ 220 000 caractères, 536 ms, 25 produits reconnus avec identifiant, libellés (2 lignes), prix, prix par unité de mesure, stock, quantité déjà au panier. **Pas d'EAN dans les données.** Autres mots : 55 à 209 produits, pages de 0,36 à 1,0 million de caractères, 0,7 à 0,9 s. |
| 5 | Ajouter un produit au panier (puis le retirer) fonctionne-t-il ? Code HTTP ? | **oui (ordinateur)** | Après correction du banc (DRV-008) : ajout `POST …/panier.aspx?op=1` **HTTP 200**, JSON de 4 évènements en 127 ms, quantité obtenue lue dans la réponse (`iQtePanier` 1, stock 520, total du panier mis à jour), puis retrait HTTP 200 (produit absent de `lstProduitsLight`, panier revenu à son état). Le 404 d'avant venait de l'en-tête `zcwb: ajax-manager` et de l'adresse du panier avec suffixe de ville (voir [DRV-007](DRV-007-requete-ajout-panier.md)). À refaire sur téléphone. |
| 6 | Un blocage (403) apparaît-il ? Après combien de requêtes ? | non (ordinateur) | Aucun 403 ni 429 sur environ 40 requêtes en série (5 en cadence 800 ms + gigue, puis un lot de 30). Réponses 200 en 0,7 à 0,9 s. Le 404 de la ligne 5 n'est pas un blocage. À refaire sur téléphone. |
| 7 | Un `iframe` vers la page de recherche du même hôte s'affiche-t-il ? | **non** | Le cadre déclenche `load` mais son contenu est inaccessible ; la page du cadre affiche « fd7-courses.leclercdrive.fr n'autorise pas la connexion » (refus par `X-Frame-Options` ou `frame-ancestors`). **P1 impossible.** |
| 8 | L'agent repère-t-il le produit que l'utilisateur ajoute dans le cadre ? | non testé | Sans objet : le cadre est refusé. |
| 9 | Un fragment de 6 Ko, 16 Ko, 64 Ko survit-il à la navigation aller vers Leclerc ? | **non (6 Ko)** | Vers Repas : 6 144, 16 384 et 65 536 caractères reçus, contenu intact (ordinateur). Vers Leclerc avec 6 Ko : Leclerc affiche sa page « désolé, ce service est momentanément indisponible » (adresse `PageDir/pgeWCSD002_Erreur.aspx?…&fromJs…=1`, sans chemin de magasin ni fragment). Fragments courts (`#repas=essai`, 1 000, 3 000 caractères) : à tester (décision D21 dans l'analyse). |
| 10 | Le retour vers `https://kantin.eu/` conserve-t-il le fragment (routeur compris) ? | oui (ordinateur) | « fragment ok reçu ». À refaire sur téléphone (application installée). |
| 11 | Un lien d'intention ouvre-t-il Chrome complet depuis la PWA installée ? Un lien normal ouvre-t-il un onglet intégré ? | non testé | Hors banc d'essai (aucun essai ne le couvre) ; à valider avec DRV-402. |
| 12 | Le maintien de l'écran allumé (Wake Lock) tient-il une minute ? | non testé | L'interface existe sur l'ordinateur (« disponible : oui ») ; la durée ne se teste que sur téléphone. |
| 13 | Le panier rempli sur le site apparaît-il dans l'appli Leclerc Drive du même compte ? | **oui** | Des pâtes ajoutées à la main sur le site (ordinateur) sont apparues dans le panier de l'appli mobile du même compte. |
| 14 | Adresse de la page panier / récapitulatif (chemin, sans identifiants) : | non noté | À relever (DRV-004 ou DRV-007). |
| 15 | Même identifiant de produit dans deux magasins différents (sur 5 produits) ? | non testé | Un seul magasin essayé. |
| 16 | Un EAN est-il visible sur la fiche produit ? | partiel | Pas d'EAN dans les données de la page de recherche. La fiche produit n'a pas été regardée. |
| 17 | Contenu net déductible du libellé ou du prix par unité de mesure : sur 30 résultats, combien ? | **25 sur 25** | Sur la recherche « farine » (25 produits) : 24 fois les deux méthodes (libellé de la 2ᵉ ligne, ou prix ÷ prix au kilo) donnent la même valeur ; 1 désaccord (« x4 » : 4 pièces par le libellé, 330 g par le prix) ; aucun produit sans réponse. Un seul mot essayé : à refaire sur des produits à l'unité, au litre et au poids variable. |
| 18 | Sur 30 ingrédients de Repas, le bon produit est-il dans les 3 premiers résultats ? Combien ? | **23 sur 30** (lecture de l'assistant, à confirmer par l'utilisateur) | Lot de 30 recherches en série, 200 à chaque fois, aucun arrêt. **Les 7 échecs sont tous des fruits et légumes frais** : tomates (sauces), ail (ail en semoule), pommes de terre (plats cuisinés), carottes (petits pois carottes), courgettes (purée), citron (jus), bananes (desserts pour bébé, shampoing). Les 23 autres produits d'épicerie, crèmerie et viande sont corrects ou plausibles. Oignons : oignons émincés, à confirmer. |
| 19 | Disponibilité : un produit déjà connu (vu à la ligne 4) est-il retrouvé par une recherche sur son libellé, avec son stock lu ? Que répond le panier quand on ajoute un produit en rupture (stock 0) ? Comment savoir avant d'ajouter qu'il est disponible ? | partiel | Recherche sur le libellé : produit retrouvé par son identifiant au rang 1, stock lu (`iQteDisponible`), déjà au panier lu (`iQuantitePanier`). Le comportement du panier pour un stock à 0 n'est pas testé (l'ajout ne marche pas, ligne 5). |

## Constats (première passe, ordinateur)

1. **La lecture et l'écriture marchent** (ordinateur). Recherche, analyse des produits, prix, stock, contenu net, ajout et retrait au panier avec confirmation de la quantité par la réponse. Le banc corrigé reproduit exactement la requête du site (en-tête `zcwb`, adresse du panier sans ville, contexte de recherche à l'ajout, identifiant en texte). Reste à voir si cela tient sur téléphone (DataDome, DRV-002 lignes 1, 2, 6, 9 à 13).
2. **DataDome n'a rien bloqué** en lecture sur ordinateur (environ 40 requêtes en série). Le téléphone reste à tester.
3. **Cadre natif refusé** (P1 impossible). Le choix du produit se fera dans une liste de résultats affichée par l'agent (P2) : le produit choisi est connu directement, sans avoir à le repérer dans un cadre.
4. **Pas d'EAN** dans la recherche : la clé d'un produit reste l'identifiant Leclerc, propre au magasin tant que la ligne 15 n'est pas testée.
5. **Fruits et légumes frais** mal trouvés par la recherche texte : les 7 échecs de l'échantillon en sont tous. Ces ingrédients passeront presque toujours par le choix de l'utilisateur, puis par la mémorisation. À traiter dans la suggestion (backlog DRV-601) et le calcul des quantités (vente à la pièce ou au kilo).
6. **Contenu net** déductible de façon fiable par deux méthodes qui se recoupent ; les désaccords (produits en pièces) se détectent.
7. **Quantités absolues confirmées** (essai 3b) : `iQuantite` est la quantité visée, dans les deux sens, avec la seule action 1 ; le retrait complet est l'action 2 avec la quantité 0. Rejouer un envoi ne double donc pas les quantités.
8. **Pages lourdes** : jusqu'à 1 million de caractères par recherche (209 produits). L'agent ne garde que les champs utiles.

## Reste à faire sur ce rapport

- Essai 10 (contenu net structuré) à faire sur ordinateur.
- Tester les fragments courts vers Leclerc (ligne 9, décision D21).
- Refaire les essais 1, 2, 6, 9, 10, 12 sur le téléphone, dès qu'un favori s'installe (DRV-006).
- Deuxième magasin (ligne 15) ; EAN sur la fiche produit (ligne 16) ; adresse de la page panier (ligne 14).

## Conclusion (à remplir par DRV-005)

- Favori viable sur téléphone : oui / non
- Choix du produit : P1 (cadre natif) / P2 (liste de résultats de l'agent) — **P1 écarté par la ligne 7**
- Ouverture dans Chrome complet : lien d'intention / lien normal / impossible
- Clé de mémorisation : par magasin / commune à tous
- Repli à retenir si non :
