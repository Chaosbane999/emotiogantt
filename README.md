# EmotioGantt

A calm, neurodivergent-friendly Gantt tool. Just the essentials: projects, timelines,
critical paths, and a clear answer to "what should I be doing right now?"

## Features
- **Today panel** — one calm list of what needs attention now, critical-path items first
- **Gantt chart** — drag bars to reschedule, drag edges to resize, finish-to-start dependencies
- **Critical path** — computed automatically, highlighted in coral; slack shown per task
- **Project cards** — traffic-light health (On track / Needs a look / Slipping) with progress
- **People & workload** — a two-week heat strip showing who's carrying what
- **Gentle deadline cues** — "3 days left", never a wall of red alarms
- Light/dark theme, low-clutter design throughout

## Run locally
```bash
npm install
npm start          # http://localhost:3000
```

## Run with Docker
```bash
docker build -t clearpath .
docker run -d -p 3000:3000 -v clearpath-data:/data \
  -e APP_PASSCODE=your-secret clearpath
```

- `APP_PASSCODE` (optional) — if set, the app requires this passcode once per device;
  a cookie remembers it for a year. Repeated wrong guesses are slowed down.
- `ALLOWED_IPS` (optional) — comma-separated IPs that skip the passcode entirely
  (e.g. office or VPN egress IPs). Everyone else still gets the passcode page.
- `DATA_DIR` — where the SQLite database lives (defaults to `./data`, `/data` in Docker).
- `MCP_TOKEN` (optional) — enables the MCP endpoint at `/mcp` (Streamable HTTP) so AI
  assistants can read and write projects. Auth: `Authorization: Bearer <token>` header
  or `?token=<token>` query. Endpoint is disabled entirely when unset.
