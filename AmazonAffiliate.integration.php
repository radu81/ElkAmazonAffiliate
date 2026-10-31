<?php

/**
 * @package Amazon Affiliate Addon for ElkArte
 * @author Thorsten Eurich (original), updated for ElkArte 1.1.x / 2.0
 * @copyright (c) 2014 Thorsten Eurich
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 2.0.0
 *
 * Works on ElkArte 1.1.x and 2.0 (PHP 7.0+ / 8.x). No namespaces and no
 * PHP 8-only syntax on purpose, so the same file runs on both branches.
 */

if (!defined('ELK'))
{
	die('No access...');
}

/* ------------------------------------------------------------------------
 * Hook entry points
 * --------------------------------------------------------------------- */

/**
 * integrate_post_bbc_parser
 *
 * Runs on the output of every BBC parse: topics, previews, PMs, search,
 * recent posts, profile post lists, signatures, news...
 *
 * @param string $message parsed HTML
 */
function amazon_affiliate_post_bbc(&$message)
{
	$message = AmazonAffiliate::processHtml($message);
}

/**
 * integrate_preparse_tokenized_code
 *
 * Runs while a post/PM/signature is being saved, with [code] blocks already
 * protected. Resolves Amazon short links (amzn.eu, amzn.to, a.co...) in advance
 * so the first view is already fast. The message itself is NOT modified: the
 * link stays exactly as the user wrote it, only the href gets the tag on display.
 *
 * @param string $message raw BBC (already htmlspecialchar'ed)
 * @param bool $previewing
 * @param array $code_blocks
 */
function amazon_affiliate_preparse(&$message, $previewing = false, $code_blocks = array())
{
	AmazonAffiliate::prefetchShortLinks($message);
}

/**
 * integrate_general_mod_settings
 *
 * Adds the settings to Admin > Configuration > Addons Settings (1.1 and 2.0)
 *
 * @param array $config_vars
 */
function amazon_affiliate_settings(&$config_vars)
{
	global $txt;

	AmazonAffiliate::loadLanguage();

	$config_vars = array_merge($config_vars, array(
		array('title', 'amazon_affiliate_title'),
		array('text', 'amazon_affiliate_id', 'subtext' => $txt['amazon_affiliate_id_desc']),
		array('check', 'amazon_affiliate_replace_existing', 'subtext' => $txt['amazon_affiliate_replace_existing_desc']),
		array('check', 'amazon_affiliate_resolve_short', 'subtext' => $txt['amazon_affiliate_resolve_short_desc']),
		array('check', 'amazon_affiliate_icon'),
	));
}

/**
 * integrate_load_theme
 *
 * Loads the icon CSS only when it is actually useful.
 */
function amazon_affiliate_load_theme()
{
	global $modSettings;

	if (AmazonAffiliate::getTag() !== '' && !empty($modSettings['amazon_affiliate_icon']))
	{
		loadCSSFile('amazon.css');
	}
}

/* ------------------------------------------------------------------------
 * Core logic
 * --------------------------------------------------------------------- */

class AmazonAffiliate
{
	/** Amazon retail marketplaces (host without "www.") */
	protected static $marketplaces = array(
		'amazon.com', 'amazon.ca', 'amazon.com.mx', 'amazon.com.br',
		'amazon.co.uk', 'amazon.ie', 'amazon.de', 'amazon.fr', 'amazon.it',
		'amazon.es', 'amazon.nl', 'amazon.se', 'amazon.pl', 'amazon.com.be',
		'amazon.com.tr', 'amazon.ae', 'amazon.sa', 'amazon.eg', 'amazon.co.za',
		'amazon.in', 'amazon.co.jp', 'amazon.com.au', 'amazon.sg', 'amazon.cn',
	);

	/** Amazon short-link hosts: they only redirect, a ?tag= appended to them is lost */
	protected static $shorteners = array('amzn.eu', 'amzn.to', 'amzn.asia', 'amzn.in', 'amzn.com', 'a.co');

