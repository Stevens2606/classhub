package api

import "context"

// Store is the persistence contract used by the HTTP handlers.
// The PostgreSQL implementation lives in internal/pgstore; tests use an in-memory fake.
type Store interface {
	Ping(ctx context.Context) error
	ListMaterials(ctx context.Context, courseID int64) ([]Material, error)
	CreateMaterial(ctx context.Context, m *Material) error
	ListTryouts(ctx context.Context, courseID int64) ([]Tryout, error)
	CreateTryout(ctx context.Context, t *Tryout) error
}
