# ElkAmazonAffiliate 2.0.0

Amazon affiliate links for ElkArte **1.1.x** and **2.0** (single package, the package manager picks the right install block).

## Changes from 1.0
- Works on ElkArte 2.0 (language files in the new `Languages/AmazonAffiliate/` layout).
- Settings moved to Admin > Configuration > Addons Settings.
- Fixed: uninstall block only matched 1.0.x, so on 1.1 the addon could not be uninstalled. Upgrading from 1.0 removes the old hooks and keeps the affiliate ID.
- Fixed: tag corrupted by query strings containing `+`, `:`, `~`... (e.g. `tag=mysite-21+b:c`).
- Fixed: `http://` links were ignored.
- Fixed: non-Amazon links containing "amazon." and "/dp/ASIN" anywhere in the URL were rewritten to Amazon.
- New: Amazon short links (amzn.eu, amzn.to, amzn.asia, amzn.in, amzn.com, a.co) are resolved, so they get the tag. The post keeps the link exactly as the user wrote it, only the href gets the tag.
- New: works everywhere BBC is parsed (topics, PMs, previews, search, recent posts, signatures), not just topic display.
- New: non-product Amazon pages (search, stores) get the tag too.
- New: options to keep existing third-party tags and to hide the icon; affiliate links get `rel="sponsored nofollow"`.
- Icon embedded in the CSS (no image file to install), shown only on rewritten links.
- Tracking ID validated (letters, digits, `-`, `_`).
- Italian translation.

License: MPL 1.1
