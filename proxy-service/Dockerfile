FROM golang:1.27-alpine AS build

WORKDIR /src
COPY go.mod ./
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /proxy-manager ./cmd/server

FROM gcr.io/distroless/static-debian13:nonroot

COPY --from=build /proxy-manager /proxy-manager
EXPOSE 8081
ENTRYPOINT ["/proxy-manager"]
