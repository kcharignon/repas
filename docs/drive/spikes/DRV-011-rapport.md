# DRV-011 — Rapport : application Android de test (WebView + banc d'essai)

APK de test : pré-version `android-lab-2026-10-17` du dépôt front (`repas-android-lab.apk`, SHA-256 `24199f1a3d8c43735c47d6208c743a515cede7a905b5ad52f5d850570e5e5917`, vérifié par l'assistant sur le fichier téléchargé), construit depuis le commit `a5bae3b`. Mode d'emploi : `drive-agent/android-lab/PROCEDURE.md` du front. L'application n'a **jamais été lancée** avant les essais de l'utilisateur (ni téléphone, ni émulateur) : tout reste à confirmer. Ne mettre ici **aucun identifiant, cookie, e-mail, numéro de magasin ni nom de ville**.

- Date du test :
- Téléphone (modèle) et version d'Android :
- Version de la WebView (affichée dans « Diagnostic ») :

## Résultats

| # | Question | Résultat | Observation |
|---|---|---|---|
| 1 | L'application s'installe-t-elle (avertissements rencontrés) ? | | |
| 2 | `leclercdrive.fr` s'affiche-t-il dans l'application ? Un défi anti-robot (captcha) apparaît-il ? | | |
| 3 | La connexion réussit-elle, y compris le code reçu par e-mail ? | | |
| 4 | Le bouton « Banc » affiche-t-il le menu (13 lignes) ? | | |
| 5 | Essai 1 (sonde) : hôte et magasin reconnus ? | | |
| 6 | Essai 2 (recherche « farine ») : HTTP 200 et produits reconnus ? | | |
| 7 | Essai 3 (ajout puis retrait) : HTTP 200 et quantité obtenue ? | | |
| 8 | Essai 3b (quantités 3 puis 2, retrait) : mêmes résultats que sur ordinateur ? | | |
| 9 | Aucun blocage 403/429 ni défi pendant les essais ? | | |
| 10 | La session survit-elle à la fermeture complète de l'application ? Et le lendemain ? | | |
| 11 | Avec l'agent utilisateur sans `wv` (Diagnostic) : la situation change-t-elle (anti-robot, connexion) ? | | |
| 12 | Sites envoyés au navigateur du téléphone (liste du Diagnostic) : lesquels ? | | |
| 13 | L'écran reste-t-il allumé pendant les essais ? | | |

## Conclusion (pour DRV-005)

- La WebView est viable face à l'anti-robot et à la connexion : oui / non (avec `wv` / sans `wv`)
- Le banc donne les mêmes résultats que Chrome de bureau : oui / non
- Forme d'application à retenir (A compagnon natif / B Repas empaqueté) : voir `../pivot-application-android.md`
