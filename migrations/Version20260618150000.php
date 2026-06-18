<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix FK constraints/indexes on school_profiles (old hash from team_profiles) + rename team_id in school_profile_packages';
    }

    public function up(Schema $schema): void
    {
        // school_profile_packages: missed team_id → school_id rename
        $this->addSql('ALTER TABLE school_profile_packages CHANGE team_id school_id varchar(36) NOT NULL');

        // school_profiles: FK constraints still named after old table "team_profiles" (hash 3372594E)
        // MariaDB 10.4 does not support RENAME INDEX; must DROP FK + DROP INDEX + ADD INDEX + ADD FK

        // school_id FK
        $this->addSql('ALTER TABLE school_profiles DROP FOREIGN KEY FK_3372594E296CD8AE');
        $this->addSql('ALTER TABLE school_profiles DROP INDEX IDX_3372594E296CD8AE');
        $this->addSql('ALTER TABLE school_profiles ADD INDEX IDX_7576B174C32A47EE (school_id)');
        $this->addSql('ALTER TABLE school_profiles ADD CONSTRAINT FK_7576B174C32A47EE FOREIGN KEY (school_id) REFERENCES schools (id)');

        // profile_id FK
        $this->addSql('ALTER TABLE school_profiles DROP FOREIGN KEY FK_3372594ECCFA12B8');
        $this->addSql('ALTER TABLE school_profiles DROP INDEX IDX_3372594ECCFA12B8');
        $this->addSql('ALTER TABLE school_profiles ADD INDEX IDX_7576B174CCFA12B8 (profile_id)');
        $this->addSql('ALTER TABLE school_profiles ADD CONSTRAINT FK_7576B174CCFA12B8 FOREIGN KEY (profile_id) REFERENCES profiles (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE school_profile_packages CHANGE school_id team_id varchar(36) NOT NULL');

        $this->addSql('ALTER TABLE school_profiles DROP FOREIGN KEY FK_7576B174CCFA12B8');
        $this->addSql('ALTER TABLE school_profiles DROP INDEX IDX_7576B174CCFA12B8');
        $this->addSql('ALTER TABLE school_profiles ADD INDEX IDX_3372594ECCFA12B8 (profile_id)');
        $this->addSql('ALTER TABLE school_profiles ADD CONSTRAINT FK_3372594ECCFA12B8 FOREIGN KEY (profile_id) REFERENCES profiles (id)');

        $this->addSql('ALTER TABLE school_profiles DROP FOREIGN KEY FK_7576B174C32A47EE');
        $this->addSql('ALTER TABLE school_profiles DROP INDEX IDX_7576B174C32A47EE');
        $this->addSql('ALTER TABLE school_profiles ADD INDEX IDX_3372594E296CD8AE (school_id)');
        $this->addSql('ALTER TABLE school_profiles ADD CONSTRAINT FK_3372594E296CD8AE FOREIGN KEY (school_id) REFERENCES schools (id)');
    }
}
