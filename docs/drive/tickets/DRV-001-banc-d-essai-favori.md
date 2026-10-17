# DRV-001 — Banc d'essai du favori (outil de test jetable)

- **Dépôt** : front (`kcharignon/repas-front`), dossier `drive-agent/lab/`
- **Jalon** : M0 Découverte
- **Taille** : M
- **Dépend de** : —
- **Statut** : à faire
- **Lire d'abord** : [`../README.md`](../README.md), [`../architecture.md`](../architecture.md) §§ 2, 8, 9 ; [`../recherche.md`](../recherche.md) §§ 2, 3, 6 ; `CLAUDE.md` du front.

## Contexte

Avant d'écrire le moindre code produit, il faut savoir si un favori JavaScript peut tourner sur une page Leclerc dans le Chrome d'un téléphone Android, si les requêtes passent DataDome et si le plan de l'agent (cadre natif, fragment d'URL, ouverture dans Chrome complet) tient. Ce ticket fabrique **un seul favori de test à menu** que l'utilisateur lance lui-même sur son téléphone (DRV-002). C'est un outil jetable : il sera supprimé ou archivé à la décision G1.

## Portée

Un favori autonome (un seul fichier JavaScript, sans dépendance, converti en chaîne `javascript:…` à coller dans un favori) qui affiche un menu en superposition avec ces essais, chacun donnant un résultat lisible à l'écran et copiable :

