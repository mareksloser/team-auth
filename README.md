# Klubový účet – OIDC přihlašovací server (Symfony + MySQL)

Centrální přihlášení pro klubové aplikace (MVR, videotéka, taktická tabule…).
Mluví standardním **OpenID Connect**, takže klienti používají běžné knihovny
(`oidc-client-ts`, Symfony `access_token` + `oidc`) a server půjde kdykoli vyměnit za Keycloak.

## Co umí

| Endpoint | Účel |
|---|---|
| `GET /.well-known/openid-configuration` | discovery |
| `GET /.well-known/jwks.json` | veřejný klíč (RS256, `kid`) |
| `GET /oauth/authorize` | Authorization Code + PKCE |
| `POST /oauth/token` | tokeny (`authorization_code`, `refresh_token`, `client_credentials`) + `id_token` |
| `GET/POST /userinfo` | claimy podle scope |
| `GET /logout` | RP-initiated logout (`post_logout_redirect_uri` jen na origin z redirect URI klienta) |

Claimy (access token, id_token, userinfo):

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

`roles` obsahuje jen role pro aplikaci (client_id), které byl token vydán. Role bez `@tym` platí pro všechny týmy.

## Instalace

```bash
composer create-project symfony/skeleton:"7.4.*" klub-auth && cd klub-auth
composer require symfony/orm-pack symfony/security-bundle symfony/twig-bundle \
  symfony/rate-limiter symfony/uid symfony/validator symfony/translation \
  league/oauth2-server-bundle nyholm/psr7 symfony/psr-http-message-bridge nelmio/cors-bundle
```

Pak zkopíruj obsah tohoto balíčku přes projekt (přepíše `src/Kernel.php`, `config/services.yaml`,
`config/routes.yaml` a konfigurace z flex receptů). Recept bundlu mohl vytvořit
`config/routes/league_oauth2_server.yaml` – smaž ho, routy jsou v `config/routes.yaml`.

```bash
cp .env.local.example .env.local          # a doplň hodnoty

# klíče
openssl genrsa -out config/jwt/private.pem 4096
openssl rsa -in config/jwt/private.pem -pubout -out config/jwt/public.pem
chmod 600 config/jwt/private.pem
echo "OAUTH_ENCRYPTION_KEY=$(openssl rand -base64 32)" >> .env.local

# databáze
docker compose up -d
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

Migrace se generuje z entit – obsahuje vaše tabulky (`users`, `team_categories`, `user_teams`,
`app_roles`, `user_roles`, `audit_log`) i tabulky bundlu (`oauth2_client`, `oauth2_access_token`, …).

## První data

```bash
bin/console club:user:create marek@klub.test "Marek Šloser" --superadmin
bin/console club:team:create u11 "Mladší přípravka"
bin/console club:team:add marek@klub.test u11 coach

# SPA klient (public = bez secretu, vynucené PKCE)
bin/console league:oauth2-server:create-client "MVR web" mvr-web --public \
  --redirect-uri=https://mvr.klub.test/auth/callback \
  --grant-type=authorization_code --grant-type=refresh_token \
  --scope=openid --scope=profile --scope=email --scope=roles --scope=teams --scope=offline_access

bin/console club:role:grant marek@klub.test mvr-web coach --team=u11
bin/console club:role:grant marek@klub.test mvr-web coach --team=u11 --revoke   # odebrání
```

Syntaxi `create-client` ověř přes `--help`, mezi verzemi bundlu se lehce liší.

## Jak to drží pohromadě

- `src/Kernel.php` (compiler pass) najde služby bundlu podle třídy a podstrčí:
  `App\OAuth\AccessTokenRepository` (JWT s `iss`, `kid` a claimy), `App\OAuth\IdTokenResponse`
  (`id_token`) a `App\OAuth\UserConverter` (`sub` = UUID místo e-mailu). Když se bundle změní
  natolik, že třídy nenajde, aplikace spadne při buildu kontejneru s jasnou hláškou.
- `App\OAuth\ClaimsProvider` je jediné místo, kde se skládají claimy – testovatelné bez HTTP.
- Deaktivovaný uživatel (`users.active = 0`) se nepřihlásí a nedostane ani nový token přes refresh.
- Všechny aplikace jsou klubové → souhlasová obrazovka se přeskakuje (`AuthorizationRequestListener`).
- Login: argon2id, CSRF, throttling 5 pokusů / 15 min, audit do `audit_log`.

## Napojení aplikací

- **Symfony API** – `examples/mvr-api/`: firewall `access_token` s `oidc` handlerem +
  `ClubUserProvider`. Žádná tabulka uživatelů není nutná; v MVR nahraď `users.id` sloupcem
  `user_sub CHAR(36)` u tabulek jako `player_marker_views`.
- **React SPA** – `examples/mvr-web/src/auth.tsx`: `react-oidc-context`, hook `useClubUser()`
  s `hasRole('coach', 'u11')`.
- Origin každé SPA přidej do `CORS_ALLOW_ORIGIN`.

## Správa (`/admin`)

Přístup má jen uživatel s globální rolí `superadmin`. Obsahuje:

- **Uživatelé** – vytvoření, úprava, deaktivace, heslo, týmy, role, smazání
- **Týmy** – vytvoření, přejmenování, smazání
- **Aplikace** – OAuth klienti (veřejný SPA / důvěrný backend), redirect URI, granty, scope,
  katalog rolí aplikace, nový secret (zobrazí se jen jednou), smazání; globální role
- **Audit** – přihlášení, vstupy do aplikací a všechny změny ze správy

Logika je ve službách `App\Admin\UserAdmin`, `TeamAdmin`, `ClientAdmin` (testovatelné bez HTTP),
kontrolery jen předávají data. Všechny změny jdou přes POST s CSRF tokenem.

## Ještě chybí (další fáze)

1. Pozvánky e-mailem a reset hesla (`symfony/mailer`), účty rodičů s vazbou na nezletilé hráče.
2. TOTP pro adminy (`scheb/2fa-bundle`), revokace refresh tokenů při odhlášení / deaktivaci.
3. `nonce` v id_token (nyní se neukládá; pro code flow s PKCE není povinný).
4. Rotace klíčů – JWKS už vrací `kid`, stačí publikovat starý i nový klíč současně.
