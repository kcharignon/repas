# AUTH-001 — Décisions du propriétaire et passage de l'ADR 0024 à `Accepted`

- **Dépôt** : back (documentation) ; assistant PO / architecte avec l'utilisateur
- **Jalon** : M0 Décisions et console
- **Taille** : S
- **Dépend de** : —
- **Faisable sans téléphone** : oui
- **Statut** : fait (2026-10-19 : D1 oui, D2 Gmail seulement, D3 on attend « mot de passe oublié », D4 non, D5 hors lot + tronc commun Kotlin ; ADR 0024 `Accepted`)
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) § 0 et § 4.4 ; [ADR 0024](../../adr/0024-connexion-avec-google.md) ; skill `repas-po` (une question précise, un défaut raisonnable) ; mémoire « Rôle sur le chantier » (l'utilisateur n'est pas anglophone natif).

## Contexte

Le cadrage recommande une réponse à chacune des cinq décisions D1 à D5. Aucune ne bloque la lecture de l'analyse, mais D1 et D2 fixent la politique de compte du ticket AUTH-006, D4 conditionne AUTH-010 et une partie d'AUTH-002, D3 décide d'AUTH-014, D5 d'AUTH-009.

## Portée

1. Présenter à l'utilisateur **une décision par message**, en français simple : la recommandation d'abord, ce qu'elle change pour ses proches, l'alternative et son prix. Ne rien lui demander dont le défaut est raisonnable : demander « d'accord ? ».
   - **D1** : un proche qui se connecte avec Google pour la première fois obtient-il un compte tout de suite (recommandé), sans que l'administrateur l'active ?
   - **D2** : un proche déjà inscrit avec un mot de passe et une adresse Gmail passe à Google sans rien faire (recommandé) ; avec une autre adresse (Orange, entreprise…), il reste au mot de passe.
   - **D3** : les comptes créés par Google n'ont pas de mot de passe. Est-ce acceptable jusqu'à « mot de passe oublié » (recommandé), ou veut-il pouvoir en poser un tout de suite (AUTH-014) ?
   - **D4** : veut-il que le nom « Repas » s'affiche dans la fenêtre Google (vérification de marque, 2 à 3 jours, page publique de conditions) ? Défaut : non pour l'instant ; la page publique de conditions est faite quand même (AUTH-010).
   - **D5** : des proches utilisent-ils le site depuis un iPhone ? Défaut : non, Safari garde le mot de passe.
2. Noter chaque réponse (date, une ligne) dans la colonne « Décision » d'un tableau ajouté à `docs/auth/connexion-google.md` § 4.4, et dans le point 9 de l'ADR 0024 si D1 ou D2 changent.
3. Passer l'ADR 0024 à `Accepted` (statut, date) et sa ligne de `docs/adr/README.md`.
4. Ajuster les tickets concernés si une réponse diffère de la recommandation (voir le tableau ci-dessous) et la roadmap.

| Réponse | Tickets à modifier |
|---|---|
| D1 « PENDING + activation » | AUTH-006 (statut de création), nouveau ticket de notification de l'administrateur ; AUTH-009 (écran « en attente ») |
| D1 « sur invitation » | AUTH-006, nouveau ticket de liste d'invités |
| D2 « Gmail ou Workspace » | AUTH-006 (`emailIsAuthoritative`), AUTH-003 (lire `hd`) |
| D2 « jamais » | AUTH-006 : la liaison automatique disparaît ; l'administrateur lie à la main (nouveau ticket) |
| D3 « maintenant » | AUTH-014 passe de facultatif à obligatoire avant AUTH-013 |
| D4 « oui » | AUTH-002 (étapes de marque), AUTH-010 (page publique avant la demande) |
| D5 « oui » | nouveau ticket : mode `redirect` de GIS (route de retour, `g_csrf_token`), AUTH-009 sans masquage iOS |

## Hors portée

Tout code. La vérification de marque elle-même (AUTH-002, humain).

## Critères d'acceptation

- Étant donné les cinq réponses, quand on ouvre l'ADR 0024, alors son statut est `Accepted` et le tableau des décisions est rempli et daté.
- Étant donné une réponse différente de la recommandation, quand on relit la roadmap, alors les tickets du tableau ci-dessus sont corrigés (ou un ticket est ajouté) dans la même PR.

## Tests exigés

Aucun (documentation). Relire les liens relatifs.

## Definition of Done

PR de documentation verte, commits courts en français sans `Co-Authored-By`, `CLAUDE.md` inchangé (la ligne du chantier existe déjà).

## Pièges connus

- Ne pas poser les cinq questions d'un coup : l'utilisateur répond mal à une liste.
- D1 et D2 sont des décisions difficiles à défaire une fois des comptes créés ou liés : les poser avant AUTH-006, pas après.
