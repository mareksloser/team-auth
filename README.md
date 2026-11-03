# Team Auth – OIDC login server (Symfony + MySQL)

Central sign-in for club applications (match video review, video library, tactics board, …).
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

Business logic lives in `App\Admin\UserAdmin`, `TeamAdmin` and `ClientAdmin` (testable without HTTP);
controllers only pass input in and render results. All changes go through POST with a CSRF token.

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
2. TOTP for admins (`scheb/2fa-bundle`), refresh-token revocation on logout / deactivation.
3. `nonce` in the id_token (not stored yet; not required for code flow with PKCE).
4. Key rotation – JWKS already returns `kid`, publish old and new keys side by side.
5. Use the bundle v2 `AccessTokenExtraClaimsResolveEvent` instead of the custom repository decorator.
