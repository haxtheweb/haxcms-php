# AGENTS.md - HAXcms Site Interface

This file provides comprehensive instructions for AI coding agents working within this specific HAXcms site. HAXcms sites are built on the HAX (Headless Authoring eXperience) ecosystem and follow specific patterns for content management, theming, and deployment.

## Site Structure Overview

This HAXcms site follows the standard structure and conventions:

```
site-root/
├── AGENTS.md              # This file - AI agent instructions
├── site.json              # JSON Outline Schema - site structure & metadata
├── pages/                 # Page content (HTML files)
├── files/                 # Media assets and resources
├── theme/                 # Custom theme files
│   ├── theme.html        # Theme template
│   └── theme.css         # Theme styles
├── assets/               # Icons, banners, and static assets
├── index.html            # Main site entry point
├── index.php             # PHP entry point (dynamic sites)
├── config.php            # PHP configuration file
├── manifest.json         # PWA manifest
├── package.json          # Node.js dependencies and scripts
└── custom/               # Custom web components and extensions
```

## Core Site Files

### site.json - Site Manifest
The `site.json` file is the heart of every HAXcms site, using **JSON Outline Schema** format:
- **Structure**: Defines page hierarchy, navigation, and content organization
- **Metadata**: Contains site settings, theme configuration, and SEO information
- **Items Array**: Each page is represented as an item with title, location, metadata, and children
- **Validation**: Must conform to JSON Outline Schema standards

### Pages Directory
- **Location**: All page content stored in `pages/` directory
- **Format**: Semantic HTML files (not Markdown)
- **Naming**: Each page has a corresponding `index.html` in its slug-named directory
- **Content**: Rich content using HAX web components and standard HTML

### Files Directory
- **Assets**: All media files, documents, and resources
- **Organization**: Maintain logical file structure for easy management
- **References**: Linked from pages using relative paths

### PHP Configuration (config.php)
- **Backend Settings**: Database connections, API keys, user permissions
- **Site Configuration**: PHP-specific settings for dynamic functionality
- **Security**: Authentication and authorization settings

## Content Management

### Adding New Pages
```bash
# Using HAX CLI (recommended)
hax site --title "Page Title" --content "<p>Initial content</p>" --slug "page-slug"

# Via HAXcms PHP Backend API
# POST to /createNode endpoint with JSON payload

# Manual creation
# 1. Create directory: pages/page-slug/
# 2. Add index.html with semantic HTML content
# 3. Update site.json to include new page in items array
```

### Page Structure
```json
{
  "id": "unique-page-id",
  "title": "Page Title",
  "location": "pages/page-slug/index.html",
  "metadata": {
    "created": 1234567890,
    "updated": 1234567890,
    "description": "Page description for SEO"
  },
  "indent": 0,
  "parent": null,
  "order": 0
}
```

### Content Guidelines
- **Semantic HTML**: Use proper heading hierarchy (h1, h2, h3, etc.)
- **HAX Components**: Leverage available web components for rich interactions
- **Accessibility**: Ensure WCAG 2.0 AA compliance in all content
- **Performance**: Optimize images and media before adding to `files/`

## PHP Backend Integration

### Dynamic vs Static Operation
- **Static Mode**: Site operates as static HTML/CSS/JS files
- **Dynamic Mode**: PHP backend provides editing, user management, and API endpoints
- **Hybrid Mode**: Can switch between static publishing and dynamic editing

### Backend API Endpoints
```php
// Key HAXcms PHP API endpoints
POST /login              // User authentication
POST /createSite         // Create new site
POST /createNode         // Add new page/content
POST /saveNode           // Update page content
POST /deleteNode         // Remove page
GET /listSites           // Get site list
GET /getNode             // Retrieve page content
```

### PHP Configuration Management
```php
// config.php - Site-specific settings
$HAXCMS = new HAXCMS();
$HAXCMS->loadConfig();
$HAXCMS->setSiteDirectory($site);
$HAXCMS->setTemplatePath($templatePath);
```

## Theme Development

### Theme Architecture
- **Base Class**: Custom themes should extend `HAXCMSLitElement`
- **Design System**: Use DDD (Design, Develop, Destroy) tokens for consistency
- **Template**: `theme/theme.html` defines the layout structure
- **Styling**: `theme/theme.css` contains theme-specific styles

### Theme Variables (Twig Processing)
Available template variables processed by PHP Twig engine:
- `{{ title }}` - Site title
- `{{ siteTitle }}` - Same as title
- `{{ description }}` - Site description
- `{{ basePath }}` - Site base URL path
- `{{ hexCode }}` - Primary theme color
- `{{ version }}` - HAXcms version
- `{{ privateSite }}` - Whether site requires authentication

### Theme Development Workflow
1. **Edit theme files** in `theme/` directory
2. **Use DDD tokens** for consistent design (colors, spacing, typography)
3. **Test locally** with PHP development server or `hax serve`
4. **Build theme** with `yarn run build` (critical for HAXCMSLitElement themes)
5. **Validate** across different content types and screen sizes

