# HAXcms change log

## 8.0.0 - 23-12-21
This is a pretty massive update in perspection of quality of the system. Since Version 7 released in June, there have been 291 issues closed that are in this release!
Major improvements in UX associated with Merlin. Merlin is starting to consolidate the UX of working with files and types and getting content streamlined in integration with HAX.
This release also starts unlocking several new types of sites that we've expressed as possible for years but have not visualized via design.
It adds a new concept in theming called "Regions" which are very easy to implement going forward.

- Documentation / Community Improvements
  - Monthly team meetings and Bi-monthly User meetings now take place
  - New community documentation and excellence in teaching resource established via HAXcellence: https://oer.hax.psu.edu/bto108/sites/haxcellence/
  - Site contains tutorials, Pedagogical articles, videos, community stances / pillars, experiments and more
  - https://www.youtube.com/@haxtheweb associated YouTube channel just for the project
- Merlin Improvements
  - User scaffolding improvements to better understand what you are trying to do and suggest actions accordingly
  - User Scaffolding runs in the background now and sets the stage for more intelligent processing and decision trees in the future
  - Merlin is now an "omnibar" in that it is immediately visible, present, and easy to understand what it does
  - Ability to import DocX, HTML and Markdown based content by dropping onto Merlin to rapidly insert HTML or build new pages
- Theming / Page capabilities:
  - Regions now possible in theme layer via site-region tag which works off of the site.json schema. Regions can also be set via the Site Settings buttton
  - better mobile support across all themes
  - Email page capability added to a few designs
  - page-break now includes possible icon, description, tags, image, ability to not appear in menus and relatedNodes. This information can all be used with smart-collection type but also some themes start implementing these capabilities in their design (namely Collections and Blog theme)
- New content blocks added:
  - play-list for slide-show type material that provides options for scrolling between items. Great for image galleries but works with any block type as it is a grid
  - collection-item / collection-list / smart-collection
- New themes added to overworld:
  - "Polaris" - a typical Brochure-ware / small project advertisement site. This is based on a popular and simple WP theme.
  - "Blog" - maturation of a design we already had which has been optimized for blogging. This is inspired by a popular blogging site.
  - "Training" - a more on-rails / intentionally limited experience of pacing through material in a linear order. This is inspired by google code labs.
  - "Collection" - a theme intented to optimize usage of the new collection blocks. It is designed to be a simple Brochure-ware site developed originally by Eberly College of Science to promote OER offerings in their college, it now can be used to build similar sites for anyone.
- Miscilaneous
  - Bug fixes to back end as far as saving content, schema validation and updating rss / sitemaps when new content added
  - Better SEO support via tags injected by backend based version of HAXcms but also  via Google Analytics support in site.json
  - Performance improvements on front-end via Lit 3.x and Lit Virtualizer implementations

## 7.0.0 - 23-06-01
Merlin added for a more inteligent way of working with the system and discovering new functionality. Merlin consolidates many UX patterns into one making it easier to work with the system.

