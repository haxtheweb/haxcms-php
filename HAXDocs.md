# HAX

HAX (Headless Authoring eXperience) is a <a href="https://developer.mozilla.org/en-US/docs/Web/Web_Components">web components</a> driven editing experience that works across platforms. To add new "bricks" to HAX either pick from the 100 or so that the HAX core team has developed or create your own web components and add a callback method to it to <code>static get haxProperties()</code> (<a href="https://haxtheweb.org/documentation-1/hax-development/hax-schema">docs</a>).

## Getting help
- [HAX Slack](https://bit.ly/haxslack) - where HAX core developers talk web components / all our projects
- [HAX Issue Queue](https://github.com/elmsln/issues/issues) - Unified issue queue for all of our projects
- [HAXCamp: Uncode](http://bit.ly/haxuncode) - Friday 3-5pm EST weekly recorded hang out to discuss web components, hax and answer community questions. Join our slack to confirm air dates. [Watch past episodes here](https://www.youtube.com/channel/UCgcFR9ojBu9P7VNQjt0nqbA/videos)
- [Web components Slack](https://www.polymer-project.org/slack-invite) - A very popular open hang out for web component developers. (Named polymer but not polymer specific)
- [HAXTheWeb YouTube playlist](https://www.youtube.com/watch?v=f_tEA9O9pco&list=PLJQupiji7J5eTqv8JFiW8SZpSeKouZACH) - all things hax the web, presentations, development efforts, etc.
- [#HAXTheWeb on twitter](https://twitter.com/search?q=%23HAXTheWeb&src=typed_query&f=live) - We microblog there a lot
## Developers
You can run and build assets local to your project as opposed to using one of the zero-config CDNs (or use your own!). CDN providers are created using the following tools:
- [Unbundled Webcomponents repo](https://github.com/elmsln/unbundled-webcomponents) - Using this can you can make your own build that works in HAX and any other website with relative ease. The tooling is all preconfigured, all you need to do is install new assets from NPM (use yarn to do this) and 
- [OpenWC](https://open-wc.org/) - While not HAX related, this is a great community tooling repo to get started with web components and front end development using best practices.
- [WCFactory](https://github.com/elmsln/wcfactory) - This can be used to build and manage a web components repo at scale. This is a meta tooling which can be used to build a monorepo using best practices of managing and deployment hundreds of elements. This is what the HAX core team uses to build and manage [LRNWebComponents](https://github.com/elmsln/lrnwebcomponents).
- [lit-element docs](https://lit-element.polymer-project.org/) - While not required, LitElement is a very popular base case for development web components that provides better DX than "VanillaJS" while still being extremely small.

## Wiring custom elements to HAX
All you need to do for your element to work with HAX is add a `static get haxProperties()` function on your element which returns HAXSchema. You can read up on [HAXschema on HAXTheWeb.org](https://haxtheweb.org/hax-schema). Schema is relatively simple to construct (honestly we usually [copy and paste from existing elements](https://github.com/elmsln/lrnwebcomponents/blob/master/elements/video-player/src/video-player-hax.json) and tweak values).

The HAX core team builds in a mix of LitElement and VanillaJS and recommends everyone start at LitElement when working with HAXSchema though because of the web component standard HAX works with ANY web component library!