1. **Sonde d'environnement** : hôte, chemin, identifiant du magasin lu dans l'URL (`magasin-<id>-<id>`), présence d'un cookie nommé `datadome` (booléen seulement, jamais sa valeur), agent utilisateur, taille d'écran, `navigator.wakeLock` disponible, politique de sécurité visible dans une balise `<meta http-equiv>` s'il y en a une.
2. **Recherche même origine** : saisie d'une requête (par défaut `farine`), `fetch` de `recherche.aspx?TexteRecherche=<requête>` sur l'hôte courant, code HTTP, nombre de produits trouvés dans le JSON embarqué, pour les 5 premiers : identifiant, libellé 1 et 2, prix, prix par unité de mesure, stock, `iQuantitePanier` ; présence d'un EAN dans les données (oui/non).
3. **Ajout puis retrait d'un produit** : l'utilisateur saisit lui-même l'identifiant d'un produit peu cher vu au test 2 ; confirmation explicite ; `POST panier.aspx?op=1` avec `eTypeAction: 1` et quantité 1, affichage du code HTTP et de l'évènement `Panier` de la réponse (total) ; puis, sur confirmation, retrait (`eTypeAction: 2`, quantité 0).
4. **Cadence** : 5 recherches successives espacées de 800 ms + gigue jusqu'à 700 ms ; arrêt à la première réponse 403/429 ; affichage du nombre de réponses reçues avant blocage.
5. **Cadre natif** : crée un `iframe` plein écran vers `recherche.aspx?TexteRecherche=<requête>` du même hôte ; indique s'il s'est chargé (évènement `load`, accès au `contentDocument`) ; détecte les refus (page vide, redirection du cadre vers la page parente).
6. **Capture d'un ajout dans le cadre** : après le test 5, l'utilisateur ajoute un produit avec l'interface Leclerc dans le cadre ; le favori affiche l'identifiant et le libellé du produit ajouté, détecté par l'une des deux méthodes (observation du DOM du cadre ; enveloppe de `XMLHttpRequest`/`fetch` du cadre) et dit laquelle a fonctionné.
7. **Fragment** : construit un fragment factice de 6 Ko, 16 Ko et 64 Ko et navigue vers `https://kantin.eu/#lab=<fragment>` ; au retour (autre ouverture de l'essai 8) vérifie la taille reçue.
8. **Retour** : essai qui, depuis Leclerc, navigue vers `https://kantin.eu/#lab=ok` ; et page d'accueil de l'application qui doit conserver le fragment (à observer par l'utilisateur dans la barre d'adresse).
9. **Wake Lock** : demande le maintien de l'écran allumé pendant 60 s et affiche si le verrou a été obtenu et conservé.
10. **Contenu net** : pour les produits du test 2, affiche le contenu déduit par deux méthodes (libellé de seconde ligne ; prix unitaire ÷ prix par unité de mesure) pour comparer.
11. **Lot de 30 recherches** : pour une liste de 30 noms d'ingrédients fournie (champ de saisie, un par ligne), enchaîne les recherches avec la cadence du test 4 et affiche pour chacun les 3 premiers libellés ; résultat copiable (texte) pour l'analyse de qualité.

Une **feuille de procédure** en français `drive-agent/lab/PROCEDURE.md` : pas à pas pour un utilisateur non technique (créer le favori sur Chrome Android, ouvrir le drive, choisir son magasin, se connecter, lancer le favori en tapant son nom dans la barre d'adresse, faire chaque essai, copier les résultats, remplir `docs/drive/spikes/DRV-002-rapport.md`). Une étape par numéro, phrases courtes, aucun jargon sans explication.

Une cible de construction dans Docker (`make lab`, dans le `Makefile` du front) qui produit `drive-agent/lab/dist/lab-bookmarklet.txt` (la chaîne `javascript:…` prête à coller) et affiche sa taille.

## Hors portée

Le vrai agent (DRV-301 et suivants), tout appel à notre API, tout envoi de données hors de la page, tout stockage persistant.

## Spécification technique

- Code en TypeScript ou JavaScript simple dans `drive-agent/lab/src/`, compilé en un fichier (esbuild ou équivalent déjà présent dans la chaîne Vite du front ; ne pas ajouter de dépendance lourde). Aucun `eval`, aucune bibliothèque tierce dans le favori.
- Interface en Shadow DOM, texte en français, cibles tactiles ≥ 44 px, lisible sur un écran de téléphone.
- **Sécurité du banc** : jamais d'affichage ni de copie de cookie, d'en-tête `Cookie`/`Set-Cookie`, de jeton ou d'identifiant de compte ; le test 3 demande une confirmation avant chaque écriture et n'écrit qu'un produit à la fois ; aucune boucle d'écriture ; arrêt immédiat sur 403/429 ; requêtes en série uniquement.
- La recherche du JSON embarqué se fait par une expression régulière tolérante sur les champs nommés au § 2 de `recherche.md` ; en cas d'échec, afficher un extrait de 300 caractères autour du premier mot-clé trouvé (pas la page entière).
- Le fichier est marqué en tête : « Outil de test jetable, à supprimer après la décision G1 (DRV-005) ».

## Critères d'acceptation

- Étant donné la page `https://<hôte Leclerc>/magasin-<id>-<id>/` ouverte dans Chrome de bureau, quand le favori est lancé, alors le menu s'affiche et la sonde indique l'hôte et l'identifiant du magasin.
- Étant donné un favori créé à partir de `lab-bookmarklet.txt`, quand l'utilisateur suit `PROCEDURE.md` sur Android, alors il peut lancer chaque essai et copier son résultat.
- Le test 3 n'écrit rien sans confirmation et ne laisse pas de produit dans le panier si l'utilisateur confirme le retrait.
- La taille de `lab-bookmarklet.txt` est affichée ; elle reste sous 60 Ko.
- Aucun appel réseau vers un autre hôte que l'hôte Leclerc courant, sauf la navigation explicite des essais 7 et 8 vers `https://kantin.eu/`.

## Tests exigés

Tests unitaires (Vitest) de l'analyse du JSON embarqué, de l'extraction du contenu et de la construction du fragment sur des données **synthétiques** inventées (jamais de HTML Leclerc brut dans le dépôt). `make check` vert. Les essais réels ne sont pas automatisables (DataDome) : ils sont faits par DRV-002.

## Definition of Done

Celle du front (`make check`, couverture non abaissée) ; PR avec le gabarit Quoi / Vérifié / À relire ; `PROCEDURE.md` relue ; roadmap cochée.

## Pièges connus

- Un favori trop long peut être refusé par le navigateur : mesurer la taille et la réduire (minification) ; sinon proposer de le charger en deux temps depuis le presse-papiers (à discuter avant).
- Sur Chrome Android, le favori se lance depuis la barre d'adresse ; il ne s'exécute pas dans l'onglet intégré d'une PWA.
- Ne pas committer de capture de page Leclerc ni de HTML brut (dépôt public).
