<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618_TeamToSchool extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename team_* columns to school_* and update SchoolRole enum values (tables already renamed in DB)';
    }

    public function up(Schema $schema): void
    {
        // NOTE: RENAME TABLE statements already ran (tables are named schools, school_profiles, etc.)
        // Using CHANGE syntax (MariaDB 10.4 compatible, not RENAME COLUMN which needs 10.5.2+)

        // ── 1. school_profiles : team_id → school_id ──────────────────────────
        $this->addSql('ALTER TABLE school_profiles CHANGE team_id school_id varchar(36) NOT NULL');

        // ── 2. school_profile_seasons : team_profile_id + team_id ─────────────
        $this->addSql('ALTER TABLE school_profile_seasons CHANGE team_profile_id school_profile_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE school_profile_seasons CHANGE team_id school_id varchar(36) NOT NULL');

        // ── 3. school_profile_packages : team_profile_id ──────────────────────
        $this->addSql('ALTER TABLE school_profile_packages CHANGE team_profile_id school_profile_id varchar(36) NOT NULL');

        // ── 4. school_profile_gala_participations ─────────────────────────────
        $this->addSql('ALTER TABLE school_profile_gala_participations CHANGE team_profile_id school_profile_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE school_profile_gala_participations CHANGE team_id school_id varchar(36) NOT NULL');

        // ── 5. school_home_kpi_daily ──────────────────────────────────────────
        $this->addSql('ALTER TABLE school_home_kpi_daily CHANGE team_id school_id varchar(36) NOT NULL');

        // ── 6. Other tables : team_id → school_id ────────────────────────────
        $this->addSql('ALTER TABLE activities CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE addresses CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE age_groups CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE events CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE event_occurences CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE group_invites CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE intent_orders CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE invoices CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE levels CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE orders CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE packages CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE payment_schedule_templates CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE payment_schedules CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE payments CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE price_modifiers CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE rooms CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE seasons CHANGE team_id school_id varchar(36) NOT NULL');

        // ── 7. event_occurence_profiles + orders : team_profile_id ───────────
        $this->addSql('ALTER TABLE event_occurence_profiles CHANGE team_profile_id school_profile_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE event_occurence_profiles CHANGE team_id school_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE orders CHANGE team_profile_id school_profile_id varchar(36) NOT NULL');

        // ── 8. Update SchoolRole enum values ──────────────────────────────────
        $this->addSql("UPDATE school_profiles SET role = 'student' WHERE role = 'team_student'");
        $this->addSql("UPDATE school_profiles SET role = 'teacher' WHERE role = 'team_teacher'");
        $this->addSql("UPDATE school_profiles SET role = 'admin'   WHERE role = 'team_admin'");
        $this->addSql("UPDATE school_profiles SET role = 'owner'   WHERE role = 'team_owner'");

        // ── 9. Unique indexes : drop old names, add new names ─────────────────
        $this->addSql('ALTER TABLE school_profiles DROP INDEX uq_team_profile, ADD UNIQUE INDEX uq_school_profile (school_id, profile_id)');
        $this->addSql('ALTER TABLE school_profile_seasons DROP INDEX uq_team_profile_season, ADD UNIQUE INDEX uq_school_profile_season (school_profile_id, season_id)');
        $this->addSql('ALTER TABLE school_profile_gala_participations DROP INDEX uq_team_profile_event, ADD UNIQUE INDEX uq_school_profile_event (school_profile_id, event_id)');
        $this->addSql('ALTER TABLE school_home_kpi_daily DROP INDEX uq_team_home_kpi_daily_team_date, ADD UNIQUE INDEX uq_school_home_kpi_daily_school_date (school_id, date)');
    }

    public function down(Schema $schema): void
    {
        // ── Restore unique indexes ─────────────────────────────────────────────
        $this->addSql('ALTER TABLE school_home_kpi_daily DROP INDEX uq_school_home_kpi_daily_school_date, ADD UNIQUE INDEX uq_team_home_kpi_daily_team_date (school_id, date)');
        $this->addSql('ALTER TABLE school_profile_gala_participations DROP INDEX uq_school_profile_event, ADD UNIQUE INDEX uq_team_profile_event (school_profile_id, event_id)');
        $this->addSql('ALTER TABLE school_profile_seasons DROP INDEX uq_school_profile_season, ADD UNIQUE INDEX uq_team_profile_season (school_profile_id, season_id)');
        $this->addSql('ALTER TABLE school_profiles DROP INDEX uq_school_profile, ADD UNIQUE INDEX uq_team_profile (school_id, profile_id)');

        // ── Restore enum values ────────────────────────────────────────────────
        $this->addSql("UPDATE school_profiles SET role = 'team_owner'   WHERE role = 'owner'");
        $this->addSql("UPDATE school_profiles SET role = 'team_admin'   WHERE role = 'admin'");
        $this->addSql("UPDATE school_profiles SET role = 'team_teacher' WHERE role = 'teacher'");
        $this->addSql("UPDATE school_profiles SET role = 'team_student' WHERE role = 'student'");

        // ── Restore columns ────────────────────────────────────────────────────
        $this->addSql('ALTER TABLE orders CHANGE school_profile_id team_profile_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE event_occurence_profiles CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE event_occurence_profiles CHANGE school_profile_id team_profile_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE seasons CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE rooms CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE price_modifiers CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE payments CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE payment_schedules CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE payment_schedule_templates CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE packages CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE orders CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE levels CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE invoices CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE intent_orders CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE group_invites CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE event_occurences CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE events CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE age_groups CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE addresses CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE activities CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE school_home_kpi_daily CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE school_profile_gala_participations CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE school_profile_gala_participations CHANGE school_profile_id team_profile_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE school_profile_packages CHANGE school_profile_id team_profile_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE school_profile_seasons CHANGE school_id team_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE school_profile_seasons CHANGE school_profile_id team_profile_id varchar(36) NOT NULL');
        $this->addSql('ALTER TABLE school_profiles CHANGE school_id team_id varchar(36) NOT NULL');

        // ── Restore table names ────────────────────────────────────────────────
        $this->addSql('RENAME TABLE school_home_kpi_daily TO team_home_kpi_daily');
        $this->addSql('RENAME TABLE school_profile_gala_participations TO team_profile_gala_participations');
        $this->addSql('RENAME TABLE school_profile_packages TO team_profile_packages');
        $this->addSql('RENAME TABLE school_profile_seasons TO team_profile_seasons');
        $this->addSql('RENAME TABLE school_profiles TO team_profiles');
        $this->addSql('RENAME TABLE schools TO teams');
    }
}
