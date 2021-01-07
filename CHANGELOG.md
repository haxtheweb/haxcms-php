# HAXcms change log

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
