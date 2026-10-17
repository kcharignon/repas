# DRV-004 — Rapport : en-têtes de sécurité de leclercdrive.fr

Gabarit à remplir par l'utilisateur avec les outils de développement d'un navigateur de bureau (F12, onglet Réseau, cliquer la requête du document, onglet « En-têtes », partie « En-têtes de réponse »). Ne copier **que** les en-têtes listés ci-dessous. **Ne jamais copier** `Cookie`, `Set-Cookie`, `Authorization` ni quoi que ce soit lié à la connexion.

Pages à relever (connecté ou non, magasin choisi) : (1) la page d'accueil du magasin, (2) une page de résultats de recherche (`recherche.aspx?TexteRecherche=farine`), (3) la page qui montre le panier ou le récapitulatif de commande.

| En-tête | Accueil | Recherche | Panier |
|---|---|---|---|
| `Content-Security-Policy` (texte complet) | | | |
| `Content-Security-Policy-Report-Only` | | | |
| `X-Frame-Options` | | | |
| `Cross-Origin-Opener-Policy` | | | |
| `Cross-Origin-Embedder-Policy` | | | |
| `Referrer-Policy` | | | |
| `Permissions-Policy` | | | |

- Date et navigateur utilisés :
- La page de recherche se charge-t-elle sans appel XHR de recherche (le JSON des produits est-il dans le HTML) ? Observation :
- Adresse (sans identifiants) de la page panier ou récapitulatif :

## Conclusion (pour DRV-005)

- Script en ligne autorisé (`'unsafe-inline'` ou nonce) : oui / non
- `connect-src` autorise-t-il notre origine : oui / non
- Cadre de même origine possible (`X-Frame-Options`, `frame-ancestors`) : oui / non
- COOP présent : oui / non
