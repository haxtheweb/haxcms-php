
// simple RSS / Atom feed generator from a JSON outline schema object
class FeedMe
{
    /**
     * Generate the RSS 2.0 header
     */
    getRSSFeed(site)
    {
        domain = "";
        if ((site.manifest.metadata.site.domain)) {
            domain = site.manifest.metadata.site.domain;
        }
        return `<?xml version="1.0" encoding="utf-8"?>
<rss xmlns:atom="http://www.w3.org/2005/Atom" version="2.0">
<channel>
  <title>${site.manifest.title}</title>
  <link>${domain}/rss.xml</link>
  <description>${site.manifest.description}</description>
  <copyright>Copyright (C) ${date('Y')} ${domain}</copyright>
  <language>${site.language}</language>
  <lastBuildDate>${date(\DateTime::RSS, site.manifest.metadata.site.updated)}</lastBuildDate>
  <atom:link href="${domain}/rss.xml" rel="self" type="application/rss+xml"/>
  ${this.rssItems(site)}
</channel>
</rss>`;
    }
    /**
     * Generate RSS items.
     */
    rssItems(site, limit = 25)
    {
        let output = '';
        let domain = "";
        let count = 0;
        if ((site.manifest.metadata.site.domain)) {
            domain = site.manifest.metadata.site.domain;
        }
        let items = site.sortItems('created');
        let siteDirectory = site.directory + '/' + site.manifest.metadata.site.name;
        for (var key in items) {
            let item = items[key];
            let tags = '';
            // beyond edge but don't want this to erorr on write
            if (!(item.metadata)) {
              item.metadata = {};
            }
            if (!(item.metadata.created)) {
              item.metadata.created = time();
              item.metadata.updated = time();
            }
            if ((item.metadata.tags)) {
                tags = implode(',', item.metadata.tags);
            }
            if (count < limit) {
            output +=`
  <item>
    <title>${item.title}</title>
    <link>
    ${domain + '/' + item.location.replace('pages/','').replace('/index.html', '')}
    </link>
    <description>
        <![CDATA[ ${file_get_contents(siteDirectory + '/' + item.location)} ]]>
    </description>
    <category>${tags}</category>
    <guid>
    ${domain + '/' + item.location.replace('pages/','').replace('/index.html', '')}
    </guid>
    <pubDate>${date(\DateTime::RSS, item.metadata.created)}</pubDate>
  </item>`;
            }
            count++;
        }
        return output;
    }
    /**
     * Generate the atom feed
     */
    getAtomFeed(site)
    {
        let domain = "";
        if (site.manifest.metadata.site.domain) {
            domain = site.manifest.metadata.site.domain;
        }
        return `<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>${site.manifest.title}</title>
  <link href="${domain}/atom.xml" rel="self" />
  <subtitle>${site.manifest.description}</subtitle>
  <updated>${date(\DateTime::ATOM, site.manifest.metadata.site.updated)}</updated>
  <author>
      <name>${site.manifest.author}</name>
  </author>
  <id>${domain}/feed</id>
  ${this.atomItems(site)}
</feed>`;
    }
    /**
     * Generate Atom items.
     */
    atomItems(site, limit = 25)
    {
        output = '';
        domain = "";
        count = 0;
        if ((site.manifest.metadata.site.domain)) {
            domain = site.manifest.metadata.site.domain;
        }
        items = site.sortItems('created');
        siteDirectory = site.directory + '/' + site.manifest.metadata.site.name;
        for (var key in items) {
            let item = items[key];
            let tags = '';
            // beyond edge but don't want this to erorr on write
            if (!(item.metadata)) {
              item.metadata = {};
            }
            if (!(item.metadata.created)) {
              item.metadata.created = time();
              item.metadata.updated = time();
            }
            if ((item.metadata.tags)) {
                for (var key2 in item.metadata.tags) {
                    let tag = item.metadata.tags[key2];
                    tags += '<category term="' + tag + '" label="' + tag + '" />';
                }
            }
            if (count < limit) {
            output +=`
  <entry>
    <title>${item.title}</title>
    <id>${domain}/${item.location.replace('pages/','').replace('/index.html', '')}</id>
    <updated>${date(\DateTime::ATOM, item.metadata.updated)}</updated>
    <published>${date(\DateTime::ATOM, item.metadata.created)}</published>
    <summary>${item.description}</summary>
    <link href="${domain}/${item.location.replace('pages/','').replace('/index.html', '')}"/>
    ${tags}]
    <content type="html">
      <![CDATA[ ${file_get_contents(siteDirectory + '/' + item.location)} ]]>
    </content>
  </entry>`;
            }
            count++;
        }
        return output;
    }
}