## Development Environment

### Local Development (PHP Backend)
```bash
# Using HAX CLI with PHP backend
hax serve

# Direct PHP development server
php -S localhost:8080 index.php

# Using XAMPP/LAMP stack
# Place site in web server document root
```

### Local Development (Static Mode)
```bash
# Serve as static files
python -m http.server 8000
# or
npx serve .
```

### Available Scripts (package.json)
- `npm start` or `npm run serve` - Start development server
- `npm run dev` - Development mode with enhanced debugging
- `npm run ghpages:build` - Prepare for GitHub Pages deployment

### Dependencies
- **HAXcms PHP backend** - Content management and API endpoints
- **Web Components** - Access to 250+ HAX web components
- **DDD Design System** - Consistent design tokens and patterns
- **Twig Templating** - Template processing for dynamic content

## HAX Components Integration

### Component Usage
HAXcms sites have access to the full HAX web component library:

```html
<!-- Video player -->
<video-player source="https://youtube.com/watch?v=example"></video-player>

<!-- Image with lightbox -->
<simple-img src="files/image.jpg" alt="Description"></simple-img>

<!-- Grid layouts -->
<grid-plate layout="1-1">
  <div slot="col-1">Left column content</div>
  <div slot="col-2">Right column content</div>
</grid-plate>

<!-- Interactive elements -->
<multiple-choice-question question="What is HAX?">
  <simple-option correct>Headless Authoring eXperience</simple-option>
  <simple-option>Heavy Application eXtension</simple-option>
</multiple-choice-question>
```

### Component Registry
- **wc-registry.json**: Contains references to all available web components
- **Dynamic Loading**: Components are loaded on-demand when detected in content
- **CDN Delivery**: Components delivered from HAX CDN for optimal performance

## Site Configuration

### Metadata Management
Key metadata in `site.json`:
```json
{
  "metadata": {
    "site": {
      "name": "site-name",
      "domain": "https://site.example.com",
      "created": 1234567890,
      "updated": 1234567890,
      "settings": {
        "lang": "en",
        "canonical": true,
        "sw": false,
        "forceUpgrade": false,
        "private": false
      }
    },
    "theme": {
      "variables": {
        "hexCode": "#3f51b5",
        "cssVariable": "--simple-colors-default-theme-light-blue-7"
      }
    },
    "author": {
      "name": "Author Name",
      "email": "author@example.com"
    }
  }
}
```

### PHP Configuration (config.php)
```php
// Site-specific PHP configuration
$HAXCMS->config->site = (object) [
  'name' => 'site-name',
  'domain' => 'https://site.example.com',
  'directory' => '/path/to/site',
  'private' => false,
  'jwt' => [
    'enabled' => true,
    'algorithm' => 'HS256',
    'key' => 'your-secret-key'
  ],
  'database' => [
    'type' => 'json', // or 'mysql', 'postgresql'
    'path' => '/path/to/data'
  ]
];
```

### SEO Optimization
- **Meta Tags**: Automatically generated from page metadata
- **Structured Data**: Schema.org markup included
- **Sitemap**: Generated automatically from site.json structure
- **Open Graph**: Social media preview optimization

## Custom Components

### Adding Custom Components
```bash
# In custom/ directory
cd custom/
npm init # if needed
# Create custom components following HAX patterns
# Build with rollup.config.js
npm run build
```

### Custom Component Structure
```javascript
// custom/src/my-component.js
import { LitElement, html, css } from 'lit';
import { DDDSuper } from '@haxtheweb/d-d-d/d-d-d.js';

class MyComponent extends DDDSuper(LitElement) {
  static get styles() {
    return [
      super.styles,
      css`
        :host {
          display: block;
          padding: var(--ddd-spacing-4);
          background-color: var(--ddd-theme-default-beaverBlue);
        }
      `
    ];
  }

  render() {
    return html`<p>Custom component content</p>`;
  }

  // HAX integration
  static get haxProperties() {
    return {
      canScale: true,
      canPosition: true,
      canEditSource: true,
      gizmo: {
        title: "My Component",
        description: "A custom component",
        icon: "icons:extension",
        color: "blue",
        tags: ["content", "custom"]
      },
      settings: {
        configure: [],
        advanced: []
      }
    };
  }
}

globalThis.customElements.define('my-component', MyComponent);
```

## Content Creation Workflow

### Editorial Process
1. **Plan Content**: Define page structure and navigation hierarchy
2. **Create Pages**: Use semantic HTML with HAX components
3. **Add Media**: Place assets in `files/` directory
4. **Update Navigation**: Modify `site.json` to include new pages
5. **Test Locally**: Preview with development server
6. **Deploy**: Push changes to hosting platform

### PHP Backend Workflow
1. **Login**: Authenticate via `/login` endpoint
2. **Create Content**: Use HAX editor interface or API endpoints
3. **Save Changes**: Content automatically persists via PHP backend
4. **Publish**: Deploy static version or keep dynamic
5. **Manage Users**: Control access via PHP user management

