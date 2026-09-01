package httpapi

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strings"

	"github.com/el-moatasem/product-scraper-trial/proxy-service/internal/proxy"
)

type Handler struct {
	pool  *proxy.Pool
	token string
	mux   *http.ServeMux
}

func New(pool *proxy.Pool, token string) *Handler {
	handler := &Handler{pool: pool, token: strings.TrimSpace(token), mux: http.NewServeMux()}
	handler.mux.HandleFunc("GET /health", handler.health)
	handler.mux.HandleFunc("GET /v1/proxies", handler.authorize(handler.list))
	handler.mux.HandleFunc("POST /v1/proxies", handler.authorize(handler.add))
	handler.mux.HandleFunc("GET /v1/proxies/next", handler.authorize(handler.next))
	handler.mux.HandleFunc("POST /v1/proxies/{id}/report", handler.authorize(handler.report))
	handler.mux.HandleFunc("DELETE /v1/proxies/{id}", handler.authorize(handler.remove))
	return handler
}

func (h *Handler) ServeHTTP(response http.ResponseWriter, request *http.Request) {
	h.mux.ServeHTTP(response, request)
}

func (h *Handler) health(response http.ResponseWriter, request *http.Request) {
	writeJSON(response, http.StatusOK, map[string]string{"status": "ok"})
}

func (h *Handler) list(response http.ResponseWriter, request *http.Request) {
	writeJSON(response, http.StatusOK, map[string]any{"data": h.pool.Snapshots()})
}

func (h *Handler) next(response http.ResponseWriter, request *http.Request) {
	writeJSON(response, http.StatusOK, h.pool.Next())
}

func (h *Handler) add(response http.ResponseWriter, request *http.Request) {
	var payload struct {
		URL string `json:"url"`
	}
	if err := decodeJSON(request, &payload); err != nil {
		writeError(response, http.StatusBadRequest, err.Error())
		return
	}

	lease, created, err := h.pool.Add(payload.URL)
	if err != nil {
		writeError(response, http.StatusUnprocessableEntity, err.Error())
		return
	}

	status := http.StatusOK
	if created {
		status = http.StatusCreated
	}
	lease.URL = redactLeaseURL(lease.URL)
	writeJSON(response, status, lease)
}

func (h *Handler) report(response http.ResponseWriter, request *http.Request) {
	var payload struct {
		Success *bool `json:"success"`
	}
	if err := decodeJSON(request, &payload); err != nil {
		writeError(response, http.StatusBadRequest, err.Error())
		return
	}
	if payload.Success == nil {
		writeError(response, http.StatusBadRequest, "success is required")
		return
	}

	if err := h.pool.Report(request.PathValue("id"), *payload.Success); err != nil {
		writeError(response, http.StatusNotFound, err.Error())
		return
	}

	response.WriteHeader(http.StatusNoContent)
}

func (h *Handler) remove(response http.ResponseWriter, request *http.Request) {
	if err := h.pool.Remove(request.PathValue("id")); err != nil {
		writeError(response, http.StatusNotFound, err.Error())
		return
	}
	response.WriteHeader(http.StatusNoContent)
}

func (h *Handler) authorize(next http.HandlerFunc) http.HandlerFunc {
	return func(response http.ResponseWriter, request *http.Request) {
		if h.token != "" && request.Header.Get("Authorization") != "Bearer "+h.token {
			writeError(response, http.StatusUnauthorized, "unauthorized")
			return
		}
		next(response, request)
	}
}

func decodeJSON(request *http.Request, destination any) error {
	defer request.Body.Close()
	decoder := json.NewDecoder(http.MaxBytesReader(nil, request.Body, 16*1024))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(destination); err != nil {
		return errors.New("request body must contain valid JSON")
	}
	if err := decoder.Decode(&struct{}{}); err != io.EOF {
		return errors.New("request body must contain a single JSON object")
	}
	return nil
}

func writeError(response http.ResponseWriter, status int, message string) {
	writeJSON(response, status, map[string]string{"message": message})
}

func writeJSON(response http.ResponseWriter, status int, payload any) {
	response.Header().Set("Content-Type", "application/json")
	response.WriteHeader(status)
	_ = json.NewEncoder(response).Encode(payload)
}

func redactLeaseURL(value string) string {
	if index := strings.LastIndex(value, "@"); index >= 0 {
		if scheme := strings.Index(value, "://"); scheme >= 0 {
			return value[:scheme+3] + value[index+1:]
		}
	}
	return value
}