	/** Max short links resolved over the network per page request */
	const MAX_RESOLVE_VIEW = 5;

	/** Max short links resolved over the network when a post is saved */
	const MAX_RESOLVE_SAVE = 15;

	/** Network timeout in seconds for each redirect hop */
	const TIMEOUT = 4;

	/** How long a failed resolution is remembered before retrying (seconds) */
	const RETRY_FAILED_AFTER = 3600;

	/** Max entries kept in the persistent resolution store */
	const STORE_MAX = 5000;

	protected static $tag = null;
	protected static $resolved_count = 0;
	protected static $store = null;
	protected static $store_dirty = false;

	/**
	 * Returns the configured tracking ID, or '' if missing/invalid.
	 * Only letters, digits, "-" and "_" are accepted (e.g. mysite-21).
	 */
	public static function getTag()
	{
		global $modSettings;

		if (self::$tag === null)
		{
			$tag = isset($modSettings['amazon_affiliate_id']) ? trim($modSettings['amazon_affiliate_id']) : '';
			self::$tag = preg_match('~^[A-Za-z0-9_-]{1,64}$~', $tag) ? $tag : '';
		}

		return self::$tag;
	}

	/**
	 * Loads the language strings on both ElkArte 1.1 and 2.0
	 */
	public static function loadLanguage()
	{
		if (class_exists('\\ElkArte\\Languages\\Txt'))
		{
			\ElkArte\Languages\Txt::load('AmazonAffiliate');
		}
		else
		{
			loadLanguage('AmazonAffiliate');
		}
	}

	/**
	 * Rewrites every <a> pointing to Amazon in a chunk of parsed HTML
	 *
	 * @param string $html
	 * @return string
	 */
	public static function processHtml($html)
	{
		if (self::getTag() === '' || !is_string($html) || $html === '')
		{
			return $html;
		}

		// Cheap check first, most messages have no Amazon links at all
		if (stripos($html, 'amazon.') === false && stripos($html, 'amzn.') === false && stripos($html, 'a.co/') === false)
		{
			return $html;
		}

		$result = preg_replace_callback('~<a\s[^>]*>~i', array(__CLASS__, 'processAnchorCallback'), $html);
		self::saveStore();

		return $result === null ? $html : $result;
	}

	/**
	 * preg callback for a single opening <a ...> tag
	 *
	 * @param array $m
	 * @return string
	 */
	public static function processAnchorCallback($m)
	{
		$anchor = $m[0];

		if (!preg_match('~\shref\s*=\s*("|\')(.*?)\1~is', $anchor, $href, PREG_OFFSET_CAPTURE))
		{
			return $anchor;
		}

		$url = htmlspecialchars_decode($href[2][0], ENT_QUOTES);
		$new_url = self::affiliateUrl($url, true);

		if ($new_url === false)
		{
			return $anchor;
		}

		// Replace the href value
		$encoded = htmlspecialchars($new_url, ENT_QUOTES, 'UTF-8');
		$anchor = substr_replace($anchor, $encoded, $href[2][1], strlen($href[2][0]));

		// Mark it, so CSS can add the icon (and to recognise it later)
		$classes = array('amazon_affiliate');
		global $modSettings;
		if (!empty($modSettings['amazon_affiliate_icon']))
		{
			$classes[] = 'amazon_icon';
		}
		$anchor = self::mergeAttribute($anchor, 'class', $classes);

		// Affiliate links should be flagged as sponsored (Google guidelines)
		$anchor = self::mergeAttribute($anchor, 'rel', array('sponsored', 'nofollow', 'noopener'));

		return $anchor;
	}

