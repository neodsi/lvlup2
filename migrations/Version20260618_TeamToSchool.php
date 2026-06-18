<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618_TeamToSchool extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename team_* tables/columns to school_* and update TeamRole enum values';
    }

    public function up(Schema $schema): void
    {
        // ── 1. Rename main tables ──────────────────────────────────────────────
        $this->addSql('RENAME TABLE teams TO schools');
        $this->addSql('RENAME TABLE team_profiles TO school_profiles');
        $this->addSql('RENAME TABLE team_profile_seasons TO school_profile_seasons');
        $this->addSql('RENAME TABLE team_profile_packages TO school_profile_packages');
        $this->addSql('RENAME TABLE team_profile_gala_participations TO school_profile_gala_participations');
        $this->addSql('RENAME TABLE team_home_kpi_daily TO school_home_kpi_daily');

        // ── 2. Rename team_id → school_id in school_profiles ──────────────────
        $this->addSql('ALTER TABLE school_profiles RENAME COLUMN team_id TO school_id');

        // ── 3. Rename columns in school_profile_seasons ────────────────────────
        $this->addSql('ALTER TABLE school_profile_seasons RENAME COLUMN team_profile_id TO school_profile_id');
        $this->addSql('ALTER TABLE school_profile_seasons RENAME COLUMN team_id TO school_id');

        // ── 4. Rename columns in school_profile_packages ───────────────────────
        $this->addSql('ALTER TABLE school_profile_packages RENAME COLUMN team_profile_id TO school_profile_id');

        // ── 5. Rename columns in school_profile_gala_participations ────────────
        $this->addSql('ALTER TABLE school_profile_gala_participations RENAME COLUMN team_profile_id TO school_profile_id');
        $this->addSql('ALTER TABLE school_profile_gala_participations RENAME COLUMN team_id TO school_id');

        // ── 6. Rename column in school_home_kpi_daily ──────────────────────────
        $this->addSql('ALTER TABLE school_home_kpi_daily RENAME COLUMN team_id TO school_id');

        // ── 7. Rename team_id → school_id in all other tables ─────────────────
        $this->addSql('ALTER TABLE activities RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE addresses RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE age_groups RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE events RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE event_occurences RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE group_invites RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE intent_orders RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE invoices RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE levels RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE orders RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE packages RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE payment_schedule_templates RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE payment_schedules RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE payments RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE price_modifiers RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE rooms RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE seasons RENAME COLUMN team_id TO school_id');

        // ── 8. Rename team_profile_id → school_profile_id in other tables ──────
        $this->addSql('ALTER TABLE event_occurence_profiles RENAME COLUMN team_profile_id TO school_profile_id');
        $this->addSql('ALTER TABLE event_occurence_profiles RENAME COLUMN team_id TO school_id');
        $this->addSql('ALTER TABLE orders RENAME COLUMN team_profile_id TO school_profile_id');

        // ── 9. Update SchoolRole enum values in school_profiles ────────────────
        $this->addSql("UPDATE school_profiles SET role = 'student' WHERE role = 'team_student'");
        $this->addSql("UPDATE school_profiles SET role = 'teacher' WHERE role = 'team_teacher'");
        $this->addSql("UPDATE school_profiles SET role = 'admin'   WHERE role = 'team_admin'");
        $this->addSql("UPDATE school_profiles SET role = 'owner'   WHERE role = 'team_owner'");

        // ── 10. Rename unique constraint indexes ───────────────────────────────
        $this->addSql('ALTER TABLE school_profiles DROP INDEX uq_team_profile, ADD UNIQUE INDEX uq_school_profile (school_id, profile_id)');
        $this->addSql('ALTER TABLE school_profile_seasons DROP INDEX uq_team_profile_season, ADD UNIQUE INDEX uq_school_profile_season (school_profile_id, season_id)');
        $this->addSql('ALTER TABLE school_profile_gala_participations DROP INDEX uq_team_profile_event, ADD UNIQUE INDEX uq_school_profile_event (school_profile_id, event_id)');
        $this->addSql('ALTER TABLE school_home_kpi_daily DROP INDEX uq_team_home_kpi_daily_team_date, ADD UNIQUE INDEX uq_school_home_kpi_daily_school_date (school_id, date)');
    }

    public function down(Schema $schema): void
    {
        // ── Restore unique constraint indexes ──────────────────────────────────
        $this->addSql('ALTER TABLE school_home_kpi_daily DROP INDEX uq_school_home_kpi_daily_school_date, ADD UNIQUE INDEX uq_team_home_kpi_daily_team_date (school_id, date)');
        $this->addSql('ALTER TABLE school_profile_gala_participations DROP INDEX uq_school_profile_event, ADD UNIQUE INDEX uq_team_profile_event (school_profile_id, event_id)');
        $this->addSql('ALTER TABLE school_profile_seasons DROP INDEX uq_school_profile_season, ADD UNIQUE INDEX uq_team_profile_season (school_profile_id, season_id)');
        $this->addSql('ALTER TABLE school_profiles DROP INDEX uq_school_profile, ADD UNIQUE INDEX uq_team_profile (school_id, profile_id)');

        // ── Restore SchoolRole enum values ────────────────────────────────────
        $this->addSql("UPDATE school_profiles SET role = 'team_owner'   WHERE role = 'owner'");
        $this->addSql("UPDATE school_profiles SET role = 'team_admin'   WHERE role = 'admin'");
        $this->addSql("UPDATE school_profiles SET role = 'team_teacher' WHERE role = 'teacher'");
        $this->addSql("UPDATE school_profiles SET role = 'team_student' WHERE role = 'student'");

        // ── Restore team_profile_id columns ──────────────────────────────────
        $this->addSql('ALTER TABLE orders RENAME COLUMN school_profile_id TO team_profile_id');
        $this->addSql('ALTER TABLE event_occurence_profiles RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE event_occurence_profiles RENAME COLUMN school_profile_id TO team_profile_id');

        // ── Restore team_id columns in other tables ────────────────────────────
        $this->addSql('ALTER TABLE seasons RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE rooms RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE price_modifiers RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE payments RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE payment_schedules RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE payment_schedule_templates RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE packages RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE orders RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE levels RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE invoices RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE intent_orders RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE group_invites RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE event_occurences RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE events RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE age_groups RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE addresses RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE activities RENAME COLUMN school_id TO team_id');

        // ── Restore school_home_kpi_daily ─────────────────────────────────────
        $this->addSql('ALTER TABLE school_home_kpi_daily RENAME COLUMN school_id TO team_id');

        // ── Restore school_profile_gala_participations ────────────────────────
        $this->addSql('ALTER TABLE school_profile_gala_participations RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE school_profile_gala_participations RENAME COLUMN school_profile_id TO team_profile_id');

        // ── Restore school_profile_packages ──────────────────────────────────
        $this->addSql('ALTER TABLE school_profile_packages RENAME COLUMN school_profile_id TO team_profile_id');

        // ── Restore school_profile_seasons ────────────────────────────────────
        $this->addSql('ALTER TABLE school_profile_seasons RENAME COLUMN school_id TO team_id');
        $this->addSql('ALTER TABLE school_profile_seasons RENAME COLUMN school_profile_id TO team_profile_id');

        // ── Restore school_profiles.school_id ─────────────────────────────────
        $this->addSql('ALTER TABLE school_profiles RENAME COLUMN school_id TO team_id');

        // ── Rename tables back ─────────────────────────────────────────────────
        $this->addSql('RENAME TABLE school_home_kpi_daily TO team_home_kpi_daily');
        $this->addSql('RENAME TABLE school_profile_gala_participations TO team_profile_gala_participations');
        $this->addSql('RENAME TABLE school_profile_packages TO team_profile_packages');
        $this->addSql('RENAME TABLE school_profile_seasons TO team_profile_seasons');
        $this->addSql('RENAME TABLE school_profiles TO team_profiles');
        $this->addSql('RENAME TABLE schools TO teams');
    }
}
