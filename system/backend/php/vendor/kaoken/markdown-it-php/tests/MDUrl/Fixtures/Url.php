<?php
/**
Copyright (c) Joyent, Kaoken, Inc. and other Node contributors.

This software is released under the MIT License.
http://opensource.org/licenses/mit-license.php
 */
namespace Kaoken\Test\MDUrl\Fixtures;


class Url
{
    private static $json = <<<_JSON_
{
  "//some_path" : {
    "pathname": "//some_path"
  },

  "HTTP://www.example.com/" : {
    "protocol": "HTTP:",
    "slashes": true,
    "hostname": "www.example.com",
    "pathname": "/"
  },

  "HTTP://www.example.com" : {
    "protocol": "HTTP:",
    "slashes": true,
    "hostname": "www.example.com",
    "pathname": ""
  },

  "http://www.ExAmPlE.com/" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "www.ExAmPlE.com",
    "pathname": "/"
  },

  "http://user:pw@www.ExAmPlE.com/" : {
    "protocol": "http:",
    "slashes": true,
    "auth": "user:pw",
    "hostname": "www.ExAmPlE.com",
    "pathname": "/"
  },

  "http://USER:PW@www.ExAmPlE.com/" : {
    "protocol": "http:",
    "slashes": true,
    "auth": "USER:PW",
    "hostname": "www.ExAmPlE.com",
    "pathname": "/"
  },

  "http://user@www.example.com/" : {
    "protocol": "http:",
    "slashes": true,
    "auth": "user",
    "hostname": "www.example.com",
    "pathname": "/"
  },

  "http://user%3Apw@www.example.com/" : {
    "protocol": "http:",
    "slashes": true,
    "auth": "user%3Apw",
    "hostname": "www.example.com",
    "pathname": "/"
  },

  "http://x.com/path?that's#all, folks" : {
    "protocol": "http:",
    "hostname": "x.com",
    "slashes": true,
    "search": "?that's",
    "pathname": "/path",
    "hash": "#all, folks"
  },

  "HTTP://X.COM/Y" : {
    "protocol": "HTTP:",
    "slashes": true,
    "hostname": "X.COM",
    "pathname": "/Y"
  },

