# Team Auth – OIDC login server (Symfony + MySQL)

Central sign-in for club applications (match video review, pitch booking, video library, …; listed in the club portal).
It speaks standard **OpenID Connect**, so clients use ordinary libraries
(`oidc-client-ts`, Symfony `access_token` + `oidc`) and the server can be swapped for Keycloak at any time.

## Features

| Endpoint | Purpose |
|---|---|
| `GET /.well-known/openid-configuration` | discovery |
| `GET /.well-known/jwks.json` | public key (RS256, `kid`) |
| `GET /oauth/authorize` | Authorization Code + PKCE |
| `POST /oauth/token` | tokens (`authorization_code`, `refresh_token`, `client_credentials`) + `id_token` |
| `GET/POST /userinfo` | claims according to granted scopes |
| `GET /logout` | RP-initiated logout (`post_logout_redirect_uri` only to an origin from the client's redirect URIs) |

Claims (access token, id_token, userinfo):

```json
{
  "sub": "0192f0e4-…",
  "name": "Jan Novák",
  "email": "jan@…",
  "roles": ["coach@u11", "viewer"],
  "global_roles": [],
  "teams": [{ "slug": "u11", "name": "Mladší přípravka", "membership": "coach" }]
}
```

`roles` contains only the roles for the application (client_id) the token was issued to.
A role without `@team` applies to all teams.

## Installation

```bash
composer create-project symfony/skeleton:"7.4.*" team-auth && cd team-auth
composer require symfony/orm-pack symfony/security-bundle symfony/twig-bundle \
  symfony/rate-limiter symfony/uid symfony/validator symfony/translation \
  league/oauth2-server-bundle nyholm/psr7 symfony/psr-http-message-bridge nelmio/cors-bundle
```

`league/oauth2-server-bundle` v2 requires the PHP **sodium** extension (`extension=sodium` in `php.ini`).

Environment (`.env.local`):

```dotenv
APP_SECRET=                      # php -r "echo bin2hex(random_bytes(32));"
TOTP_ENCRYPTION_KEY=             # php -r "echo base64_encode(random_bytes(32));" (two-factor secrets)
DATABASE_URL="mysql://user:pass@127.0.0.1:3306/team_auth?serverVersion=8.4&charset=utf8mb4"
OIDC_ISSUER=https://auth.example.com   # public URL, no trailing slash
OAUTH_PRIVATE_KEY=%kernel.project_dir%/config/jwt/private.pem
OAUTH_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
OAUTH_PASSPHRASE=                # optional; set it BEFORE generating keys
OAUTH_ENCRYPTION_KEY=            # php -r "echo base64_encode(random_bytes(32));"
CORS_ALLOW_ORIGIN='^https://(mvr|videoteka)\.example\.com$'
```

Keys and database:

```bash
mkdir config/jwt
php bin/console league:oauth2-server:generate-keypair
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

The migration is generated from entities and contains both our tables (`users`, `team_categories`,
`user_teams`, `app_roles`, `user_roles`, `audit_log`) and the bundle's tables (`oauth2_client`, …).

### Web server

The document root must point to `public/`. For Apache, `public/.htaccess` rewrites everything to
`index.php` and passes the `Authorization` header through (required for Bearer tokens on `/userinfo`).
It needs `mod_rewrite` and `AllowOverride All`.

## First data

Either via the admin UI (see below) or from the CLI:

```bash
php bin/console club:user:create admin@example.com "Admin Name" --superadmin
php bin/console club:team:create u11 "Mladší přípravka"
php bin/console club:team:add admin@example.com u11 coach

# public SPA client (no secret, PKCE enforced)
php bin/console league:oauth2-server:create-client "MVR web" mvr-web --public \
  --redirect-uri=https://mvr.example.com/auth/callback \
  --grant-type=authorization_code --grant-type=refresh_token \
  --scope=openid --scope=profile --scope=email --scope=roles --scope=teams --scope=offline_access

php bin/console club:role:grant admin@example.com mvr-web coach --team=u11
php bin/console club:role:grant admin@example.com mvr-web coach --team=u11 --revoke
```

## Admin UI (`/admin`)

Only users with the global `superadmin` role have access.

- **Users** – create, edit, deactivate, set password, teams, roles, delete
- **Teams** – create, rename, delete
- **Applications** – OAuth clients (public SPA / confidential backend), redirect URIs, grants, scopes,
  the application's role catalogue, secret regeneration (shown only once), delete; global roles
- **Audit** – logins, application sign-ins and every change made in the admin

### Login page branding

Each application can have its own logo (PNG, JPEG, WebP or SVG, max. 512 kB), accent colour and
page background (*Aplikace → detail → Vzhled přihlášení*, with a live preview). When a user arrives
from that application's `/oauth/authorize`, the login page shows its logo and name and uses its
colours; the text colour on buttons is picked automatically for contrast. Without a known client
(e.g. opening `/login` directly) the default look is used.

Logos are stored in `var/branding/` (must be writable; include it in backups) and served from
`/branding/{client_id}/logo` with a content-hash URL, so browsers cache them for a year.
The file type is detected from the content, and SVGs are served with a sandboxing CSP.

Business logic lives in `App\Admin\UserAdmin`, `TeamAdmin`, `ClientAdmin` and `App\Branding\BrandingService`
(testable without HTTP; `Branding\Color` and `Branding\LogoType` are pure);
controllers only pass input in and render results. All changes go through POST with a CSRF token.

## Two-factor authentication (optional TOTP)

Every user can turn on two-factor authentication on their account page (`/` → *Dvoufázové ověření*):
scan a QR code with an authenticator app (Google/Microsoft Authenticator, Aegis, 1Password, …),
confirm with the first code and store 10 one-time backup codes. After that, signing in asks for the
password and then a 6-digit code (or a backup code). The code step uses the application's branding too.

- Built on [scheb/2fa-bundle](https://github.com/scheb/2fa) (TOTP: SHA-1, 6 digits, 30 s, ±15 s leeway).
  `login_throttling` also limits code attempts and a used code can't be replayed (`code_reuse_cache`).
- Secrets are encrypted at rest with libsodium (`TOTP_ENCRYPTION_KEY`); backup codes are stored as hashes.
  If the key is lost or changed, users with 2FA can't sign in (fail closed) until an admin turns it off.
- The QR code is rendered locally (bacon/bacon-qr-code), the secret never leaves the server.
- Turning it off or regenerating backup codes requires the password. An admin can turn it off for a
  user who lost both the phone and the backup codes (*Uživatelé → detail*). Everything is in the audit log.

Setup:

```bash
composer require scheb/2fa-bundle scheb/2fa-totp scheb/2fa-backup-code bacon/bacon-qr-code
# .env.local: TOTP_ENCRYPTION_KEY=   php -r "echo base64_encode(random_bytes(32));"
php bin/console doctrine:migrations:diff     # adds totp_* columns to users
php bin/console doctrine:migrations:migrate
php bin/console cache:clear
```

Requires PHP 8.4+ (scheb/2fa-bundle 8) and the `sodium` and `xmlwriter` extensions. Keep a backup of
`TOTP_ENCRYPTION_KEY` together with the database backups.

## Signed-in devices

The account page lists where the user is signed in: one row per browser (*Chrome · Windows*, *Safari · iPhone*),
with the applications used on it, last activity and IP. Other devices can be signed out one by one or all at
once; an admin can sign a user out everywhere (*Uživatelé → detail*).

- A browser is recognised by a long-lived `device_id` cookie (only its SHA-256 is stored), so signing in
  again from the same browser reuses the row.
- Each application sign-in (`app_grants`) follows its refresh token through rotation. Signing out a device
  ends its auth-server session on the next request and makes the applications' refreshes fail, so they
  lose access when the current access token expires (max. 10 minutes).
- Logout (including RP-initiated logout from an app) signs the current device out.
- A password change or deactivation by an admin signs the user out of all devices and revokes all tokens.
- Rows inactive for 90 days are deleted.

After applying: `php bin/console doctrine:migrations:diff && php bin/console doctrine:migrations:migrate`
(tables `user_devices`, `app_grants`).

## Club portal (application catalogue)

The club portal ([club-portal](https://github.com/mareksloser/club-portal)) is a launchpad: after signing in,
a user sees a tile for every application they can use. Permissions are not duplicated anywhere: an application
is listed when the user has **any of its roles** (the same roles that go into its tokens), when it is marked
public, or for a `superadmin`.

**Admin:** *Aplikace → detail → Rozcestník* – show/hide the tile, its URL, a short description, order and
"visible to every signed-in user". The tile uses the logo and accent colour from *Vzhled přihlášení*.
Applications without a portal entry (backends, the portal itself) are never listed. Point the tile at the
app's `/login` when it redirects straight to the club login (like MVR): thanks to the IdP session the user lands
in the app already signed in.

**API:** `GET /api/apps` with a Bearer access token that has the `apps` scope (any client allowed to request it):

```json
{
  "apps": [{
    "clientId": "mvr-web",
    "name": "Match Video Review",
    "description": "Rozbory zápasů",
    "url": "https://mvr.example.com/login",
    "logoUrl": "https://auth.example.com/branding/mvr-web/logo?v=mvr-web-3f2a….svg",
    "accentColor": "#166534",
    "roles": [{ "role": "coach", "team": "u11", "teamName": "Mladší přípravka" }],
    "access": "role"
  }]
}
```

`access` is `role`, `public` (listed for everyone) or `superadmin` (listed only because of the global role).
The selection lives in `App\Portal\AppCatalog::select()` (pure); admin changes go through `App\Portal\PortalAdmin`.

Setup:

```bash
php bin/console doctrine:migrations:diff      # adds the client_portal table
php bin/console doctrine:migrations:migrate
php bin/console cache:clear

# the portal SPA (public client); it needs no roles of its own
php bin/console league:oauth2-server:create-client "Klubový rozcestník" portal-web --public \
  --redirect-uri=https://portal.example.com/auth/callback \
  --grant-type=authorization_code --grant-type=refresh_token \
  --scope=openid --scope=profile --scope=email --scope=roles --scope=teams --scope=apps --scope=offline_access
```

Add the portal origin to `CORS_ALLOW_ORIGIN`. Existing clients that list their scopes explicitly keep working;
only clients that should read the catalogue need `apps`.

## Directory API

`GET /api/directory` with a Bearer access token that has the `roles` scope returns all teams and the role
catalogue of the application the token was issued to. Apps use it to offer "who may see this" choices without
keeping their own copy (e.g. the [club portal](https://github.com/mareksloser/club-portal) link editor):

```json
{
  "clientId": "portal-web",
  "teams": [{ "slug": "u11", "name": "Mladší přípravka" }],
  "roles": [{ "slug": "admin", "description": "Upravuje rozcestník" }, { "slug": "vybor", "description": "Výbor klubu" }]
}
```

It is meant to be called by the app's backend with the signed-in user's token, so no CORS entry is needed.

`GET /api/directory/users?q=nov` (at least 2 characters, max 20 results) lists club accounts by name or e-mail,
`{"users": [{"sub", "name", "email", "active"}]}`, so an app can link its own records (players, coaches) to an
account. Only for superadmins and holders of the role `admin` in the calling application, because it shows e-mails.

## How it fits together

- `src/Kernel.php` (compiler pass) finds the bundle's services by class and swaps in
  `App\OAuth\AccessTokenRepository` (JWT with `iss`, `kid` and claims), `App\OAuth\IdTokenResponse`
  (`id_token`) and `App\OAuth\UserConverter` (`sub` = UUID instead of e-mail). If a bundle upgrade
  changes things so the classes cannot be found, the container build fails with a clear message.
- `App\OAuth\ClaimsProvider` is the single place where claims are built – testable without HTTP.
- A deactivated user (`users.active = 0`) cannot log in and gets no new token via refresh either.
- All apps are first-party, so the consent screen is skipped (`AuthorizationRequestListener`).
- Login: argon2id, CSRF, throttling of 5 attempts / 15 min, audit to `audit_log`.

## Connecting applications

- **Symfony API** – `examples/mvr-api/`: `access_token` firewall with the `oidc` handler and
  `ClubUserProvider`. No users table is needed; store `sub` as `CHAR(36)` wherever you need a user reference.
- **React SPA** – `examples/mvr-web/src/auth.tsx`: `react-oidc-context` and a `useClubUser()` hook
  with `hasRole('coach', 'u11')`.
- Add each SPA origin to `CORS_ALLOW_ORIGIN`.

## Roadmap

1. E-mail invitations and password reset (`symfony/mailer`), parent accounts linked to minor players.
2. Passkeys (WebAuthn) as a passwordless option.
3. `nonce` in the id_token (not stored yet; not required for code flow with PKCE).
4. Key rotation – JWKS already returns `kid`, publish old and new keys side by side.
5. Use the bundle v2 `AccessTokenExtraClaimsResolveEvent` instead of the custom repository decorator.
