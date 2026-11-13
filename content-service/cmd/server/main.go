// Command server runs the ClassHub content microservice (materials and tryouts).
package main

import (
	"context"
	"errors"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/Stevens2606/classhub/content-service/internal/api"
	"github.com/Stevens2606/classhub/content-service/internal/pgstore"
)

func main() {
	databaseURL := os.Getenv("DATABASE_URL")
	internalKey := os.Getenv("INTERNAL_API_KEY")
	if databaseURL == "" || internalKey == "" {
		log.Fatal("DATABASE_URL and INTERNAL_API_KEY must be set")
	}
	port := os.Getenv("PORT")
	if port == "" {
		port = "8080"
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	store, err := pgstore.Open(databaseURL)
	if err != nil {
		log.Fatalf("open database: %v", err)
	}
	defer store.Close()

	if err := store.WaitReady(ctx, 30, 2*time.Second); err != nil {
		log.Fatal(err)
	}
	if err := store.Migrate(ctx); err != nil {
		log.Fatalf("migrate: %v", err)
	}
	if err := store.SeedIfEmpty(ctx); err != nil {
		log.Fatalf("seed: %v", err)
	}

	srv := &http.Server{
		Addr:              ":" + port,
		Handler:           api.New(store, internalKey),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      10 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	go func() {
		log.Printf("content-service listening on :%s", port)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Fatalf("server: %v", err)
		}
	}()

	<-ctx.Done()
	log.Println("shutting down...")
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := srv.Shutdown(shutdownCtx); err != nil {
		log.Printf("graceful shutdown failed: %v", err)
	}
}
