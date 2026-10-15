# HAXsite

This folder is a complete website made with [HAX](https://haxtheweb.org/). It is portable: the same files work in the HAX CLI, in a HAXcms server (Node.js or PHP), or on any static host.

## Work on it locally

With the [HAX CLI](https://github.com/haxtheweb/create) installed (`npm install --global @haxtheweb/create`):

```bash
hax serve
```

Run that in this folder, then open the address it prints (usually http://localhost:3000). Local development mode lets you edit right away: use the **Edit** button in the top bar to change a page, and **Merlin** to find everything else HAX can do.

Other useful commands, all run from this folder:

- `hax site` shows the site's status and every action you can run.
- `hax site node:add --title "My page" --y --no-i` adds a page.
- `hax site site:surge`, `site:netlify` or `site:vercel` publishes the site. `setup:github-actions` sets up publishing to GitHub Pages.

## What's in here

- `site.json` holds the site's structure and settings ([JSON Outline Schema](https://github.com/haxtheweb/json-outline-schema)). Change it through the editor or the `hax` CLI rather than by hand.
- `pages/` has one folder per page. Each page's content is plain HTML in `index.html`.
- `files/` holds uploaded images, documents and other media.
- `custom/` is where you can add your own web components.
- `AGENTS.md` and `llms.txt` describe this site for AI agents and tools.

Files such as `sitemap.xml`, `rss.xml`, `manifest.json` and `lunrSearchIndex.json` are generated, so don't edit them by hand.

## Running it on a HAXcms server

This folder can also live in the `_sites/` folder of a HAXcms install ([Node.js](https://github.com/haxtheweb/haxcms-nodejs) or [PHP](https://github.com/haxtheweb/haxcms-php)). There, people sign in to edit. The admin account is created the first time the server starts, and its password is printed once in that terminal.

## Get help

- Documentation: https://haxtheweb.org/
- Community: https://discord.gg/aCGxmRHEJP
- Report an issue: https://github.com/haxtheweb/issues/issues

## License

[Apache 2.0](LICENSE.md)
