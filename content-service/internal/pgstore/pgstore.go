// Package pgstore is the PostgreSQL implementation of api.Store.
package pgstore

import (
	"context"
	"database/sql"
	"fmt"
	"time"

	_ "github.com/lib/pq" // PostgreSQL driver

	"github.com/Stevens2606/classhub/content-service/internal/api"
)

// Store keeps materials and tryouts in the service's own database.
type Store struct {
	db *sql.DB
}

// Open prepares a connection pool. It does not connect yet; call WaitReady for that.
func Open(databaseURL string) (*Store, error) {
	db, err := sql.Open("postgres", databaseURL)
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(10)
	db.SetMaxIdleConns(5)
	db.SetConnMaxLifetime(30 * time.Minute)
	return &Store{db: db}, nil
}

// Close releases the connection pool.
func (s *Store) Close() error { return s.db.Close() }

// WaitReady retries until the database answers, so the service can start
// before PostgreSQL is fully up (for example under docker compose).
func (s *Store) WaitReady(ctx context.Context, attempts int, delay time.Duration) error {
	var err error
	for i := 0; i < attempts; i++ {
		if err = s.Ping(ctx); err == nil {
			return nil
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(delay):
		}
	}
	return fmt.Errorf("database not ready after %d attempts: %w", attempts, err)
}

func (s *Store) Ping(ctx context.Context) error {
	ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()
	return s.db.PingContext(ctx)
}

// Migrate creates the tables if they do not exist yet.
func (s *Store) Migrate(ctx context.Context) error {
	const ddl = `
CREATE TABLE IF NOT EXISTS materials (
	id         BIGSERIAL PRIMARY KEY,
	course_id  BIGINT      NOT NULL,
	title      TEXT        NOT NULL,
	type       TEXT        NOT NULL,
	url        TEXT        NOT NULL,
	position   INT         NOT NULL DEFAULT 0,
	created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_materials_course ON materials (course_id, position);

CREATE TABLE IF NOT EXISTS tryouts (
	id               BIGSERIAL PRIMARY KEY,
	course_id        BIGINT      NOT NULL,
	title            TEXT        NOT NULL,
	duration_minutes INT         NOT NULL,
	question_count   INT         NOT NULL,
	created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_tryouts_course ON tryouts (course_id);
`
	_, err := s.db.ExecContext(ctx, ddl)
	return err
}

// SeedIfEmpty inserts demo content for the two demo courses created by the main API's seeder.
func (s *Store) SeedIfEmpty(ctx context.Context) error {
	var n int
	if err := s.db.QueryRowContext(ctx, `SELECT count(*) FROM materials`).Scan(&n); err != nil {
		return err
	}
	if n > 0 {
		return nil
	}
	materials := []api.Material{
		{CourseID: 1, Title: "Algebra basics (PDF)", Type: "pdf", URL: "https://example.com/algebra-basics.pdf", Position: 1},
		{CourseID: 1, Title: "Linear equations walkthrough", Type: "video", URL: "https://example.com/linear-equations", Position: 2},
		{CourseID: 2, Title: "Newton's laws summary", Type: "article", URL: "https://example.com/newtons-laws", Position: 1},
	}
	for i := range materials {
		if err := s.CreateMaterial(ctx, &materials[i]); err != nil {
			return err
		}
	}
	tryouts := []api.Tryout{
		{CourseID: 1, Title: "Algebra tryout 1", DurationMinutes: 60, QuestionCount: 30},
		{CourseID: 2, Title: "Mechanics tryout 1", DurationMinutes: 90, QuestionCount: 40},
	}
	for i := range tryouts {
		if err := s.CreateTryout(ctx, &tryouts[i]); err != nil {
			return err
		}
	}
	return nil
}

func (s *Store) ListMaterials(ctx context.Context, courseID int64) ([]api.Material, error) {
	rows, err := s.db.QueryContext(ctx,
		`SELECT id, course_id, title, type, url, position FROM materials WHERE course_id = $1 ORDER BY position, id`, courseID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []api.Material
	for rows.Next() {
		var m api.Material
		if err := rows.Scan(&m.ID, &m.CourseID, &m.Title, &m.Type, &m.URL, &m.Position); err != nil {
			return nil, err
		}
		out = append(out, m)
	}
	return out, rows.Err()
}

func (s *Store) CreateMaterial(ctx context.Context, m *api.Material) error {
	return s.db.QueryRowContext(ctx,
		`INSERT INTO materials (course_id, title, type, url, position) VALUES ($1, $2, $3, $4, $5) RETURNING id`,
		m.CourseID, m.Title, m.Type, m.URL, m.Position).Scan(&m.ID)
}

func (s *Store) ListTryouts(ctx context.Context, courseID int64) ([]api.Tryout, error) {
	rows, err := s.db.QueryContext(ctx,
		`SELECT id, course_id, title, duration_minutes, question_count FROM tryouts WHERE course_id = $1 ORDER BY id`, courseID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []api.Tryout
	for rows.Next() {
		var t api.Tryout
		if err := rows.Scan(&t.ID, &t.CourseID, &t.Title, &t.DurationMinutes, &t.QuestionCount); err != nil {
			return nil, err
		}
		out = append(out, t)
	}
	return out, rows.Err()
}

func (s *Store) CreateTryout(ctx context.Context, t *api.Tryout) error {
	return s.db.QueryRowContext(ctx,
		`INSERT INTO tryouts (course_id, title, duration_minutes, question_count) VALUES ($1, $2, $3, $4) RETURNING id`,
		t.CourseID, t.Title, t.DurationMinutes, t.QuestionCount).Scan(&t.ID)
}
