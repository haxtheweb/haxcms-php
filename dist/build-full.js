// the base line build that's used to setup everything in a production environment
import "./build.js";
import "./build-home.js";
// this can be used for customizations / additional elements to get pulled in
// this assumes you're operating in a bit of a pro mode where you know to compile
// via polymer build and that you're managing your own fork of the package.json we ship
import "../_config/my-custom-elements.js";
// site listing
import "@lrnwebcomponents/haxcms-elements/lib/haxcms-site-listing.js";
// core
import "@lrnwebcomponents/haxcms-elements/lib/haxcms-site-builder.js";
import "@lrnwebcomponents/haxcms-elements/lib/haxcms-theme-behavior.js";
// core editing capabilities
import "@lrnwebcomponents/haxcms-elements/lib/haxcms-site-editor.js";
import "@lrnwebcomponents/haxcms-elements/lib/haxcms-editor-builder.js";
import "@lrnwebcomponents/haxcms-elements/lib/haxcms-manifest-editor-dialog.js";
import "@lrnwebcomponents/haxcms-elements/lib/haxcms-outline-editor-dialog.js";
import "@lrnwebcomponents/haxcms-elements/lib/haxcms-site-editor-ui.js";

// themes are dynamically imported
import "@lrnwebcomponents/outline-player/outline-player.js";
import "@lrnwebcomponents/simple-blog/simple-blog.js";
import "@lrnwebcomponents/haxcms-elements/lib/haxcms-dev-theme.js";

// these should all be dynamically imported as well
import "@lrnwebcomponents/a11y-gif-player/a11y-gif-player.js";
import "@lrnwebcomponents/citation-element/citation-element.js";
import "@lrnwebcomponents/hero-banner/hero-banner.js";
import "@lrnwebcomponents/image-compare-slider/image-compare-slider.js";
import "@lrnwebcomponents/license-element/license-element.js";
import "@lrnwebcomponents/lrn-aside/lrn-aside.js";
import "@lrnwebcomponents/lrn-calendar/lrn-calendar.js";
import "@lrnwebcomponents/lrn-math/lrn-math.js";
import "@lrnwebcomponents/lrn-table/lrn-table.js";
import "@lrnwebcomponents/lrn-vocab/lrn-vocab.js";
import "@lrnwebcomponents/lrndesign-blockquote/lrndesign-blockquote.js";
import "@lrnwebcomponents/magazine-cover/magazine-cover.js";
import "@lrnwebcomponents/media-behaviors/media-behaviors.js";
import "@lrnwebcomponents/media-image/media-image.js";
import "@lrnwebcomponents/meme-maker/meme-maker.js";
import "@lrnwebcomponents/multiple-choice/multiple-choice.js";
import "@lrnwebcomponents/paper-audio-player/paper-audio-player.js";
import "@lrnwebcomponents/person-testimonial/person-testimonial.js";
import "@lrnwebcomponents/place-holder/place-holder.js";
import "@lrnwebcomponents/q-r/q-r.js";
import "@lrnwebcomponents/full-width-image/full-width-image.js";
import "@lrnwebcomponents/self-check/self-check.js";
import "@lrnwebcomponents/simple-concept-network/simple-concept-network.js";
import "@lrnwebcomponents/stop-note/stop-note.js";
import "@lrnwebcomponents/tab-list/tab-list.js";
import "@lrnwebcomponents/task-list/task-list.js";
import "@lrnwebcomponents/video-player/video-player.js";
import "@lrnwebcomponents/wave-player/wave-player.js";
import "@lrnwebcomponents/wikipedia-query/wikipedia-query.js";
