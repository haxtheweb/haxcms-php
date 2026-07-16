# WordPress Multisite with DDEV

Quick reference for setting up WordPress Multisite in DDEV. This works for various hostname configurations including subdomains, subdirectories, or completely different domains.

## 1. Configure wp-config.php

Add the standard DDEV snippet plus Multisite configuration:

```php
/** DDEV Settings */
if (getenv('IS_DDEV_PROJECT') == 'true') {
    require __DIR__ . '/wp-config-ddev.php';
}

/* Multisite */
define('WP_ALLOW_MULTISITE', true);
define('MULTISITE', true);

// Set to true for subdomains, false for subdirectories
define('SUBDOMAIN_INSTALL', true);

// Must match the main site URL configured in wp-config-ddev.php
define('DOMAIN_CURRENT_SITE', 'my-project.ddev.site');

define('PATH_CURRENT_SITE', '/');
define('SITE_ID_CURRENT_SITE', 1);
define('BLOG_ID_CURRENT_SITE', 1);
```

## 2. Configure DDEV Hostnames

DDEV supports multiple hostname patterns for Multisite. Choose the approach that fits your setup:

### Option A: Wildcard Subdomains
For sites like `blog.my-project.ddev.site`, `shop.my-project.ddev.site`:

```yaml
name: my-project
type: wordpress
php_version: "8.4"

additional_hostnames:
  - "my-project"
  - "*.my-project"
```

### Option B: Specific Different Hostnames
For sites with completely different domains like `site1.ddev.site`, `site2.ddev.site`:

```yaml
name: my-project
type: wordpress
php_version: "8.4"

additional_hostnames:
  - "site1.ddev.site"
  - "site2.ddev.site"
  - "site3.ddev.site"
```

### Option C: Subdirectories (no extra hostnames needed)
For subdirectory-based Multisite (`/blog/`, `/shop/`), set `SUBDOMAIN_INSTALL` to `false` in wp-config.php. No additional hostnames required.

**Note**: The `*.my-project` wildcard pattern matches any subdomain automatically.

## 3. Update Database URLs with WP-CLI

### List all sites in the network

```bash
ddev wp site list
```

### Search and Replace URLs

Use `ddev wp search-replace` with the `--url` flag to target specific subsites:

```bash
# Replace URLs on the main site
ddev wp search-replace 'https://old-domain.com' 'https://my-project.ddev.site'

# Subdomain-based subsites
ddev wp search-replace 'https://old-blog.com' 'https://blog.my-project.ddev.site' --url=blog.my-project.ddev.site
ddev wp search-replace 'https://old-shop.com' 'https://shop.my-project.ddev.site' --url=shop.my-project.ddev.site

# Different hostname subsites
ddev wp search-replace 'https://old-site1.com' 'https://site1.ddev.site' --url=site1.ddev.site
ddev wp search-replace 'https://old-site2.com' 'https://site2.ddev.site' --url=site2.ddev.site

# Subdirectory-based subsites (path-based)
ddev wp search-replace 'https://old-domain.com/blog' 'https://my-project.ddev.site/blog' --url=my-project.ddev.site/blog
```

### Bulk replace across all tables

```bash
# Run search-replace across all network tables
ddev wp search-replace 'https://old-domain.com' 'https://my-project.ddev.site' --all-tables
```

### Update specific database tables manually

If needed, update these tables directly:

| Table | Field | Format | Subdomain Example | Different Hostname Example | Subdirectory Example |
|-------|-------|--------|-------------------|---------------------------|---------------------|
| `wp_site` | `domain` | No protocol, no trailing `/` | `my-project.ddev.site` | `my-project.ddev.site` | `my-project.ddev.site` |
| `wp_sitemeta` | `siteurl` | With protocol and trailing `/` | `https://my-project.ddev.site/` | `https://my-project.ddev.site/` | `https://my-project.ddev.site/` |
| `wp_blogs` | `domain` | No protocol, no trailing `/` | `blog.my-project.ddev.site` | `site1.ddev.site` | `my-project.ddev.site` |
| `wp_blogs` | `path` | Leading `/` | `/` | `/` | `/blog/` |
| `wp_options` | `siteurl`, `home` | With protocol, no trailing `/` | `https://my-project.ddev.site` | `https://my-project.ddev.site` | `https://my-project.ddev.site` |
| `wp_#_options` | `siteurl`, `home` | With protocol, no trailing `/` | `https://blog.my-project.ddev.site` | `https://site1.ddev.site` | `https://my-project.ddev.site` |

## 4. Verify Setup

```bash
# Start or restart DDEV
ddev restart

# Check site is accessible
ddev describe

# List all network sites
ddev wp site list

# Test subdomain subsites
ddev wp option get siteurl --url=blog.my-project.ddev.site
ddev wp option get siteurl --url=shop.my-project.ddev.site

# Test different hostname subsites
ddev wp option get siteurl --url=site1.ddev.site
ddev wp option get siteurl --url=site2.ddev.site

# Test subdirectory subsites
ddev wp option get siteurl --url=my-project.ddev.site/blog
```

## Domain Mapping Note

In production, Multisite often uses domain mapping (e.g., `site1.com`, `site2.com` mapped to subsites). For local development, map these to DDEV hostnames:

```yaml
# .ddev/config.yaml
additional_hostnames:
  - "site1.ddev.site"    # Maps to production site1.com
  - "site2.ddev.site"    # Maps to production site2.com
```

Then use search-replace to update URLs from production domains to DDEV domains.

## Troubleshooting

### Subdomain not resolving

Ensure wildcard DNS is configured:

```bash
ddev debug test
```

### Mixed content errors

Run search-replace on all tables:

```bash
ddev wp search-replace 'http://' 'https://' --all-tables
ddev wp search-replace 'old-domain.com' 'my-project.ddev.site' --all-tables
```

### Flush rewrite rules

```bash
ddev wp rewrite flush
```

## Quick Command Reference

| Task | Command |
|------|---------|
| List all sites | `ddev wp site list` |
| Create new site | `ddev wp site create --slug=new-site` |
| Activate plugin network-wide | `ddev wp plugin activate my-plugin --network` |
| Run search-replace on subsite | `ddev wp search-replace old new --url=subsite.domain` |
| Update network options | `ddev wp network meta update 1 siteurl https://new.domain` |
