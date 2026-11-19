# Journal des listes de courses : lire les traces en SQL

Décision : [ADR 0041](../adr/0041-journal-des-listes-de-courses.md). Ces traces ne servent qu'à **comprendre** ce qui s'est passé sur une liste (un article « manquant » au drive, une ligne cochée toute seule, une liste disparue). L'application ne les lit pas : on les lit en SQL, sur le serveur, en lecture seule.

## Ce qu'on garde

| Où | Quoi |
|---|---|
| `shopping_list_row.checked_by` | `MANUAL` (la personne a coché la ligne) ou `DRIVE` (le passage au drive a mis l'article au panier). Vide si la ligne n'est pas cochée, **et** pour une ligne cochée avant le 2026-11-19 (origine inconnue, jamais devinée) |
| `shopping_list_row.checked_at` | quand elle a été cochée |
| `shopping_list_row.drive_outcome` | ce que le **dernier** passage au drive a fait de la ligne : `IN_CART` (au panier, cochée par le drive), `NOT_IN_CART` (**refusée par le drive** : produit choisi que le panier n'a pas pris, la ligne reste décochée), `UNAVAILABLE` (aucun produit disponible), `SKIPPED` (« passer cette fois »), `HAVE_AT_HOME` (« j'en ai toujours »), `FAILED` (erreur de l'agent). Vide : la ligne n'a jamais été au drive |
| `shopping_list_row.drive_reason` | un code, quand l'agent le connaît (aujourd'hui seulement avec `FAILED` : `BLOCKED`, `NETWORK`…). **Pas la raison du drive pour un refus** : l'agent sait seulement que le panier « n'a rien pris » |
| `shopping_list_row.drive_attempted_at` | quand le passage a eu lieu |
| `shopping_list_archive` | **une ligne par liste supprimée**, copiée juste avant sa suppression : la liste, ses lignes (`lines`, JSONB, avec les noms d'ingrédient et d'unité et les mêmes colonnes que ci-dessus) et ses repas (`meals`, JSONB, avec le nom de la recette). Aucune jointure à faire |

Les listes **terminées** sont supprimées (donc archivées, cause `CLEANED_AFTER_COMPLETION`) quand la personne crée une nouvelle liste ; une liste supprimée à la main est archivée avec la cause `DELETED_BY_USER`. Supprimer un **compte** efface aussi ses archives.

Le site doit envoyer `notAdded` (le détail de ce que le drive n'a pas mis au panier) pour que `NOT_IN_CART`, `UNAVAILABLE`, `SKIPPED`, `HAVE_AT_HOME` et `FAILED` soient écrits ; sans lui, seul `IN_CART` l'est.

## Se connecter (lecture seule)

Sur le serveur : `docker compose exec database psql -U app repas` (voir `docs/production.md`). Ne rien écrire.

## Requêtes

Une liste en cours (ou envoyée) : qui a coché chaque ligne, et ce que le drive en a fait.

```sql
SELECT i.name AS ingredient, r.quantity, r.checked, r.checked_by, r.checked_at,
       r.drive_outcome, r.drive_reason, r.drive_attempted_at
FROM shopping_list_row r
LEFT JOIN ingredient i ON i.slug = r.ingredient
WHERE r.shopping_list = '<identifiant de la liste>'
ORDER BY i.name;
```

Les articles que le drive a refusés et qui sont toujours à acheter (listes non terminées).

```sql
SELECT l.id AS liste, l.name, l.status, i.name AS ingredient, r.drive_attempted_at
FROM shopping_list_row r
JOIN shopping_list l ON l.id = r.shopping_list
LEFT JOIN ingredient i ON i.slug = r.ingredient
WHERE r.drive_outcome = 'NOT_IN_CART' AND r.checked = false AND l.status <> 'COMPLETED'
ORDER BY r.drive_attempted_at DESC;
```

Les articles refusés par le drive puis **cochés à la main** (la personne les a achetés ailleurs ou autrement).

```sql
SELECT l.id AS liste, i.name AS ingredient, r.drive_attempted_at AS refuse_le, r.checked_at AS coche_le
FROM shopping_list_row r
JOIN shopping_list l ON l.id = r.shopping_list
LEFT JOIN ingredient i ON i.slug = r.ingredient
WHERE r.drive_outcome = 'NOT_IN_CART' AND r.checked_by = 'MANUAL'
ORDER BY r.checked_at DESC;
```

Les listes envoyées au drive dont **aucune** ligne n'a été cochée par le drive (le passage n'a rien rapporté, ou rien n'a été au panier).

```sql
SELECT l.id AS liste, l.owner, l.name, l.sent_at
FROM shopping_list l
WHERE l.status = 'SENT'
  AND NOT EXISTS (SELECT 1 FROM shopping_list_row r WHERE r.shopping_list = l.id AND r.checked_by = 'DRIVE')
ORDER BY l.sent_at DESC;
```

Les lignes cochées dont on ne connaît pas l'origine (cochées avant le journal).

```sql
SELECT count(*) AS lignes_cochees_sans_origine
FROM shopping_list_row
WHERE checked = true AND checked_by IS NULL;
```

Les listes supprimées, la plus récente d'abord : à quoi elles ressemblaient (statut à la suppression, lignes, qui les avait cochées).

```sql
SELECT a.archived_at, a.cause, a.name, a.status, a.line_count,
       (SELECT count(*) FROM jsonb_array_elements(a.lines) AS line WHERE line->>'checked_by' = 'MANUAL') AS cochees_a_la_main,
       (SELECT count(*) FROM jsonb_array_elements(a.lines) AS line WHERE line->>'checked_by' = 'DRIVE') AS cochees_par_le_drive,
       (SELECT count(*) FROM jsonb_array_elements(a.lines) AS line WHERE line->>'drive_outcome' IN ('NOT_IN_CART', 'UNAVAILABLE', 'FAILED')) AS non_mises_au_panier
FROM shopping_list_archive a
ORDER BY a.archived_at DESC
LIMIT 50;
```

Dans les archives : toutes les lignes refusées par le drive, avec la liste qui les portait.

```sql
SELECT a.archived_at, a.shopping_list_id AS liste, line->>'ingredient_name' AS ingredient,
       line->>'drive_outcome' AS issue, line->>'drive_reason' AS raison,
       line->>'checked' AS cochee, line->>'checked_by' AS cochee_par
FROM shopping_list_archive a, jsonb_array_elements(a.lines) AS line
WHERE line->>'drive_outcome' = 'NOT_IN_CART'
ORDER BY a.archived_at DESC;
```

Les issues du drive, comptées, listes en cours et archives ensemble.

```sql
SELECT issue, count(*) AS lignes
FROM (
    SELECT r.drive_outcome AS issue FROM shopping_list_row r WHERE r.drive_outcome IS NOT NULL
    UNION ALL
    SELECT line->>'drive_outcome' FROM shopping_list_archive a, jsonb_array_elements(a.lines) AS line WHERE line->>'drive_outcome' IS NOT NULL
) AS toutes
GROUP BY issue
ORDER BY lignes DESC;
```

Pour retrouver une liste supprimée dont on a l'identifiant, ou toutes celles d'un compte.

```sql
SELECT a.id, a.archived_at, a.cause, a.name, a.status, a.lines, a.meals
FROM shopping_list_archive a
WHERE a.shopping_list_id = '<identifiant de la liste>' OR a.owner_id = '<identifiant du compte>'
ORDER BY a.archived_at DESC;
```

## Ce que le journal ne dit pas

- **Pourquoi** le drive a refusé un produit (`NOT_IN_CART`) : l'agent ne sait que « quantité obtenue inférieure à 1 ». Une raison plus fine demanderait de lire la réponse du panier de l'enseigne dans l'agent (une nouvelle version de l'application) : à décider si le journal montre que c'est utile.
- **L'enseigne** du passage (Leclerc ou Auchan) : le contrat de la route ne la porte pas.
- Les **allers-retours** d'une ligne (cochée, décochée, recochée) : seul l'état courant est gardé.
- Les lignes **supprimées** d'une liste avant sa suppression (retirer un repas, un produit) : l'archive reflète la liste à l'instant de sa suppression.
