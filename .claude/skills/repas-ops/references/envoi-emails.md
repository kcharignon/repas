# Mettre en place l'envoi d'emails (Brevo)

But : que le back puisse envoyer les liens de confirmation d'adresse (ADR 0020). DigitalOcean bloque le SMTP sortant (ports 25, 465, 587) : on passe par l'**API HTTPS de Brevo** (`brevo+api://`), port 443. Gratuit jusqu'à 300 emails par jour. **Une étape par message**, l'utilisateur fait les clics, je vérifie ce que je peux en lecture seule.

État du DNS de `kantin.eu` (vérifié le 2026-10-16, `https://dns.google/resolve?name=kantin.eu&type=TXT`) : zone chez DigitalOcean (`ns1/2/3.digitalocean.com`), aucun enregistrement TXT, MX ni DMARC : rien à fusionner avec ceux de Brevo.

1. **Compte Brevo** : l'utilisateur crée un compte gratuit sur brevo.com et confirme son adresse.
2. **Domaine d'envoi** : dans Brevo, *Senders, domains & dedicated IPs* → *Domains* → *Add a domain* → `kantin.eu`. Brevo affiche des enregistrements DNS à créer (code de vérification Brevo, DKIM, DMARC). Définir *domaine* = terme à expliquer : prouve que Brevo a le droit d'écrire au nom de `kantin.eu`.
3. **Enregistrements DNS** : chez DigitalOcean, *Networking* → *Domains* → `kantin.eu` → *Create record*, un par ligne affichée par Brevo (type, nom, valeur recopiés tels quels ; le nom est relatif à `kantin.eu`, DigitalOcean ajoute le domaine). Je vérifie ensuite par la requête DNS publique ci-dessus (`type=TXT`, `type=CNAME`, nom `brevo1._domainkey.kantin.eu`, etc.) ; la propagation prend de quelques minutes à une heure. Puis *Authenticate* dans Brevo.
4. **Expéditeur** : dans Brevo, un expéditeur `no-reply@kantin.eu` (nom « Repas »), sur le domaine authentifié.
5. **Clé d'API** : Brevo → *SMTP & API* → *API Keys* → *Generate a new API key* (commence par `xkeysib-`). Elle ne s'affiche qu'une fois : **l'utilisateur la garde, ne la colle jamais dans le chat**.
6. **Écrire dans `/opt/repas/.env`** : script que l'utilisateur lance (`! ssh … 'bash -s' < script`) ; la clé est saisie sur le serveur (`read -rs`, sans écho) et jamais écrite ailleurs que dans le `.env` (droits 600). Variables :
   `MAILER_DSN=brevo+api://<clé>@default` et `MAILER_FROM="Repas <no-reply@kantin.eu>"`. Un `MAILER_DSN` déjà présent est remplacé, pas dupliqué ; sauvegarde du `.env` avant (`cp -p .env .env.avant-brevo`). Voir `references/ecrire-mailer-env.sh` pour le script.
7. **Livrer** le back puis le front (`references/release.md`) : sans ces deux variables, `docker compose` refuse de démarrer le back et le déploiement revient en arrière tout seul.
8. **Essai réel** : l'utilisateur s'inscrit avec une vraie adresse sur `https://kantin.eu/inscription`, reçoit le message (regarder aussi les courriers indésirables), ouvre le lien, se connecte. Si rien n'arrive : `ssh … 'cd /opt/repas && docker compose logs --tail 100 php | grep -i "confirmation"'` (lecture seule) puis la page *Logs* / *Transactional* de Brevo.
