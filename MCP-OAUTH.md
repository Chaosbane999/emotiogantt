# Adding OAuth 2.1 to an MCP Server (so ChatGPT & co. can connect)

This is the complete, dependency-free OAuth layer used by EmotioGantt
(`gantt.emotioflow.com/mcp`). Some MCP clients — ChatGPT's connectors in
particular — refuse plain bearer-token MCP servers and demand a full OAuth
handshake. This document contains everything a developer needs to bolt that
onto any Express + SQLite MCP server. No OAuth library required; it's ~180
lines of plain Node.

## What the client actually does

1. Probes `/.well-known/oauth-authorization-server` (discovery metadata).
   If this 404s, ChatGPT shows "does not implement OAuth" and gives up.
2. Registers itself via `POST /oauth/register` (RFC 7591 dynamic client
   registration) and receives a `client_id`.
3. Opens `GET /oauth/authorize` in a browser — your consent page. The user
   approves (here: by entering the app passcode), and you 302 back to the
   client's `redirect_uri` with a single-use authorization `code`.
4. Exchanges the code at `POST /oauth/token` (with PKCE verification) for an
   `access_token` + `refresh_token`.
5. Calls your MCP endpoint with `Authorization: Bearer <access_token>`.

Your MCP endpoint's 401 response should also advertise where the metadata
lives via `WWW-Authenticate` (RFC 9728) — some clients discover through that
instead of probing.

## The invariants that make it safe

- **PKCE (S256) enforced** when the client sends a `code_challenge` — the
  token exchange fails unless `SHA256(code_verifier)` matches.
- **Authorization codes are single-use and expire in 10 minutes.**
- **redirect_uri is validated** against what the client registered (https
  only, localhost excepted), both at authorize and token time.
- **Consent is a real gate** — the form requires the app passcode, so a link
  to your authorize URL can't silently mint tokens.
- Tokens are random 256-bit values stored server-side; revoke by deleting rows
  from `oauth_tokens`.

## 1. The OAuth module (complete)

`oauth.js` — adjust `PUBLIC_URL`, the `PASSCODE` consent check, and the
styling of the consent page to taste:

