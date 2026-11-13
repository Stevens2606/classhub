package api

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

type fakeStore struct {
	materials []Material
	tryouts   []Tryout
	pingErr   error
}

func (f *fakeStore) Ping(context.Context) error { return f.pingErr }

func (f *fakeStore) ListMaterials(_ context.Context, courseID int64) ([]Material, error) {
	var out []Material
	for _, m := range f.materials {
		if m.CourseID == courseID {
			out = append(out, m)
		}
	}
	return out, nil
}

func (f *fakeStore) CreateMaterial(_ context.Context, m *Material) error {
	m.ID = int64(len(f.materials) + 1)
	f.materials = append(f.materials, *m)
	return nil
}

func (f *fakeStore) ListTryouts(_ context.Context, courseID int64) ([]Tryout, error) {
	var out []Tryout
	for _, t := range f.tryouts {
		if t.CourseID == courseID {
			out = append(out, t)
		}
	}
	return out, nil
}

func (f *fakeStore) CreateTryout(_ context.Context, t *Tryout) error {
	t.ID = int64(len(f.tryouts) + 1)
	f.tryouts = append(f.tryouts, *t)
	return nil
}

const testKey = "secret-key"

func do(h http.Handler, method, path, body string, withKey bool) *httptest.ResponseRecorder {
	req := httptest.NewRequest(method, path, strings.NewReader(body))
	if withKey {
		req.Header.Set("X-Internal-Key", testKey)
	}
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	return rec
}

func TestHealth(t *testing.T) {
	ok := New(&fakeStore{}, testKey)
	if rec := do(ok, "GET", "/healthz", "", false); rec.Code != http.StatusOK {
		t.Fatalf("healthz: got %d, want 200", rec.Code)
	}
	down := New(&fakeStore{pingErr: errors.New("db down")}, testKey)
	if rec := do(down, "GET", "/healthz", "", false); rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("healthz with db down: got %d, want 503", rec.Code)
	}
}

func TestRequiresInternalKey(t *testing.T) {
	h := New(&fakeStore{}, testKey)
	if rec := do(h, "GET", "/materials?course_id=1", "", false); rec.Code != http.StatusUnauthorized {
		t.Fatalf("without key: got %d, want 401", rec.Code)
	}
	// A server started without a key must reject everything instead of allowing everyone.
	open := New(&fakeStore{}, "")
	if rec := do(open, "GET", "/materials?course_id=1", "", true); rec.Code != http.StatusUnauthorized {
		t.Fatalf("empty server key: got %d, want 401", rec.Code)
	}
}

func TestListMaterialsValidation(t *testing.T) {
	h := New(&fakeStore{}, testKey)
	for _, path := range []string{"/materials", "/materials?course_id=abc", "/materials?course_id=0"} {
		if rec := do(h, "GET", path, "", true); rec.Code != http.StatusBadRequest {
			t.Fatalf("%s: got %d, want 400", path, rec.Code)
		}
	}
}

func TestListMaterialsEmptyIsArray(t *testing.T) {
	h := New(&fakeStore{}, testKey)
	rec := do(h, "GET", "/materials?course_id=9", "", true)
	if rec.Code != http.StatusOK {
		t.Fatalf("got %d, want 200", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), `"data":[]`) {
		t.Fatalf("expected empty array, got %s", rec.Body.String())
	}
}

func TestCreateAndListMaterial(t *testing.T) {
	h := New(&fakeStore{}, testKey)
	body := `{"course_id":1,"title":"Intro","type":"pdf","url":"https://example.com/a.pdf","position":1}`
	if rec := do(h, "POST", "/materials", body, true); rec.Code != http.StatusCreated {
		t.Fatalf("create: got %d (%s), want 201", rec.Code, rec.Body.String())
	}
	rec := do(h, "GET", "/materials?course_id=1", "", true)
	if rec.Code != http.StatusOK || !strings.Contains(rec.Body.String(), "Intro") {
		t.Fatalf("list: got %d %s", rec.Code, rec.Body.String())
	}
}

func TestCreateMaterialRejectsBadInput(t *testing.T) {
	h := New(&fakeStore{}, testKey)
	cases := map[string]string{
		"missing title": `{"course_id":1,"title":" ","type":"pdf","url":"https://x.io"}`,
		"bad type":      `{"course_id":1,"title":"A","type":"exe","url":"https://x.io"}`,
		"bad url":       `{"course_id":1,"title":"A","type":"pdf","url":"ftp://x.io"}`,
		"bad course":    `{"course_id":0,"title":"A","type":"pdf","url":"https://x.io"}`,
	}
	for name, body := range cases {
		if rec := do(h, "POST", "/materials", body, true); rec.Code != http.StatusUnprocessableEntity {
			t.Errorf("%s: got %d, want 422", name, rec.Code)
		}
	}
	if rec := do(h, "POST", "/materials", `{"unknown":1}`, true); rec.Code != http.StatusBadRequest {
		t.Errorf("unknown field: got %d, want 400", rec.Code)
	}
}

func TestCreateAndListTryout(t *testing.T) {
	h := New(&fakeStore{}, testKey)
	body := `{"course_id":2,"title":"Midterm","duration_minutes":90,"question_count":40}`
	if rec := do(h, "POST", "/tryouts", body, true); rec.Code != http.StatusCreated {
		t.Fatalf("create: got %d (%s), want 201", rec.Code, rec.Body.String())
	}
	rec := do(h, "GET", "/tryouts?course_id=2", "", true)
	if rec.Code != http.StatusOK || !strings.Contains(rec.Body.String(), "Midterm") {
		t.Fatalf("list: got %d %s", rec.Code, rec.Body.String())
	}
	bad := `{"course_id":2,"title":"X","duration_minutes":0,"question_count":10}`
	if rec := do(h, "POST", "/tryouts", bad, true); rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("bad duration: got %d, want 422", rec.Code)
	}
}
