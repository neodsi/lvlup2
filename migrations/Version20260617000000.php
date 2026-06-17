<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema — full lvlup database (MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id                      CHAR(36)     NOT NULL,
                email                   VARCHAR(255) NOT NULL,
                password_hash           VARCHAR(255) NOT NULL,
                app_role                ENUM('app_default','app_moderator','app_admin','app_super_admin') NOT NULL DEFAULT 'app_default',
                email_verified          TINYINT(1)   NOT NULL DEFAULT 0,
                reset_token             VARCHAR(255)          DEFAULT NULL,
                reset_token_expires_at  DATETIME              DEFAULT NULL,
                created_at              DATETIME     NOT NULL,
                updated_at              DATETIME     NOT NULL,
                deleted_at              DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_users_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE profiles (
                id           CHAR(36)     NOT NULL,
                user_id      CHAR(36)              DEFAULT NULL,
                first_name   VARCHAR(100) NOT NULL,
                last_name    VARCHAR(100) NOT NULL,
                dob          DATE                  DEFAULT NULL,
                gender       ENUM('male','female','other') DEFAULT NULL,
                phone        VARCHAR(30)           DEFAULT NULL,
                address_text TEXT                  DEFAULT NULL,
                avatar_path  VARCHAR(500)          DEFAULT NULL,
                is_primary   TINYINT(1)   NOT NULL DEFAULT 1,
                created_at   DATETIME     NOT NULL,
                updated_at   DATETIME     NOT NULL,
                deleted_at   DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_profiles_user_id (user_id),
                CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE price_modifiers (
                id         CHAR(36)     NOT NULL,
                team_id    CHAR(36)     NOT NULL,
                season_id  CHAR(36)              DEFAULT NULL,
                name       VARCHAR(255) NOT NULL,
                value      INT          NOT NULL,
                value_type ENUM('percentage','fixed') NOT NULL,
                operation  ENUM('add','subtract')    NOT NULL,
                type       ENUM('cart','profile','registration_fee') NOT NULL,
                terms      JSON                  DEFAULT NULL,
                created_at DATETIME     NOT NULL,
                updated_at DATETIME     NOT NULL,
                deleted_at DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_price_modifiers_team_id (team_id),
                KEY idx_price_modifiers_season_id (season_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE teams (
                id                              CHAR(36)     NOT NULL,
                name                            VARCHAR(255) NOT NULL,
                type                            VARCHAR(100)          DEFAULT NULL,
                status                          ENUM('waiting','accepted','refused','disabled') NOT NULL DEFAULT 'waiting',
                currency                        VARCHAR(3)   NOT NULL DEFAULT 'EUR',
                current_season_id               CHAR(36)              DEFAULT NULL,
                current_slug                    VARCHAR(100)          DEFAULT NULL,
                previous_slugs                  JSON                  DEFAULT NULL,
                avatar_path                     VARCHAR(500)          DEFAULT NULL,
                carousel_paths                  JSON                  DEFAULT NULL,
                invoice_prefix                  VARCHAR(20)           DEFAULT NULL,
                invoice_numbering_start         INT          NOT NULL DEFAULT 1,
                invoice_address                 TEXT                  DEFAULT NULL,
                stripe_account_id               VARCHAR(100)          DEFAULT NULL,
                stripe_account_status           ENUM('not_created','pending','active','restricted') NOT NULL DEFAULT 'not_created',
                stripe_payment_capabilities     JSON                  DEFAULT NULL,
                fee_paid_by                     ENUM('student','team') NOT NULL DEFAULT 'student',
                created_at                      DATETIME     NOT NULL,
                updated_at                      DATETIME     NOT NULL,
                deleted_at                      DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_teams_current_slug (current_slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE seasons (
                id                      CHAR(36)     NOT NULL,
                team_id                 CHAR(36)     NOT NULL,
                name                    VARCHAR(255) NOT NULL,
                start_at                DATE         NOT NULL,
                end_at                  DATE         NOT NULL,
                registration_fee_id     CHAR(36)              DEFAULT NULL,
                planning_image_path     VARCHAR(500)          DEFAULT NULL,
                packages_image_path     VARCHAR(500)          DEFAULT NULL,
                closures                JSON                  DEFAULT NULL,
                copy_id                 CHAR(36)              DEFAULT NULL,
                created_at              DATETIME     NOT NULL,
                updated_at              DATETIME     NOT NULL,
                deleted_at              DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_seasons_team_id (team_id),
                KEY idx_seasons_registration_fee_id (registration_fee_id),
                CONSTRAINT fk_seasons_team    FOREIGN KEY (team_id)             REFERENCES teams (id),
                CONSTRAINT fk_seasons_reg_fee FOREIGN KEY (registration_fee_id) REFERENCES price_modifiers (id) ON DELETE SET NULL,
                CONSTRAINT fk_seasons_copy    FOREIGN KEY (copy_id)             REFERENCES seasons (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Now add the FK from teams.current_season_id -> seasons
        $this->addSql(<<<'SQL'
            ALTER TABLE teams
                ADD CONSTRAINT fk_teams_current_season FOREIGN KEY (current_season_id) REFERENCES seasons (id) ON DELETE SET NULL
        SQL);

        // Now add the FK from price_modifiers.team_id -> teams and season_id -> seasons
        $this->addSql(<<<'SQL'
            ALTER TABLE price_modifiers
                ADD CONSTRAINT fk_price_modifiers_team   FOREIGN KEY (team_id)   REFERENCES teams   (id),
                ADD CONSTRAINT fk_price_modifiers_season FOREIGN KEY (season_id) REFERENCES seasons (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE activities (
                id         CHAR(36)     NOT NULL,
                team_id    CHAR(36)     NOT NULL,
                season_id  CHAR(36)     NOT NULL,
                name       VARCHAR(255) NOT NULL,
                created_at DATETIME     NOT NULL,
                updated_at DATETIME     NOT NULL,
                deleted_at DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_activities_team_id   (team_id),
                KEY idx_activities_season_id (season_id),
                CONSTRAINT fk_activities_team   FOREIGN KEY (team_id)   REFERENCES teams   (id),
                CONSTRAINT fk_activities_season FOREIGN KEY (season_id) REFERENCES seasons (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE rooms (
                id         CHAR(36)     NOT NULL,
                team_id    CHAR(36)     NOT NULL,
                season_id  CHAR(36)     NOT NULL,
                name       VARCHAR(255) NOT NULL,
                created_at DATETIME     NOT NULL,
                updated_at DATETIME     NOT NULL,
                deleted_at DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_rooms_team_id   (team_id),
                KEY idx_rooms_season_id (season_id),
                CONSTRAINT fk_rooms_team   FOREIGN KEY (team_id)   REFERENCES teams   (id),
                CONSTRAINT fk_rooms_season FOREIGN KEY (season_id) REFERENCES seasons (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE levels (
                id         CHAR(36)     NOT NULL,
                team_id    CHAR(36)     NOT NULL,
                season_id  CHAR(36)     NOT NULL,
                name       VARCHAR(255) NOT NULL,
                created_at DATETIME     NOT NULL,
                updated_at DATETIME     NOT NULL,
                deleted_at DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_levels_team_id   (team_id),
                KEY idx_levels_season_id (season_id),
                CONSTRAINT fk_levels_team   FOREIGN KEY (team_id)   REFERENCES teams   (id),
                CONSTRAINT fk_levels_season FOREIGN KEY (season_id) REFERENCES seasons (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE age_groups (
                id         CHAR(36)     NOT NULL,
                team_id    CHAR(36)     NOT NULL,
                season_id  CHAR(36)     NOT NULL,
                name       VARCHAR(255) NOT NULL,
                min_age    INT                   DEFAULT NULL,
                max_age    INT                   DEFAULT NULL,
                created_at DATETIME     NOT NULL,
                updated_at DATETIME     NOT NULL,
                deleted_at DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_age_groups_team_id   (team_id),
                KEY idx_age_groups_season_id (season_id),
                CONSTRAINT fk_age_groups_team   FOREIGN KEY (team_id)   REFERENCES teams   (id),
                CONSTRAINT fk_age_groups_season FOREIGN KEY (season_id) REFERENCES seasons (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE addresses (
                id         CHAR(36)     NOT NULL,
                team_id    CHAR(36)     NOT NULL,
                season_id  CHAR(36)     NOT NULL,
                name       VARCHAR(255) NOT NULL,
                address    TEXT                  DEFAULT NULL,
                city       VARCHAR(100)          DEFAULT NULL,
                zip        VARCHAR(20)           DEFAULT NULL,
                country    VARCHAR(100)          DEFAULT NULL,
                created_at DATETIME     NOT NULL,
                updated_at DATETIME     NOT NULL,
                deleted_at DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_addresses_team_id   (team_id),
                KEY idx_addresses_season_id (season_id),
                CONSTRAINT fk_addresses_team   FOREIGN KEY (team_id)   REFERENCES teams   (id),
                CONSTRAINT fk_addresses_season FOREIGN KEY (season_id) REFERENCES seasons (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE team_profiles (
                id                 CHAR(36)  NOT NULL,
                team_id            CHAR(36)  NOT NULL,
                profile_id         CHAR(36)           DEFAULT NULL,
                role               ENUM('team_student','team_teacher','team_admin','team_owner') NOT NULL,
                status             ENUM('waiting','accepted','refused','suspended') NOT NULL DEFAULT 'waiting',
                stripe_customer_id VARCHAR(100)        DEFAULT NULL,
                created_at         DATETIME  NOT NULL,
                updated_at         DATETIME  NOT NULL,
                deleted_at         DATETIME           DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_team_profiles_team_profile (team_id, profile_id),
                KEY idx_team_profiles_team_id    (team_id),
                KEY idx_team_profiles_profile_id (profile_id),
                CONSTRAINT fk_team_profiles_team    FOREIGN KEY (team_id)    REFERENCES teams    (id),
                CONSTRAINT fk_team_profiles_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE events (
                id               CHAR(36)     NOT NULL,
                team_id          CHAR(36)     NOT NULL,
                season_id        CHAR(36)     NOT NULL,
                name             VARCHAR(255) NOT NULL,
                type             ENUM('lesson','stage','gala','workshop','other') NOT NULL,
                room_id          CHAR(36)              DEFAULT NULL,
                address_id       CHAR(36)              DEFAULT NULL,
                teacher_id       CHAR(36)              DEFAULT NULL,
                rrule            TEXT         NOT NULL,
                start_at         DATETIME     NOT NULL,
                end_at           DATETIME     NOT NULL,
                max_participants INT                   DEFAULT NULL,
                rrule_day_order  INT                   DEFAULT NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NOT NULL,
                deleted_at       DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_events_team_id    (team_id),
                KEY idx_events_season_id  (season_id),
                KEY idx_events_room_id    (room_id),
                KEY idx_events_address_id (address_id),
                KEY idx_events_teacher_id (teacher_id),
                CONSTRAINT fk_events_team      FOREIGN KEY (team_id)    REFERENCES teams         (id),
                CONSTRAINT fk_events_season    FOREIGN KEY (season_id)  REFERENCES seasons       (id),
                CONSTRAINT fk_events_room      FOREIGN KEY (room_id)    REFERENCES rooms         (id) ON DELETE SET NULL,
                CONSTRAINT fk_events_address   FOREIGN KEY (address_id) REFERENCES addresses     (id) ON DELETE SET NULL,
                CONSTRAINT fk_events_teacher   FOREIGN KEY (teacher_id) REFERENCES team_profiles (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE event_levels (
                event_id CHAR(36) NOT NULL,
                level_id CHAR(36) NOT NULL,
                PRIMARY KEY (event_id, level_id),
                KEY idx_event_levels_level_id (level_id),
                CONSTRAINT fk_event_levels_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
                CONSTRAINT fk_event_levels_level FOREIGN KEY (level_id) REFERENCES levels (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE event_age_groups (
                event_id     CHAR(36) NOT NULL,
                age_group_id CHAR(36) NOT NULL,
                PRIMARY KEY (event_id, age_group_id),
                KEY idx_event_age_groups_age_group_id (age_group_id),
                CONSTRAINT fk_event_age_groups_event     FOREIGN KEY (event_id)     REFERENCES events     (id) ON DELETE CASCADE,
                CONSTRAINT fk_event_age_groups_age_group FOREIGN KEY (age_group_id) REFERENCES age_groups (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE packages (
                id                              CHAR(36)     NOT NULL,
                team_id                         CHAR(36)     NOT NULL,
                season_id                       CHAR(36)     NOT NULL,
                name                            VARCHAR(255) NOT NULL,
                type                            ENUM('subscription_one_year','subscription_one_semester','trial_class','a_la_carte') NOT NULL,
                price                           INT          NOT NULL,
                classes_qty                     INT                   DEFAULT NULL,
                validity_start_type             ENUM('at_attribution','fixed_date') NOT NULL DEFAULT 'at_attribution',
                validity_starts_at              DATE                  DEFAULT NULL,
                expires_at                      DATE                  DEFAULT NULL,
                expiration_type                 ENUM('fixed','seasonal') NOT NULL DEFAULT 'seasonal',
                pre_registration_payment_type   VARCHAR(100)          DEFAULT NULL,
                usage_count                     INT          NOT NULL DEFAULT 0,
                apply_validity_to_existing      TINYINT(1)   NOT NULL DEFAULT 0,
                created_at                      DATETIME     NOT NULL,
                updated_at                      DATETIME     NOT NULL,
                deleted_at                      DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_packages_team_id   (team_id),
                KEY idx_packages_season_id (season_id),
                CONSTRAINT fk_packages_team   FOREIGN KEY (team_id)   REFERENCES teams   (id),
                CONSTRAINT fk_packages_season FOREIGN KEY (season_id) REFERENCES seasons (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE event_packages (
                event_id   CHAR(36) NOT NULL,
                package_id CHAR(36) NOT NULL,
                PRIMARY KEY (event_id, package_id),
                KEY idx_event_packages_package_id (package_id),
                CONSTRAINT fk_event_packages_event   FOREIGN KEY (event_id)   REFERENCES events   (id) ON DELETE CASCADE,
                CONSTRAINT fk_event_packages_package FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE package_age_groups (
                package_id   CHAR(36) NOT NULL,
                age_group_id CHAR(36) NOT NULL,
                PRIMARY KEY (package_id, age_group_id),
                KEY idx_package_age_groups_age_group_id (age_group_id),
                CONSTRAINT fk_package_age_groups_package   FOREIGN KEY (package_id)   REFERENCES packages   (id) ON DELETE CASCADE,
                CONSTRAINT fk_package_age_groups_age_group FOREIGN KEY (age_group_id) REFERENCES age_groups (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE payment_schedule_templates (
                id                                  CHAR(36)     NOT NULL,
                team_id                             CHAR(36)     NOT NULL,
                season_id                           CHAR(36)     NOT NULL,
                name                                VARCHAR(255) NOT NULL,
                type                                ENUM('recurring','fixed_dates') NOT NULL,
                nb_payments                         INT                   DEFAULT NULL,
                interval_duration                   INT                   DEFAULT NULL,
                day_of_month                        INT                   DEFAULT NULL,
                starts_at                           DATE                  DEFAULT NULL,
                fixed_dates                         JSON                  DEFAULT NULL,
                fixed_first_date_is_at_attribution  TINYINT(1)   NOT NULL DEFAULT 0,
                created_at                          DATETIME     NOT NULL,
                updated_at                          DATETIME     NOT NULL,
                deleted_at                          DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_pst_team_id   (team_id),
                KEY idx_pst_season_id (season_id),
                CONSTRAINT fk_pst_team   FOREIGN KEY (team_id)   REFERENCES teams   (id),
                CONSTRAINT fk_pst_season FOREIGN KEY (season_id) REFERENCES seasons (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE group_invites (
                id         CHAR(36)     NOT NULL,
                team_id    CHAR(36)     NOT NULL,
                season_id  CHAR(36)              DEFAULT NULL,
                email      VARCHAR(255)          DEFAULT NULL,
                role       ENUM('team_student','team_teacher','team_admin','team_owner') NOT NULL DEFAULT 'team_student',
                status     ENUM('pending','accepted','refused','expired') NOT NULL DEFAULT 'pending',
                token      VARCHAR(255) NOT NULL,
                expires_at DATETIME              DEFAULT NULL,
                invited_by CHAR(36)              DEFAULT NULL,
                created_at DATETIME     NOT NULL,
                updated_at DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_group_invites_token (token),
                KEY idx_group_invites_team_id   (team_id),
                KEY idx_group_invites_season_id (season_id),
                KEY idx_group_invites_invited_by (invited_by),
                CONSTRAINT fk_group_invites_team      FOREIGN KEY (team_id)   REFERENCES teams   (id),
                CONSTRAINT fk_group_invites_season    FOREIGN KEY (season_id) REFERENCES seasons (id) ON DELETE SET NULL,
                CONSTRAINT fk_group_invites_invited_by FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE event_occurences (
                id           CHAR(36)  NOT NULL,
                event_id     CHAR(36)  NOT NULL,
                team_id      CHAR(36)  NOT NULL,
                occurence_at DATETIME  NOT NULL,
                cancelled    TINYINT(1) NOT NULL DEFAULT 0,
                created_at   DATETIME  NOT NULL,
                updated_at   DATETIME  NOT NULL,
                deleted_at   DATETIME           DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_event_occurences_event_id (event_id),
                KEY idx_event_occurences_team_id  (team_id),
                KEY idx_event_occurences_date     (occurence_at),
                CONSTRAINT fk_event_occurences_event FOREIGN KEY (event_id) REFERENCES events (id),
                CONSTRAINT fk_event_occurences_team  FOREIGN KEY (team_id)  REFERENCES teams  (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE event_occurence_profiles (
                id                 CHAR(36)  NOT NULL,
                event_occurence_id CHAR(36)  NOT NULL,
                team_profile_id    CHAR(36)  NOT NULL,
                team_id            CHAR(36)  NOT NULL,
                status             ENUM('present','absent','unknown') NOT NULL DEFAULT 'unknown',
                created_at         DATETIME  NOT NULL,
                updated_at         DATETIME  NOT NULL,
                PRIMARY KEY (id),
                KEY idx_eop_occurence_id    (event_occurence_id),
                KEY idx_eop_team_profile_id (team_profile_id),
                KEY idx_eop_team_id         (team_id),
                CONSTRAINT fk_eop_occurence    FOREIGN KEY (event_occurence_id) REFERENCES event_occurences (id),
                CONSTRAINT fk_eop_team_profile FOREIGN KEY (team_profile_id)    REFERENCES team_profiles    (id),
                CONSTRAINT fk_eop_team         FOREIGN KEY (team_id)            REFERENCES teams            (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE intent_orders (
                id                          CHAR(36)  NOT NULL,
                team_id                     CHAR(36)  NOT NULL,
                season_id                   CHAR(36)  NOT NULL,
                profile_id                  CHAR(36)  NOT NULL,
                status                      ENUM('pending','completed','failed','expired') NOT NULL DEFAULT 'pending',
                payload                     JSON      NOT NULL,
                version                     VARCHAR(20)       DEFAULT NULL,
                stripe_checkout_session_id  VARCHAR(255)      DEFAULT NULL,
                created_at                  DATETIME  NOT NULL,
                updated_at                  DATETIME  NOT NULL,
                PRIMARY KEY (id),
                KEY idx_intent_orders_team_id   (team_id),
                KEY idx_intent_orders_season_id (season_id),
                KEY idx_intent_orders_profile_id (profile_id),
                KEY idx_intent_orders_stripe_session (stripe_checkout_session_id),
                CONSTRAINT fk_intent_orders_team    FOREIGN KEY (team_id)    REFERENCES teams    (id),
                CONSTRAINT fk_intent_orders_season  FOREIGN KEY (season_id)  REFERENCES seasons  (id),
                CONSTRAINT fk_intent_orders_profile FOREIGN KEY (profile_id) REFERENCES profiles (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE orders (
                id              CHAR(36)  NOT NULL,
                team_id         CHAR(36)  NOT NULL,
                season_id       CHAR(36)  NOT NULL,
                profile_id      CHAR(36)  NOT NULL,
                team_profile_id CHAR(36)  NOT NULL,
                package_type    VARCHAR(100)       DEFAULT NULL,
                total_amount    INT       NOT NULL,
                paid_amount     INT       NOT NULL DEFAULT 0,
                status          ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
                created_at      DATETIME  NOT NULL,
                updated_at      DATETIME  NOT NULL,
                deleted_at      DATETIME           DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_orders_team_id         (team_id),
                KEY idx_orders_season_id       (season_id),
                KEY idx_orders_profile_id      (profile_id),
                KEY idx_orders_team_profile_id (team_profile_id),
                CONSTRAINT fk_orders_team         FOREIGN KEY (team_id)         REFERENCES teams         (id),
                CONSTRAINT fk_orders_season       FOREIGN KEY (season_id)       REFERENCES seasons       (id),
                CONSTRAINT fk_orders_profile      FOREIGN KEY (profile_id)      REFERENCES profiles      (id),
                CONSTRAINT fk_orders_team_profile FOREIGN KEY (team_profile_id) REFERENCES team_profiles (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE order_items (
                id         CHAR(36)     NOT NULL,
                order_id   CHAR(36)     NOT NULL,
                type       ENUM('package','add_amount','remove_amount','commission','pre_registration_fee') NOT NULL,
                amount     INT          NOT NULL,
                package_id CHAR(36)              DEFAULT NULL,
                label      VARCHAR(255)          DEFAULT NULL,
                created_at DATETIME     NOT NULL,
                updated_at DATETIME     NOT NULL,
                deleted_at DATETIME              DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_order_items_order_id   (order_id),
                KEY idx_order_items_package_id (package_id),
                CONSTRAINT fk_order_items_order   FOREIGN KEY (order_id)   REFERENCES orders   (id),
                CONSTRAINT fk_order_items_package FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE payments (
                id                          CHAR(36)     NOT NULL,
                order_id                    CHAR(36)     NOT NULL,
                team_id                     CHAR(36)     NOT NULL,
                profile_id                  CHAR(36)     NOT NULL,
                amount                      INT          NOT NULL,
                paid_at                     DATETIME              DEFAULT NULL,
                method                      ENUM('onsite_cash','onsite_check','onsite_transfer','online_stripe_checkout','online_stripe_customer_balance','online_stripe_sepa_debit','online_stripe_link') NOT NULL,
                stripe_payment_intent_id    VARCHAR(255)          DEFAULT NULL,
                stripe_checkout_session_id  VARCHAR(255)          DEFAULT NULL,
                details                     JSON                  DEFAULT NULL,
                refund_amount               INT          NOT NULL DEFAULT 0,
                refunded_at                 DATETIME              DEFAULT NULL,
                created_at                  DATETIME     NOT NULL,
                updated_at                  DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY idx_payments_order_id   (order_id),
                KEY idx_payments_team_id    (team_id),
                KEY idx_payments_profile_id (profile_id),
                KEY idx_payments_stripe_pi  (stripe_payment_intent_id),
                KEY idx_payments_stripe_cs  (stripe_checkout_session_id),
                CONSTRAINT fk_payments_order   FOREIGN KEY (order_id)   REFERENCES orders   (id),
                CONSTRAINT fk_payments_team    FOREIGN KEY (team_id)    REFERENCES teams    (id),
                CONSTRAINT fk_payments_profile FOREIGN KEY (profile_id) REFERENCES profiles (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE payment_schedules (
                id           CHAR(36)  NOT NULL,
                order_id     CHAR(36)  NOT NULL,
                team_id      CHAR(36)  NOT NULL,
                profile_id   CHAR(36)  NOT NULL,
                amount       INT       NOT NULL,
                due_at       DATETIME  NOT NULL,
                status       ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
                payment_id   CHAR(36)           DEFAULT NULL,
                retry_count  INT       NOT NULL DEFAULT 0,
                last_retry_at DATETIME          DEFAULT NULL,
                created_at   DATETIME  NOT NULL,
                updated_at   DATETIME  NOT NULL,
                PRIMARY KEY (id),
                KEY idx_payment_schedules_order_id   (order_id),
                KEY idx_payment_schedules_team_id    (team_id),
                KEY idx_payment_schedules_profile_id (profile_id),
                KEY idx_payment_schedules_payment_id (payment_id),
                KEY idx_payment_schedules_due_at     (due_at),
                CONSTRAINT fk_payment_schedules_order   FOREIGN KEY (order_id)   REFERENCES orders   (id),
                CONSTRAINT fk_payment_schedules_team    FOREIGN KEY (team_id)    REFERENCES teams    (id),
                CONSTRAINT fk_payment_schedules_profile FOREIGN KEY (profile_id) REFERENCES profiles (id),
                CONSTRAINT fk_payment_schedules_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE invoices (
                id             CHAR(36)     NOT NULL,
                order_id       CHAR(36)     NOT NULL,
                team_id        CHAR(36)     NOT NULL,
                profile_id     CHAR(36)     NOT NULL,
                invoice_number VARCHAR(100) NOT NULL,
                invoice_date   DATE         NOT NULL,
                pdf_path       VARCHAR(500)          DEFAULT NULL,
                created_at     DATETIME     NOT NULL,
                updated_at     DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_invoices_number (invoice_number),
                KEY idx_invoices_order_id   (order_id),
                KEY idx_invoices_team_id    (team_id),
                KEY idx_invoices_profile_id (profile_id),
                CONSTRAINT fk_invoices_order   FOREIGN KEY (order_id)   REFERENCES orders   (id),
                CONSTRAINT fk_invoices_team    FOREIGN KEY (team_id)    REFERENCES teams    (id),
                CONSTRAINT fk_invoices_profile FOREIGN KEY (profile_id) REFERENCES profiles (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE team_profile_seasons (
                id                  CHAR(36)  NOT NULL,
                team_profile_id     CHAR(36)  NOT NULL,
                season_id           CHAR(36)  NOT NULL,
                team_id             CHAR(36)  NOT NULL,
                registration_status ENUM('not_registered','pre_registered','registered','withdrawn') NOT NULL DEFAULT 'not_registered',
                activity_ids        JSON               DEFAULT NULL,
                age_group_id        CHAR(36)           DEFAULT NULL,
                level_id            CHAR(36)           DEFAULT NULL,
                top_size            VARCHAR(10)        DEFAULT NULL,
                bottom_size         VARCHAR(10)        DEFAULT NULL,
                feet_size           VARCHAR(10)        DEFAULT NULL,
                region_size         VARCHAR(10)        DEFAULT NULL,
                emergency_contact   JSON               DEFAULT NULL,
                injury_warning      TEXT               DEFAULT NULL,
                created_at          DATETIME  NOT NULL,
                updated_at          DATETIME  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tps_team_profile_season (team_profile_id, season_id),
                KEY idx_tps_team_profile_id (team_profile_id),
                KEY idx_tps_season_id       (season_id),
                KEY idx_tps_team_id         (team_id),
                KEY idx_tps_age_group_id    (age_group_id),
                KEY idx_tps_level_id        (level_id),
                CONSTRAINT fk_tps_team_profile FOREIGN KEY (team_profile_id) REFERENCES team_profiles (id),
                CONSTRAINT fk_tps_season       FOREIGN KEY (season_id)       REFERENCES seasons       (id),
                CONSTRAINT fk_tps_team         FOREIGN KEY (team_id)         REFERENCES teams         (id),
                CONSTRAINT fk_tps_age_group    FOREIGN KEY (age_group_id)    REFERENCES age_groups    (id) ON DELETE SET NULL,
                CONSTRAINT fk_tps_level        FOREIGN KEY (level_id)        REFERENCES levels        (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE team_profile_packages (
                id                  CHAR(36)     NOT NULL,
                team_profile_id     CHAR(36)     NOT NULL,
                package_id          CHAR(36)     NOT NULL,
                team_id             CHAR(36)     NOT NULL,
                season_id           CHAR(36)     NOT NULL,
                order_id            CHAR(36)               DEFAULT NULL,
                type                VARCHAR(100) NOT NULL,
                status              ENUM('active','expired','cancelled','pending','exhausted') NOT NULL DEFAULT 'pending',
                classes_done        INT          NOT NULL DEFAULT 0,
                classes_qty         INT                    DEFAULT NULL,
                validity_start_type ENUM('at_attribution','fixed_date') DEFAULT NULL,
                validity_starts_at  DATE                   DEFAULT NULL,
                expires_at          DATE                   DEFAULT NULL,
                validity_status     VARCHAR(50)            DEFAULT NULL,
                created_at          DATETIME     NOT NULL,
                updated_at          DATETIME     NOT NULL,
                deleted_at          DATETIME               DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_tpp_team_profile_id (team_profile_id),
                KEY idx_tpp_package_id      (package_id),
                KEY idx_tpp_team_id         (team_id),
                KEY idx_tpp_season_id       (season_id),
                KEY idx_tpp_order_id        (order_id),
                CONSTRAINT fk_tpp_team_profile FOREIGN KEY (team_profile_id) REFERENCES team_profiles (id),
                CONSTRAINT fk_tpp_package      FOREIGN KEY (package_id)      REFERENCES packages      (id),
                CONSTRAINT fk_tpp_team         FOREIGN KEY (team_id)         REFERENCES teams         (id),
                CONSTRAINT fk_tpp_season       FOREIGN KEY (season_id)       REFERENCES seasons       (id),
                CONSTRAINT fk_tpp_order        FOREIGN KEY (order_id)        REFERENCES orders        (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE team_profile_gala_participations (
                id              CHAR(36)  NOT NULL,
                team_profile_id CHAR(36)  NOT NULL,
                event_id        CHAR(36)  NOT NULL,
                team_id         CHAR(36)  NOT NULL,
                season_id       CHAR(36)  NOT NULL,
                participates    TINYINT(1)         DEFAULT NULL,
                notes           TEXT               DEFAULT NULL,
                created_at      DATETIME  NOT NULL,
                updated_at      DATETIME  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tpgp_team_profile_event (team_profile_id, event_id),
                KEY idx_tpgp_team_profile_id (team_profile_id),
                KEY idx_tpgp_event_id        (event_id),
                KEY idx_tpgp_team_id         (team_id),
                KEY idx_tpgp_season_id       (season_id),
                CONSTRAINT fk_tpgp_team_profile FOREIGN KEY (team_profile_id) REFERENCES team_profiles (id),
                CONSTRAINT fk_tpgp_event        FOREIGN KEY (event_id)        REFERENCES events        (id),
                CONSTRAINT fk_tpgp_team         FOREIGN KEY (team_id)         REFERENCES teams         (id),
                CONSTRAINT fk_tpgp_season       FOREIGN KEY (season_id)       REFERENCES seasons       (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE profile_price_modifiers (
                team_profile_id   CHAR(36) NOT NULL,
                price_modifier_id CHAR(36) NOT NULL,
                PRIMARY KEY (team_profile_id, price_modifier_id),
                KEY idx_ppm_price_modifier_id (price_modifier_id),
                CONSTRAINT fk_ppm_team_profile   FOREIGN KEY (team_profile_id)   REFERENCES team_profiles   (id) ON DELETE CASCADE,
                CONSTRAINT fk_ppm_price_modifier FOREIGN KEY (price_modifier_id) REFERENCES price_modifiers (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE email_bounces (
                id         CHAR(36)    NOT NULL,
                email      VARCHAR(255) NOT NULL,
                event_type VARCHAR(50)  NOT NULL,
                payload    JSON                  DEFAULT NULL,
                created_at DATETIME    NOT NULL,
                PRIMARY KEY (id),
                KEY idx_email_bounces_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE team_home_kpi_daily (
                id         CHAR(36)  NOT NULL,
                team_id    CHAR(36)  NOT NULL,
                date       DATE      NOT NULL,
                data       JSON      NOT NULL,
                created_at DATETIME  NOT NULL,
                updated_at DATETIME  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_team_home_kpi_daily_team_date (team_id, date),
                KEY idx_team_home_kpi_daily_team_id (team_id),
                CONSTRAINT fk_team_home_kpi_daily_team FOREIGN KEY (team_id) REFERENCES teams (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE teams DROP FOREIGN KEY fk_teams_current_season');
        $this->addSql('ALTER TABLE price_modifiers DROP FOREIGN KEY fk_price_modifiers_team');
        $this->addSql('ALTER TABLE price_modifiers DROP FOREIGN KEY fk_price_modifiers_season');

        $this->addSql('DROP TABLE IF EXISTS team_home_kpi_daily');
        $this->addSql('DROP TABLE IF EXISTS email_bounces');
        $this->addSql('DROP TABLE IF EXISTS profile_price_modifiers');
        $this->addSql('DROP TABLE IF EXISTS team_profile_gala_participations');
        $this->addSql('DROP TABLE IF EXISTS team_profile_packages');
        $this->addSql('DROP TABLE IF EXISTS team_profile_seasons');
        $this->addSql('DROP TABLE IF EXISTS invoices');
        $this->addSql('DROP TABLE IF EXISTS payment_schedules');
        $this->addSql('DROP TABLE IF EXISTS payments');
        $this->addSql('DROP TABLE IF EXISTS order_items');
        $this->addSql('DROP TABLE IF EXISTS orders');
        $this->addSql('DROP TABLE IF EXISTS intent_orders');
        $this->addSql('DROP TABLE IF EXISTS event_occurence_profiles');
        $this->addSql('DROP TABLE IF EXISTS event_occurences');
        $this->addSql('DROP TABLE IF EXISTS package_age_groups');
        $this->addSql('DROP TABLE IF EXISTS event_packages');
        $this->addSql('DROP TABLE IF EXISTS packages');
        $this->addSql('DROP TABLE IF EXISTS event_age_groups');
        $this->addSql('DROP TABLE IF EXISTS event_levels');
        $this->addSql('DROP TABLE IF EXISTS events');
        $this->addSql('DROP TABLE IF EXISTS group_invites');
        $this->addSql('DROP TABLE IF EXISTS payment_schedule_templates');
        $this->addSql('DROP TABLE IF EXISTS team_profiles');
        $this->addSql('DROP TABLE IF EXISTS addresses');
        $this->addSql('DROP TABLE IF EXISTS age_groups');
        $this->addSql('DROP TABLE IF EXISTS levels');
        $this->addSql('DROP TABLE IF EXISTS rooms');
        $this->addSql('DROP TABLE IF EXISTS activities');
        $this->addSql('DROP TABLE IF EXISTS price_modifiers');
        $this->addSql('DROP TABLE IF EXISTS seasons');
        $this->addSql('DROP TABLE IF EXISTS teams');
        $this->addSql('DROP TABLE IF EXISTS profiles');
        $this->addSql('DROP TABLE IF EXISTS users');
    }
}
