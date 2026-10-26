**Known limitations**

* Mobile Detect script was designed to detect `mobile` devices. Implicitly other devices are considered to be `desktop`.
* User-Agent and HTTP headers sniffing is a non-reliable method of detecting a mobile device.
* If the mobile browser is set on `Desktop mode`, the Mobile Detect script has no indicator (eg. a group of strings) that would allow it to detect that the device is `mobile`.
* Ipad 2019 is being recognized as a desktop because of Safari's default `Request Desktop Website` setting. See details and possible workaround [#820](https://github.com/serbanghita/Mobile-Detect/issues/820)
  * Also see [#886](https://github.com/serbanghita/Mobile-Detect/issues/886#issuecomment-1047187763) 
* Some touchscreen devices (eg. Microsoft Surface) are tough to detect as mobile since they can be used in a laptop mode. See: [#32](https://github.com/serbanghita/Mobile-Detect/issues/32), [#461](https://github.com/serbanghita/Mobile-Detect/issues/461), [#667](https://github.com/serbanghita/Mobile-Detect/issues/667)
* Some mobile devices (eg. IPadOS, Google Pixel Slate). See: [#795](https://github.com/serbanghita/Mobile-Detect/issues/795), [#788](https://github.com/serbanghita/Mobile-Detect/issues/788)
* Detecting the device brand (eg. Apple, Samsung, HTC) is not 100% reliable.
* We don't monitor the quality of the 3rd party tools based on Mobile Detect script. 
We cannot guarantee that they are using the class properly or if they provide the latest version.
* Version `2.x` is made to be PHP 5.3 compatible because of the backward compatibility changes of PHP.
* There are hundreds of devices launched every month, we cannot keep a 100% up-to-date detection rate.
* The script cannot detect the viewport, pixel density or resolution of the screen since it's running server-side.
* **Full-page edge / proxy caches that don't key on User-Agent will defeat server-side detection.** When a CDN or page-cache plugin stores the rendered HTML and serves it to subsequent visitors without varying by `User-Agent`, the device class baked into the *first* cached response is delivered to everyone — PHP (and Mobile Detect) never run on a cache hit. This is an architectural property of full-page caches, not a bug in this library, and applies equally to any server-side branching (geo redirects, A/B tests, header-based locale, etc.). Known examples reported against this project:
  * WP Engine *Edge Full Page Cache* — disable it on routes that branch on UA. See [#980](https://github.com/serbanghita/Mobile-Detect/issues/980).
  * W3 Total Cache (WordPress plugin). See [#447](https://github.com/serbanghita/Mobile-Detect/issues/447).
  * Cloudflare + Google Signed Exchanges (SXG). See [#945](https://github.com/serbanghita/Mobile-Detect/issues/945).
  * Possible mitigations belong to the host/CDN config, not the library: disable the cache on UA-sensitive routes, add `Vary: User-Agent` if the CDN honors it, classify UA at the edge (Cloudflare Workers, Akamai EdgeWorkers, Vercel Middleware) and use the result as part of the cache key, or move device-specific logic client-side.