- Over 370 features, bug fixes, and enhancements since HAX 6 (Jan '23)
- Overhauled block discovery system. See preview on hover, easily expand and collapse logical groupings of elements
- Merlin - A command discovery agent that allows you to type and discover functionality, search for media, search with your voice, suggest community improvements, insert blocks, and much, much more!
- More unified authoring experience - menus slide in gracefully to indicate editing mode changes, authoring tools all grouped in one location, menus look and feel unified and the inputs for editing blocks in context have all been reviewed to improve their usability and ease of understanding.
- Dark mode and enhanced mobile support for viewing AND authoring
- Lots of new block types for instructional design including inline audio, multiple choice, mark the words, "learning component", page types, worksheet downloads and more!
- Additional blocks including audio players, spotify embed, twitter embed, author "page flag" notes, collapsed fieldsets!
- Performance - This version of HAX loads even faster than previous iterations at all levels. Sites load faster, pages load faster, larger sites load faster, and the editor loads faster with less resources with extensive testing and support for low performance / connectivity devices and environments
- Ground work laid for the team to begin building out HAXCellence - A resource for teaching excellence with HAX. Learn more about this work in progress effort to make HAX the ultimate instructional design and development backed platform -- https://oer.hax.psu.edu/bto108/sites/haxcellence/ontology

## 6.0.0 - 2022-12-20
Features as related from our new Request Intake process
- [video-player] full-screen / sticky corner bugs #1063
- Full featured, reimagined Outline Designer!!! - This is the 1st major rewrite of Outline Designer in 10 years and now has come to HAXcms and ELMS
- Smart Lesson and Insights capabilities - Authors can now gain insights into material as they write it
- Ability to easily internally link content - Links can now easily leverage the internal linkage to the site structure!
- Block level operation panel - Ability to have an overview of the content tree in HAX and modify through this outline
- Table's can now be edited in context with a full featured table editor!
- New courses can be generated fom .DOCX heading and content structure!
- Outline designer now supports importing, reviewing, and modifying content from .DOCX headings and content structure!
- Ability to remote / by reference content - a new tag that can render content in HAX that's coming from a remote HAXcms site!

Additional issues resolved can be viewed in our issue queue https://github.com/elmsln/issues/issues?q=is%3Aissue+is%3Aclosed

## 2.0.0 - 2021-01-07
All themes have received a11y and mobile clean up. Lots of performance timing updates as well as an enhanced build routine to improve performance and compatibility with older browsers (and Safari). In all there are over 100 documented issue improvements and far more than that beyond the HAX editor. The editor itself as well as all other elements are now at a 3.0.0 status to reflect their additional stability and performance gains. HAX now loads up (with some issues) on legacy browsers (previously only evergreen could load the editor itself). The UX has improved dramatically as far as accuracy, speed, typing experience, drag and drop, and user expectations when editing in all platforms. Special work was done to bring Firefox and Safari into functional alignment with Chrome/Edge and legacy browsers will even pick up a lot of the UX patterns because of enhanced polyfill support as required.

While 2.0.0, this does not break changes from 1.4.0 released toward the end of 2020.
A couple of the issues resolved though most are in our consolidated issue queue:
main queue: https://github.com/elmsln/issues/issues?q=is%3Aissue+is%3Aclosed+haxcms
haxcms queue: https://github.com/elmsln/HAXcms/milestone/7

## 1.2.0 - 2020-07-07
New themes (clean-one, clean-two) as well as multiple a11y and ux issues cleaned up. Improved performance among different site- elements via dropping of the @apply legacy concept. HAXcms is just one of our build targets now so it's issue queue is being winded down in favor of the unified issue queue.

- Milestone in HAXcms https://github.com/elmsln/HAXcms/milestone/5?closed=1
- Closed issues tagged HAXcms https://github.com/elmsln/issues/issues?q=is%3Aissue+is%3Aclosed+label%3AHAXcms

## 1.0.0 - 2020-01-28
LitElement is predominant in this release. This release has undergone a first round of user audit with a group of 30 providing feedback on HAXcms and HAX directly. This has drag and drop, click to build grids in the page, lots of stability and performance improvements to HAXcms itself, accurate JWT invalidation and securing with timing tokens, a CLI, improved support for HAXiam, a complete rewrite of the API to be a unified backend, Swagger documented, bette DX for theme developers, local developer experience via yarn for those working on HAXcms core, and a backend restructuring to support multiple backends in the future as well as initial work on an Express based backend.

All in all, this is a massive release with lots of sticky issues resolved that were blocking a full stable release. And so, with those removed, we have arrived at 1.0.0.

Full breakdown of the 85 issues resolved in this release: https://github.com/elmsln/HAXcms/milestone/3?closed=1

## 0.12.0 - 2019-10-01
This release provides dramatically better user experience and is the culmination of months of bug fixing across the many systems that HAX is deployed. Most notably is the user experience unification between sites, dashboard, HAX styles meshign with HAXcms, HAX and HAX capable element data binding and accessibilit fixes, a full headless Form API on the backend with front-end tag to render and validate, and better data integrity and experience in using the site outline tool.

Full breakdown of the 86 issues resolved in this release: https://github.com/elmsln/HAXcms/milestone/2?closed=1

## 0.11.0 - 2019-08-02
This release is to hit a common release point with ELMS:LN. As they both implement HAX and HAXcms
actually sits inside of ELMSLN for future integrations. This provides lots of performance, accessibility
and cross browser support.

To read about the changes in this release see: https://github.com/elmsln/HAXcms/milestone/1?closed=1
