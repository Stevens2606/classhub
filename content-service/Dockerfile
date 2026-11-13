FROM golang:1.24-alpine AS build
WORKDIR /src
COPY . .
# Resolves dependencies (lib/pq) and writes go.sum inside the build container.
RUN go mod tidy && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/server ./cmd/server

FROM alpine:3.20
RUN adduser -D -u 10001 app
USER app
COPY --from=build /out/server /server
EXPOSE 8080
ENTRYPOINT ["/server"]
