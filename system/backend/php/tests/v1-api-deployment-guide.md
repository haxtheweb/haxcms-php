# HAXcms PHP v1 API Deployment Guide

## Overview

The HAXcms PHP backend now supports a v1 REST API that mirrors the NodeJS backend's `siteRoutes` and `systemRoutes` architecture, with Bearer JWT authentication, OpenAPI specifications, and HAXIAM integration.

## Deployment Steps

### 1. Prerequisites

- Apache or Nginx with PHP 8.1+
- `mod_rewrite` enabled (Apache) or equivalent URL rewriting (Nginx)
- Existing HAXcms `_config` directory with `config.php`, `config.json`, `SALT.txt`, and keys
- The `Authorization` HTTP header must be forwarded to PHP:
  - Apache: `.htaccess` already includes `RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`
  - Nginx: add `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` to your PHP location block

### 2. File Placement

All new files are within the repository. Ensure the following paths exist after pulling the latest code:

```
haxcms-php/
├── system/api/v1/index.php                          # v1 system API entry point
├── system/backend/php/lib/systemRoutes/
│   ├── SystemApiRouter.php                          # v1 request router
│   ├── SystemApiRequestContext.php                  # Request context parser
│   ├── SystemApiSecurity.php                        # Bearer JWT + IAM security
│   ├── SystemRoutesMap.php                          # v1 route definitions
│   ├── discovery/
│   │   ├── api.php                                  # System API discovery payload
│   │   └── openapi.php                              # Serves system-spec.yaml
│   ├── openapi/
│   │   └── system-spec.yaml                         # OpenAPI spec (copied from NodeJS)
│   └── v1/
│       ├── haxiam.php                               # HAXIAM addUserAccess wrapper
│       ├── session.php                              # Session/connection settings wrapper
│       ├── lifecycle.php                            # Sites lifecycle wrapper
│       ├── settings.php                             # System settings wrapper
│       ├── sites.php                                # Sites management wrapper
│       └── integrations.php                         # App store integration wrapper
├── system/backend/php/lib/siteRoutes/
│   ├── discovery/
│   │   ├── api.php                                  # Site API discovery payload
│   │   └── openapi.php                              # Serves site-spec.yaml
│   └── openapi/
│       └── site-spec.yaml                           # OpenAPI spec (copied from NodeJS)
```

### 3. Web Server Configuration

#### Apache

The `.htaccess` file at the repository root has been updated. Ensure the following rewrite rule is present (it should be automatically active if `.htaccess` is processed):

```apache
RewriteRule ^system/api/v1/(.*)$ system/api/v1/index.php [L]
```

This routes all `system/api/v1/*` requests to the v1 entry point while preserving legacy `system/api/*` (op-based) routes.

#### Nginx

Add the following location block inside your `server` block:

```nginx
location ~ ^/system/api/v1/ {
    try_files $uri $uri/ /system/api/v1/index.php?$query_string;
}

location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;  # adjust to your PHP-FPM socket
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
}
```

### 4. Authentication Flow

The v1 API only accepts `Authorization: Bearer <token>` headers. No cookie or POST body fallback is used for v1 endpoints.

1. **Login**: `POST /system/api/v1/session/login` with `{u, p}` body
2. **Receive JWT**: Extract `jwt` from the JSON response
3. **Subsequent requests**: Include `Authorization: Bearer <jwt>` header
4. **Site token**: For site-specific routes under `/x/api/v1/*`, include `X-HAXCMS-Site-Token: <siteToken>` header (where `<siteToken>` is the HMAC token for the site)

### 5. Endpoint Summary

