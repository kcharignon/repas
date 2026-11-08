# MAR-001 — Lire une page Marmiton, sans risque (back)

- **Dépôt** : **back** ; suite : MAR-003 (qui utilise ce lecteur)
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (appel sortant vers un site tiers à partir d'une adresse saisie par un utilisateur : SSRF, taille, délai)
- **Dépend de** : — (peut se faire en parallèle de MAR-002 et MAR-004)
- **Statut** : à faire
- **Lire d'abord** : [ADR 0037](../../adr/0037-copier-une-recette-depuis-marmiton.md) décisions 12 et 13 ; `CLAUDE.md` ; skill `repas-dev` ; issue #434.

## But

Donner au reste du code un port qui, pour une adresse de recette Marmiton, rend les faits utiles de la recette ou une erreur nommée, et rien d'autre. Rien n'est écrit en base, aucun endpoint n'est ajouté ici.

## À livrer

1. **`Repas\Repas\Domain\Model\MarmitonUrl`** (pur, `final readonly`) : `MarmitonUrl::parse(string $raw): self` (lève `MarmitonImportException::urlInvalid()`), `->id` (chiffres, 1 à 12), `->canonical()` (`https://www.marmiton.org/recettes/recette_{slug}_{id}.aspx`), `->fetchUrl()` (même chose). Règles de la décision 12 : `https` (ou `http`, relevé en `https`), hôte `www.marmiton.org` ou `marmiton.org` (insensible à la casse, **pas** un sous-domaine, pas `user@`, pas de port, pas d'IDN ni de variante Unicode), chemin `/recettes/recette_{slug}_{id}.aspx` avec `slug` de `[a-z0-9-]{1,150}`, requête et fragment écartés, au plus 300 caractères, espaces autour retirés. Aucune autre adresse n'est jamais construite.
2. **`Repas\Repas\Domain\Model\MarmitonRecipePage`** (`final readonly`) : `canonicalUrl` (adresse canonique lue après la redirection éventuelle, revalidée par `MarmitonUrl`), `title` (texte brut), `servingsText` (`recipeYield` brut, ou null), `category` (`recipeCategory` brut, ou null), `ingredientLines` (`list<string>`, 1 à 100 lignes, chacune coupée à 300 caractères, espaces normalisés). Pas de logique de nettoyage ici (MAR-002).
3. **`Repas\Repas\Domain\Interface\MarmitonPageReader`** : `read(MarmitonUrl $url): MarmitonRecipePage` ; `@throws MarmitonImportException` (`recipeNotFound` si 404 ou 410, `recipeUnreadable`, `unavailable`).
4. **`Repas\Repas\Domain\Exception\MarmitonImportException`** (étend `DomainException`) : fabriques `urlInvalid()`, `recipeNotFound()`, `recipeUnreadable()`, `unavailable()`, dont les messages sont les constantes `MARMITON_URL_INVALID`, `MARMITON_RECIPE_NOT_FOUND`, `MARMITON_RECIPE_UNREADABLE`, `MARMITON_UNAVAILABLE` ; le code HTTP est posé par le controller de MAR-003 (422, 422, 422, 502).
5. **Adaptateur** `Repas\Repas\Infrastructure\Marmiton\MarmitonHttpPageReader` (Symfony HttpClient, services dans `config/`), qui respecte la décision 13 : un `GET`, **hôte figé `www.marmiton.org`** (pas de résolution vers une adresse privée : vérifier l'IP résolue n'est pas dans une plage privée, loopback, lien local ou réservée, ou n'accepter que la résolution publique ; documenter ce qui est choisi), `max_redirects: 0` puis **une** redirection suivie à la main, seulement si `Location` est une adresse qui passe `MarmitonUrl::parse` (sinon `unavailable`), délai total 8 s, corps lu en flux et abandonné à 2 Mio (`unavailable`), `Content-Type` `text/html` exigé, en-tête `User-Agent: Repas/1.0 (copie de recette demandée par un utilisateur)`, `Accept-Language: fr`, aucun cookie, aucun autre en-tête. Extraction : parcourir **tous** les blocs `<script type="application/ld+json">`, décoder en JSON (`JSON_THROW_ON_ERROR`, profondeur 16), chercher un objet `@type` `Recipe` (ou une liste contenant `Recipe`) y compris dans `@graph` ; `name` obligatoire non vide, `recipeIngredient` obligatoire non vide, chaînes seulement (ignorer le reste du bloc) ; **jamais d'évaluation, ni de lien suivi dans la page**. Réponse 404 ou 410 : `recipeNotFound` ; autre 4xx, 5xx, délai, erreur réseau, TLS : `unavailable` ; page sans bloc lisible : `recipeUnreadable`.
6. **Double de test** `MarmitonInMemoryPageReader` dans `tests/Helper/` (réponses programmées par identifiant, enregistre ses appels) — c'est ce que MAR-003 utilisera.

## Tests (le plus important du ticket)

- `MarmitonUrlTest` : les adresses valides (avec requête, avec `http`, sans `www`, en majuscules) donnent l'adresse canonique ; sont refusées (une ligne chacune) : autre hôte (`marmiton.org.evil.com`, `www.marmiton.org.evil.com`, `evilmarmiton.org`, `m.marmiton.org`, `user@www.marmiton.org`, `www.marmiton.org:8080`, IP, `localhost`, `www.marmiton.org@evil.com`), autre schéma (`ftp`, `javascript`, `file`, `//host`), autre chemin (`/recettes/recherche.aspx`, `/recettes/recette_x.aspx`, `/recettes/recette_x_abc.aspx`, `..`, `%2e%2e`, retour à la ligne, octet nul), identifiant de 13 chiffres, texte vide ou de 5 000 caractères.
- `MarmitonPageReaderTest` avec un client HTTP simulé (`MockHttpClient`) : page valide (fixture ci-dessous), `@graph`, liste de types, 404, 410, 500, délai, redirection vers la bonne forme (suivie une fois), redirection vers un autre hôte (`unavailable`), deux redirections (`unavailable`), corps de plus de 2 Mio (abandonné sans tout lire), `Content-Type` JSON, JSON cassé, bloc sans `Recipe`, `Recipe` sans ingrédient, ingrédients non textuels, nom vide ; l'appel porte l'agent et l'hôte attendus et **aucun** en-tête de plus.
- Fixtures sous `tests/Repas/Fixture/Marmiton/` : le bloc `ld+json` **réduit aux faits** (nom, catégorie, personnes, ingrédients) de la page d'exemple `recette_the-tarte-au-citron-meringuee_22082.aspx` (13 lignes : voir l'issue) enveloppé dans un HTML minimal ; **pas de page entière, pas d'étapes, pas de photo**.
- Un test de couche : le port est dans `Domain\Interface`, l'adaptateur dans `Infrastructure\Marmiton`, rien d'autre ne l'importe (Deptrac).

## Critères d'acceptation

- [ ] Aucune adresse construite à partir d'autre chose que l'identifiant ; `MarmitonUrlTest` couvre la liste ci-dessus.
- [ ] `make test` et `make quality` verts ; PHPStan sans nouvelle entrée de baseline.
- [ ] Aucune dépendance Composer de plus (HttpClient est déjà là, sinon le dire et passer `make audit`).
- [ ] L'appel réel a été essayé une fois à la main sur la page d'exemple (résultat noté dans la PR, jamais en test automatique).
- [ ] PR vers `main`, description au gabarit, CI verte.
