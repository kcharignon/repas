# STORE-017 — Déclarations « Contenu de l'application » et fiche dans la console

- **Dépôt** : aucun (étape humaine, guidée par l'assistant)
- **Jalon** : M4 Essais et publication
- **Taille** : M (deux heures, en deux fois)
- **Dépend de** : STORE-009 (lien de suppression), STORE-010 (confidentialité), STORE-013 (compte de relecture), STORE-014 et STORE-015 (textes et visuels) ; STORE-011 et STORE-012 si D5 = B
- **Faisable sans téléphone ni compte Play** : **non** (compte Play), téléphone inutile
- **Bloquant pour AUTH-002 / la connexion Google** : non
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 6 (toute la section), § 9 phase C et l'annexe A ; skill `repas-ops`.

## Contexte

Les déclarations du « Contenu de l'application » (politique de confidentialité, accès à l'application, publicités, classification, public cible, sécurité des données, suppression de compte, autres) et la fiche principale sont **obligatoires pour la production** et, très probablement, pour le test fermé (supposé : la page de Google ne dispense de la fiche « sécurité des données » que les applications en test interne uniquement). Une incohérence entre ces déclarations et le comportement de l'application est une cause de refus.

## Portée

Guider, une action par message, selon C.1 à C.11 de l'analyse (§ 9, phase C), en s'appuyant sur l'**annexe A** (réponses proposées) :

1. Politique de confidentialité : `https://kantin.eu/confidentialite`.
2. Accès à l'application : les identifiants du compte de relecture **saisis par le propriétaire** dans la console, avec les instructions de l'annexe A.
3. Publicités : non. Classification : questionnaire, réponses honnêtes (contenu partagé entre utilisateurs). Public cible : 18 ans et plus. Autres déclarations : non.
4. Sécurité des données : l'annexe A ; **relire chaque réponse avec l'assistant** (collecte, partage, finalité, chiffrement en transit, suppression) ; lien de suppression : `https://kantin.eu/suppression-de-compte`.
5. Fiche principale : titre, descriptions (STORE-014), icône, image de présentation, captures (STORE-015), catégorie, e-mail d'assistance (l'alias dédié), site `https://kantin.eu`.
6. Pays : France.
7. Relire l'ensemble avec l'assistant : chaque réponse correspond à ce que fait réellement l'application (colonne « D'où ça vient » de l'annexe A) ; consigner les libellés exacts de la console dans `publication-play-store.md` pour la prochaine fois.

## Hors portée

L'envoi du bundle (STORE-016, STORE-018) ; la demande de production (STORE-018).

## Critères d'acceptation

- Étant donné la page « Contenu de l'application », quand le propriétaire l'ouvre, alors toutes les déclarations sont marquées comme terminées, sans avertissement.
- Étant donné le formulaire « sécurité des données », alors la suppression de compte est déclarée et le lien est celui de `/suppression-de-compte`.
- Étant donné la fiche, alors aucun nom ni logo d'enseigne n'apparaît dans le titre, l'icône, l'image ni les captures ; la description contient la phrase de non-affiliation si D4 = oui.
- Étant donné la console, le dépôt et le chat, alors aucun mot de passe du compte de relecture n'a été transmis à l'assistant.

## Tests exigés

Aucun automatique. Preuve : captures décrites par le propriétaire, aucune alerte.

## Definition of Done

Déclarations et fiche complètes ; libellés exacts consignés ; roadmap cochée avec la date.

## Pièges connus

- Les réponses de « sécurité des données » **sont publiques** sur la fiche et doivent rester vraies à chaque version : toute nouvelle donnée collectée (par exemple le signalement de recettes, STORE-011) impose de les revoir.
- La collecte via une *webview* dont l'application contrôle le code (le drive et son agent) compte comme collecte : ne pas répondre « aucune donnée » sur cet écran.
- « Public cible 18 ans et plus » : ne pas ajouter dans la fiche un style ou un texte destiné aux enfants.
- Les libellés français de la console peuvent différer des libellés anglais de l'aide : décrire l'écran, ne pas deviner.
- Ne pas cocher « contient des publicités » par prudence : c'est une fausse déclaration qui peut être vérifiée.