```js
// Minimal OAuth 2.1 provider so MCP clients that require OAuth (ChatGPT etc.)
// can connect. Dynamic client registration, PKCE (S256), authorization codes,
// bearer access tokens. The consent step is the app passcode.
const crypto = require('crypto');
const db = require('./db');

const PASSCODE = process.env.APP_PASSCODE || '';
const PUBLIC_URL = (process.env.PUBLIC_URL || 'https://gantt.emotioflow.com').replace(/\/$/, '');

db.exec(`
CREATE TABLE IF NOT EXISTS oauth_clients (
  client_id TEXT PRIMARY KEY,
  name TEXT NOT NULL DEFAULT '',
  redirect_uris TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS oauth_codes (
  code TEXT PRIMARY KEY,
  client_id TEXT NOT NULL,
  redirect_uri TEXT NOT NULL,
  challenge TEXT,
  expires INTEGER NOT NULL,
  used INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS oauth_tokens (
  token TEXT PRIMARY KEY,
  kind TEXT NOT NULL,
  client_id TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
`);

const rand = () => crypto.randomBytes(32).toString('hex');
const s256 = (v) => crypto.createHash('sha256').update(v).digest('base64')
  .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function asMetadata(req, res) {
  res.json({
    issuer: PUBLIC_URL,
    authorization_endpoint: `${PUBLIC_URL}/oauth/authorize`,
    token_endpoint: `${PUBLIC_URL}/oauth/token`,
    registration_endpoint: `${PUBLIC_URL}/oauth/register`,
    response_types_supported: ['code'],
    grant_types_supported: ['authorization_code', 'refresh_token'],
    code_challenge_methods_supported: ['S256'],
    token_endpoint_auth_methods_supported: ['none', 'client_secret_post'],
    scopes_supported: ['mcp'],
  });
}

function prMetadata(req, res) {
  res.json({
    resource: `${PUBLIC_URL}/mcp`,
    authorization_servers: [PUBLIC_URL],
    bearer_methods_supported: ['header'],
    scopes_supported: ['mcp'],
  });
}

function register(req, res) {
  const b = req.body || {};
  const uris = Array.isArray(b.redirect_uris) ? b.redirect_uris.filter(u =>
    /^https:\/\//.test(u) || /^http:\/\/(localhost|127\.0\.0\.1)/.test(u)) : [];
  if (!uris.length) {
    return res.status(400).json({ error: 'invalid_client_metadata',
      error_description: 'redirect_uris (https) required' });
  }
  const id = rand();
  db.prepare('INSERT INTO oauth_clients (client_id, name, redirect_uris) VALUES (?,?,?)')
    .run(id, String(b.client_name || '').slice(0, 100), JSON.stringify(uris));
  res.status(201).json({
    client_id: id,
    client_name: b.client_name || '',
    redirect_uris: uris,
    token_endpoint_auth_method: 'none',
    grant_types: ['authorization_code', 'refresh_token'],
    response_types: ['code'],
  });
}

function clientAndUri(clientId, redirectUri) {
  const c = db.prepare('SELECT * FROM oauth_clients WHERE client_id=?').get(clientId || '');
  if (!c) return null;
  if (!JSON.parse(c.redirect_uris).includes(redirectUri || '')) return null;
  return c;
}

function authorizeForm(req, res) {
  const q = req.query;
  const c = clientAndUri(q.client_id, q.redirect_uri);
  if (!c || q.response_type !== 'code') {
    return res.status(400).send('<p>Invalid authorization request.</p>');
  }
  const hidden = ['client_id', 'redirect_uri', 'state', 'code_challenge',
    'code_challenge_method', 'scope', 'response_type']
    .map(k => `<input type="hidden" name="${k}" value="${esc(q[k] || '')}">`).join('');
  res.send(`<!DOCTYPE html><html><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EmotioGantt — allow access</title></head>
    <body style="font-family:-apple-system,sans-serif;background:#f6f6f3;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">
    <form method="POST" action="/oauth/authorize" style="background:#fff;border:1px solid #e3e3dd;border-radius:16px;padding:40px;width:340px;text-align:center;box-shadow:0 4px 14px rgba(40,44,52,.06)">
      <div style="font-weight:700;font-size:24px">Emotio<span style="color:#24bbb6">Gantt</span></div>
      <p style="color:#6d7480;font-size:14px"><strong>${esc(c.name || 'An application')}</strong>
        wants to read and update your projects.</p>
      ${hidden}
      ${PASSCODE ? '<input type="password" name="passcode" placeholder="Passcode" autofocus style="width:100%;padding:9px 12px;border:1px solid #e3e3dd;border-radius:9px;margin:12px 0;text-align:center;box-sizing:border-box">' : ''}
      <button type="submit" style="width:100%;background:#24bbb6;color:#fff;border:none;border-radius:9px;padding:10px;font-size:14px;font-weight:600;cursor:pointer">Allow access</button>
    </form></body></html>`);
}

function authorizeSubmit(req, res) {
  const b = req.body || {};
  const c = clientAndUri(b.client_id, b.redirect_uri);
  if (!c) return res.status(400).send('<p>Invalid authorization request.</p>');
  if (PASSCODE && (b.passcode || '') !== PASSCODE) {
    return res.status(401).send('<p>Wrong passcode. <a href="javascript:history.back()">Try again</a>.</p>');
  }
  const code = rand();
  db.prepare('INSERT INTO oauth_codes (code, client_id, redirect_uri, challenge, expires) VALUES (?,?,?,?,?)')
    .run(code, b.client_id, b.redirect_uri, b.code_challenge || null,
      Date.now() + 10 * 60 * 1000);
  const url = new URL(b.redirect_uri);
  url.searchParams.set('code', code);
  if (b.state) url.searchParams.set('state', b.state);
  res.redirect(url.toString());
}

function token(req, res) {
  const b = req.body || {};
  const issue = (clientId) => {
    const access = rand(), refresh = rand();
    const ins = db.prepare('INSERT INTO oauth_tokens (token, kind, client_id) VALUES (?,?,?)');
    ins.run(access, 'access', clientId);
    ins.run(refresh, 'refresh', clientId);
    res.json({ access_token: access, token_type: 'bearer',
      expires_in: 31536000, refresh_token: refresh, scope: 'mcp' });
  };
  if (b.grant_type === 'authorization_code') {
    const row = db.prepare('SELECT * FROM oauth_codes WHERE code=?').get(b.code || '');
    if (!row || row.used || row.expires < Date.now() ||
        row.client_id !== (b.client_id || '') ||
        (b.redirect_uri && row.redirect_uri !== b.redirect_uri)) {
      return res.status(400).json({ error: 'invalid_grant' });
    }
    if (row.challenge && s256(b.code_verifier || '') !== row.challenge) {
      return res.status(400).json({ error: 'invalid_grant',
        error_description: 'PKCE verification failed' });
    }
    db.prepare('UPDATE oauth_codes SET used=1 WHERE code=?').run(row.code);
    return issue(row.client_id);
  }
  if (b.grant_type === 'refresh_token') {
    const row = db.prepare("SELECT * FROM oauth_tokens WHERE token=? AND kind='refresh'")
      .get(b.refresh_token || '');
    if (!row) return res.status(400).json({ error: 'invalid_grant' });
    return issue(row.client_id);
  }
  res.status(400).json({ error: 'unsupported_grant_type' });
}

function isValidToken(token) {
  if (!token) return false;
  return !!db.prepare("SELECT 1 FROM oauth_tokens WHERE token=? AND kind='access'").get(token);
}

module.exports = { asMetadata, prMetadata, register, authorizeForm, authorizeSubmit, token, isValidToken, PUBLIC_URL };
```

## 2. Mount it (Express)

Routes must be reachable WITHOUT any auth gate (they ARE the auth):

```js
const oauth = require('./oauth');
app.use(express.urlencoded({ extended: false })); // /oauth/token is form-encoded

app.get('/.well-known/oauth-authorization-server', oauth.asMetadata);
app.get('/.well-known/oauth-authorization-server/mcp', oauth.asMetadata); // path-suffixed variant
app.get('/.well-known/openid-configuration', oauth.asMetadata);           // some clients probe this
app.get('/.well-known/oauth-protected-resource', oauth.prMetadata);
app.get('/.well-known/oauth-protected-resource/mcp', oauth.prMetadata);
app.post('/oauth/register', oauth.register);
app.get('/oauth/authorize', oauth.authorizeForm);
app.post('/oauth/authorize', oauth.authorizeSubmit);
app.post('/oauth/token', oauth.token);
```

## 3. Accept the issued tokens on the MCP endpoint

```js
const oauth = require('./oauth');

function mcpHandler(req, res) {
  const auth = req.headers.authorization || '';
  const bearer = auth.startsWith('Bearer ') ? auth.slice(7) : '';
  const authed = bearer && (bearer === STATIC_TOKEN || oauth.isValidToken(bearer));
  if (!authed) {
    // RFC 9728: tell the client where to find the auth server
    res.set('WWW-Authenticate',
      `Bearer resource_metadata="${oauth.PUBLIC_URL}/.well-known/oauth-protected-resource"`);
    return res.status(401).json({ error: 'unauthorized' });
  }
  // ... handle the JSON-RPC MCP request ...
}
```

A static long-lived token can coexist with OAuth tokens (EmotioGantt keeps one
for Claude), but it's optional.

## Connecting from ChatGPT

Server URL: `https://your-host/mcp` · Authentication: **OAuth**. ChatGPT
discovers, registers, and opens the consent page; the user enters the passcode
and clicks Allow. Note: ChatGPT's deep-research mode additionally requires
tools named `search` and `fetch`; its general agent/tool modes accept any tools.

## Gotchas learned the hard way

- ChatGPT strips query strings when probing, so `?token=` workarounds don't
  satisfy it — only real OAuth does.
- `POST /oauth/token` arrives as `application/x-www-form-urlencoded`, not JSON.
- Return **201** from `/oauth/register` (not 200).
- Serve the metadata at BOTH `/.well-known/...` and `/.well-known/.../mcp`
  (clients using the 2025-06-18 MCP spec append the resource path).
- `expires_in` is required in the token response; pick something long and also
  implement `refresh_token` — ChatGPT uses it.
- Test the whole handshake with curl before pointing a client at it: register,
  authorize (follow the 302), exchange with PKCE, then call the MCP endpoint.
