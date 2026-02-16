# ADR 0001: Transport = STDIO

- Status: Accepted
- Date: 2026-02-16

## Context
The MCP server is intended for local/offline analysis and needs a long-running process without a web surface.

## Decision
Use a Symfony Console command as a long-lived STDIO loop receiving JSON messages from `STDIN` and writing JSON responses to `STDOUT`.

## Consequences
- Positive: Minimal deployment footprint, no HTTP server requirements, natural fit for local MCP clients.
- Positive: Lower attack surface versus exposing network endpoints.
- Negative: Process supervision and lifecycle management are delegated to caller/runtime.
- Negative: Horizontal scaling is not built-in; parallelism is client-managed.