	/**
	 * Adds tokens to a space separated attribute (class, rel), creating it if needed
	 *
	 * @param string $anchor
	 * @param string $attr
	 * @param string[] $tokens
	 * @return string
	 */
	protected static function mergeAttribute($anchor, $attr, $tokens)
	{
		if (preg_match('~\s' . $attr . '\s*=\s*("|\')(.*?)\1~is', $anchor, $m, PREG_OFFSET_CAPTURE))
		{
			$current = preg_split('~\s+~', trim($m[2][0]), -1, PREG_SPLIT_NO_EMPTY);
			foreach ($tokens as $token)
			{
				if (!in_array($token, $current, true))
				{
					$current[] = $token;
				}
			}

			return substr_replace($anchor, implode(' ', $current), $m[2][1], strlen($m[2][0]));
		}

		return preg_replace('~^<a\s~i', '<a ' . $attr . '="' . implode(' ', $tokens) . '" ', $anchor, 1);
	}

	/**
	 * Builds the affiliate version of an Amazon URL.
	 *
	 * @param string $url
	 * @param bool $resolve_short follow amzn.eu / amzn.to / a.co redirects
	 * @return string|false the new URL, or false to leave the link untouched
	 */
	public static function affiliateUrl($url, $resolve_short = true)
	{
		global $modSettings;

		$tag = self::getTag();
		if ($tag === '')
		{
			return false;
		}

		$parts = self::parse($url);
		if ($parts === false)
		{
			return false;
		}

		// Short link: we need the real destination, a tag appended here is dropped by the redirect
		if (in_array(preg_replace('~^www\.~', '', $parts['host']), self::$shorteners, true))
		{
			if (!$resolve_short || empty($modSettings['amazon_affiliate_resolve_short']))
			{
				return false;
			}

			$resolved = self::resolveShortLink($url, self::MAX_RESOLVE_VIEW);
			if ($resolved === false)
			{
				return false;
			}

			$parts = self::parse($resolved);
			if ($parts === false)
			{
				return false;
			}
		}

		$domain = self::marketplace($parts['host']);
		if ($domain === false)
		{
			return false;
		}

		// Someone else's tag is already there: respect the admin choice
		$existing = self::queryParam($parts['query'], 'tag');
		if ($existing !== null && $existing !== $tag && empty($modSettings['amazon_affiliate_replace_existing']))
		{
			return false;
		}

		// Product page: rebuild a clean canonical link
		$asin = self::extractAsin($parts['path']);
		if ($asin !== false)
		{
			return 'https://www.' . $domain . '/dp/' . $asin . '/?tag=' . rawurlencode($tag);
		}

		// Any other page (search, store, deals...): keep it, just set the tag
		$query = self::removeQueryParams($parts['query'], array('tag'));
		$query = ($query === '' ? '' : $query . '&') . 'tag=' . rawurlencode($tag);

		return 'https://www.' . $domain . ($parts['path'] === '' ? '/' : $parts['path']) . '?' . $query . ($parts['fragment'] !== '' ? '#' . $parts['fragment'] : '');
	}

	/**
	 * Resolves the short links found in raw BBC and stores the result, without
	 * changing the message. Called when a message is saved or previewed.
	 *
	 * @param string $message
	 */
	public static function prefetchShortLinks($message)
	{
		global $modSettings;

		if (self::getTag() === '' || empty($modSettings['amazon_affiliate_resolve_short']) || !is_string($message))
		{
			return;
		}

		if (stripos($message, 'amzn.') === false && stripos($message, 'a.co/') === false)
		{
			return;
		}

		if (preg_match_all('~\bhttps?://(?:www\.)?(?:amzn\.(?:eu|to|asia|in|com)|a\.co)/[A-Za-z0-9_\-/]+~i', $message, $matches))
		{
			foreach (array_unique($matches[0]) as $url)
			{
				self::resolveShortLink($url, self::MAX_RESOLVE_SAVE);
			}
			self::saveStore();
		}
	}

	/* --------------------------------------------------------------------
	 * URL helpers
	 * ----------------------------------------------------------------- */

