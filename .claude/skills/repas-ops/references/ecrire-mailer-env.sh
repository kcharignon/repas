#!/usr/bin/env bash
# Écrit MAILER_DSN et MAILER_FROM dans /opt/repas/.env (ADR 0020). À lancer par l'utilisateur :
#   ssh -t -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s' < .claude/skills/repas-ops/references/ecrire-mailer-env.sh
# La clé d'API Brevo est saisie ici, sans écho : elle ne passe ni par le chat, ni par un fichier du dépôt, ni par l'historique du shell.
set -euo pipefail
env_file=/opt/repas/.env
[[ -f $env_file ]] || { echo "ECHEC : $env_file introuvable"; exit 1; }

read -rsp "Clé d'API Brevo (rien ne s'affiche en la collant), puis Entrée : " key < /dev/tty
echo
[[ $key == xkeysib-* ]] || { echo "ECHEC : une clé d'API Brevo commence par xkeysib-"; exit 1; }
[[ $key =~ ^[A-Za-z0-9_-]+$ ]] || { echo "ECHEC : caractères inattendus dans la clé"; exit 1; }

cp -p "$env_file" "$env_file.avant-brevo"
# Retire d'éventuelles lignes existantes, puis ajoute les nouvelles
grep -v -E '^(MAILER_DSN|MAILER_FROM)=' "$env_file.avant-brevo" > "$env_file.nouveau" || true
{
	echo "MAILER_DSN=brevo+api://$key@default"
	echo 'MAILER_FROM="Repas <no-reply@kantin.eu>"'
} >> "$env_file.nouveau"
chmod 600 "$env_file.nouveau"
chown --reference="$env_file.avant-brevo" "$env_file.nouveau"
mv "$env_file.nouveau" "$env_file"
unset key

echo "ok : MAILER_DSN et MAILER_FROM écrits dans $env_file (copie : $env_file.avant-brevo)"
grep -c -E '^(MAILER_DSN|MAILER_FROM)=' "$env_file" | sed 's/^/lignes MAILER_* : /'
