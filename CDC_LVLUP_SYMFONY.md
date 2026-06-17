# Cahier des Charges — lvlup (Symfony / MariaDB)

> Rewrite complet du projet lvlup. Stack d'origine : SvelteKit + Supabase (PostgreSQL). Nouvelle stack : Symfony 7 monolithique + MariaDB + Twig + Alpine.js + Tailwind CSS. Ce document est le cahier des charges opérationnel destiné à être donné directement à Claude pour implémenter le projet.

---

## Table des matières

1. [Contexte et objectif du rewrite](#1-contexte-et-objectif-du-rewrite)
2. [Stack technique](#2-stack-technique)
3. [Architecture du projet](#3-architecture-du-projet)
4. [Modèle de données](#4-modèle-de-données)
5. [Système d'authentification](#5-système-dauthentification)
6. [Système de rôles et droits (RBAC)](#6-système-de-rôles-et-droits-rbac)
7. [Catalogue des routes](#7-catalogue-des-routes)
8. [Fonctionnalités détaillées](#8-fonctionnalités-détaillées)
9. [API interne (controllers JSON)](#9-api-interne-controllers-json)
10. [Jobs planifiés (Crons)](#10-jobs-planifiés-crons)
11. [Webhooks entrants](#11-webhooks-entrants)
12. [Système de paiement Stripe](#12-système-de-paiement-stripe)
13. [Système d'emails (Resend)](#13-système-demails-resend)
14. [Upload de fichiers](#14-upload-de-fichiers)
15. [Internationalisation](#15-internationalisation)
16. [Sécurité — règles non négociables](#16-sécurité--règles-non-négociables)
17. [Bonnes pratiques et standards de code](#17-bonnes-pratiques-et-standards-de-code)
18. [Environnements et déploiement](#18-environnements-et-déploiement)

---

## 1. Contexte et objectif du rewrite

**lvlup** est un SaaS B2B multi-tenant de gestion d'écoles de danse. Il permet à des gérants d'école de piloter leur activité en ligne (inscriptions, paiements, cours, membres) et à leurs élèves d'accéder à un espace personnel.

### Pourquoi ce rewrite

Le projet existant (SvelteKit + Supabase) accumule une dette technique documentée : 23 failles de sécurité non corrigées, 728 `console.log` en production, un god file de 921 lignes, des UUIDs clients hardcodés dans les crons, et des échecs silencieux dans les webhooks critiques. L'objectif du rewrite est de repartir sur des fondations propres, avec une stack maîtrisée, des règles de sécurité appliquées dès le départ, et une architecture qui élimine structurellement les dérives du projet original.

### Périmètre

Rewrite fonctionnel à l'identique de ce qui est terminé dans l'original. Les 11 routes marquées WIP/placeholder sont exclues.

### Profils utilisateurs

| Profil | Description |
|---|---|
| **Gérant / admin** | Crée et configure l'école, gère saisons, cours, membres, paiements |
| **Élève** | S'inscrit aux cours, consulte son espace, paie ses échéances |
| **Professeur** | Accès lecture aux fiches élèves, inscriptions, présences |
| **Super-admin** | Gestion plateforme, impersonation d'utilisateurs |

### Concept de tenant (Team / École)

Chaque école est un tenant isolé (`team`). Toutes les données sont scopées par `team_id`. Un utilisateur peut appartenir à plusieurs teams avec des rôles différents. Une team a toujours une `current_season_id` active.

---

## 2. Stack technique

| Couche | Technologie | Version cible |
|---|---|---|
| Langage | PHP | 8.1+ |
| Framework | Symfony | 6.4 LTS |
| ORM | Doctrine ORM | 2.x |
| Base de données | MariaDB | 10.11+ |
| Templating | Twig | 3.x |
| CSS | Tailwind CSS | 3.x (via standalone CLI ou npm) |
| JS interactivité | Alpine.js | 3.x (CDN) — uniquement si nécessaire |
| Paiements | Stripe PHP SDK | dernière stable |
| Emails | Resend (HTTP API directe) | — |
| PDF | Dompdf | — |
| Récurrence | Recurr (rrule PHP) | — |
| Sécurité | symfony/security-bundle | inclus Symfony |
| Validation | symfony/validator | inclus Symfony |
| Formulaires | symfony/form | inclus Symfony |
| Media / images | LiipImagineBundle | dernière stable |
| Tâches planifiées | cron système (Debian) | — |
| Logging | Monolog (PSR-3) | inclus Symfony |
| Tests | PHPUnit + Symfony test utilities | — |
| Monitoring | Sentry PHP SDK | — |

### Ce qui est écarté

- Supabase (remplacé par MariaDB + Doctrine)
- Vercel (déploiement sur Debian/Apache)
- Supabase Auth (remplacé par symfony/security + sessions PHP)
- Supabase Storage (remplacé par stockage local filesystem)
- RLS PostgreSQL (remplacé par des vérifications explicites dans les services)

---

## 3. Architecture du projet

### Structure des répertoires

```
lvlup/
├── config/
│   ├── packages/          # Config Symfony (doctrine, security, mailer, etc.)
│   ├── routes/            # Déclarations de routes
│   └── services.yaml
├── migrations/            # Migrations Doctrine
├── public/
│   ├── index.php
│   └── assets/            # CSS compilé, JS, images statiques
├── src/
│   ├── Controller/
│   │   ├── Auth/          # Login, signup, reset password, invitations
│   │   ├── App/           # Pages protégées (home, profile)
│   │   ├── School/        # Interface école (cours, membres, commandes, settings)
│   │   ├── Shop/          # Pages publiques shop
│   │   ├── Admin/         # Panel super-admin
│   │   ├── Api/           # Endpoints JSON internes (v1)
│   │   ├── Cron/          # Endpoints crons sécurisés
│   │   └── Webhook/       # Webhooks Stripe, Resend
│   ├── Entity/            # Entités Doctrine (une par table)
│   ├── Repository/        # Repositories Doctrine (requêtes)
│   ├── Service/           # Logique métier (un service par domaine)
│   │   ├── Auth/
│   │   ├── Order/
│   │   ├── Payment/
│   │   ├── Season/
│   │   ├── Event/
│   │   ├── Member/
│   │   ├── Stripe/
│   │   ├── Email/
│   │   └── Pdf/
│   ├── Security/
│   │   ├── Voter/         # Voters Symfony pour le RBAC
│   │   └── Guard/         # Authenticators
│   ├── Form/              # Types de formulaires Symfony (formulaires Twig)
│   ├── Enum/              # Enums PHP 8.1+ (statuts, types, rôles)
│   └── EventListener/     # Listeners (ex. soft delete, timestamps)
├── templates/
│   ├── layout/            # Layouts Twig (base, app, school, public, admin)
│   ├── auth/
│   ├── app/
│   ├── school/
│   ├── shop/
│   ├── admin/
│   ├── email/             # Templates emails (HTML)
│   └── components/        # Composants Twig réutilisables
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── Functional/
└── var/
    ├── log/
    ├── cache/
    └── uploads/           # Fichiers uploadés (avatar, images saison, etc.)
```

### Règles d'architecture

La logique métier réside exclusivement dans les `Service/`. Les controllers sont fins : ils reçoivent la requête, appellent un service, retournent une réponse. Aucune requête Doctrine directe dans les controllers. Les repositories encapsulent toutes les requêtes. Pour les endpoints JSON, les entrées sont validées via des classes dédiées annotées avec les contraintes Symfony Validator (pas de données brutes `$request->getContent()` utilisées directement). Les Voters Symfony gèrent intégralement le RBAC.

---

## 4. Modèle de données

### Conventions générales

- Toutes les tables ont un `id` UUID (généré côté PHP, type `CHAR(36)`).
- Toutes les tables ont `created_at DATETIME` et `updated_at DATETIME` (mis à jour automatiquement via un EventListener Doctrine).
- Le soft delete est implémenté via `deleted_at DATETIME NULL`. Toutes les requêtes filtrent `deleted_at IS NULL` par défaut via un Doctrine Filter activé globalement.
- Les colonnes JSON sont typées `JSON` en MariaDB et mappées en `array` ou DTO PHP.

### Entités

#### `users`
Compte utilisateur de la plateforme.
```
id              CHAR(36) PK
email           VARCHAR(255) UNIQUE NOT NULL
password_hash   VARCHAR(255) NOT NULL
app_role        ENUM('app_default','app_moderator','app_admin','app_super_admin') DEFAULT 'app_default'
email_verified  TINYINT(1) DEFAULT 0
reset_token     VARCHAR(255) NULL
reset_token_expires_at DATETIME NULL
created_at      DATETIME
updated_at      DATETIME
deleted_at      DATETIME NULL
```

#### `profiles`
Profil humain (un user peut avoir plusieurs profils — lui + ses enfants).
```
id              CHAR(36) PK
user_id         CHAR(36) FK users.id NULL  (NULL = profil manuel sans compte)
first_name      VARCHAR(100) NOT NULL
last_name       VARCHAR(100) NOT NULL
dob             DATE NULL
gender          ENUM('male','female','other') NULL
phone           VARCHAR(30) NULL
address_text    TEXT NULL
avatar_path     VARCHAR(500) NULL
is_primary      TINYINT(1) DEFAULT 1
created_at      DATETIME
updated_at      DATETIME
deleted_at      DATETIME NULL
```

#### `teams`
École / tenant.
```
id                      CHAR(36) PK
name                    VARCHAR(255) NOT NULL
type                    VARCHAR(100) NULL
status                  ENUM('waiting','accepted','refused','disabled') DEFAULT 'waiting'
currency                VARCHAR(3) DEFAULT 'EUR'
current_season_id       CHAR(36) FK seasons.id NULL
current_slug            VARCHAR(100) UNIQUE NULL
previous_slugs          JSON NULL
avatar_path             VARCHAR(500) NULL
carousel_paths          JSON NULL
invoice_prefix          VARCHAR(20) NULL
invoice_numbering_start INT DEFAULT 1
invoice_address         TEXT NULL
stripe_account_id       VARCHAR(100) NULL
stripe_account_status   ENUM('not_created','pending','active','restricted') DEFAULT 'not_created'
stripe_payment_capabilities JSON NULL
fee_paid_by             ENUM('student','team') DEFAULT 'student'
created_at              DATETIME
updated_at              DATETIME
deleted_at              DATETIME NULL
```

#### `seasons`
Saison d'une école.
```
id                      CHAR(36) PK
team_id                 CHAR(36) FK teams.id NOT NULL
name                    VARCHAR(255) NOT NULL
start_at                DATE NOT NULL
end_at                  DATE NOT NULL
registration_fee_id     CHAR(36) FK price_modifiers.id NULL
planning_image_path     VARCHAR(500) NULL
packages_image_path     VARCHAR(500) NULL
closures                JSON NULL   -- tableau de {start_at, end_at, label}
copy_id                 CHAR(36) FK seasons.id NULL
created_at              DATETIME
updated_at              DATETIME
deleted_at              DATETIME NULL
```

#### `activities`
Disciplines de danse proposées par une école.
```
id          CHAR(36) PK
team_id     CHAR(36) FK teams.id NOT NULL
season_id   CHAR(36) FK seasons.id NOT NULL
name        VARCHAR(255) NOT NULL
created_at  DATETIME
updated_at  DATETIME
deleted_at  DATETIME NULL
```

#### `rooms`
Salles d'une école.
```
id          CHAR(36) PK
team_id     CHAR(36) FK teams.id NOT NULL
season_id   CHAR(36) FK seasons.id NOT NULL
name        VARCHAR(255) NOT NULL
created_at  DATETIME
updated_at  DATETIME
deleted_at  DATETIME NULL
```

#### `levels`
Niveaux de cours.
```
id          CHAR(36) PK
team_id     CHAR(36) FK teams.id NOT NULL
season_id   CHAR(36) FK seasons.id NOT NULL
name        VARCHAR(255) NOT NULL
created_at  DATETIME
updated_at  DATETIME
deleted_at  DATETIME NULL
```

#### `age_groups`
Groupes d'âge.
```
id          CHAR(36) PK
team_id     CHAR(36) FK teams.id NOT NULL
season_id   CHAR(36) FK seasons.id NOT NULL
name        VARCHAR(255) NOT NULL
min_age     INT NULL
max_age     INT NULL
created_at  DATETIME
updated_at  DATETIME
deleted_at  DATETIME NULL
```

#### `addresses`
Lieux / adresses d'événements.
```
id          CHAR(36) PK
team_id     CHAR(36) FK teams.id NOT NULL
season_id   CHAR(36) FK seasons.id NOT NULL
name        VARCHAR(255) NOT NULL
address     TEXT NULL
city        VARCHAR(100) NULL
zip         VARCHAR(20) NULL
country     VARCHAR(100) NULL
created_at  DATETIME
updated_at  DATETIME
deleted_at  DATETIME NULL
```

#### `events`
Cours récurrents.
```
id                  CHAR(36) PK
team_id             CHAR(36) FK teams.id NOT NULL
season_id           CHAR(36) FK seasons.id NOT NULL
name                VARCHAR(255) NOT NULL
type                ENUM('lesson','stage','gala','workshop','other') NOT NULL
room_id             CHAR(36) FK rooms.id NULL
address_id          CHAR(36) FK addresses.id NULL
teacher_id          CHAR(36) FK team_profiles.id NULL
rrule               TEXT NOT NULL          -- règle de récurrence iCal
start_at            DATETIME NOT NULL      -- date/heure de la première occurrence
end_at              DATETIME NOT NULL      -- date/heure de fin de la première occurrence (durée)
max_participants    INT NULL
rrule_day_order     INT NULL
created_at          DATETIME
updated_at          DATETIME
deleted_at          DATETIME NULL
```

#### `event_levels` (table de liaison)
```
event_id    CHAR(36) FK events.id
level_id    CHAR(36) FK levels.id
PRIMARY KEY (event_id, level_id)
```

#### `event_age_groups` (table de liaison)
```
event_id        CHAR(36) FK events.id
age_group_id    CHAR(36) FK age_groups.id
PRIMARY KEY (event_id, age_group_id)
```

#### `event_occurences`
Instances concrètes d'un événement récurrent.
```
id              CHAR(36) PK
event_id        CHAR(36) FK events.id NOT NULL
team_id         CHAR(36) FK teams.id NOT NULL
occurence_at    DATETIME NOT NULL
cancelled       TINYINT(1) DEFAULT 0
created_at      DATETIME
updated_at      DATETIME
deleted_at      DATETIME NULL
```

#### `event_occurence_profiles`
Présences/inscriptions à une occurrence précise.
```
id                  CHAR(36) PK
event_occurence_id  CHAR(36) FK event_occurences.id NOT NULL
team_profile_id     CHAR(36) FK team_profiles.id NOT NULL
team_id             CHAR(36) FK teams.id NOT NULL
status              ENUM('present','absent','unknown') DEFAULT 'unknown'
created_at          DATETIME
updated_at          DATETIME
```

#### `packages`
Forfaits d'une saison.
```
id                              CHAR(36) PK
team_id                         CHAR(36) FK teams.id NOT NULL
season_id                       CHAR(36) FK seasons.id NOT NULL
name                            VARCHAR(255) NOT NULL
type                            ENUM('subscription_one_year','subscription_one_semester','trial_class','a_la_carte') NOT NULL
price                           INT NOT NULL   -- en centimes
classes_qty                     INT NULL
validity_start_type             ENUM('at_attribution','fixed_date') DEFAULT 'at_attribution'
validity_starts_at              DATE NULL
expires_at                      DATE NULL
expiration_type                 ENUM('fixed','seasonal') DEFAULT 'seasonal'
pre_registration_payment_type   VARCHAR(100) NULL
usage_count                     INT DEFAULT 0
apply_validity_to_existing      TINYINT(1) DEFAULT 0
created_at                      DATETIME
updated_at                      DATETIME
deleted_at                      DATETIME NULL
```

#### `event_packages` (table de liaison)
```
event_id    CHAR(36) FK events.id
package_id  CHAR(36) FK packages.id
PRIMARY KEY (event_id, package_id)
```

#### `package_age_groups` (table de liaison)
```
package_id      CHAR(36) FK packages.id
age_group_id    CHAR(36) FK age_groups.id
PRIMARY KEY (package_id, age_group_id)
```

#### `price_modifiers`
Modificateurs de prix (remises, suppléments, frais d'inscription).
```
id          CHAR(36) PK
team_id     CHAR(36) FK teams.id NOT NULL
season_id   CHAR(36) FK seasons.id NULL
name        VARCHAR(255) NOT NULL
value       INT NOT NULL      -- en centimes ou en pourcentage * 100
value_type  ENUM('percentage','fixed') NOT NULL
operation   ENUM('add','subtract') NOT NULL
type        ENUM('cart','profile','registration_fee') NOT NULL
terms       JSON NULL          -- conditions d'application
created_at  DATETIME
updated_at  DATETIME
deleted_at  DATETIME NULL
```

#### `payment_schedule_templates`
Modèles d'échelonnement de paiement.
```
id                              CHAR(36) PK
team_id                         CHAR(36) FK teams.id NOT NULL
season_id                       CHAR(36) FK seasons.id NOT NULL
name                            VARCHAR(255) NOT NULL
type                            ENUM('recurring','fixed_dates') NOT NULL
nb_payments                     INT NULL
interval_duration               INT NULL       -- en jours
day_of_month                    INT NULL
starts_at                       DATE NULL
fixed_dates                     JSON NULL
fixed_first_date_is_at_attribution TINYINT(1) DEFAULT 0
created_at                      DATETIME
updated_at                      DATETIME
deleted_at                      DATETIME NULL
```

#### `group_invites`
Invitations à rejoindre une école.
```
id              CHAR(36) PK
team_id         CHAR(36) FK teams.id NOT NULL
season_id       CHAR(36) FK seasons.id NULL
email           VARCHAR(255) NULL
role            ENUM('team_student','team_teacher','team_admin','team_owner') DEFAULT 'team_student'
status          ENUM('pending','accepted','refused','expired') DEFAULT 'pending'
token           VARCHAR(255) UNIQUE NOT NULL
expires_at      DATETIME NULL
invited_by      CHAR(36) FK users.id NULL
created_at      DATETIME
updated_at      DATETIME
```

#### `team_profiles`
Lien entre un profil et une école (membership).
```
id                  CHAR(36) PK
team_id             CHAR(36) FK teams.id NOT NULL
profile_id          CHAR(36) FK profiles.id NULL
role                ENUM('team_student','team_teacher','team_admin','team_owner') NOT NULL
status              ENUM('waiting','accepted','refused','suspended') DEFAULT 'waiting'
stripe_customer_id  VARCHAR(100) NULL
created_at          DATETIME
updated_at          DATETIME
deleted_at          DATETIME NULL
UNIQUE (team_id, profile_id)
```

#### `team_profile_seasons`
Inscription d'un membre à une saison.
```
id                      CHAR(36) PK
team_profile_id         CHAR(36) FK team_profiles.id NOT NULL
season_id               CHAR(36) FK seasons.id NOT NULL
team_id                 CHAR(36) FK teams.id NOT NULL
registration_status     ENUM('not_registered','pre_registered','registered','withdrawn') DEFAULT 'not_registered'
activity_ids            JSON NULL
age_group_id            CHAR(36) FK age_groups.id NULL
level_id                CHAR(36) FK levels.id NULL
top_size                VARCHAR(10) NULL
bottom_size             VARCHAR(10) NULL
feet_size               VARCHAR(10) NULL
region_size             VARCHAR(10) NULL
emergency_contact       JSON NULL   -- {name, relationship, email, phone}
injury_warning          TEXT NULL
created_at              DATETIME
updated_at              DATETIME
UNIQUE (team_profile_id, season_id)
```

#### `team_profile_packages`
Forfaits attribués à un membre.
```
id                  CHAR(36) PK
team_profile_id     CHAR(36) FK team_profiles.id NOT NULL
package_id          CHAR(36) FK packages.id NOT NULL
team_id             CHAR(36) FK teams.id NOT NULL
season_id           CHAR(36) FK seasons.id NOT NULL
order_id            CHAR(36) FK orders.id NULL
type                VARCHAR(100) NOT NULL
status              ENUM('active','expired','cancelled','pending','exhausted') DEFAULT 'pending'
classes_done        INT DEFAULT 0
classes_qty         INT NULL
validity_start_type ENUM('at_attribution','fixed_date') NULL
validity_starts_at  DATE NULL
expires_at          DATE NULL
validity_status     VARCHAR(50) NULL
created_at          DATETIME
updated_at          DATETIME
deleted_at          DATETIME NULL
```

#### `team_profile_gala_participations`
Participation d'un membre à un gala.
```
id                  CHAR(36) PK
team_profile_id     CHAR(36) FK team_profiles.id NOT NULL
event_id            CHAR(36) FK events.id NOT NULL
team_id             CHAR(36) FK teams.id NOT NULL
season_id           CHAR(36) FK seasons.id NOT NULL
participates        TINYINT(1) NULL   -- NULL = en attente, 1 = oui, 0 = non
notes               TEXT NULL
created_at          DATETIME
updated_at          DATETIME
UNIQUE (team_profile_id, event_id)
```

#### `intent_orders`
Snapshot d'une commande en cours de création (avant paiement Stripe).
```
id              CHAR(36) PK
team_id         CHAR(36) FK teams.id NOT NULL
season_id       CHAR(36) FK seasons.id NOT NULL
profile_id      CHAR(36) FK profiles.id NOT NULL
status          ENUM('pending','completed','failed','expired') DEFAULT 'pending'
payload         JSON NOT NULL    -- snapshot complet de la commande
version         VARCHAR(20) NULL -- ex. "18.06.2025"
stripe_checkout_session_id VARCHAR(255) NULL
created_at      DATETIME
updated_at      DATETIME
```

#### `orders`
Commandes effectives.
```
id                  CHAR(36) PK
team_id             CHAR(36) FK teams.id NOT NULL
season_id           CHAR(36) FK seasons.id NOT NULL
profile_id          CHAR(36) FK profiles.id NOT NULL
team_profile_id     CHAR(36) FK team_profiles.id NOT NULL
package_type        VARCHAR(100) NULL
total_amount        INT NOT NULL   -- en centimes
paid_amount         INT DEFAULT 0
status              ENUM('pending','completed','cancelled') DEFAULT 'pending'
created_at          DATETIME
updated_at          DATETIME
deleted_at          DATETIME NULL
```

#### `order_items`
Lignes d'une commande.
```
id          CHAR(36) PK
order_id    CHAR(36) FK orders.id NOT NULL
type        ENUM('package','add_amount','remove_amount','commission','pre_registration_fee') NOT NULL
amount      INT NOT NULL   -- en centimes
package_id  CHAR(36) FK packages.id NULL
label       VARCHAR(255) NULL
created_at  DATETIME
updated_at  DATETIME
deleted_at  DATETIME NULL
```

#### `payments`
Paiements effectués.
```
id                          CHAR(36) PK
order_id                    CHAR(36) FK orders.id NOT NULL
team_id                     CHAR(36) FK teams.id NOT NULL
profile_id                  CHAR(36) FK profiles.id NOT NULL
amount                      INT NOT NULL
paid_at                     DATETIME NULL
method                      ENUM('onsite_cash','onsite_check','onsite_transfer','online_stripe_checkout','online_stripe_customer_balance','online_stripe_sepa_debit','online_stripe_link') NOT NULL
stripe_payment_intent_id    VARCHAR(255) NULL
stripe_checkout_session_id  VARCHAR(255) NULL
details                     JSON NULL
refund_amount               INT DEFAULT 0
refunded_at                 DATETIME NULL
created_at                  DATETIME
updated_at                  DATETIME
```

#### `payment_schedules`
Échéances de paiement.
```
id              CHAR(36) PK
order_id        CHAR(36) FK orders.id NOT NULL
team_id         CHAR(36) FK teams.id NOT NULL
profile_id      CHAR(36) FK profiles.id NOT NULL
amount          INT NOT NULL
due_at          DATETIME NOT NULL
status          ENUM('pending','paid','failed','cancelled') DEFAULT 'pending'
payment_id      CHAR(36) FK payments.id NULL
retry_count     INT DEFAULT 0
last_retry_at   DATETIME NULL
created_at      DATETIME
updated_at      DATETIME
```

#### `invoices`
Factures générées.
```
id              CHAR(36) PK
order_id        CHAR(36) FK orders.id NOT NULL
team_id         CHAR(36) FK teams.id NOT NULL
profile_id      CHAR(36) FK profiles.id NOT NULL
invoice_number  VARCHAR(100) NOT NULL
invoice_date    DATE NOT NULL
pdf_path        VARCHAR(500) NULL
created_at      DATETIME
updated_at      DATETIME
```

#### `profile_price_modifiers` (table de liaison)
Remises spécifiques à un profil membre.
```
team_profile_id     CHAR(36) FK team_profiles.id
price_modifier_id   CHAR(36) FK price_modifiers.id
PRIMARY KEY (team_profile_id, price_modifier_id)
```

#### `email_bounces`
Tracking des bounces email (via webhook Resend).
```
id          CHAR(36) PK
email       VARCHAR(255) NOT NULL INDEX
event_type  VARCHAR(50) NOT NULL
payload     JSON NULL
created_at  DATETIME
```

#### `team_home_kpi_daily`
Cache KPIs quotidiens par école.
```
id          CHAR(36) PK
team_id     CHAR(36) FK teams.id NOT NULL
date        DATE NOT NULL
data        JSON NOT NULL
created_at  DATETIME
updated_at  DATETIME
UNIQUE (team_id, date)
```

---

## 5. Système d'authentification

L'authentification est gérée par `symfony/security-bundle`. Pas de Supabase Auth, pas de JWT stateless — sessions PHP classiques.

### Flows

**Inscription (`/signup`)**
1. Formulaire email + mot de passe (Symfony Form + contraintes Validator)
2. Vérification email unique
3. Création de l'entité `User` avec `password_hash` (bcrypt via `UserPasswordHasherInterface`)
4. Envoi email de confirmation avec token signé
5. Redirect vers `/setup/profile`

**Connexion (`/login`)**
1. Email + mot de passe via `form_login` Symfony
2. Session PHP (durée configurable)
3. Redirect vers `/home` ou URL demandée (paramètre `_target_path`)

**Réinitialisation mot de passe**
- `GET/POST /reset-password` : saisie email → génération `reset_token` en base → envoi email
- `GET/POST /update-password?token=xxx` : validation token, mise à jour `password_hash`, suppression token
- Token expiré après 1h

**Confirmation email**
- `GET /auth/confirm?token=xxx` : validation token → `email_verified = 1` → redirect `/home`

**Invitations (`/invitations/{token}`)**
- Page publique : affiche nom école + activités
- Si non connecté : propose login ou signup
- À l'acceptation : création `team_profile`, association profil, suppression invitation

**Déconnexion**
- `GET /signout` → invalidation session Symfony

**Impersonation (super-admin)**
- Route `/admin/impersonate/{userId}` : utilise le mécanisme `switch_user` de Symfony Security
- Stockage de la session admin originale
- Retour via `GET /admin/switch-back`

---

## 6. Système de rôles et droits (RBAC)

### Deux niveaux de droits

**App-level** : rôle global de l'utilisateur (`User.app_role`).
**Team-level** : rôle dans une école spécifique (`TeamProfile.role`).

### Rôles applicatifs

| Rôle | Droits |
|---|---|
| `app_default` | Aucun droit spécifique |
| `app_moderator` | Accès panel admin (lecture) |
| `app_admin` | + Créer/modifier/changer statut des écoles |
| `app_super_admin` | + Impersonation |

### Rôles d'équipe (hiérarchie cumulative)

**`team_student`**
- Lire : rooms, packages, events, activities, addresses, levels, age_groups, price_modifiers, payment_schedule_templates
- CRUD sur ses propres participations gala
- CRUD sur ses propres présences (event_occurence_profiles)

**`team_teacher`** (hérite de student)
- Lire tous les profils membres (nom, email, téléphone, note)
- Voir les inscriptions, commandes, paiements de tous les membres
- Voir qui est inscrit à chaque occurrence
- Comptage rapide (`fast_count`)

**`team_admin`** (hérite de teacher)
- CRUD complet : rooms, activities, addresses, levels, age_groups, packages, events
- CRUD : price_modifiers, payment_schedule_templates
- Gérer invitations (tous niveaux sauf owner)
- Gérer équipe, saisons (CRUD)
- Gérer paiements, échéances
- Modifier commandes, CRUD order_items
- Supprimer et exporter CSV des membres
- Accéder aux stats de l'école
- Configurer Stripe
- Gérer participations gala (tous membres)

**`team_owner`** (hérite de admin)
- Supprimer l'école
- Inviter un `team_owner`

### Implémentation Symfony

Les droits sont vérifiés via des **Voters Symfony** (`src/Security/Voter/`). Un voter par domaine métier :

- `TeamVoter` — droits sur une team
- `OrderVoter` — droits sur les commandes
- `PaymentVoter` — droits sur les paiements
- `MemberVoter` — droits sur les membres
- `SeasonVoter` — droits sur les saisons
- `EventVoter` — droits sur les cours
- `PackageVoter` — droits sur les forfaits
- `AdminVoter` — droits super-admin

**Règle absolue** : chaque action controller passe par `$this->denyAccessUnlessGranted('PERMISSION', $subject)` ou `$this->isGranted()`. Aucun accès sans vérification explicite.

La résolution du rôle courant dans une école est faite via `TeamContextService` qui lit le `team_profile` de l'utilisateur connecté pour la `teamId` courante (stockée en session).

---

## 7. Catalogue des routes

### Auth

| Route | Méthode | Description |
|---|---|---|
| `/login` | GET/POST | Connexion |
| `/signup` | GET/POST | Inscription |
| `/reset-password` | GET/POST | Demande réinitialisation mot de passe |
| `/update-password` | GET/POST | Nouveau mot de passe (depuis email) |
| `/signout` | GET | Déconnexion |
| `/auth/confirm` | GET | Confirmation email |
| `/invitations/{token}` | GET/POST | Acceptation invitation |

### App (protégées, auth requise)

| Route | Description |
|---|---|
| `/home` | Tableau de bord général |
| `/profile` | Consultation profil |
| `/profile/edit` | Édition profil (infos perso, tailles, contact urgence) |
| `/no-school` | Aucune école sélectionnée |
| `/setup/you` | Bienvenue onboarding |
| `/setup/profile` | Création profil |
| `/setup/create-school` | Création école (app_admin+) |

### School (auth + teamId en session)

| Route | Description |
|---|---|
| `/school` | Accueil école |
| `/school/home` | Dashboard école |
| `/school/edit` | Édition infos école |
| `/school/events` | Vue calendrier/liste événements |
| `/school/fast-count` | Comptage présences à la carte |
| `/school/my/{event_type}` | Mes cours (par type) |
| `/school/my/gala` | Ma participation gala |
| `/school/my/packages` | Mes forfaits |
| `/school/my/payment-schedules` | Mes échéances |
| `/school/my/season` | Mon inscription saison |
| `/school/members/{type}` | Liste membres (students/teachers/admins) |
| `/school/members/{type}/create` | Créer membre |
| `/school/events/{type}` | Liste cours par type |
| `/school/events/{type}/{id}` | Détail cours |
| `/school/events/{type}/{id}/{occurence_at}` | Détail occurrence (présences) |
| `/school/orders` | Mes commandes |
| `/school/orders/{id}` | Détail commande |
| `/school/shop` | Interface inscription interne (admin) |
| `/school/settings` | Paramètres école |
| `/school/settings/stripe/payments` | Sessions Stripe |
| `/school/settings/season` | Redirect saison active |
| `/school/settings/season/create` | Créer saison |
| `/school/settings/season/{id}` | Paramètres saison |
| `/school/settings/season/{id}/lessons` | Cours de la saison |
| `/school/settings/season/{id}/lessons/{lessonId}` | Détail cours |
| `/school/settings/season/{id}/lessons/{lessonId}/occurences` | Occurrences d'un cours |
| `/school/settings/season/{id}/lessons/{lessonId}/participants` | Participants d'un cours |
| `/school/settings/season/{id}/lessons/create` | Créer cours |
| `/school/settings/season/{id}/lessons/{lessonId}/edit` | Éditer cours |
| `/school/settings/season/{id}/packages` | Forfaits |
| `/school/settings/season/{id}/payment-schedulers` | Modèles échelonnement |
| `/school/settings/season/{id}/price-modifiers` | Modificateurs prix |
| `/school/settings/season/{id}/rooms` | Salles |
| `/school/settings/season/{id}/levels` | Niveaux |
| `/school/settings/season/{id}/age-groups` | Groupes d'âge |
| `/school/settings/season/{id}/addresses` | Adresses/lieux |
| `/school/settings/season/{id}/gala` | Participations gala |
| `/school/settings/season/{id}/stats` | Statistiques saison |

### Public

| Route | Description |
|---|---|
| `/shop/{teamSlug}` | Boutique publique inscription |
| `/iframes/shop/{teamSlug}` | Version iframe boutique |

### Admin (app_moderator+)

| Route | Description |
|---|---|
| `/admin` | Dashboard super-admin |
| `/admin/schools` | Liste toutes les écoles |
| `/admin/schools/{id}` | Détail école |
| `/admin/users` | Tous les membres plateforme |
| `/admin/impersonate/{userId}` | Impersoner un utilisateur |
| `/admin/switch-back` | Retour session admin |

### API JSON (préfixe `/api/v1/`)

Voir section 9.

### Crons (préfixe `/crons/`)

Voir section 10.

### Webhooks (préfixe `/webhooks/`)

Voir section 11.

---

## 8. Fonctionnalités détaillées

### 8.1 Gestion des saisons

**Création** : formulaire nom + dates. Si première saison de la team → définie comme `current_season_id` automatiquement.

**Configuration** : fermetures (plages de dates), image planning, image forfaits, frais d'inscription (lien vers un price_modifier).

**Copie de saison** : copie les entités suivantes vers une nouvelle saison : rooms, age_groups, levels, price_modifiers, payment_schedule_templates, events, packages, activities. Le champ `copy_id` trace la relation. Si des price_modifiers sont copiés et que la saison source avait un `registration_fee_id`, la nouvelle saison est mise à jour en conséquence.

**Changement de saison active** : action admin qui met à jour `teams.current_season_id`.

### 8.2 Gestion des cours (Events)

**Types** : `lesson`, `stage`, `gala`, `workshop`, `other`.

**Création** : formulaire nom, type, salle, horaire début/fin, règle rrule de récurrence, niveaux associés, groupes d'âge associés.

**Génération des occurrences** : à chaque création ou modification d'un event, le `EventService` recalcule les `event_occurences` à venir en appliquant la règle rrule et en excluant les périodes de fermeture de la saison. Les occurrences passées ne sont jamais supprimées.

**Gestion des présences** : chaque occurrence peut avoir des `event_occurence_profiles` par membre. Les élèves gèrent leurs propres présences. Les admins et teachers gèrent celles de tous les membres.

### 8.3 Gestion des membres

**Création** : profil (existant ou manuel — sans compte user), rôle, saison courante. Création automatique d'un `team_profile_season` pour la saison active.

**Invitation** : l'admin crée un `group_invite` avec token unique → email envoyé → à l'acceptation, création `team_profile` + `group_invite.status = accepted`.

**Statuts d'inscription** : `not_registered`, `pre_registered`, `registered`, `withdrawn`.

**Export CSV** : export de tous les membres avec leurs données saison. Requiert `team_profiles:export_csv` (droit admin).

**Fast-count** : page dédiée listant les élèves avec forfaits `a_la_carte`. Action `remove-one` incrémente `classes_done` de +1. Action `cancel-remove` annule si dans les 5 minutes. Recalcul du statut du package après chaque action.

**Multi-profils** : un user peut avoir plusieurs profils (lui + enfants). Chaque profil est indépendant dans les inscriptions. `is_primary = true` pour le profil principal.

### 8.4 Forfaits (Packages)

**Types** : `subscription_one_year`, `subscription_one_semester`, `trial_class`, `a_la_carte`.

**Règle unicité annuelle** : un profil ne peut avoir qu'une seule commande `subscription_one_year` active par saison. Exception : création par un admin pour quelqu'un d'autre.

**Validité** : calculée selon `validity_start_type` (à l'attribution ou date fixe), `expires_at`, `expiration_type`.

**Associations** : un forfait peut être lié à des cours spécifiques (`event_packages`) et à des groupes d'âge (`package_age_groups`).

### 8.5 Commandes (Orders)

**Flux de création** :
1. Validation DTO côté serveur (validation Symfony Validator)
2. Vérification que l'utilisateur appartient bien à la `teamId` → **OBLIGATOIRE, critique sécurité**
3. Si création pour quelqu'un d'autre → vérifier droit `orders:update`
4. Vérification absence de doublon commande annuelle
5. Création `team_profile` si inexistant
6. Snapshot `intent_order` en base avec `status: pending`
7. Calcul des échéances (`PaymentScheduleService`)
8. Si paiement en ligne → création session Stripe Checkout → retour URL Stripe
9. Si paiement sur place → création commande effective immédiate + `payment_schedules`
10. Si webhook Stripe reçu → création commande effective depuis l'intent

**Mise à jour** : requiert droit `orders:update`. Identification buyer + ordre cible, application modifications.

**Suppression** : soft-delete via `deleted_at`.

**Types d'items** : `package`, `add_amount`, `remove_amount`, `commission`, `pre_registration_fee`.

### 8.6 Échelonnement des paiements

**Modèles** : `recurring` (N paiements espacés) ou `fixed_dates` (dates prédéfinies).

**Calcul** : `PaymentScheduleService::processPaymentDetails()` calcule les échéances en appliquant les `price_modifiers` (remises en % ou montant fixe, sur le panier ou par profil).

**Méthodes de paiement** : `onsite_cash`, `onsite_check`, `onsite_transfer`, `online_stripe_checkout`, `online_stripe_customer_balance`, `online_stripe_sepa_debit`, `online_stripe_link`.

**Rappels automatiques** : cron `check-payment-schedules` envoie des emails selon 5 horizons : J-3, J0, J+3, J+10, J+15.

**Auto-pay SEPA** : cron `auto-pay-schedules` déclenche les prélèvements automatiques pour les échéances SEPA.

### 8.7 Modificateurs de prix

- `cart` : s'applique au panier entier
- `profile` : s'applique par profil/élève
- `registration_fee` : frais d'inscription saison
- `operation` : `add` (supplément) ou `subtract` (remise)
- `value_type` : `percentage` ou `fixed`
- `terms` : conditions d'application (JSON — ex. si le panier contient X cours d'un certain type)
- Peuvent être attachés directement à un profil membre spécifique (`profile_price_modifiers`)

### 8.8 Gestion du gala

- Admin : consulte toutes les participations par saison et par événement gala. Filtres : événement, nom, statut.
- Élève : gère sa propre participation (oui/non/en attente).
- Statut : `true` (participe), `false` (ne participe pas), `null` (en attente).

### 8.9 Documents et médias

**Types** : avatar école, carousel école, image planning saison, image forfaits saison.

**Stockage** : filesystem local (`var/uploads/`). Chemin stocké en base. Taille max : 50 Mo.

**Accès** : les fichiers sont servis par Apache via un répertoire public (symlink `public/uploads → var/uploads`).

### 8.10 Shop public

**`GET /shop/{teamSlug}`** et **`GET /iframes/shop/{teamSlug}`**

Accès public (pas d'auth pour voir). Auth requise pour commander.

Chargement : données école, saison courante (ou `?seasonId=`), événements récurrents, forfaits disponibles, infos inscription du visiteur connecté s'il est membre.

Sélection saison : si la saison courante est fermée, trouver la prochaine saison ouverte (date de début la plus proche).

Actions : login, signup, setupProfile depuis le shop.

Version iframe : même logique, layout minimal pour intégration externe.

### 8.11 Statistiques école

Accessible via `/school/settings/season/{id}/stats`. Données agrégées par saison : nombre de membres inscrits, CA, répartition par type de forfait, taux de paiement. Cache quotidien via `team_home_kpi_daily`.

---

## 9. API interne (controllers JSON)

Tous les endpoints JSON sont dans `src/Controller/Api/`. Ils retournent du JSON via `JsonResponse`. Toutes les entrées passent par des DTOs validés avec `symfony/validator`.

**Authentification** : session Symfony (cookies). Pas de JWT.

**Vérification tenant** : chaque endpoint qui opère sur une `teamId` vérifie que l'utilisateur connecté est bien membre de cette team avant toute opération.

### Auth / Session

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/cookies` | — | Lecture/écriture cookies (ex. `currentTeamId`) |

### Commandes

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/orders/create` | Auth + membre team | Crée une commande (intent → order) |
| `POST` | `/api/v1/orders/update` | `orders:update` | Met à jour une commande |

### Paiements

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/payments/stripe/get-payment-link-for-schedules` | Auth (owner commande) | Lien paiement pour N échéances |
| `POST` | `/api/v1/payments/stripe/refund/{paymentId}` | team_admin+ | Remboursement — **vérification `team_admin` obligatoire** |

### Saisons

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/seasons/{id}/copy` | `seasons:update` | Copie entités d'une saison |
| `GET` | `/api/v1/seasons/{id}/entities` | `seasons:update` | Liste entités d'une saison |

### Membres

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/teams/{teamId}/team-profiles/create` | team_admin | Crée un team profile |
| `POST` | `/api/v1/teams/{teamId}/team-profiles/{id}/update` | team_admin | Met à jour un team profile |
| `POST` | `/api/v1/teams/{teamId}/team-profiles/export` | `team_profiles:export_csv` | Export CSV membres |

### Fast-count

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/teams/{teamId}/team-profile-packages/{id}/fast-count/remove-one` | team_teacher+ | Incrémente classes_done |
| `POST` | `/api/v1/teams/{teamId}/team-profile-packages/{id}/fast-count/cancel-remove` | team_teacher+ | Annule dernière incrémentation (5 min max) |

### Stripe

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/teams/{teamId}/stripe/create-connected-account` | `teams:configure_stripe` | Crée compte Stripe Connect |
| `POST` | `/api/v1/teams/{teamId}/stripe/get-onboarding-link` | `teams:configure_stripe` | URL onboarding Stripe |
| `POST` | `/api/v1/teams/{teamId}/stripe/get-requirements` | team_admin | Prérequis Stripe manquants |
| `POST` | `/api/v1/teams/{teamId}/stripe/update-account-status` | team_admin | Met à jour statut compte Stripe |

### Documents

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/teams/documents` | `teams:update` | Upload avatar, carousel, images saison |

### Invitations

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/invites/accept` | Auth | Accepte une invitation |
| `POST` | `/api/v1/invites/mails` | team_admin | Envoie emails d'invitation |

### Événements

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/events/adjust-occurences` | `CRON_SECRET` header | Synchronise occurrences après modif event |

### Admin

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/admin/magic-link` | app_super_admin | Génère magic link impersonation |

---

## 10. Jobs planifiés (Crons)

Tous les crons sont des routes GET sécurisées par un header `Authorization: Bearer {CRON_SECRET}`. Implémentés dans `src/Controller/Cron/`. Déclenchés par le cron système du serveur Debian (crontab).

### `GET /crons/auto-pay-schedules`

Déclenche les prélèvements SEPA automatiques. Traite les teams par chunks de 10. Envoie emails de confirmation en batch via Resend.

### `GET /crons/check-payment-schedules`

Envoie rappels de paiement selon 5 horizons (J-3, J0, J+3, J+10, J+15). La liste noire des écoles exclues est stockée en base (table `cron_exclusions` ou configuration admin), pas dans le code source.

### `GET /crons/reconcile-stripe-payments`

Réconcilie les paiements Stripe avec la base. Pour chaque team avec `stripe_account_id` : récupère les checkout sessions Stripe, compare avec les paiements en base, met à jour les statuts. Concurrence : max 10 teams simultanées.

### `GET /crons/settle-customer-balances`

Solde les balances positives des clients Stripe en les imputant sur les prochaines échéances.

---

## 11. Webhooks entrants

### `POST /webhooks/stripe/connect`

**Sécurité** : vérification signature Stripe (`Stripe-Signature` header) via `\Stripe\Webhook::constructEvent()`. Rejet immédiat si signature invalide (HTTP 400).

**Événements gérés** :

`checkout.session.completed` / `checkout.session.async_payment_succeeded` :
1. Charger l'`intent_order` associé au `stripe_checkout_session_id`
2. Créer la commande effective depuis les données de l'intent
3. Créer les `team_profile_packages`, `order_items`, `payment_schedules`
4. Enregistrer le paiement avec `paid_at`
5. Si auto-pay : configurer le mandat SEPA Stripe
6. Mettre à jour l'intent à `completed`
7. Envoyer email de confirmation — **si l'envoi échoue, logger ERROR + alerter Sentry, ne pas silently drop**

`payment_intent.succeeded` :
1. Trouver le paiement en base par `stripe_payment_intent_id`
2. Mettre à jour `paid_at`, `status`, `stripe_checkout_session_id`
3. Mettre à jour l'échéance correspondante (`payment_schedules.status = paid`)

**Gestion d'erreurs** : toute exception dans un handler webhook doit être loggée (`ERROR`) et remontée à Sentry. Retourner HTTP 200 à Stripe même en cas d'erreur interne (pour éviter les retentatives infinies), mais logger l'incident.

### `POST /webhooks/resend`

**Sécurité** : vérification signature Resend.

**Événements** : `email.sent`, `email.bounced`, `email.delivery_delayed`. Enregistrement dans `email_bounces` pour éviter les re-envois vers des adresses invalides.

---

## 12. Système de paiement Stripe

### Architecture Stripe Connect

Chaque école a un compte Stripe Connect séparé. La plateforme lvlup est le compte principal.

**Onboarding** :
1. Admin clique "Connecter Stripe"
2. `POST /api/v1/teams/{teamId}/stripe/create-connected-account` → crée compte, stocke `stripe_account_id`
3. `POST /api/v1/teams/{teamId}/stripe/get-onboarding-link` → URL KYC Stripe
4. Webhook Stripe met à jour `stripe_account_status`

**Statuts** : `not_created`, `pending`, `active`, `restricted`.

### Lien de paiement

1. Récupération/création `stripe_customer_id` du profil (stocké dans `team_profiles.stripe_customer_id`)
2. Création session Checkout sur le compte connecté de l'école
3. Metadata de la session : `intentOrderId`, `orderId`, `paymentScheduleId`
4. URL de succès configurable

### Auto-pay SEPA

À la création commande avec `isAutoPay` sans échéance immédiate : setup mandat SEPA (montant 0). Le cron `auto-pay-schedules` traite ensuite les prélèvements.

### Commission

Gérée via `order_items` de type `commission`. Configurable par `teams.fee_paid_by` : `student` ou `team`.

---

## 13. Système d'emails (Resend)

**Envoi** : via l'API HTTP Resend (pas de SDK PHP officiel — appel `curl`/`Guzzle` direct). Service `EmailService` dans `src/Service/Email/`.

**Templates** : Twig templates HTML dans `templates/email/`. Un template par type d'email.

**Emails à implémenter** :

| Trigger | Template |
|---|---|
| Inscription | Confirmation email (lien de confirmation) |
| Invitation membre | Lien d'invitation à l'école |
| Commande créée (paiement en ligne) | Confirmation de commande |
| Rappel J-3 | Rappel préventif avec lien de paiement |
| Rappel J0 | Rappel jour J |
| Relance J+3 | Relance douce |
| Relance J+10 | Relance ferme |
| Relance J+15 | Relance finale |
| Auto-pay exécuté | Confirmation prélèvement automatique |
| Réinitialisation mot de passe | Lien de réinitialisation |

**Gestion des bounces** : avant tout envoi, vérifier que l'email n'est pas dans `email_bounces` avec `event_type = 'bounced'`.

**Règle critique** : un email qui échoue à l'envoi ne doit jamais être droppé silencieusement. Logger `ERROR` + Sentry.

---

## 14. Upload de fichiers et traitement d'images

### Stockage

Fichiers uploadés dans `var/uploads/{team_id}/{type}/` sur le filesystem local. Accès public via symlink Apache `public/uploads → var/uploads`.

**Sécurité upload** : renommer chaque fichier avec un UUID v4 (jamais conserver le nom original). Vérifier le type MIME réel via `finfo` PHP (pas seulement l'extension). Taille max : 50 Mo. Types acceptés : JPEG, PNG, WebP uniquement.

### LiipImagineBundle — filtres et cache

LiipImagineBundle gère le resize, le crop et le cache des images. Les images originales sont stockées dans `var/uploads/`, les versions traitées sont cachées dans `public/media/cache/` par LiipImagine.

**Configuration des filtres** (`config/packages/liip_imagine.yaml`) :

```yaml
liip_imagine:
  resolvers:
    default:
      web_path:
        web_root: '%kernel.project_dir%/public'
        cache_prefix: 'media/cache'

  filter_sets:
    team_avatar:
      quality: 85
      filters:
        thumbnail: { size: [200, 200], mode: outbound }  # crop centré carré
        strip: ~

    team_carousel:
      quality: 82
      filters:
        thumbnail: { size: [1200, 450], mode: outbound }  # crop centré paysage
        strip: ~

    season_planning:
      quality: 82
      filters:
        thumbnail: { size: [800, 600], mode: outbound }
        strip: ~

    season_packages:
      quality: 82
      filters:
        thumbnail: { size: [800, 600], mode: outbound }
        strip: ~

    profile_avatar:
      quality: 85
      filters:
        thumbnail: { size: [150, 150], mode: outbound }  # crop centré carré
        strip: ~
```

**Utilisation dans les templates Twig** :

```twig
{# Avatar école #}
<img src="{{ team.avatarPath | imagine_filter('team_avatar') }}" alt="{{ team.name }}">

{# Carousel #}
<img src="{{ path | imagine_filter('team_carousel') }}" alt="...">

{# Avatar profil #}
<img src="{{ profile.avatarPath | imagine_filter('profile_avatar') }}" alt="...">
```

**Invalidation du cache** : à chaque remplacement d'image, appeler `CacheManager::remove($path)` de LiipImagine pour invalider le cache de l'ancienne image avant de stocker la nouvelle.

**Types de documents gérés** :

| Type | Filtre LiipImagine | Champ en base |
|---|---|---|
| Avatar école | `team_avatar` | `teams.avatar_path` |
| Carousel école | `team_carousel` | `teams.carousel_paths` (JSON array) |
| Image planning saison | `season_planning` | `seasons.planning_image_path` |
| Image forfaits saison | `season_packages` | `seasons.packages_image_path` |
| Avatar profil | `profile_avatar` | `profiles.avatar_path` |

---

## 15. Internationalisation et affichage des dates

### Locale et langue

Langue unique : **français (FR)**. Locale par défaut : `fr`. Pas de détection automatique via `Accept-Language` — la locale est fixée à `fr` dans la configuration Symfony.

```yaml
# config/packages/translation.yaml
framework:
  default_locale: fr
  translator:
    default_path: '%kernel.project_dir%/translations'
    fallbacks: [fr]
```

Tous les libellés UI sont dans des fichiers de traduction Twig (`translations/messages.fr.yaml`) dès le départ, même si FR est la seule langue — ça permet d'ajouter d'autres langues sans toucher aux templates.

### Fuseau horaire

Fuseau fixe : **Europe/Paris**. Configuré globalement dans `php.ini` (`date.timezone = Europe/Paris`) et dans Symfony :

```yaml
# config/packages/framework.yaml
framework:
  timezone: 'Europe/Paris'
```

Toutes les dates stockées en base sont en **UTC** (MariaDB `DATETIME` sans timezone). La conversion UTC → Europe/Paris se fait à l'affichage uniquement, via les helpers Twig.

### Affichage des dates et heures

Utiliser l'extension Twig `twig/intl-extra` + `Twig\Extra\Intl\IntlExtension` pour le formatage des dates selon la locale FR.

**Filtres Twig disponibles** :

```twig
{# Date longue : "lundi 12 janvier 2026" #}
{{ event.startAt | format_date('full', locale='fr') }}

{# Date courte : "12/01/2026" #}
{{ event.startAt | format_date('short', locale='fr') }}

{# Heure : "14h30" #}
{{ event.startAt | format_time('short', locale='fr') }}

{# Date + heure : "12 jan. 2026 à 14h30" #}
{{ event.startAt | format_datetime('medium', 'short', locale='fr') }}

{# Montant en euros (filtre custom) #}
{{ order.totalAmount | money }}
{# Affiche "125,00 €" depuis des centimes #}
```

**Filtre custom `money`** à déclarer comme Twig Extension (`src/Twig/MoneyExtension.php`) :

```php
// Convertit des centimes en euros formatés FR
// 12500 → "125,00 €"
public function formatMoney(int $amountInCents): string
{
    return number_format($amountInCents / 100, 2, ',', ' ') . ' €';
}
```

### Formats attendus par contexte

| Contexte | Format | Exemple |
|---|---|---|
| Date dans un tableau | `d MMM yyyy` | 12 jan. 2026 |
| Date + heure d'un cours | `EEEE d MMMM, HH'h'mm` | lundi 12 janvier, 14h30 |
| Date courte (formulaires) | `dd/MM/yyyy` | 12/01/2026 |
| Montant | nombre formaté FR + € | 125,00 € |
| Échéance de paiement | `d MMMM yyyy` | 12 janvier 2026 |

---

## 16. Sécurité — règles non négociables

Ces règles s'appliquent sans exception. Elles corrigent directement les failles documentées dans l'audit du projet original.

### Isolation tenant (critique)

Chaque requête qui opère sur des données d'une team vérifie systématiquement que l'utilisateur connecté est membre de cette team. Cette vérification se fait dans le Voter correspondant, pas dans le controller. Il ne doit pas être possible pour un user de l'école A d'accéder, créer ou modifier des données de l'école B.

### Vérification d'autorisation sur chaque route

Chaque action controller appelle `$this->denyAccessUnlessGranted()` ou `$this->isGranted()`. Aucune route protégée ne retourne de données sans vérification explicite du rôle.

### Remboursement (critique)

L'endpoint de remboursement vérifie que l'utilisateur connecté est `team_admin` ou supérieur dans l'école concernée, ET que le paiement appartient bien à une commande de cette école. Un élève ne peut pas rembourser un paiement.

### Webhooks

Signature vérifiée avant tout traitement. Rejet HTTP 400 si signature invalide. Jamais de traitement sur une requête non authentifiée.

### Crons

Sécurisés par header `Authorization: Bearer {CRON_SECRET}`. Rejet HTTP 401 si absent ou invalide.

### CSRF

Protection CSRF Symfony activée sur tous les formulaires POST. Désactivée uniquement pour les webhooks entrants (Stripe, Resend) qui ont leur propre mécanisme de signature.

### Fichiers uploadés

Renommage UUID obligatoire. Vérification MIME via `finfo`. Les chemins stockés en base sont relatifs (`/uploads/...`). Invalidation cache LiipImagine à chaque remplacement d'image.

### Données sensibles

Aucun UUID client, email, ou donnée métier hardcodée dans le code source. Les exclusions de crons et autres configurations métier sont en base de données ou en variables d'environnement.

### Impersonation

Réservée à `app_super_admin`. Utilise le mécanisme natif `switch_user` de Symfony Security. La session originale admin est préservée pour permettre le retour.

---

## 17. Bonnes pratiques et standards de code

### Logger structuré

Utiliser Monolog (PSR-3) exclusivement. Zéro `echo`, zéro `var_dump`, zéro `print_r` en dehors du debug local.

Niveaux à respecter :
- `DEBUG` : informations de développement (désactivé en prod)
- `INFO` : événements normaux significatifs (commande créée, paiement reçu)
- `WARNING` : situation anormale non bloquante (retry, donnée manquante non critique)
- `ERROR` : erreur qui nécessite une intervention (email non envoyé, webhook échoué, paiement incohérent)
- `CRITICAL` : défaillance système

En production, le niveau minimum est `WARNING`. Les logs `ERROR` et `CRITICAL` sont remontés à Sentry.

### Typage strict

`declare(strict_types=1)` dans chaque fichier PHP. Zéro `mixed` ou type manquant dans les signatures de méthodes. Les DTOs et entités sont entièrement typés.

### Gestion des erreurs

Pas d'exception silencieusement swallowée. Pattern standard : si une opération peut échouer, soit elle lève une exception (qui remonte jusqu'au handler Symfony), soit elle retourne un résultat typé (`Result` pattern ou nullable avec log). Jamais de `catch` vide. Jamais de `return null` sans log si c'est une erreur.

### Séparation des responsabilités

- Controller : reçoit la requête, appelle un service, retourne une réponse. Maximum 30 lignes.
- Service : contient la logique métier. Un service par domaine fonctionnel.
- Repository : contient les requêtes Doctrine. Aucune logique métier.
- Entity : contient uniquement le mapping Doctrine et les getters/setters.

Un "god file" est interdit. Si un service dépasse 300 lignes, il doit être découpé.

### Validation des entrées

Toutes les entrées HTTP sont validées avant traitement. Pour les formulaires Twig : Symfony Form + Validator directement sur l'entité ou un objet de formulaire dédié. Pour les endpoints JSON : désérialisation + contraintes Symfony Validator sur une classe d'input dédiée (pas de `$request->toArray()` utilisé brut dans la logique métier). Jamais de données brutes `$request->get()` passées directement à Doctrine ou à un service.

### Pas de code mort

Pas de blocs commentés laissés dans le code. Pas de dossier `tmp_old`. Pas de routes WIP qui retournent une page vide. Si du code est en cours, il est sur une branche, pas en production.

### Alpine.js — usage restreint

Alpine.js est chargé via CDN uniquement sur les pages qui en ont besoin (pas en global). Il est réservé aux interactions qui ne peuvent pas être résolues par du Twig pur : modales de confirmation, toggles d'affichage, dropdowns, formulaires multi-étapes avec état côté client. Toute page qui n'a pas d'interactivité côté client doit rester en Twig pur, sans Alpine. Ne pas utiliser Alpine pour ce qu'un simple lien ou un submit de formulaire Symfony peut faire.

### CSS — fichier unique

Un seul fichier source Tailwind (`assets/css/app.css`) et un seul fichier compilé en output (`public/assets/app.css`). Pas de fichiers CSS par composant, pas de `<style>` inline dans les templates Twig. Toutes les classes utilitaires custom, les `@layer components` et les overrides Tailwind sont dans `assets/css/app.css`. Le fichier est compilé via le Tailwind CLI standalone (pas de Node.js requis en production).

### Responsive

Deux breakpoints Tailwind uniquement : `md` (768px) comme seuil. En dessous → layout mobile. Au-dessus → layout desktop. Le cas tablette s'affiche en mobile. Aucun breakpoint `lg`, `xl`, `2xl` sauf exception documentée.

### Transactions Doctrine

Toute opération qui touche plusieurs tables dans un même flux métier (création commande, acceptation invitation, copie saison) utilise une transaction Doctrine explicite avec rollback en cas d'erreur.

### UUID

Tous les IDs sont des UUID v4 générés côté PHP (`Symfony\Component\Uid\Uuid::v4()`). Jamais d'auto-increment MySQL pour les IDs principaux.

### Montants monétaires

Tous les montants sont stockés et manipulés en **centimes** (entiers). La conversion en euros se fait uniquement à l'affichage (filtre Twig `|money`). Jamais de `float` pour les montants.

---

## 18. Environnements et déploiement

### Environnements

| Environnement | URL | Base de données |
|---|---|---|
| Local | `http://localhost:8080` | MariaDB local |
| Staging | `https://staging.lvlup.com` | MariaDB staging |
| Production | `https://lvlup.com` | MariaDB production |

### Variables d'environnement

```
APP_ENV=prod
APP_SECRET=
DATABASE_URL=mysql://user:password@localhost:3306/lvlup
CRON_SECRET=
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
RESEND_API_KEY=
RESEND_WEBHOOK_SECRET=
SENTRY_DSN=
UPLOAD_DIR=%kernel.project_dir%/var/uploads
LIIP_IMAGINE_CACHE_DIR=%kernel.project_dir%/public/media/cache
```

### Déploiement (Debian / Apache)

Déploiement via `git pull` + `composer install --no-dev` + `php bin/console doctrine:migrations:migrate --no-interaction` + `php bin/console cache:clear`.

Apache : VirtualHost pointant sur `public/`. `AllowOverride All` pour le `.htaccess` Symfony. PHP 8.3 via mod_php ou PHP-FPM.

Crontab système pour les crons :
```
*/5 * * * * curl -s -H "Authorization: Bearer ${CRON_SECRET}" https://lvlup.com/crons/auto-pay-schedules
0 8 * * * curl -s -H "Authorization: Bearer ${CRON_SECRET}" https://lvlup.com/crons/check-payment-schedules
*/30 * * * * curl -s -H "Authorization: Bearer ${CRON_SECRET}" https://lvlup.com/crons/reconcile-stripe-payments
0 2 * * * curl -s -H "Authorization: Bearer ${CRON_SECRET}" https://lvlup.com/crons/settle-customer-balances
```

### Migrations

Une migration Doctrine par changement de schéma. Jamais de modification manuelle de la base en production. Les migrations sont versionnées avec le code source.

---

*Fin du cahier des charges. Ce document décrit l'état cible du projet lvlup en stack Symfony 7 / MariaDB. Il est destiné à être fourni directement à Claude pour l'implémentation feature par feature.*
