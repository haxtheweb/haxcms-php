<?php

class HAXAppStoreService
{
    /**
     * returns an array of app store definitions based
     * on passing in the apikeys for the ones we have
     * baked in support for.
     * @param  array $apikeys  array of API keys per service
     * @return array           HAX appstore specification
     */
    public function loadBaseAppStore($apikeys = array())
    {
        $json = array();
        // youtube
        if (isset($apikeys['youtube'])) {
            $jsonstring =
                '{
        "details": {
          "title": "Youtube",
          "icon": "mdi-social:youtube",
          "color": "red",
          "author": "Google, Youtube LLC",
          "description": "The most popular online video sharing and remix site.",
          "status": "available",
          "tags": ["video", "crowdsourced"],
          "tos": [
            {
              "title": "YouTube Terms of Service",
              "link": "https://www.youtube.com/t/terms"
            },
            {
              "title": "Google Privacy Policy",
              "link": "https://policies.google.com/privacy"
            }
          ]
        },
        "connection": {
            "protocol": "https",
            "url": "www.googleapis.com/youtube/v3",
            "data": {
              "key": "' .
                $apikeys['youtube'] .
                '"
            },
            "operations": {
              "browse": {
                "method": "GET",
                "endPoint": "search",
                "pagination": {
                  "style": "page",
                  "props": {
                    "previous": "prevPageToken",
                    "next": "nextPageToken",
                    "total_items": "pageInfo.totalResults"
                  }
                },
                "search": {
                  "q": {
                    "title": "Search",
                    "type": "string"
                  }
                },
                "data": {
                  "part": "snippet",
                  "type": "video",
                  "maxResults": "20"
                },

                  "url": "https://www.youtube.com/watch?v=",

                "resultMap": {
                  "defaultGizmoType": "video",
                  "items": "items",
                  "preview": {
                    "title": "snippet.title",
                    "details": "snippet.description",
                    "image": "snippet.thumbnails.default.url",
                    "id": "id.videoId"
                  },
                  "gizmo": {
                    "title": "snippet.title",
                    "description": "snippet.description",
                    "id": "id.videoId",
                    "_url_source": "https://www.youtube.com/watch?v=<%= id %>",
                    "caption": "snippet.description",
                    "citation": "snippet.channelTitle"
                  }
                }
              }
            }
        }
      }';
            $tmp = json_decode($jsonstring);
            array_push($json, $tmp);
        }
        // vimeo
        if (isset($apikeys['vimeo'])) {
            $jsonstring =
                '{
        "details": {
          "title": "Vimeo",
          "icon": "av:play-circle-filled",
          "color": "blue",
          "author": "Vimeo Inc.",
          "description": "A high quality video sharing community.",
          "status": "available",
          "tags": ["video", "crowdsourced"]
        },
        "connection": {
          "protocol": "https",
          "url": "api.vimeo.com",
          "data": {
            "access_token": "' .
                $apikeys['vimeo'] .
                '"
          },
          "operations": {
            "browse": {
              "method": "GET",
              "endPoint": "videos",
              "pagination": {
                "style": "link",
                "props": {
                  "first": "paging.first",
                  "next": "paging.next",
                  "previous": "paging.previous",
                  "last": "paging.last"
                }
              },
              "search": {
                "query": {
                  "title": "Search",
                  "type": "string"
                }
              },
              "data": {
                "direction": "asc",
                "sort": "alphabetical",
                "filter": "CC",
                "per_page": "20"
              },
              "resultMap": {
                "defaultGizmoType": "video",
                "items": "data",
                "preview": {
                  "title": "name",
                  "details": "description",
                  "image": "pictures.sizes.1.link",
                  "id": "id"
                },
                "gizmo": {
                  "_url_source": "https://vimeo.com<%= id %>",
                  "id": "uri",
                  "title": "title",
                  "caption": "description",
                  "description": "description",
                  "citation": "user.name"
                }
              }
            }
          }
        }
      }';
            $tmp = json_decode($jsonstring);
            array_push($json, $tmp);
        }
        // giphy
        if (isset($apikeys['giphy'])) {
            $jsonstring =
                '{
        "details": {
          "title": "Giphy",
          "icon": "gif",
          "color": "green",
          "author": "Giphy",
          "description": "Crowd sourced memes via animated gifs.",
          "status": "available",
          "tags": ["gif", "crowdsourced", "meme"]
        },
        "connection": {
          "protocol": "https",
          "url": "api.giphy.com",
          "data": {
            "api_key": "' .
                $apikeys['giphy'] .
                '"
          },
          "operations": {
            "browse": {
              "method": "GET",
              "endPoint": "v1/gifs/search",
              "pagination": {
                "style": "offset",
                "props": {
                  "offset": "pagination.offset",
                  "total": "pagination.total_count",
                  "count": "pagination.count"
                }
              },
              "search": {
                "q": {
                  "title": "Search",
                  "type": "string"
                },
                "rating": {
                  "title": "Rating",
                  "type": "string",
                  "component": {
                    "name": "dropdown-select",
                    "slot": "<paper-item value=\'Y\'>Y</paper-item><paper-item value=\'G\'>G</paper-item><paper-item value=\'PG\'>PG</paper-item><paper-item value=\'PG-13\'>PG-13</paper-item><paper-item value=\'R\'>R</paper-item>"
                  }
                },
                "lang": {
                  "title": "Language",
                  "type": "string",
                  "component": {
                    "name": "dropdown-select",
                    "slot": "<paper-item value=\'en\'>en</paper-item><paper-item value=\'es\'>es</paper-item><paper-item value=\'pt\'>pt</paper-item><paper-item value=\'id\'>id</paper-item><paper-item value=\'fr\'>fr</paper-item><paper-item value=\'ar\'>ar</paper-item><paper-item value=\'tr\'>tr</paper-item><paper-item value=\'th\'>th</paper-item><paper-item value=\'vi\'>vi</paper-item><paper-item value=\'de\'>de</paper-item><paper-item value=\'it\'>it</paper-item><paper-item value=\'ja\'>ja</paper-item><paper-item value=\'zh-CN\'>zh-CN</paper-item><paper-item value=\'zh-TW\'>zh-TW</paper-item><paper-item value=\'ru\'>ru</paper-item><paper-item value=\'ko\'>ko</paper-item><paper-item value=\'pl\'>pl</paper-item><paper-item value=\'nl\'>nl</paper-item><paper-item value=\'ro\'>ro</paper-item><paper-item value=\'hu\'>hu</paper-item><paper-item value=\'sv\'>sv</paper-item><paper-item value=\'cs\'>cs</paper-item><paper-item value=\'hi\'>hi</paper-item><paper-item value=\'bn\'>bn</paper-item><paper-item value=\'da\'>da</paper-item><paper-item value=\'fa\'>fa</paper-item><paper-item value=\'tl\'>tl</paper-item><paper-item value=\'fi\'>fi</paper-item><paper-item value=\'iw\'>iw</paper-item><paper-item value=\'ms\'>ms</paper-item><paper-item value=\'no\'>no</paper-item><paper-item value=\'uk\'>uk</paper-item>"
                  }
                }
              },
              "data": {
                "limit": "20",
                "lang": "en"
              },
              "resultMap": {
                "defaultGizmoType": "gif",
                "items": "data",
                "preview": {
                  "title": "title",
                  "details": "description",
                  "image": "images.preview_gif.url",
                  "id": "id"
                },
                "gizmo": {
                  "source": "images.original.url",
                  "source2": "images.480w_still.url",
                  "id": "id",
                  "title": "title",
                  "alt": "title",
                  "caption": "user.display_name",
                  "citation": "user.display_name"
                }
              }
            }
          }
        }
      }';
            $tmp = json_decode($jsonstring);
            array_push($json, $tmp);
        }
        // unsplash
        if (isset($apikeys['unsplash'])) {
            $jsonstring =
                '{
        "details": {
          "title": "Unsplash",
          "icon": "image:collections",
          "color": "grey",
          "author": "Unsplash",
          "description": "Crowd sourced, open photos",
          "status": "available",
          "tags": ["images", "crowdsourced", "cc"]
        },
        "connection": {
          "protocol": "https",
          "url": "api.unsplash.com",
          "data": {
            "client_id": "' .
                $apikeys['unsplash'] .
                '"
          },
          "operations": {
            "browse": {
              "method": "GET",
              "endPoint": "search/photos",
              "pagination": {
                "style": "link",
                "props": {
                  "first": "paging.first",
                  "next": "paging.next",
                  "previous": "paging.previous",
                  "last": "paging.last"
                }
              },
              "search": {
                "query": {
                  "title": "Search",
                  "type": "string"
                }
              },
              "data": {
              },
              "resultMap": {
                "defaultGizmoType": "image",
                "items": "results",
                "preview": {
                  "title": "tags.0.title",
                  "details": "description",
                  "image": "urls.thumb",
                  "id": "id"
                },
                "gizmo": {
                  "id": "id",
                  "source": "urls.regular",
                  "alt": "description",
                  "caption": "description",
                  "citation": "user.name"
                }
              }
            }
          }
        }
      }';
            $tmp = json_decode($jsonstring);
            array_push($json, $tmp);
        }
        // flickr
        if (isset($apikeys['flickr'])) {
            $jsonstring =
                '{
        "details": {
          "title": "Flickr",
          "icon": "image:collections",
          "color": "pink",
          "author": "Yahoo",
          "description": "The original photo sharing platform on the web.",
          "status": "available",
          "rating": "0",
          "tags": ["images", "creative commons", "crowdsourced"]
        },
        "connection": {
          "protocol": "https",
          "url": "api.flickr.com",
          "data": {
            "api_key": "' .
                $apikeys['flickr'] .
                '"
          },
          "operations": {
            "browse": {
              "method": "GET",
              "endPoint": "services/rest",
              "pagination": {
                "style": "page",
                "props": {
                  "per_page": "photos.perpage",
                  "total_pages": "photos.pages",
                  "page": "photos.page"
                }
              },
              "search": {
                "text": {
                  "title": "Search",
                  "type": "string"
                },
                "safe_search": {
                  "title": "Safe results",
                  "type": "string",
                  "value": "1",
                  "component": {
                    "name": "dropdown-select",
                    "valueProperty": "value",
                    "slot": "<paper-item value=\'1\'>Safe</paper-item><paper-item value=\'2\'>Moderate</paper-item><paper-item value=\'3\'>Restricted</paper-item>"
                  }
                },
                "license": {
                  "title": "License type",
                  "type": "string",
                  "value": "",
                  "component": {
                    "name": "dropdown-select",
                    "valueProperty": "value",
                    "slot": "<paper-item value=\'\'>Any</paper-item><paper-item value=\'0\'>All Rights Reserved</paper-item><paper-item value=\'4\'>Attribution License</paper-item><paper-item value=\'6\'>Attribution-NoDerivs License</paper-item><paper-item value=\'3\'>Attribution-NonCommercial-NoDerivs License</paper-item><paper-item value=\'2\'>Attribution-NonCommercial License</paper-item><paper-item value=\'1\'>Attribution-NonCommercial-ShareAlike License</paper-item><paper-item value=\'5\'>Attribution-ShareAlike License</paper-item><paper-item value=\'7\'>No known copyright restrictions</paper-item><paper-item value=\'8\'>United States Government Work</paper-item><paper-item value=\'9\'>Public Domain Dedication (CC0)</paper-item><paper-item value=\'10\'>Public Domain Mark</paper-item>"
                  }
                }
              },
              "data": {
                "method": "flickr.photos.search",
                "safe_search": "1",
                "format": "json",
                "per_page": "20",
                "nojsoncallback": "1",
                "extras": "license,description,url_l,url_s"
              },
              "resultMap": {
                "defaultGizmoType": "image",
                "items": "photos.photo",
                "preview": {
                  "title": "title",
                  "details": "description._content",
                  "image": "url_s",
                  "id": "id"
                },
                "gizmo": {
                  "title": "title",
                  "source": "url_l",
                  "alt": "description._content"
                }
              }
            }
          }
        }
      }';
            $tmp = json_decode($jsonstring);
            array_push($json, $tmp);
        }
        // nasa
        $jsonstring = '{
      "details": {
        "title": "NASA",
        "icon": "places:all-inclusive",
        "color": "blue",
        "author": "US Government",
        "description": "The cozmos through one simple API.",
        "status": "available",
        "tags": ["images", "government", "space"]
      },
      "connection": {
        "protocol": "https",
        "url": "images-api.nasa.gov",
        "operations": {
          "browse": {
            "method": "GET",
            "endPoint": "search",
            "pagination": {
              "style": "page",
              "props": {
                "page": "page"
              }
            },
            "search": {
              "q": {
                "title": "Search",
                "type": "string"
              }
            },
            "data": {
              "media_type": "image"
            },
            "resultMap": {
              "defaultGizmoType": "image",
              "items": "collection.items",
              "preview": {
                "title": "data.0.title",
                "details": "data.0.description",
                "image": "links.0.href",
                "id": "links.0.href"
              },
              "gizmo": {
                "id": "links.0.href",
                "source": "links.0.href",
                "title": "data.0.title",
                "caption": "data.0.description",
                "description": "data.0.description",
                "citation": "data.0.photographer",
                "type": "data.0.media_type"
              }
            }
          }
        }
      }
    }';
        $tmp = json_decode($jsonstring);
        array_push($json, $tmp);
        // sketchfab
        $jsonstring = '{
      "details": {
        "title": "Sketchfab",
        "icon": "icons:3d-rotation",
        "color": "purple",
        "author": "Sketchfab",
        "description": "3D sharing community.",
        "status": "available",
        "rating": "0",
        "tags": ["3D", "creative commons", "crowdsourced"]
      },
      "connection": {
        "protocol": "https",
        "url": "api.sketchfab.com",
        "data": {
          "type": "models"
        },
        "operations": {
          "browse": {
            "method": "GET",
            "endPoint": "v3/search",
            "pagination": {
              "style": "page",
              "props": {
                "per_page": "photos.perpage",
                "total_pages": "photos.pages",
                "page": "photos.page"
              }
            },
            "search": {
              "q": {
                "title": "Search",
                "type": "string"
              },
              "license": {
                "title": "License type",
                "type": "string",
                "value": "",
                "component": {
                  "name": "dropdown-select",
                  "valueProperty": "value",
                  "slot": "<paper-item value=\'\'>Any</paper-item><paper-item value=\'by\'>Attribution</paper-item><paper-item value=\'by-sa\'>Attribution ShareAlike</paper-item><paper-item value=\'by-nd\'>Attribution NoDerivatives</paper-item><paper-item value=\'by-nc\'>Attribution-NonCommercial</paper-item><paper-item value=\'by-nc-sa\'>Attribution NonCommercial ShareAlike</paper-item><paper-item value=\'by-nc-nd\'>Attribution NonCommercial NoDerivatives</paper-item><paper-item value=\'cc0\'>Public Domain Dedication (CC0)</paper-item>"
                }
              }
            },
            "resultMap": {
              "defaultGizmoType": "video",
              "items": "results",
              "preview": {
                "title": "name",
                "details": "description._content",
                "image": "thumbnails.images.2.url",
                "id": "uid"
              },
              "gizmo": {
                "title": "name",
                "source": "embedUrl",
                "alt": "description"
              }
            }
          }
        }
      }
    }';
        $tmp = json_decode($jsonstring);
        array_push($json, $tmp);
        // wikipedia
        $jsonstring = '{
      "details": {
        "title": "Wikipedia",
        "icon": "account-balance",
        "color": "grey",
        "author": "Wikimedia",
        "description": "Encyclopedia of the world.",
        "status": "available",
        "tags": ["content", "encyclopedia", "wiki"]
      },
      "connection": {
        "protocol": "https",
        "url": "en.wikipedia.org",
        "data": {
          "action": "query",
          "list": "search",
          "format": "json",
          "origin": "*"
        },
        "operations": {
          "browse": {
            "method": "GET",
            "endPoint": "w\/api.php",
            "pagination": {
              "style": "offset",
              "props": {
                "offset": "sroffset"
              }
            },
            "search": {
              "srsearch": {
                "title": "Search",
                "type": "string"
              }
            },
            "data": {},
            "resultMap": {
              "image": "https://en.wikipedia.org/static/images/project-logos/enwiki.png",
              "defaultGizmoType": "wikipedia",
              "items": "query.search",
              "preview": {
                "title": "title",
                "details": "snippet",
                "id": "title"
              },
              "gizmo": {
                "_url_source": "https://en.wikipedia.org/wiki/<%= id %>",
                "id": "title",
                "title": "title",
                "caption": "snippet",
                "description": "snippet"
              }
            }
          }
        }
      }
    }';
        $tmp = json_decode($jsonstring);
        array_push($json, $tmp);
        return $json;
    }
    /**
     * Returns some example STAX definitions, which are
     * predefined sets of items which can be broken apart
     * after the fact. This is like a template in traditional WYSIWYGs.
     * @return array           HAX stax specification
     */
    public function loadBaseStax()
    {
        $jsonstring = '[
          {
            "details": {
              "title": "Two column Article",
              "image": "",
              "author": "HAXTheWeb core team",
              "description": "Content with media to right",
              "status": "available",
              "rating": "0",
              "tags": ["media"]
            },
            "stax": [
              {
                "tag": "grid-plate",
                "properties": {
                  "disableResponsive": true,
                  "layout": "2-1"
                },
                "content": "<h2 data-design-treatment=\"vert\" data-primary=\"15\" slot=\"col-1\">Scanning Process / Software</h2><p slot=\"col-1\">The following stages of the process involves aligning the various scans to create\n    a coherent, detailed 3D model using Artec Studio 17 software. The point cloud is\n    converted into a mesh, which is a solid 3D model, and the texture data captured by\n    the scanner is mapped back onto the mesh to add color, markings, and other details.\n    The final 3D model can be exported into other software platforms, such as Blender\n    and Instant Mesh, both of which are open-source.</p>\n\n    <p data-hax-layout=\"true\" =\"true\"=\"\" slot=\"col-1\">To optimize the model for web use, it must be reduced significantly in size. To\n    achieve this, the polygon count is reduced using Instant Mesh, and the resulting\n    low-polygon model is unwrapped, and the texture and additional details are added\n    through a process called “baking” onto the model using normal mapping. This is a\n    commonly used technique in film and video game production to reduce file sizes, processing\n    power, and other resources required.</p>\n\n <media-image citation=\"3D scanner and software shown scanning an ODL coffee mug.\" accent-color=\"grey\" size=\"wide\" offset=\"none\" slot=\"col-2\" source=\"https://bones.courses.science.psu.edu/assets/images/scanning-page-images/scanner-odl.jpg\" card box></media-image>"
              }
            ]
          }
        ]';
        return json_decode($jsonstring);
    }

    /**
     * Return an array of the base app keys we support. This
     * can reduce the time to integrate with other solutions.
     * @return array  service names keyed by their key name
     */
    public function baseSupportedApps()
    {
        return array(
            'youtube' => array(
                'name' => 'YouTube',
                'docs' =>
                    'https://developers.google.com/youtube/v3/getting-started'
            ),
            'vimeo' => array(
                'name' => 'Vimeo',
                'docs' => 'https://developer.vimeo.com/'
            ),
            'giphy' => array(
                'name' => 'Giphy',
                'docs' => 'https://developers.giphy.com/docs/'
            ),
            'unsplash' => array(
                'name' => 'Unsplash',
                'docs' => 'https://unsplash.com/developers'
            ),
            'flickr' => array(
                'name' => 'Flickr',
                'docs' => 'https://www.flickr.com/services/developer/api/'
            )
        );
    }
}
