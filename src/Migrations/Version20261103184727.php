<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103184727 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_roles (id INT AUTO_INCREMENT NOT NULL, client_id VARCHAR(80) DEFAULT NULL, slug VARCHAR(50) NOT NULL, description VARCHAR(255) DEFAULT NULL, UNIQUE INDEX uniq_client_role (client_id, slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_log (id BIGINT AUTO_INCREMENT NOT NULL, user_uuid VARCHAR(36) DEFAULT NULL, client_id VARCHAR(80) DEFAULT NULL, action VARCHAR(50) NOT NULL, ip VARCHAR(45) DEFAULT NULL, context JSON NOT NULL, created_at DATETIME NOT NULL, INDEX idx_audit_user (user_uuid), INDEX idx_audit_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth2_access_token (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, user_identifier VARCHAR(128) DEFAULT NULL, scopes TEXT DEFAULT NULL, revoked TINYINT NOT NULL, client VARCHAR(32) NOT NULL, INDEX IDX_454D9673C7440455 (client), PRIMARY KEY (identifier)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth2_authorization_code (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, user_identifier VARCHAR(128) DEFAULT NULL, scopes TEXT DEFAULT NULL, revoked TINYINT NOT NULL, client VARCHAR(32) NOT NULL, INDEX IDX_509FEF5FC7440455 (client), PRIMARY KEY (identifier)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth2_client (name VARCHAR(128) NOT NULL, secret VARCHAR(128) DEFAULT NULL, redirect_uris TEXT DEFAULT NULL, grants TEXT DEFAULT NULL, scopes TEXT DEFAULT NULL, active TINYINT NOT NULL, allow_plain_text_pkce TINYINT DEFAULT 0 NOT NULL, identifier VARCHAR(32) NOT NULL, PRIMARY KEY (identifier)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth2_refresh_token (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, revoked TINYINT NOT NULL, access_token CHAR(80) DEFAULT NULL, INDEX IDX_4DD90732B6A2DD68 (access_token), PRIMARY KEY (identifier)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE team_categories (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(50) NOT NULL, name VARCHAR(100) NOT NULL, UNIQUE INDEX UNIQ_42A34AD989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_roles (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, role_id INT NOT NULL, team_id INT DEFAULT NULL, UNIQUE INDEX uniq_user_role_team (user_id, role_id, team_id), INDEX IDX_54FCD59FA76ED395 (user_id), INDEX IDX_54FCD59FD60322AC (role_id), INDEX IDX_54FCD59F296CD8AE (team_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_teams (id INT AUTO_INCREMENT NOT NULL, membership VARCHAR(20) NOT NULL, user_id INT NOT NULL, team_id INT NOT NULL, UNIQUE INDEX uniq_user_team (user_id, team_id), INDEX IDX_7400D900A76ED395 (user_id), INDEX IDX_7400D900296CD8AE (team_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(120) NOT NULL, active TINYINT DEFAULT 1 NOT NULL, email_verified TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_1483A5E9D17F50A6 (uuid), UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE oauth2_access_token ADD CONSTRAINT FK_454D9673C7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth2_authorization_code ADD CONSTRAINT FK_509FEF5FC7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth2_refresh_token ADD CONSTRAINT FK_4DD90732B6A2DD68 FOREIGN KEY (access_token) REFERENCES oauth2_access_token (identifier) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FD60322AC FOREIGN KEY (role_id) REFERENCES app_roles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59F296CD8AE FOREIGN KEY (team_id) REFERENCES team_categories (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_teams ADD CONSTRAINT FK_7400D900A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_teams ADD CONSTRAINT FK_7400D900296CD8AE FOREIGN KEY (team_id) REFERENCES team_categories (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth2_access_token DROP FOREIGN KEY FK_454D9673C7440455');
        $this->addSql('ALTER TABLE oauth2_authorization_code DROP FOREIGN KEY FK_509FEF5FC7440455');
        $this->addSql('ALTER TABLE oauth2_refresh_token DROP FOREIGN KEY FK_4DD90732B6A2DD68');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FA76ED395');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FD60322AC');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59F296CD8AE');
        $this->addSql('ALTER TABLE user_teams DROP FOREIGN KEY FK_7400D900A76ED395');
        $this->addSql('ALTER TABLE user_teams DROP FOREIGN KEY FK_7400D900296CD8AE');
        $this->addSql('DROP TABLE app_roles');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE oauth2_access_token');
        $this->addSql('DROP TABLE oauth2_authorization_code');
        $this->addSql('DROP TABLE oauth2_client');
        $this->addSql('DROP TABLE oauth2_refresh_token');
        $this->addSql('DROP TABLE team_categories');
        $this->addSql('DROP TABLE user_roles');
        $this->addSql('DROP TABLE user_teams');
        $this->addSql('DROP TABLE users');
    }
}
