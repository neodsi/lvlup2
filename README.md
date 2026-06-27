# LVL UP

## Installation

```bash
composer install
npm install
cp .env .env.local
# Renseigner les variables dans .env.local
php bin/console doctrine:schema:update --force
```

## Déploiement (production)

### Permissions — ACL

Les dossiers `public/media`, `public/uploads` et `var` doivent être accessibles en écriture par le user web et le user deploy.

```bash
HTTPDUSER=$(ps axo user,comm | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | head -1 | awk '{print $1}')

sudo setfacl -dR -m u:"$HTTPDUSER":rwX -m u:$(whoami):rwX public/media public/uploads var
sudo setfacl -R  -m u:"$HTTPDUSER":rwX -m u:$(whoami):rwX public/media public/uploads var
```

La première commande configure les ACL par défaut (nouveaux fichiers créés hériteront des droits), la seconde applique sur l'existant.

Pour vérifier :

```bash
getfacl public/media
```