#### System API (`/system/api/v1`)

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/` | Discovery payload | Public |
| GET | `/openapi` | OpenAPI YAML/JSON | Public |
| POST | `/session/login` | Authenticate | Public |
| POST | `/session/logout` | Log out | Public |
| GET/POST | `/session/refresh` | Refresh token | Public |
| GET/POST | `/session/connection-settings` | Frontend config | Public |
| GET/POST | `/session/connection-test` | Health check | Public |
| GET/POST | `/session/user` | User profile | Bearer |
| GET | `/sites` | List sites | Bearer |
| POST | `/sites` | Create site | Bearer |
| GET | `/sites/:siteName` | Site metadata | Bearer |
| POST | `/sites/:siteName/clone` | Clone site | Bearer |
| POST | `/sites/:siteName/archive` | Archive site | Bearer |
| POST | `/sites/:siteName/download` | Download site | Bearer |
| POST | `/sites/:siteName/download-skeleton` | Export skeleton | Bearer |
| POST | `/sites/:siteName/save-as-template` | Save as template | Bearer |
| GET | `/status` | System status | Bearer (admin) |
| GET | `/system/version` | Version info | Bearer (admin) |
| GET | `/entities` | Entity descriptors | Bearer (admin) |
| GET | `/schemas` | Schema descriptors | Bearer (admin) |
| GET/POST | `/configuration/api-keys` | API keys | Bearer (admin) |
| GET/POST | `/configuration/media` | Media settings | Bearer (admin) |
| POST | `/configuration/schema-files/operations` | File operations | Bearer (admin) |
| GET/POST/PATCH | `/blocks` | System blocks | Bearer (admin) |
| GET/POST/PATCH | `/skeletons` | System skeletons | Bearer (admin) |
| GET/PATCH/PUT/DELETE | `/skeletons/:skeletonName` | Skeleton detail | Bearer (admin) |
| GET/POST/PATCH | `/themes` | System themes | Bearer (admin) |
| GET | `/integrations/app-store` | App store | Bearer (site token) |
| POST | `/haxiamAddUserAccess` | HAXIAM user access | Bearer (admin) |

#### Site API (`/x/api/v1`)

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/` | Discovery payload | Public |
| GET | `/openapi` | OpenAPI spec | Public |
| GET | `/site` | Site summary | Public |
| GET | `/items` | List items | Public |
| POST | `/items` | Create item | Bearer + site token |
| GET | `/items/:idOrSlug` | Item detail | Public |
| PATCH | `/items/:idOrSlug` | Update item | Bearer + site token |
| DELETE | `/items/:idOrSlug` | Delete item | Bearer + site token |
| GET | `/content/:idOrSlug` | Content detail | Public |
| PATCH | `/content/:idOrSlug` | Update content | Bearer + site token |
| GET | `/files` | List files | Bearer + site token |
| POST | `/files` | Upload file | Bearer + site token |
| PATCH | `/files/:fileUuid` | File operation | Bearer + site token |
| GET | `/search` | Search content | Public |
| GET | `/tags` | List tags | Public |
| GET | `/blocks` | Block catalog | Public |
| GET | `/themes` | Theme catalog | Public |
| GET | `/themes/active` | Active theme | Public |
| GET | `/entities` | Entity descriptors | Public |
| GET | `/schemas` | Schema descriptors | Public |
| GET | `/regions` | Region list | Public |
| GET | `/custom-elements` | Web component registry | Public |
| GET | `/reports` | Report descriptors | Bearer + site token |
| GET | `/analytics` | Analytics metadata | Bearer + site token |
| GET | `/views` | Saved views | Bearer + site token |

### 6. HAXIAM Deployment Notes

If `config.json` has `iam: true`, the following additional protections apply:

- `validateIAMRouteAuthorization()` is called on all admin routes.
- The authenticated user (from the Bearer JWT) must match the IAM tenant user resolved from the request path or `HAXCMS_ROOT`.
- Mismatches return `403 Access denied`.
- The `haxiamAddUserAccess` endpoint is only exposed when `iam: true`.

### 7. Legacy Compatibility

Legacy `op` routes (`system/api?op=...`) remain fully functional. The `system/api.php` entry point tries the v1 router first; if the route does not match a v1 pattern, it falls back to the legacy `executeRequest()` dispatcher.

The `appJWTConnectionSettings()` method returns:
- **Primary fields** (e.g., `login`, `saveNodePath`) with v1 path strings for the frontend.
- **Legacy fields** (e.g., `legacyLogin`, `legacyConnectionSettings`) for any internal code that still requires the old `op` paths.

### 8. Testing

Run the CLI integration test (no server required):

```bash
php system/backend/php/tests/v1-integration-tests.php
```

Run the HTTP integration test (requires a running web server):

```bash
bash system/backend/php/tests/test-v1-http.sh http://localhost admin admin
```

### 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `404` on v1 endpoints | `.htaccess` rewrite not active | Enable `mod_rewrite` and `AllowOverride All` |
| `401` on all protected routes | Missing `Authorization` header | Ensure web server forwards `HTTP_AUTHORIZATION` to PHP |
| `500` on discovery | Missing `system-spec.yaml` | Verify `system/backend/php/lib/systemRoutes/openapi/system-spec.yaml` exists |
| v1 paths not in connectionSettings | `appJWTConnectionSettings()` not updated | Confirm `lib/HAXCMS.php` has the v1 path mappings |
| HAXIAM 403 on valid login | Tenant mismatch | Check `HAXCMS_ROOT` resolves to the expected user directory |

### 10. Rollback

If issues arise, the legacy system is still available:

- Revert `.htaccess` to remove the `system/api/v1` rewrite rule.
- Revert `system/api.php` to remove the `SystemApiRouter::dispatch()` call.
- The v1 files themselves are safe to leave in place; they will not be invoked if the rewrite and dispatch logic are reverted.