	/**
	 * @param string $url
	 * @return array|false host (lowercase), path, query, fragment
	 */
	protected static function parse($url)
	{
		$url = trim($url);
		if (!preg_match('~^https?://~i', $url))
		{
			return false;
		}

		$p = @parse_url($url);
		if ($p === false || empty($p['host']))
		{
			return false;
		}

		return array(
			'host' => strtolower($p['host']),
			'path' => isset($p['path']) ? $p['path'] : '',
			'query' => isset($p['query']) ? $p['query'] : '',
			'fragment' => isset($p['fragment']) ? $p['fragment'] : '',
		);
	}

	/**
	 * Returns the marketplace domain (e.g. amazon.it) for a retail Amazon host,
	 * false for anything else (aws.amazon.com, sellercentral, fake domains...)
	 *
	 * @param string $host
	 * @return string|false
	 */
	protected static function marketplace($host)
	{
		// smile.amazon.* was shut down in 2023, m. is the old mobile host
		$host = preg_replace('~^(?:www\.|smile\.|m\.)~', '', $host);

		return in_array($host, self::$marketplaces, true) ? $host : false;
	}

	/**
	 * @param string $path
	 * @return string|false
	 */
	protected static function extractAsin($path)
	{
		if (preg_match('~/(?:dp(?:/product)?|gp/product|gp/aw/d|gp/offer-listing|exec/obidos/(?:tg/detail/-|ASIN)|o/ASIN)/([A-Z0-9]{10})(?=[/?#;]|$)~i', $path, $m))
		{
			return strtoupper($m[1]);
		}

		return false;
	}

	/**
	 * @param string $query raw query string
	 * @param string $name
	 * @return string|null
	 */
	protected static function queryParam($query, $name)
	{
		foreach (explode('&', $query) as $pair)
		{
			$kv = explode('=', $pair, 2);
			if (strtolower(urldecode($kv[0])) === $name)
			{
				return isset($kv[1]) ? urldecode($kv[1]) : '';
			}
		}

		return null;
	}

	/**
	 * Removes some parameters from a raw query string, leaving the rest byte-for-byte
	 *
	 * @param string $query
	 * @param string[] $names lowercase names
	 * @return string
	 */
	protected static function removeQueryParams($query, $names)
	{
		if ($query === '')
		{
			return '';
		}

		$keep = array();
		foreach (explode('&', $query) as $pair)
		{
			if ($pair === '')
			{
				continue;
			}

			$kv = explode('=', $pair, 2);
			if (!in_array(strtolower(urldecode($kv[0])), $names, true))
			{
				$keep[] = $pair;
			}
		}

		return implode('&', $keep);
	}

	/* --------------------------------------------------------------------
	 * Short link resolution
	 * ----------------------------------------------------------------- */

	/**
	 * Follows the redirects of an Amazon short link until it lands on a
	 * marketplace URL. Only shortener hosts are ever contacted (no SSRF,
	 * and amazon.* itself is never fetched, so no bot-blocking issues).
	 * Results are stored persistently in the cache directory.
	 *
	 * @param string $url
	 * @param int $limit max network lookups allowed in this request
	 * @return string|false
	 */
	public static function resolveShortLink($url, $limit)
	{
		// Short codes are case sensitive: only the host is normalised
		$parts = self::parse($url);
		if ($parts === false)
		{
			return false;
		}
		$key = preg_replace('~^www\.~', '', $parts['host']) . rtrim($parts['path'], '/');

		self::loadStore();
		if (isset(self::$store[$key]))
		{
			$entry = self::$store[$key];
			if ($entry['u'] !== '')
			{
				return $entry['u'];
			}

			if (time() - $entry['t'] < self::RETRY_FAILED_AFTER)
			{
				return false;
			}
		}

		if (self::$resolved_count >= $limit)
		{
			return false;
		}
		self::$resolved_count++;

		$final = self::followRedirects('https://' . $key);

		self::$store[$key] = array('u' => $final === false ? '' : $final, 't' => time());
		self::$store_dirty = true;

		return $final;
	}

