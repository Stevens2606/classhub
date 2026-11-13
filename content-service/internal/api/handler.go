// Package api contains the HTTP layer of the content service.
package api

import (
	"crypto/subtle"
	"encoding/json"
	"log"
	"net/http"
	"strconv"
	"strings"
)

var validMaterialTypes = map[string]bool{"pdf": true, "video": true, "article": true}

// Handler wires routes to the store.
type Handler struct {
	store Store
	key   string
}

// New builds the router. Every route except /healthz requires the X-Internal-Key header,
// because this service is only meant to be called by the main API, never by end users.
func New(store Store, internalKey string) http.Handler {
	h := &Handler{store: store, key: internalKey}
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", h.health)
	mux.Handle("GET /materials", h.auth(http.HandlerFunc(h.listMaterials)))
	mux.Handle("POST /materials", h.auth(http.HandlerFunc(h.createMaterial)))
	mux.Handle("GET /tryouts", h.auth(http.HandlerFunc(h.listTryouts)))
	mux.Handle("POST /tryouts", h.auth(http.HandlerFunc(h.createTryout)))
	return logRequests(mux)
}

func (h *Handler) auth(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got := r.Header.Get("X-Internal-Key")
		if h.key == "" || subtle.ConstantTimeCompare([]byte(got), []byte(h.key)) != 1 {
			writeError(w, http.StatusUnauthorized, "unauthorized")
			return
		}
		next.ServeHTTP(w, r)
	})
}

func (h *Handler) health(w http.ResponseWriter, r *http.Request) {
	if err := h.store.Ping(r.Context()); err != nil {
		writeError(w, http.StatusServiceUnavailable, "database unreachable")
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (h *Handler) listMaterials(w http.ResponseWriter, r *http.Request) {
	courseID, ok := courseIDFromQuery(w, r)
	if !ok {
		return
	}
	items, err := h.store.ListMaterials(r.Context(), courseID)
	if err != nil {
		serverError(w, err)
		return
	}
	if items == nil {
		items = []Material{}
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": items})
}

func (h *Handler) createMaterial(w http.ResponseWriter, r *http.Request) {
	var in Material
	if err := decodeJSON(w, r, &in); err != nil {
		writeError(w, http.StatusBadRequest, "invalid JSON body")
		return
	}
	in.Title = strings.TrimSpace(in.Title)
	switch {
	case in.CourseID <= 0:
		writeError(w, http.StatusUnprocessableEntity, "course_id must be a positive number")
	case in.Title == "" || len(in.Title) > 200:
		writeError(w, http.StatusUnprocessableEntity, "title is required (max 200 characters)")
	case !validMaterialTypes[in.Type]:
		writeError(w, http.StatusUnprocessableEntity, "type must be one of: pdf, video, article")
	case !strings.HasPrefix(in.URL, "http://") && !strings.HasPrefix(in.URL, "https://"):
		writeError(w, http.StatusUnprocessableEntity, "url must start with http:// or https://")
	default:
		if err := h.store.CreateMaterial(r.Context(), &in); err != nil {
			serverError(w, err)
			return
		}
		writeJSON(w, http.StatusCreated, map[string]any{"data": in})
	}
}

func (h *Handler) listTryouts(w http.ResponseWriter, r *http.Request) {
	courseID, ok := courseIDFromQuery(w, r)
	if !ok {
		return
	}
	items, err := h.store.ListTryouts(r.Context(), courseID)
	if err != nil {
		serverError(w, err)
		return
	}
	if items == nil {
		items = []Tryout{}
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": items})
}

func (h *Handler) createTryout(w http.ResponseWriter, r *http.Request) {
	var in Tryout
	if err := decodeJSON(w, r, &in); err != nil {
		writeError(w, http.StatusBadRequest, "invalid JSON body")
		return
	}
	in.Title = strings.TrimSpace(in.Title)
	switch {
	case in.CourseID <= 0:
		writeError(w, http.StatusUnprocessableEntity, "course_id must be a positive number")
	case in.Title == "" || len(in.Title) > 200:
		writeError(w, http.StatusUnprocessableEntity, "title is required (max 200 characters)")
	case in.DurationMinutes < 1 || in.DurationMinutes > 300:
		writeError(w, http.StatusUnprocessableEntity, "duration_minutes must be between 1 and 300")
	case in.QuestionCount < 1 || in.QuestionCount > 200:
		writeError(w, http.StatusUnprocessableEntity, "question_count must be between 1 and 200")
	default:
		if err := h.store.CreateTryout(r.Context(), &in); err != nil {
			serverError(w, err)
			return
		}
		writeJSON(w, http.StatusCreated, map[string]any{"data": in})
	}
}

func courseIDFromQuery(w http.ResponseWriter, r *http.Request) (int64, bool) {
	id, err := strconv.ParseInt(r.URL.Query().Get("course_id"), 10, 64)
	if err != nil || id <= 0 {
		writeError(w, http.StatusBadRequest, "course_id query parameter is required")
		return 0, false
	}
	return id, true
}

func decodeJSON(w http.ResponseWriter, r *http.Request, dst any) error {
	r.Body = http.MaxBytesReader(w, r.Body, 1<<20)
	dec := json.NewDecoder(r.Body)
	dec.DisallowUnknownFields()
	return dec.Decode(dst)
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	if err := json.NewEncoder(w).Encode(v); err != nil {
		log.Printf("write response: %v", err)
	}
}

func writeError(w http.ResponseWriter, status int, msg string) {
	writeJSON(w, status, map[string]string{"error": msg})
}

func serverError(w http.ResponseWriter, err error) {
	log.Printf("internal error: %v", err)
	writeError(w, http.StatusInternalServerError, "internal server error")
}

func logRequests(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		log.Printf("%s %s", r.Method, r.URL.Path)
		next.ServeHTTP(w, r)
	})
}
