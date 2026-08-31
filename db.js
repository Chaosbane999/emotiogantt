const { DatabaseSync } = require('node:sqlite');
const path = require('path');
const fs = require('fs');

const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, 'data');
fs.mkdirSync(DATA_DIR, { recursive: true });

const db = new DatabaseSync(path.join(DATA_DIR, 'clearpath.db'));
db.exec('PRAGMA journal_mode = WAL');
db.exec('PRAGMA foreign_keys = ON');

db.exec(`
CREATE TABLE IF NOT EXISTS projects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  color TEXT NOT NULL DEFAULT '#24bbb6',
  due_date TEXT,
  notes TEXT NOT NULL DEFAULT '',
  archived INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (date('now'))
);
CREATE TABLE IF NOT EXISTS people (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  color TEXT NOT NULL DEFAULT '#8a94a6'
);
CREATE TABLE IF NOT EXISTS tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  start TEXT NOT NULL,
  end TEXT NOT NULL,
  done INTEGER NOT NULL DEFAULT 0,
  milestone INTEGER NOT NULL DEFAULT 0,
  person_id INTEGER REFERENCES people(id) ON DELETE SET NULL,
  notes TEXT NOT NULL DEFAULT '',
  sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS snapshots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (date('now')),
  data TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS deps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  pred_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
  succ_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
  UNIQUE(pred_id, succ_id)
);
`);

// migration: per-task progress percent (0-100); done tasks count as 100
try {
  db.exec('ALTER TABLE tasks ADD COLUMN progress INTEGER NOT NULL DEFAULT 0');
  db.exec('UPDATE tasks SET progress = 100 WHERE done = 1');
} catch (e) { /* column already exists */ }

// migration: one-level task hierarchy (phases with subtasks)
try {
  db.exec('ALTER TABLE tasks ADD COLUMN parent_id INTEGER');
} catch (e) { /* column already exists */ }

// migration: per-user logins, project allocation, audit trail
try { db.exec('ALTER TABLE people ADD COLUMN username TEXT'); } catch (e) {}
try { db.exec('ALTER TABLE people ADD COLUMN password_hash TEXT'); } catch (e) {}
try { db.exec("ALTER TABLE people ADD COLUMN role TEXT NOT NULL DEFAULT 'member'"); } catch (e) {}
db.exec(`
CREATE UNIQUE INDEX IF NOT EXISTS idx_people_username ON people(username) WHERE username IS NOT NULL;
CREATE TABLE IF NOT EXISTS sessions (
  token TEXT PRIMARY KEY,
  person_id INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS project_members (
  project_id INTEGER NOT NULL,
  person_id INTEGER NOT NULL,
  PRIMARY KEY (project_id, person_id)
);
CREATE TABLE IF NOT EXISTS pw_resets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  person_id INTEGER NOT NULL,
  code TEXT,
  requested_at TEXT NOT NULL DEFAULT (datetime('now')),
  expires INTEGER,
  used INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS audit (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  at TEXT NOT NULL DEFAULT (datetime('now')),
  person_id INTEGER,
  via TEXT NOT NULL DEFAULT 'web',
  action TEXT NOT NULL,
  entity TEXT NOT NULL,
  entity_id INTEGER,
  project_id INTEGER,
  detail TEXT NOT NULL DEFAULT ''
);
`);

module.exports = db;