### Best Practices
- **Content Structure**: Use proper heading hierarchy for accessibility
- **Image Optimization**: Compress images before adding to `files/`
- **Component Usage**: Leverage HAX components for interactive content
- **SEO Metadata**: Include descriptions and keywords in page metadata
- **Responsive Design**: Test content across device sizes

## Deployment Options

### Static Hosting
HAXcms sites can be deployed as static sites to:
- **GitHub Pages**: Use `npm run ghpages:build`
- **Netlify**: Direct git integration with build commands
- **Vercel**: Automatic deployments from repository
- **Traditional Hosting**: Upload built files via FTP/SSH

### Dynamic PHP Hosting
For full HAXcms features with PHP backend:
- **Shared Hosting**: Most PHP hosting providers
- **VPS/Dedicated**: Full control over environment
- **Docker**: Use provided Dockerfile for containerization
- **Cloud Platforms**: AWS, Google Cloud, Azure with PHP support

### Hybrid Deployment
- **Development**: PHP backend for content creation
- **Production**: Static export for performance and security
- **CI/CD**: Automated static builds from PHP backend

## HAX Ecosystem Integration

### Issue Reporting
- **Unified Issues**: Report issues at `haxtheweb/issues`
- **GitHub CLI**: Use `gh issue create` for quick reporting
- **Merlin Integration**: Use "Issue" command in HAX for automated reporting

### Community Resources
- **HAX Discord**: https://bit.ly/hax-discord
- **Documentation**: https://haxtheweb.org/
- **Component Gallery**: Explore available components
- **CLI Help**: Run `hax help` for command reference

### Updates and Maintenance
- **HAX CLI**: Update with `hax update`
- **PHP Dependencies**: Update via Composer when needed
- **Component Library**: Benefits from ecosystem-wide component updates
- **Security**: Regular security updates through HAX ecosystem

## AI Agent Guidelines

### Working with Content
- **Always validate** `site.json` structure when adding/modifying pages
- **Use semantic HTML** in page content, not Markdown
- **Reference existing pages** in `pages/` directory for content patterns
- **Check `files/` directory** for available media assets before adding new ones

### PHP Backend Integration
- **Respect authentication** when working with protected sites
- **Use proper API endpoints** for content manipulation
- **Validate PHP configuration** in `config.php` before making changes
- **Test both static and dynamic modes** when applicable

### Theme Modifications
- **Never edit** generated files like `custom-elements.json`
- **Always run** `yarn run build` after theme changes
- **Test theme changes** with both PHP and static servers
- **Use DDD tokens** for styling consistency

### Site Maintenance
- **Backup `site.json` and `config.php`** before major changes
- **Validate HTML** in page content for accessibility compliance
- **Optimize assets** in `files/` directory for performance
- **Test both deployment modes** after significant changes

### HAX Component Integration
- **Check component availability** in wc-registry.json before using
- **Follow HAX patterns** for component configuration
- **Test component rendering** in both static and dynamic contexts
- **Document custom components** for future maintenance

## Security Considerations

- **PHP Security**: Validate all inputs, use prepared statements, sanitize output
- **Content Validation**: Sanitize any user-generated content
- **File Uploads**: Validate file types and sizes in `files/` directory
- **Authentication**: Use JWT tokens and secure session management
- **Schema Compliance**: Ensure `site.json` follows JSON Outline Schema
- **Dependency Updates**: Keep PHP and Node.js dependencies current for security

## Performance Optimization

- **PHP Optimization**: Enable OPcache, use proper caching strategies
- **Image Compression**: Optimize all images in `files/` directory
- **Component Loading**: Leverage lazy loading for better performance
- **Service Worker**: Consider enabling SW for offline capabilities
- **CDN Usage**: Components delivered via HAX CDN automatically
- **Database Optimization**: Use appropriate indexing and query optimization

## PHP-Specific Features

### User Management
```php
// User authentication and management
$user = new HAXCMSUser();
$user->login($username, $password);
$user->hasPermission('create');
$user->getRoles();
```

### Content API
```php
// Programmatic content management
$site = new HAXCMSSite();
$site->loadSite($siteName);
$page = $site->addPage($title, $content);
$site->saveManifest();
```

### Git Integration
```php
// Automatic version control
$site->gitCommit('Updated content');
$site->gitPush();
```

---

*This AGENTS.md file is specific to HAXcms sites and complements the ecosystem-wide AGENTS.md files found in HAX repositories. For broader HAX development, refer to the main ecosystem documentation.*

## Getting Help

- **Local Help**: Run `hax help` for CLI assistance
- **Community**: Join HAX Discord at https://bit.ly/hax-discord
- **Documentation**: Visit https://haxtheweb.org/ for comprehensive guides
- **Issues**: Report problems at https://github.com/haxtheweb/issues
- **PHP Documentation**: Check HAXcms PHP backend documentation for API details
