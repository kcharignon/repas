# DRV-011 — Rapport : application Android de test (WebView + banc d'essai)

APK de test : pré-versions `android-lab-2026-10-17` (version 2) puis `android-lab-2026-10-17-2` (version 4, avec le mode « Ordinateur », SHA-256 `f6586da5d21f24afbc7cd24805d49d41ac2fa807df53d6c48f09d933d22f8ccf`, vérifié par l'assistant) du dépôt front. Mode d'emploi : `drive-agent/android-lab/PROCEDURE.md` du front. Aucun identifiant, cookie, e-mail, numéro de magasin ni nom de ville dans ce rapport.

- Date du test : 2026-10-17 (soir)
- Téléphone : Pixel 9, Android 17 (API 37)
- WebView : `com.google.android.webview` 155.0.8059.43

## Résultats

| # | Question | Résultat | Observation |
|---|---|---|---|
| 1 | L'application s'installe-t-elle ? | **oui** | Installée à la main depuis la pré-version. |
| 2 | `leclercdrive.fr` s'affiche-t-il ? Défi anti-robot ? | **oui, sans défi signalé** | En mode WebView par défaut, Leclerc sert le **site mobile** : hôte `m-courses.leclercdrive.fr`, recherche en `/magasin-…/recherche/farine`. |
| 3 | La connexion réussit-elle ? | **oui** | L'utilisateur s'est connecté lui-même ; le panier est ensuite celui de son compte. |
| 4 | Le bouton « Banc » affiche-t-il le menu ? | **oui** | |
| 5 | Essai 1 (sonde) | **oui, dans les deux modes** | Mode mobile : hôte `m-courses…`, chemin `/magasin-…/recherche/farine`, point de livraison non trouvé dans la page. Mode ordinateur : hôte `fd7-courses…`, chemin `/magasin-…/recherche.aspx`, point de livraison trouvé, cookie `datadome` visible, Wake Lock disponible, pas de cadre. |
| 6 | Essai 2 (recherche « farine ») | **mobile : non ; ordinateur : oui** | Mode mobile : HTTP 200 mais 0 produit reconnu (le site mobile n'a pas les routes du site PC). Mode ordinateur : HTTP 200, 225 000 caractères, 1,5 s, **26 produits reconnus**, sans EAN, prix, stock et quantité au panier lus. |
| 7 | Essai 3 (ajout puis retrait) | **ajout : oui** | Mode ordinateur : ajout du produit 492, HTTP 200, 310 ms, quantité obtenue 1, adresse du panier « sans suffixe de ville », en-têtes `Content-Type, Accept, X-Requested-With, zcwb`, corps de 316 octets, total du panier mis à jour. Le retrait n'est pas encore rapporté. |
| 8 | Essai 3b (quantités) | **étape 1 : oui** | Action 1, quantité 3, HTTP 200, quantité obtenue 3 (évènement produit 103, changement de quantité). Les étapes 2 (quantité 2) et le retrait ne sont pas encore rapportés ; sur ordinateur elles ont donné 2 puis 0. |
| 9 | Aucun blocage 403/429 ni défi pendant les essais ? | **oui** | Aucun blocage sur la recherche et les écritures (environ 6 requêtes en mode ordinateur). Le lot de 30 recherches n'a pas été fait sur téléphone. |
| 10 | La session survit-elle à la fermeture complète de l'application ? | non testé | À faire. |
| 11 | Avec l'agent utilisateur sans `wv` : la situation change-t-elle ? | non testé | Plus nécessaire pour décider : le mode ordinateur fonctionne. |
| 12 | Sites envoyés au navigateur du téléphone | **aucun** | Ligne du Diagnostic. |
| 13 | L'écran reste-t-il allumé ? | non testé | |

Diagnostic du mode ordinateur : agent utilisateur `Mozilla/5.0 (X11; Linux x86_64) … Chrome/155.0.0.0 Safari/537.36` (sans `wv`, sans `Mobile`), indications client appliquées (plateforme Linux, mobile = non, marques Google Chrome 155 / Chromium 155), fenêtre large de 1026 pixels. Les caractéristiques de l'appareil (`navigator.platform`, tactile, écran de 412 × 924) restent celles d'un téléphone, sans effet constaté.

## Conclusion (pour DRV-005) — provisoire

- La WebView est viable face à l'anti-robot et à la connexion : **oui, en mode ordinateur**, sur les premiers essais (recherche, ajout, quantités sans blocage). Le mode mobile sert un autre site (`m-courses…`) dont les routes sont inconnues.
- Le banc donne les mêmes résultats que Chrome de bureau en mode ordinateur : **oui** (recherche, ajout, quantité absolue).
- Reste à vérifier : persistance de la session, lot de 30 recherches sur téléphone, retrait, ergonomie du site PC sur écran de téléphone pour la connexion et la finalisation.
- Forme d'application à retenir (A compagnon natif / B Repas empaqueté) : voir `../pivot-application-android.md`.

## Essai réel du test automatique (2026-10-18, application de débogage version 12, Pixel 9)

**Verdict : OK, 41,3 secondes, aucun blocage.** Hôte `fd7`, mode ordinateur. Diagnostic de l'agent : tous les contrôles OK sauf deux « non testés » voulus (origine de Repas, cadre de même origine). Lecture du magasin OK (libellé absent : l'agent ne le lit pas, voir DRV-403). **Lot de 12 recherches : 12 sur 12 réussies**, 1,9 s en moyenne, contenu net et prix lus pour 100 % des produits (25 à 209 produits par mot). **Essai au panier** sur farine, sucre et sel : ajout, passage à 3 puis à 2 (farine), retrait, tous en HTTP 200 avec la quantité obtenue attendue. **Panier avant et après : 0 article et 0,00 € dans les deux cas** (le panier est revenu à son état initial). Aucune commande, aucun paiement.

Conclusion : sur téléphone, l'application Android en mode ordinateur fait fonctionner la recherche, la lecture du contenu net et des prix, l'ajout, les quantités absolues et le retrait, sans blocage de l'anti-robot. Le critère « la WebView est viable » de G1 est confirmé par un essai complet.