  "http://x.y.com+a/b/c" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "x.y.com+a",
    "pathname": "/b/c"
  },

  "HtTp://x.y.cOm;a/b/c?d=e#f g<h>i" : {
    "protocol": "HtTp:",
    "slashes": true,
    "hostname": "x.y.cOm",
    "pathname": ";a/b/c",
    "search": "?d=e",
    "hash": "#f g<h>i"
  },

  "HtTp://x.y.cOm;A/b/c?d=e#f g<h>i" : {
    "protocol": "HtTp:",
    "slashes": true,
    "hostname": "x.y.cOm",
    "pathname": ";A/b/c",
    "search": "?d=e",
    "hash": "#f g<h>i"
  },

  "http://x...y...#p": {
    "protocol": "http:",
    "slashes": true,
    "hostname": "x...y...",
    "hash": "#p",
    "pathname": ""
  },

  "http://x/p/\"quoted\"": {
    "protocol": "http:",
    "slashes": true,
    "hostname": "x",
    "pathname": "/p/\"quoted\""
  },

  "<http://goo.corn/bread> Is a URL!": {
    "pathname": "<http://goo.corn/bread> Is a URL!"
  },

  "http://www.narwhaljs.org/blog/categories?id=news" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "www.narwhaljs.org",
    "search": "?id=news",
    "pathname": "/blog/categories"
  },

  "http://mt0.google.com/vt/lyrs=m@114&hl=en&src=api&x=2&y=2&z=3&s=" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "mt0.google.com",
    "pathname": "/vt/lyrs=m@114&hl=en&src=api&x=2&y=2&z=3&s="
  },

  "http://mt0.google.com/vt/lyrs=m@114???&hl=en&src=api&x=2&y=2&z=3&s=" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "mt0.google.com",
    "search": "???&hl=en&src=api&x=2&y=2&z=3&s=",
    "pathname": "/vt/lyrs=m@114"
  },

  "http://user:pass@mt0.google.com/vt/lyrs=m@114???&hl=en&src=api&x=2&y=2&z=3&s=":
      {
        "protocol": "http:",
        "slashes": true,
        "auth": "user:pass",
        "hostname": "mt0.google.com",
        "search": "???&hl=en&src=api&x=2&y=2&z=3&s=",
        "pathname": "/vt/lyrs=m@114"
      },

  "file:///etc/passwd" : {
    "slashes": true,
    "protocol": "file:",
    "pathname": "/etc/passwd",
    "hostname": ""
  },

  "file://localhost/etc/passwd" : {
    "protocol": "file:",
    "slashes": true,
    "pathname": "/etc/passwd",
    "hostname": "localhost"
  },

  "file://foo/etc/passwd" : {
    "protocol": "file:",
    "slashes": true,
    "pathname": "/etc/passwd",
    "hostname": "foo"
  },

  "file:///etc/node/" : {
    "slashes": true,
    "protocol": "file:",
    "pathname": "/etc/node/",
    "hostname": ""
  },

  "file://localhost/etc/node/" : {
    "protocol": "file:",
    "slashes": true,
    "pathname": "/etc/node/",
    "hostname": "localhost"
  },

  "file://foo/etc/node/" : {
    "protocol": "file:",
    "slashes": true,
    "pathname": "/etc/node/",
    "hostname": "foo"
  },

  "http:/baz/../foo/bar" : {
    "protocol": "http:",
    "pathname": "/baz/../foo/bar"
  },

  "http://user:pass@example.com:8000/foo/bar?baz=quux#frag" : {
    "protocol": "http:",
    "slashes": true,
    "auth": "user:pass",
    "port": "8000",
    "hostname": "example.com",
    "hash": "#frag",
    "search": "?baz=quux",
    "pathname": "/foo/bar"
  },

  "//user:pass@example.com:8000/foo/bar?baz=quux#frag" : {
    "slashes": true,
    "auth": "user:pass",
    "port": "8000",
    "hostname": "example.com",
    "hash": "#frag",
    "search": "?baz=quux",
    "pathname": "/foo/bar"
  },

  "/foo/bar?baz=quux#frag" : {
    "hash": "#frag",
    "search": "?baz=quux",
    "pathname": "/foo/bar"
  },

  "http:/foo/bar?baz=quux#frag" : {
    "protocol": "http:",
    "hash": "#frag",
    "search": "?baz=quux",
    "pathname": "/foo/bar"
  },

  "mailto:foo@bar.com?subject=hello" : {
    "protocol": "mailto:",
    "auth" : "foo",
    "hostname" : "bar.com",
    "search": "?subject=hello"
  },

  "javascript:alert('hello');" : {
    "protocol": "javascript:",
    "pathname": "alert('hello');"
  },

  "xmpp:isaacschlueter@jabber.org" : {
    "protocol": "xmpp:",
    "auth": "isaacschlueter",
    "hostname": "jabber.org"
  },

  "http://atpass:foo%40bar@127.0.0.1:8080/path?search=foo#bar" : {
    "protocol" : "http:",
    "slashes": true,
    "auth" : "atpass:foo%40bar",
    "hostname" : "127.0.0.1",
    "port" : "8080",
    "pathname": "/path",
    "search" : "?search=foo",
    "hash" : "#bar"
  },

  "svn+ssh://foo/bar": {
    "hostname": "foo",
    "protocol": "svn+ssh:",
    "pathname": "/bar",
    "slashes": true
  },

  "dash-test://foo/bar": {
    "hostname": "foo",
    "protocol": "dash-test:",
    "pathname": "/bar",
    "slashes": true
  },

  "dash-test:foo/bar": {
    "hostname": "foo",
    "protocol": "dash-test:",
    "pathname": "/bar"
  },

  "dot.test://foo/bar": {
    "hostname": "foo",
    "protocol": "dot.test:",
    "pathname": "/bar",
    "slashes": true
  },

  "dot.test:foo/bar": {
    "hostname": "foo",
    "protocol": "dot.test:",
    "pathname": "/bar"
  },

  "http://www.日本語.com/" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "www.日本語.com",
    "pathname": "/"
  },

  "http://example.Bücher.com/" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "example.Bücher.com",
    "pathname": "/"
  },

  "http://www.Äffchen.com/" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "www.Äffchen.com",
    "pathname": "/"
  },

  "http://www.Äffchen.cOm;A/b/c?d=e#f g<h>i" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "www.Äffchen.cOm",
    "pathname": ";A/b/c",
    "search": "?d=e",
    "hash": "#f g<h>i"
  },

  "http://SÉLIER.COM/" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "SÉLIER.COM",
    "pathname": "/"
  },

  "http://ليهمابتكلموشعربي؟.ي؟/" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "ليهمابتكلموشعربي؟.ي؟",
    "pathname": "/"
  },

  "http://➡.ws/➡" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "➡.ws",
    "pathname": "/➡"
  },

  "http://bucket_name.s3.amazonaws.com/image.jpg": {
    "protocol":"http:",
    "slashes":true,
    "hostname":"bucket_name.s3.amazonaws.com",
    "pathname":"/image.jpg"
  },

  "git+http://github.com/joyent/node.git": {
    "protocol":"git+http:",
    "slashes":true,
    "hostname":"github.com",
    "pathname":"/joyent/node.git"
  },

  "local1@domain1": {
    "pathname": "local1@domain1"
  },

  "www.example.com" : {
    "pathname": "www.example.com"
  },

  "[fe80::1]": {
    "pathname": "[fe80::1]"
  },

  "coap://[FEDC:BA98:7654:3210:FEDC:BA98:7654:3210]": {
    "protocol": "coap:",
    "slashes": true,
    "hostname": "FEDC:BA98:7654:3210:FEDC:BA98:7654:3210"
  },

  "coap://[1080:0:0:0:8:800:200C:417A]:61616/": {
    "protocol": "coap:",
    "slashes": true,
    "port": "61616",
    "hostname": "1080:0:0:0:8:800:200C:417A",
    "pathname": "/"
  },

  "http://user:password@[3ffe:2a00:100:7031::1]:8080": {
    "protocol": "http:",
    "slashes": true,
    "auth": "user:password",
    "port": "8080",
    "hostname": "3ffe:2a00:100:7031::1",
    "pathname": ""
  },

  "coap://u:p@[::192.9.5.5]:61616/.well-known/r?n=Temperature": {
    "protocol": "coap:",
    "slashes": true,
    "auth": "u:p",
    "port": "61616",
    "hostname": "::192.9.5.5",
    "search": "?n=Temperature",
    "pathname": "/.well-known/r"
  },

  "http://example.com:": {
    "protocol": "http:",
    "slashes": true,
    "hostname": "example.com",
    "pathname": ":"
  },

  "http://example.com:/a/b.html": {
    "protocol": "http:",
    "slashes": true,
    "hostname": "example.com",
    "pathname": ":/a/b.html"
  },

  "http://example.com:?a=b": {
    "protocol": "http:",
    "slashes": true,
    "hostname": "example.com",
    "search": "?a=b",
    "pathname": ":"
  },

  "http://example.com:#abc": {
    "protocol": "http:",
    "slashes": true,
    "hostname": "example.com",
    "hash": "#abc",
    "pathname": ":"
  },

  "http://[fe80::1]:/a/b?a=b#abc": {
    "protocol": "http:",
    "slashes": true,
    "hostname": "fe80::1",
    "search": "?a=b",
    "hash": "#abc",
    "pathname": ":/a/b"
  },

  "http://-lovemonsterz.tumblr.com/rss": {
    "protocol": "http:",
    "slashes": true,
    "hostname": "-lovemonsterz.tumblr.com",
    "pathname": "/rss"
  },

  "http://-lovemonsterz.tumblr.com:80/rss": {
    "protocol": "http:",
    "slashes": true,
    "port": "80",
    "hostname": "-lovemonsterz.tumblr.com",
    "pathname": "/rss"
  },

  "http://user:pass@-lovemonsterz.tumblr.com/rss": {
    "protocol": "http:",
    "slashes": true,
    "auth": "user:pass",
    "hostname": "-lovemonsterz.tumblr.com",
    "pathname": "/rss"
  },

  "http://user:pass@-lovemonsterz.tumblr.com:80/rss": {
    "protocol": "http:",
    "slashes": true,
    "auth": "user:pass",
    "port": "80",
    "hostname": "-lovemonsterz.tumblr.com",
    "pathname": "/rss"
  },

  "http://_jabber._tcp.google.com/test": {
    "protocol": "http:",
    "slashes": true,
    "hostname": "_jabber._tcp.google.com",
    "pathname": "/test"
  },

  "http://user:pass@_jabber._tcp.google.com/test": {
    "protocol": "http:",
    "slashes": true,
    "auth": "user:pass",
    "hostname": "_jabber._tcp.google.com",
    "pathname": "/test"
  },

  "http://_jabber._tcp.google.com:80/test": {
    "protocol": "http:",
    "slashes": true,
    "port": "80",
    "hostname": "_jabber._tcp.google.com",
    "pathname": "/test"
  },

  "http://user:pass@_jabber._tcp.google.com:80/test": {
    "protocol": "http:",
    "slashes": true,
    "auth": "user:pass",
    "port": "80",
    "hostname": "_jabber._tcp.google.com",
    "pathname": "/test"
  },

  "http://x:1/' <>\"`/{}|\\\\^~`/": {
    "protocol":"http:",
    "slashes":true,
    "port":"1",
    "hostname":"x",
    "pathname":"/' <>\"`/{}|\\\\^~`/"
  },

  "http://a@b@c/": {
    "protocol":"http:",
    "slashes":true,
    "auth":"a@b",
    "hostname":"c",
    "pathname":"/"
  },

  "http://a@b?@c": {
    "protocol":"http:",
    "slashes":true,
    "auth":"a",
    "hostname":"b",
    "pathname":"",
    "search":"?@c"
  },

  "http://a\\r\" \\t\\n<'b:b@c\\r\\nd/e?f":{
    "protocol":"http:",
    "slashes":true,
    "auth":"a\\r\" \\t\\n<'b:b",
    "hostname":"c",
    "search":"?f",
    "pathname":"\\r\\nd/e"
  },

  "git+ssh://git@github.com:npm/npm": {
    "protocol":"git+ssh:",
    "slashes":true,
    "auth":"git",
    "hostname":"github.com",
    "pathname":":npm/npm"
  },

  "http://example.com?foo=bar#frag" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "example.com",
    "hash": "#frag",
    "search": "?foo=bar",
    "pathname": ""
  },

  "http://example.com?foo=@bar#frag" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "example.com",
    "hash": "#frag",
    "search": "?foo=@bar",
    "pathname": ""
  },

  "http://example.com?foo=/bar/#frag" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "example.com",
    "hash": "#frag",
    "search": "?foo=/bar/",
    "pathname": ""
  },

  "http://example.com?foo=?bar/#frag" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "example.com",
    "hash": "#frag",
    "search": "?foo=?bar/",
    "pathname": ""
  },

  "http://example.com#frag=?bar/#frag" : {
    "protocol": "http:",
    "slashes": true,
    "hostname": "example.com",
    "hash": "#frag=?bar/#frag",
    "pathname": ""
  },

  "http://google.com\" onload=\"alert(42)/" : {
    "hostname": "google.com",
    "protocol": "http:",
    "slashes": true,
    "pathname": "\" onload=\"alert(42)/"
  },

  "http://a.com/a/b/c?s#h" : {
    "protocol": "http:",
    "slashes": true,
    "pathname": "/a/b/c",
    "hostname": "a.com",
    "hash": "#h",
    "search": "?s"
  },

  "http://atpass:foo%40bar@127.0.0.1/" : {
    "auth": "atpass:foo%40bar",
    "slashes": true,
    "hostname": "127.0.0.1",
    "protocol": "http:",
    "pathname": "/"
  },

  "http://atslash%2F%40:%2F%40@foo/" : {
    "auth": "atslash%2F%40:%2F%40",
    "hostname": "foo",
    "protocol": "http:",
    "pathname": "/",
    "slashes": true
  },

  "coap:u:p@[::1]:61616/.well-known/r?n=Temperature": {
    "protocol": "coap:",
    "auth": "u:p",
    "hostname": "::1",
    "port": "61616",
    "pathname": "/.well-known/r",
    "search": "?n=Temperature"
  },

  "coap:[fedc:ba98:7654:3210:fedc:ba98:7654:3210]:61616/s/stopButton": {
    "hostname": "fedc:ba98:7654:3210:fedc:ba98:7654:3210",
    "port": "61616",
    "protocol": "coap:",
    "pathname": "/s/stopButton"
  },


  "http://ex.com/foo%3F100%m%23r?abc=the%231?&foo=bar#frag": {
    "protocol":"http:",
    "hostname":"ex.com",
    "hash":"#frag",
    "search":"?abc=the%231?&foo=bar",
    "pathname":"/foo%3F100%m%23r",
    "slashes":true
  },

  "http://ex.com/fooA100%mBr?abc=the%231?&foo=bar#frag": {
    "protocol":"http:",
    "hostname":"ex.com",
    "hash":"#frag",
    "search":"?abc=the%231?&foo=bar",
    "pathname":"/fooA100%mBr",
    "slashes":true
  }
}
_JSON_;
    public static function get()
    {
        return json_decode(self::$json);
    }
}