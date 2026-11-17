"""Execute the production aggregate expression against controlled events in SQLite.
This checks report semantics, not MariaDB migrations, grants, or row locking.
"""
import sqlite3
import subprocess

expression = subprocess.run(
    ['php', '-r', 'require "plugin/cargo-utm/src/VisitAnalytics.php"; echo CargoUTM\\VisitAnalytics::select();'],
    check=True, capture_output=True, text=True,
).stdout
connection = sqlite3.connect(':memory:')
connection.row_factory = sqlite3.Row
connection.execute('CREATE TABLE events (identity_kind TEXT, session_id TEXT, visitor_hash TEXT, occurred_at TEXT, placement_id INTEGER)')
connection.executemany('INSERT INTO events VALUES (?,?,?,?,?)', [
    ('cookie', 'visit-a', 'visitor-a', '2026-11-17 23:59:00', 1),
    ('cookie', 'visit-a', 'visitor-a', '2026-11-18 00:01:00', 2),
    ('cookie', 'visit-b', 'visitor-a', '2026-11-18 01:00:00', 1),
    ('cookie', 'visit-c', 'visitor-b', '2026-11-18 02:00:00', 1),
    ('fingerprint', 'estimate-visit', 'estimate-a', '2026-11-18 02:00:00', 1),
    ('fingerprint', 'estimate-visit', 'estimate-a', '2026-11-18 02:01:00', 1),
    ('unknown', 'unknown-visit', 'unknown-a', '2026-11-18 02:02:00', 1),
])
summary = dict(connection.execute(f'SELECT {expression} FROM events e').fetchone())
assert summary == dict(events=7, visits=3, visitors=2, estimated_visits=1, estimated_visitors=1, unknown_events=1)
per_day = connection.execute(f'SELECT {expression} FROM events e GROUP BY substr(occurred_at,1,10)').fetchall()
assert sum(row['visitors'] for row in per_day) == 3 and summary['visitors'] == 2
by_placement = connection.execute(f'SELECT {expression} FROM events e GROUP BY placement_id').fetchall()
assert sum(row['visits'] for row in by_placement) == 4 and summary['visits'] == 3
empty = dict(connection.execute(f'SELECT {expression} FROM events e WHERE placement_id=999').fetchone())
assert empty['visits'] == 0 and empty['visitors'] == 0
print('PASS: production report SQL deduplicates period visitors/sessions, separates fingerprint estimates and handles empty results')
