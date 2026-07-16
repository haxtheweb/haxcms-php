# npm / Node.js Projects in DDEV

Reference for running npm-based dev servers (Vite, Next.js, Astro, etc.) inside DDEV containers, including port exposure and required project adjustments.

## Core Concepts

DDEV runs Node.js inside its web container. To serve a Node.js app through DDEV's router, two things are needed:

1. **Expose the dev server port** via `web_extra_exposed_ports` in `.ddev/config.yaml`
2. **Bind the dev server to `0.0.0.0`** so DDEV's router can reach it inside the container

## 1. DDEV Configuration

### Expose the Port

Add `web_extra_exposed_ports` to `.ddev/config.yaml` to route traffic from DDEV's HTTPS/HTTP proxy to the container-internal port:

```yaml
web_extra_exposed_ports:
  - name: node-dev
    container_port: 3000  # port your dev server listens on inside the container
    http_port: 80         # external HTTP port (use 80/443 for primary access)
    https_port: 443       # external HTTPS port
```

**Port mapping rules:**
- `container_port` must match the port your Node.js process binds to
- Use `http_port: 80` / `https_port: 443` to serve on the primary project URL (replaces the default web server)
- Use non-standard ports (e.g. `http_port: 5172` / `https_port: 5173`) to run alongside a PHP backend

### Auto-start the Dev Server

Use `web_extra_daemons` to start the dev server automatically when DDEV starts:

```yaml
web_extra_daemons:
  - name: node-dev
    command: bash -c 'npm install && npm run dev -- --host'
    directory: /var/www/html
```

### Node.js Version

Pin the Node.js version in `.ddev/config.yaml`:

```yaml
nodejs_version: "20"
```

Or use `"auto"` to read from `.nvmrc` / `.node-version`.

### Full Standalone Node.js Project

For projects without a PHP backend, use `generic` project and webserver types:

```yaml
name: my-node-project
type: generic
webserver_type: generic
omit_containers: ["db"]      # skip the database container if unused
nodejs_version: "20"

web_extra_exposed_ports:
  - name: node-dev
    container_port: 3000
    http_port: 80
    https_port: 443

web_extra_daemons:
  - name: node-dev
    command: bash -c 'npm install && npm run dev -- --host'
    directory: /var/www/html
```

## 2. Project-Side Adjustments

Every Node.js dev server must:

1. **Bind to `0.0.0.0`** (not `localhost` or `127.0.0.1`) so DDEV's router can reach it
2. **Use a fixed port** matching `container_port` in the DDEV config
3. **Allow the DDEV hostname** in any CORS or allowed-hosts settings

## 3. Vite

### DDEV Config

```yaml
web_extra_exposed_ports:
  - name: vite
    container_port: 5173
    http_port: 5172
    https_port: 5173
```

### vite.config.js / vite.config.ts

```js
import { defineConfig } from 'vite'

export default defineConfig({
  server: {
    host: "0.0.0.0",
    port: 5173,
    strictPort: true,
    origin: `${process.env.DDEV_PRIMARY_URL_WITHOUT_PORT}:5173`,
    cors: {
      origin: /https?:\/\/([A-Za-z0-9\-\.]+)?(\.ddev\.site)(?::\d+)?$/,
    },
  },
})
```

### Run

```bash
ddev npm run dev
```

Vite dev server accessible at `https://<project>.ddev.site:5173`.

### Vite as Primary Server (no PHP backend)

Map to ports 80/443 instead:

```yaml
type: generic
webserver_type: generic
omit_containers: ["db"]

web_extra_exposed_ports:
  - name: vite
    container_port: 5173
    http_port: 80
    https_port: 443

web_extra_daemons:
  - name: vite
    command: bash -c 'npm install && npm run dev -- --host'
    directory: /var/www/html
```

Accessible at the primary project URL: `https://<project>.ddev.site`.

## 4. Next.js

### DDEV Config

```yaml
type: generic
webserver_type: generic
omit_containers: ["db"]
nodejs_version: "20"

web_extra_exposed_ports:
  - name: nextjs
    container_port: 3000
    http_port: 80
    https_port: 443

web_extra_daemons:
  - name: nextjs
    command: bash -c 'npm install && npm run dev -- --hostname 0.0.0.0'
    directory: /var/www/html
```

**Key difference from Vite**: Next.js uses `--hostname 0.0.0.0` (not `--host`).

### next.config.js

No special DDEV adjustments needed. Next.js respects the `--hostname` CLI flag.

### Run

```bash
ddev npm run dev
```

Accessible at `https://<project>.ddev.site`.

## 5. Astro

Real-world example from the [ddev.com repository](https://github.com/ddev/ddev.com/blob/main/.ddev/config.yaml):

### DDEV Config

```yaml
name: my-astro-site
type: php
webserver_type: nginx-fpm
nodejs_version: "auto"
omit_containers: ["db"]

web_extra_exposed_ports:
  - name: astro-dev
    container_port: 4321
    http_port: 4322
    https_port: 4321

web_extra_daemons:
  - name: astro-dev-daemon
    command: bash -c 'npm install && npm run dev -- --host'
    directory: /var/www/html
```

**Note**: Astro's default dev port is `4321`. The extra `--` before `--host` is a Vite requirement (Astro uses Vite under the hood).

### Run

```bash
ddev npm run dev
```

Accessible at `https://<project>.ddev.site:4321`.

## 6. Alongside a PHP Backend

When running Node.js alongside WordPress, Drupal, or Laravel, use a non-standard port so the PHP site keeps ports 80/443:

```yaml
name: my-wp-site
type: wordpress

web_extra_exposed_ports:
  - name: vite
    container_port: 5173
    http_port: 5172
    https_port: 5173

web_extra_daemons:
  - name: vite
    command: bash -c 'npm install && npm run dev -- --host'
    directory: /var/www/html
```

- PHP site: `https://my-wp-site.ddev.site` (port 443)
- Vite HMR: `https://my-wp-site.ddev.site:5173`

## Quick Command Reference

| Task | Command |
|------|---------|
| Install dependencies | `ddev npm install` |
| Run dev server | `ddev npm run dev` |
| Production build | `ddev npm run build` |
| Run scripts | `ddev npm run <script>` |
| Use yarn | `ddev yarn dev` |
| Check Node.js version | `ddev exec node -v` |
| View dev server logs | `ddev logs -s web` |
| Kill stuck process | `ddev exec bash -c "pkill -f node"` |

## Troubleshooting

### Dev server not reachable

- Verify the server binds to `0.0.0.0`, not `localhost`
- Ensure `container_port` matches the actual port the process uses
- Run `ddev restart` after changing `web_extra_exposed_ports`

### Port conflict

Change `container_port` and the dev server port to a different value in both the DDEV config and the project config.

### CORS errors (Vite)

Add the CORS origin pattern to `vite.config.js`:

```js
server: {
  cors: {
    origin: /https?:\/\/([A-Za-z0-9\-\.]+)?(\.ddev\.site)(?::\d+)?$/,
  },
}
```

### HMR not working

Ensure `origin` is set in the Vite config:

```js
server: {
  origin: `${process.env.DDEV_PRIMARY_URL_WITHOUT_PORT}:5173`,
}
```
