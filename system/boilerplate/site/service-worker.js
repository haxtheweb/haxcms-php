/* eslint-env worker, serviceworker */
// HAXcms site service worker. This is a managed file: HAXcms renders it from
// the boilerplate on every rebuild, so edits here are replaced.
//
// Offline support without downloading the whole site:
// - page navigations go to the network first, so visitors always get the
//   server-rendered page and fresh content; the last copy of each page they
//   read is kept for when they are offline
// - site.json, page bodies and the component registry are network first too
// - build modules (local or from the CDN) are stale-while-revalidate: served
//   at once from the cache and refreshed in the background
// - uploads under files/ are never stored (PDFs and video can be huge)
// - install only caches the small shell listed in shellFiles
// Every cache is capped, and caches from older service workers are removed.
'use strict';

// [path, content hash] for the shell files; the hashes version the shell cache
var shellFiles = {{ swhash|json_encode(constant('JSON_PRETTY_PRINT'))|raw }};

function hashString(value) {
  var hash = 5381;
  for (var i = 0; i < value.length; i++) {
    hash = ((hash << 5) + hash + value.charCodeAt(i)) | 0;
  }
  return (hash >>> 0).toString(36);
}

var scope = self.registration ? self.registration.scope : self.location.href;
var SHELL_CACHE =
  'haxcms-shell-' +
  hashString(
    shellFiles
      .map(function (item) {
        return item[0] + ':' + item[1];
      })
      .join('|')
  );
var PAGES_CACHE = 'haxcms-pages';
var ASSETS_CACHE = 'haxcms-assets';
var PAGES_MAX = 60;
var ASSETS_MAX = 400;
var OFFLINE_URL = new URL('offline.html', scope).href;
var SHELL_URL = new URL('index.html', scope).href;
var KEEP = [SHELL_CACHE, PAGES_CACHE, ASSETS_CACHE];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(function (cache) {
      return Promise.all(
        shellFiles.map(function (item) {
          var url = new URL(item[0], scope).href;
          // reload: skip the HTTP cache so the shell matches this version
          return fetch(new Request(url, { cache: 'reload' }))
            .then(function (response) {
              if (response.ok) {
                return cache.put(url, response);
              }
            })
            .catch(function () {});
        })
      );
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.map(function (key) {
          // older HAXcms workers used sw-precache / sw-toolbox caches
          if (KEEP.indexOf(key) === -1) {
            return caches.delete(key);
          }
        })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

function trimCache(name, max) {
  return caches.open(name).then(function (cache) {
    return cache.keys().then(function (keys) {
      if (keys.length <= max) {
        return;
      }
      // keys come back oldest first
      return Promise.all(
        keys.slice(0, keys.length - max).map(function (key) {
          return cache.delete(key);
        })
      );
    });
  });
}

function storable(response) {
  return (
    response &&
    response.ok &&
    (response.type === 'basic' || response.type === 'cors')
  );
}

function networkFirst(request, cacheName, max, fallbacks) {
  return fetch(request)
    .then(function (response) {
      if (storable(response)) {
        var copy = response.clone();
        caches.open(cacheName).then(function (cache) {
          return cache.put(request, copy).then(function () {
            return trimCache(cacheName, max);
          });
        });
      }
      return response;
    })
    .catch(function () {
      return caches.match(request, { ignoreSearch: true }).then(function (cached) {
        if (cached) {
          return cached;
        }
        var chain = Promise.resolve(undefined);
        (fallbacks || []).forEach(function (url) {
          chain = chain.then(function (found) {
            return found || caches.match(url);
          });
        });
        return chain.then(function (found) {
          return found || Response.error();
        });
      });
    });
}

function staleWhileRevalidate(event, request) {
  return caches.open(ASSETS_CACHE).then(function (cache) {
    return cache.match(request).then(function (cached) {
      var refresh = fetch(request)
        .then(function (response) {
          if (storable(response)) {
            return cache.put(request, response.clone()).then(function () {
              trimCache(ASSETS_CACHE, ASSETS_MAX);
              return response;
            });
          }
          return response;
        })
        .catch(function () {
          return cached || Response.error();
        });
      if (cached) {
        event.waitUntil(refresh);
        return cached;
      }
      return refresh;
    });
  });
}

var siteOrigin = new URL(scope).origin;
var sitePath = new URL(scope).pathname;
{% if cdnRegex %}
var cdnPattern = new RegExp({{ cdnRegex|json_encode|raw }});
{% else %}
var cdnPattern = null;
{% endif %}

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') {
    return;
  }
  var url = new URL(request.url);
  if (url.protocol !== 'https:' && url.protocol !== 'http:') {
    return;
  }
  var sameOrigin = url.origin === siteOrigin;
  if (sameOrigin && url.pathname.indexOf(sitePath) !== 0) {
    return;
  }
  var path = sameOrigin ? url.pathname.substring(sitePath.length) : '';
  if (request.mode === 'navigate') {
    if (sameOrigin) {
      event.respondWith(
        networkFirst(request, PAGES_CACHE, PAGES_MAX, [SHELL_URL, OFFLINE_URL])
      );
    }
    return;
  }
  // HAXcms build modules: local build/, or the same layout on a CDN
  var isBuild =
    url.pathname.indexOf('/build/es6/node_modules/') !== -1 &&
    (sameOrigin || (cdnPattern ? cdnPattern.test(request.url) : true));
  if (isBuild) {
    event.respondWith(staleWhileRevalidate(event, request));
    return;
  }
  if (!sameOrigin) {
    return;
  }
  // API calls, uploads and system paths are never stored
  if (
    path.indexOf('x/') === 0 ||
    path.indexOf('system/') === 0 ||
    path.indexOf('files/') === 0
  ) {
    return;
  }
  event.respondWith(networkFirst(request, PAGES_CACHE, PAGES_MAX, []));
});
