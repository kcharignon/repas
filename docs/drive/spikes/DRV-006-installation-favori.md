# DRV-006 — Rapport : installer un favori JavaScript sur le téléphone

Constat du 2026-10-17 (utilisateur, Chrome Android) avec le favori du banc d'essai (49 629 caractères) :

1. Créé sur l'ordinateur (Chrome de bureau), il **ne se synchronise pas** vers le téléphone, alors qu'un favori créé juste après se synchronise tout de suite.
2. Créé sur le téléphone, la chaîne est **tronquée au collage** dans le champ « URL » du favori.

Conséquence : un favori de cette taille ne s'installe pas. L'agent final sera plus gros que le banc d'essai. La cause (taille ? type d'adresse `javascript:` ?) et la limite sont **non établies** : ce test les mesure. Le détail du raisonnement est dans [`../analyse.md`](../analyse.md) (risque 11, décision D20).

## Test de taille (sur l'ordinateur, puis sur le téléphone)

Quatre favoris de test, de plus en plus longs : `t2` (2 Ko), `t8` (8 Ko), `t16` (16 Ko), `t32` (32 Ko). Chacun est un commentaire de remplissage suivi de `alert('OK … Ko')` **à la fin** : si la fin de la chaîne est perdue, rien ne s'affiche.

1. Générer les quatre fichiers dans un dossier de travail hors du dépôt : `for n in 2 8 16 32; do { printf 'javascript:/*'; head -c $((n*1000)) /dev/zero | tr '\0' x; printf "*/alert('OK $n Ko');void 0"; } > t$n.txt; done`. Sur l'ordinateur, créer les quatre favoris (nom = `t2`, `t8`, `t16`, `t32`) avec le contenu de chaque fichier (`javascript:/*xxxx…*/alert('OK n Ko');void 0`, n milliers de `x`).
2. Attendre une minute, ouvrir sur le téléphone une page ordinaire (par exemple `https://example.com`), taper `t2` dans la barre d'adresse, choisir l'entrée du favori (celle qui a l'icône d'étoile), noter si le message « OK 2 Ko » s'affiche. Idem `t8`, `t16`, `t32`.
3. Si `t2` n'apparaît même pas dans la liste des favoris du téléphone : les favoris `javascript:` ne se synchronisent pas du tout, noter-le.

## Résultats

| Favori | Visible sur le téléphone ? | Le message s'affiche ? | Remarque |
|---|---|---|---|
| `t2` (2 Ko) | oui (synchronisé) | **non** | Rien ne s'affiche quand on touche la ligne du favori dans les suggestions. |
| `t8` (8 Ko) | oui (synchronisé) | **non** | Rien ne s'affiche. |
| `t16` (16 Ko) | **non** | — | Jamais arrivé sur le téléphone. |
| `t32` (32 Ko) | **non** | — | Jamais arrivé sur le téléphone. |

- Modèle du téléphone, version de Chrome (téléphone et ordinateur) :
- Synchronisation des favoris activée sur les deux appareils : oui / non
- Limite de taille constatée à la synchronisation : **entre 8 Ko (passe) et 16 Ko (ne passe pas)** pour un favori `javascript:` créé sur Chrome de bureau et synchronisé vers Chrome Android. Les favoris `javascript:` se synchronisent donc bien. Le favori du banc (49,6 Ko) était trop gros.
- Collage direct sur le téléphone (chaîne de 2 Ko, puis 8 Ko) : coupé ? à quelle longueur ?

## Conclusion (pour DRV-005)

- Plus grande taille de favori installable : au moins 8 Ko, moins de 16 Ko (limite exacte non cherchée)
- Chargeur court (moins de 1 Ko) possible : **oui** pour la taille ; la politique de sécurité de Leclerc (DRV-004) ne l'en empêche pas. Reste à prouver sur le téléphone qu'un chargeur lance bien un script (DRV-009).

## Suite (2026-10-17, soir) : Chrome Android ne lance pas ces favoris

- `t2` et `t8` (synchronisés) ne font rien quand l'utilisateur les touche dans les suggestions de la barre d'adresse de Chrome Android (page `example.com`, nom tapé sans Entrée).
- Le chargeur du banc d'essai (DRV-009, favori `lab2`, 500 caractères) n'affiche pas son menu non plus sur une page du drive.
- Contrôle : taper à la main `javascript:alert(1)` dans la barre d'adresse de Chrome Android lance **une recherche Google**. Chrome n'exécute donc pas d'adresse `javascript:` depuis la barre d'adresse de ce téléphone.
- Conclusion : le favori JavaScript sous **Chrome Android** n'est pas utilisable sur le téléphone de l'utilisateur (la taille n'était qu'un premier obstacle). Les témoignages trouvés vont dans le même sens (Chrome Android n'a jamais bien géré les favoris JavaScript). Voir D20 et DRV-010.
