<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/logo-dark.svg">
    <img src="art/logo.svg" alt="inventaire.cloud" width="420">
  </picture>
</p>

<p align="center">
  L'outil d'inventaire libre, gratuit et simple, pour les pros comme pour les particuliers.
</p>

---

Un inventaire, c'est un tableau dont vous choisissez les colonnes : quantités, prix, dates, listes de choix, codes-barres, photos… Partez d'une page blanche ou d'un modèle (cave à vin, parc informatique, stock de boutique, inventaire du logement…), remplissez-le depuis votre ordinateur ou votre téléphone, puis exportez-le, partagez-le ou faites-le signer.

La version hébergée ouvrira prochainement sur [inventaire.cloud](https://inventaire.cloud). En attendant, vous pouvez [installer votre propre instance](#héberger-sa-propre-instance).

## Fonctionnalités

- **20 types de champs**, dont montant, note, couleur, code-barres (EAN, UPC, ISBN vérifiés), fichier et photo.
- **Modèles prêts à l'emploi**, en français et en anglais.
- **Tableau rapide** : recherche, tri, filtres, totaux, modification dans les cellules, vue galerie.
- **Scan des codes-barres** avec la caméra du téléphone, et application installable (PWA).
- **Historique** de chaque modification et **pièces jointes** sur les articles.
- **Saisie par photo** (facultative) : Claude propose les valeurs, vous validez.
- **Espaces de travail** avec rôles (propriétaire, éditeur, lecteur), invitations par e-mail et liens de partage en lecture seule.
- **Exports** XLSX, ODS, CSV, JSON, PDF ou ZIP avec les fichiers ; **imports** depuis un tableur.
- **Version officielle** : un état figé et horodaté, en PDF, envoyé en signature électronique via [Documenso](https://documenso.com).
- **API REST** avec jetons personnels et documentation OpenAPI sur `/docs/api`.
- **Respect de la vie privée** : connexion sans mot de passe (code par e-mail), aucun traceur ni CDN, export et suppression du compte en libre-service.

## Héberger sa propre instance

L'image Docker contient tout le nécessaire (PHP, serveur web, interface compilée) :

```bash
git clone https://github.com/enzomerand/inventaire.cloud.git
cd inventaire.cloud/web/docker
cp .env.example .env   # renseignez au moins DB_PASSWORD
docker compose up -d --build
```

L'assistant d'installation s'ouvre sur <http://localhost:8080> ; son jeton s'obtient avec `docker compose exec app php artisan inventaire:install-token`. PostgreSQL, MySQL et MariaDB sont pris en charge, les fichiers vont sur disque ou sur un stockage S3. Placez un reverse proxy HTTPS devant le conteneur et sauvegardez le volume `storage` avec la base : il contient la clé de chiffrement de l'instance.

Configuration complète : [documentation technique](web/README.md#self-hosting-with-docker) (en anglais).

## Développer

Laravel 13 (PHP 8.4+), Blade, [Tabler](https://tabler.io) et Alpine.js. Il faut Composer 2, Node.js 20.19+ et Docker.

```bash
cd web
docker compose -f compose.dev.yaml up -d --wait
composer setup
composer dev
```

L'application tourne sur <http://localhost:8000>, les e-mails arrivent dans Mailpit sur <http://localhost:8025>. Avant une pull request : `composer lint && composer analyse && composer test` puis `npm run lint && npm test && npm run build` (la suite PHP passe sur les trois moteurs de base de données). Tout le détail est dans [`web/README.md`](web/README.md).

## Contribuer

Les contributions sont les bienvenues, en particulier les **modèles d'inventaire** : un fichier JSON par modèle dans [`web/inventory-templates/`](web/inventory-templates), vérifié par les tests. Pour un bug ou une idée, ouvrez une [issue](https://github.com/enzomerand/inventaire.cloud/issues).

## Licence

[GNU Affero GPL v3.0](LICENSE) : si vous proposez une version modifiée en ligne, vous devez en publier le code source sous la même licence.
