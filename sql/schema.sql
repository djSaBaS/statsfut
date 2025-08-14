-- sql/schema.sql
--
-- STATSFUT – Esquema inicial
-- Motor: InnoDB, Collation: utf8mb4_unicode_ci

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- 1) Usuarios
CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_hash      CHAR(64) NOT NULL UNIQUE,        -- sha256(normalized email) para búsquedas
  email_enc       TEXT NOT NULL,                   -- email cifrado app-layer (AES-256-GCM)
  password_hash   VARCHAR(255) NOT NULL,           -- password_hash(PASSWORD_DEFAULT)
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_users_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Equipos
CREATE TABLE IF NOT EXISTS teams (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  name_enc      TEXT NOT NULL,                     -- nombre cifrado
  crest_path    VARCHAR(255) DEFAULT NULL,         -- ruta relativa en /assets/img
  is_own        TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = nuestro equipo
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_teams_user (user_id),
  CONSTRAINT fk_teams_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Ajustes de usuario (predeterminados de partido)
CREATE TABLE IF NOT EXISTS user_settings (
  user_id            INT UNSIGNED NOT NULL PRIMARY KEY,
  halves_default     TINYINT UNSIGNED NOT NULL DEFAULT 2,
  minutes_per_half   TINYINT UNSIGNED NOT NULL DEFAULT 25,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Partidos
CREATE TABLE IF NOT EXISTS matches (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  our_team_id       INT UNSIGNED NOT NULL,            -- FK a nuestro equipo (teams.is_own=1)
  our_team_is_home  TINYINT(1) NOT NULL DEFAULT 1,    -- 1 = local, 0 = visitante
  opponent_name_enc TEXT NOT NULL,                     -- rival (cifrado)
  match_datetime    DATETIME NOT NULL,
  halves            TINYINT UNSIGNED NOT NULL DEFAULT 2,
  minutes_per_half  TINYINT UNSIGNED NOT NULL DEFAULT 25,
  status            ENUM('scheduled','ongoing','finished') NOT NULL DEFAULT 'scheduled',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_matches_user (user_id),
  INDEX idx_matches_time (match_datetime),
  CONSTRAINT fk_matches_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_matches_ourteam FOREIGN KEY (our_team_id) REFERENCES teams(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Estadísticas agregadas por equipo (por partido)
CREATE TABLE IF NOT EXISTS match_stats (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id           INT UNSIGNED NOT NULL,
  team_side          ENUM('us','them') NOT NULL,     -- lado dentro del partido
  passes             INT UNSIGNED NOT NULL DEFAULT 0,
  corners            INT UNSIGNED NOT NULL DEFAULT 0,
  throwins           INT UNSIGNED NOT NULL DEFAULT 0,
  shots_on_target    INT UNSIGNED NOT NULL DEFAULT 0,
  goals              INT UNSIGNED NOT NULL DEFAULT 0,
  max_pass_streak    INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_match_team (match_id, team_side),
  CONSTRAINT fk_stats_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Estado de racha en vivo (para cálculo eficiente en tiempo real)
CREATE TABLE IF NOT EXISTS match_runtime (
  match_id          INT UNSIGNED NOT NULL PRIMARY KEY,
  us_current_streak   INT UNSIGNED NOT NULL DEFAULT 0,
  them_current_streak INT UNSIGNED NOT NULL DEFAULT 0,
  last_event_at       DATETIME DEFAULT NULL,
  CONSTRAINT fk_runtime_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) Eventos (para auditoría y analítica)
CREATE TABLE IF NOT EXISTS match_events (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id         INT UNSIGNED NOT NULL,
  team_side        ENUM('us','them') NOT NULL,
  event_type       ENUM('pass','corner','throwin','shot_on_target','goal') NOT NULL,
  streak_at_event  INT UNSIGNED NOT NULL DEFAULT 0,  -- racha de pases en el instante del evento
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_events_match (match_id, created_at),
  CONSTRAINT fk_events_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) Detalle de goles (para consultar de forma directa las rachas en gol)
CREATE TABLE IF NOT EXISTS goals (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id         INT UNSIGNED NOT NULL,
  team_side        ENUM('us','them') NOT NULL,
  passes_streak    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_goals_match (match_id, team_side),
  CONSTRAINT fk_goals_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9) Control de reloj por partido/parte
CREATE TABLE IF NOT EXISTS match_clock (
  match_id          INT UNSIGNED NOT NULL PRIMARY KEY,      -- referencia 1:1 con el partido
  current_half      TINYINT UNSIGNED NOT NULL DEFAULT 1,    -- parte actual (1..N)
  is_running        TINYINT(1) NOT NULL DEFAULT 0,          -- 1 si el tiempo corre
  seconds_in_half   INT UNSIGNED NOT NULL DEFAULT 0,        -- segundos acumulados en la parte actual (pausado)
  last_started_at   DATETIME DEFAULT NULL,                  -- cuándo se inició el último conteo (si is_running=1)
  CONSTRAINT fk_clock_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
