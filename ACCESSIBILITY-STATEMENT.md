# Accessibility Statement — haxtheweb/haxcms-php

**Last updated:** November 2026  
**Contact:** hax@psu.edu  
**Standard:** WCAG 2.1 AA (with WCAG 2.0 AA baseline)

## Our Commitment

`haxcms-php` is the PHP HAXcms backend: it serves the HAX dashboard and editor, stores sites, and writes the published HTML that site visitors read. Accessibility is one of the HAX project pillars: **Accessible — built for everyone, by everyone.**

## What is in this repository

- **Server output this repository owns:** the HTML shell of every site page (the site's language on `<html lang>`, page title and description, canonical, alternate, previous and next links), the site and page exports (HTML, Markdown, PDF, DOCX, EPUB) and llms.txt.
- **The HAX dashboard, editor, themes and content elements** are built from [`haxtheweb/webcomponents`](https://github.com/haxtheweb/webcomponents) and shipped here unchanged. Their accessibility work and testing happen there; see its [accessibility statement](https://github.com/haxtheweb/webcomponents/blob/master/ACCESSIBILITY-STATEMENT.md).

## What we do

- The end-to-end suite (`test/e2e`) drives the real dashboard and editor in a headless browser; 3 of its 8 files also run axe on the screens they open (create site, edit content, page management).
- Behavior is kept identical with [`haxcms-nodejs`](https://github.com/haxtheweb/haxcms-nodejs), so a site is equally accessible on either backend.

## Known Limitations

- **The axe checks in the end-to-end suite report but do not block.** Critical or serious findings are logged, and the e2e job is `continue-on-error`, so a regression shows in the run without failing it.
- **Screen reader testing of the server output has not been recorded.** Reports from NVDA, JAWS, VoiceOver and TalkBack users are welcome.

## How You Can Help

- **Report accessibility issues** in the unified issue queue, [haxtheweb/issues](https://github.com/haxtheweb/issues/issues), or email hax@psu.edu.
- **Test with assistive technologies** (screen readers, keyboard-only navigation, high contrast modes) and share what you find.

## Support

- **Email:** hax@psu.edu
- **GitHub:** [haxtheweb/issues](https://github.com/haxtheweb/issues)
- **Discord:** [discord.gg/aCGxmRHEJP](https://discord.gg/aCGxmRHEJP) (#accessibility channel)

---

_This statement reflects our current status and commitments. As an evolving open source project, we acknowledge there is always more work to do to ensure accessibility for all users._
