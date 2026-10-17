# DRV-002 — Rapport : banc d'essai du favori sur le téléphone

Gabarit à remplir après l'exécution du banc d'essai (DRV-001) sur le téléphone Android de l'utilisateur. Ne mettre ici **aucun identifiant, cookie, jeton, adresse e-mail ni numéro de magasin personnel**. Répondre « oui », « non » ou « non testé » et noter l'observation exacte (message affiché, code HTTP).

- Date du test :
- Téléphone (modèle) et version d'Android :
- Navigateur et version (Chrome ?) :
- Magasin testé : (région ou « magasin A » ; pas l'adresse précise)

## Résultats

| # | Question | Résultat | Observation |
|---|---|---|---|
| 1 | Le favori se lance-t-il sur une page Leclerc (nom tapé dans la barre d'adresse) ? | | |
| 2 | Le favori affiche-t-il son menu de test ? | | |
| 3 | La sonde d'environnement voit-elle le magasin courant et l'hôte ? | | |
| 4 | Une recherche même origine répond-elle 200 avec des produits analysables (combien) ? | | |
| 5 | Ajouter un produit au panier (puis le retirer) fonctionne-t-il ? Code HTTP ? | | |
| 6 | Un blocage (403) apparaît-il ? Après combien de requêtes ? | | |
| 7 | Un `iframe` vers la page de recherche du même hôte s'affiche-t-il ? | | |
| 8 | L'agent repère-t-il le produit que l'utilisateur ajoute dans le cadre ? | | |
| 9 | Un fragment de 6 Ko, 16 Ko, 64 Ko survit-il à la navigation aller vers Leclerc ? | | |
| 10 | Le retour vers `https://kantin.eu/` conserve-t-il le fragment (routeur compris) ? | | |
| 11 | Un lien d'intention ouvre-t-il Chrome complet depuis la PWA installée ? Un lien normal ouvre-t-il un onglet intégré ? | | |
| 12 | Le maintien de l'écran allumé (Wake Lock) tient-il une minute ? | | |
| 13 | Le panier rempli sur le site apparaît-il dans l'appli Leclerc Drive du même compte ? | | |
| 14 | Adresse de la page panier / récapitulatif (chemin, sans identifiants) : | | |
| 15 | Même identifiant de produit dans deux magasins différents (sur 5 produits) ? | | |
| 16 | Un EAN est-il visible sur la fiche produit ? | | |
| 17 | Contenu net déductible du libellé ou du prix par unité de mesure : sur 30 résultats, combien ? | | |
| 18 | Sur 30 ingrédients de Repas, le bon produit est-il dans les 3 premiers résultats ? Combien ? | | |

## Conclusion (à remplir par DRV-005)

- Favori viable sur téléphone : oui / non
- Choix du produit : P1 (cadre natif) / P2 (liste de résultats de l'agent)
- Ouverture dans Chrome complet : lien d'intention / lien normal / impossible
- Clé de mémorisation : par magasin / commune à tous
- Repli à retenir si non :