	/**
	 * @param string $url
	 * @return string|false
	 */
	protected static function followRedirects($url)
	{
		for ($hop = 0; $hop < 5; $hop++)
		{
			$parts = self::parse($url);
			if ($parts === false)
			{
				return false;
			}

			// Arrived on a marketplace: done, no need to load the product page
			if (self::marketplace($parts['host']) !== false)
			{
				return $url;
			}

			// Never contact anything that is not an Amazon shortener
			if (!in_array(preg_replace('~^www\.~', '', $parts['host']), self::$shorteners, true))
			{
				return false;
			}

			$location = self::getLocation($url);
			if ($location === false)
			{
				return false;
			}

			// Relative redirect
			if (strpos($location, '//') === 0)
			{
				$location = 'https:' . $location;
			}
			elseif (strpos($location, '/') === 0)
			{
				$location = 'https://' . $parts['host'] . $location;
			}

			$url = $location;
		}

		return false;
	}

	/**
	 * One HTTP request, returns the Location header (no automatic redirects)
	 *
	 * @param string $url
	 * @return string|false
	 */
	protected static function getLocation($url)
	{
		$ua = 'Mozilla/5.0 (compatible; ElkArte AmazonAffiliate; +' . (isset($GLOBALS['boardurl']) ? $GLOBALS['boardurl'] : '') . ')';

		if (function_exists('curl_init'))
		{
			$ch = curl_init($url);
			curl_setopt_array($ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => true,
				CURLOPT_NOBODY => false,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
				CURLOPT_TIMEOUT => self::TIMEOUT,
				CURLOPT_USERAGENT => $ua,
				CURLOPT_RANGE => '0-0',
				CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			));
			$response = curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
			curl_close($ch);

			if ($response === false || $code < 300 || $code >= 400)
			{
				return false;
			}

			if (!empty($location))
			{
				return $location;
			}

			return preg_match('~^Location:\s*(\S+)~mi', $response, $m) ? $m[1] : false;
		}

		if (!ini_get('allow_url_fopen'))
		{
			return false;
		}

		$context = stream_context_create(array('http' => array(
			'method' => 'GET',
			'follow_location' => 0,
			'max_redirects' => 0,
			'timeout' => self::TIMEOUT,
			'ignore_errors' => true,
			'user_agent' => $ua,
		)));

		$http_response_header = array();
		@file_get_contents($url, false, $context, 0, 1);
		$headers = function_exists('http_get_last_response_headers') ? (array) http_get_last_response_headers() : $http_response_header;

		foreach ($headers as $header)
		{
			if (preg_match('~^Location:\s*(\S+)~i', $header, $m))
			{
				return $m[1];
			}
		}

		return false;
	}

	/* --------------------------------------------------------------------
	 * Persistent store (works even with the forum cache disabled)
	 * ----------------------------------------------------------------- */

	protected static function storeFile()
	{
		return (defined('CACHEDIR') ? CACHEDIR : BOARDDIR . '/cache') . '/amazon_affiliate_links.json';
	}

	protected static function loadStore()
	{
		if (self::$store !== null)
		{
			return;
		}

		self::$store = array();
		$file = self::storeFile();
		if (is_readable($file))
		{
			$data = json_decode((string) @file_get_contents($file), true);
			if (is_array($data))
			{
				self::$store = $data;
			}
		}
	}

	protected static function saveStore()
	{
		if (!self::$store_dirty)
		{
			return;
		}

		// Keep only the most recent entries
		if (count(self::$store) > self::STORE_MAX)
		{
			uasort(self::$store, function ($a, $b) {
				return $b['t'] - $a['t'];
			});
			self::$store = array_slice(self::$store, 0, self::STORE_MAX, true);
		}

		@file_put_contents(self::storeFile(), json_encode(self::$store), LOCK_EX);
		self::$store_dirty = false;
	}
}
