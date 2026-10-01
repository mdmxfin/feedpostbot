<?php
/**
 *
 * Feed post bot. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace ger\feedpostbot\classes;

class driver
{
	public const FEED_TIMEOUT_DEFAULT = 10;
	public const FEED_TIMEOUT_PARSE = 3;
	public const LOG_CRITICAL = 'critical';
	public const LOG_ADMIN = 'admin';
	public const LOG_FEED_FETCHED = 'FPB_LOG_FEED_FETCHED';
	public const MAX_RUN_SECONDS = 300;
	public const HISTORY_LIMIT = 10000;
	public const LOG_FEED_ERROR = 'FPB_LOG_FEED_ERROR';
	public const LANG_READ_MORE = 'FPB_READ_MORE';
	public const LANG_SOURCE = 'FPB_SOURCE';
	/** Bump when the meaning of source/content fingerprints changes; tracked items are then re-baselined silently. */
	public const FINGERPRINT_VERSION = 1;

	protected $config;
	protected $config_text;
	protected $user;
	protected $language;
	protected $auth;
	protected $db;
	protected $log;
	protected $phpbb_root_path;
	protected $php_ext;
	protected $phpbb_dispatcher;
	/** @var item_tracker|null Storage for tracked feed items, null when update tracking is unavailable */
	protected $tracker;
	public $current_state;

	/** @var array Cache for forum names */
	protected $forum_name_cache = array();

	/** @var array Cache for user data rows */
	protected $user_data_cache = array();
	protected $forum_data_cache = array();
	protected $destinations_loaded = false;
	protected $run_deadline = 0;

	/**
	 * Constructor
	 *
	 * @param \phpbb\config\config							$config				Config object
	 * @param \phpbb\config\db_text							$config_text		Config text object
	 * @param \phpbb\user									$user				User object
	 * @param \phpbb\language\language						$language			Language object
	 * @param \phpbb\auth\auth								$auth				Auth object
	 * @param \phpbb\db\driver\driver_interface				$db					DB object
	 * @param string										$phpbb_root_path
	 * @param string										$php_ext
	 * @param \phpbb\event\dispatcher						$phpbb_dispatcher
	 * @param item_tracker|null								$tracker			Tracked item storage
	 */
	public function __construct(\phpbb\config\config $config, \phpbb\config\db_text $config_text, \phpbb\user $user, \phpbb\language\language $language, \phpbb\auth\auth $auth, \phpbb\db\driver\driver_interface $db, \phpbb\log\log $log, $phpbb_root_path, $php_ext, \phpbb\event\dispatcher $phpbb_dispatcher, ?item_tracker $tracker = null)
	{
		$this->config = $config;
		$this->config_text = $config_text;
		$this->user = $user;
		$this->language = $language;
		$this->auth = $auth;
		$this->db = $db;
		$this->log = $log;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		$this->phpbb_dispatcher = $phpbb_dispatcher;
		$this->tracker = $tracker;
	}

	/**
	 * Set and return current state
	 */
	public function init_current_state()
	{
		$ct = $this->config_text->get('ger_feedpostbot_current_state');
		if (empty($ct) || $ct === 'null')
		{
			$this->current_state = array();
		}
		else
		{
			$decoded = json_decode($ct, true);
			if (!is_array($decoded))
			{
				$this->current_state = array();
			}
			else
			{
				$this->current_state = $decoded;
				$this->check_state_parameters();
			}
		}
		return $this->current_state;
	}

	/**
	 * Make sure we have all parameters set
	 */
	private function check_state_parameters()
	{
		if (!is_array($this->current_state))
		{
			$this->current_state = array();
			return;
		}

		$new_state = array();
		foreach ($this->current_state as $id => $source)
		{
			if (is_array($source) && isset($source['append_link']))
			{
				$new_state[$id] = $source;
			}
			else if (is_array($source))
			{
				$new = $source;
				$new['append_link'] = 1;
				$new_state[$id] = $new;
			}
		}
		$this->current_state = $new_state;
	}

	/**
	 * Fetch all feeds
	 * This is called by the cron handler
	 * @return int
	 */
	public function fetch_all()
	{
		if (empty($this->current_state))
		{
			$this->init_current_state();
		}
		$counter = 0;
		if (empty($this->current_state))
		{
			return 0;
		}
		$lock = $this->create_lock();
		if (!$lock->acquire())
		{
			return 0;
		}
		$context = $this->capture_user_context();
		$max_time = (int) ini_get('max_execution_time');
		$run_limit = ($max_time > 0 && $max_time < self::MAX_RUN_SECONDS) ? max(5, $max_time - 5) : self::MAX_RUN_SECONDS;
		$this->run_deadline = microtime(true) + $run_limit;
		try
		{
			// Reload after acquiring the lock, since another worker may have just finished.
			$this->init_current_state();
			$this->prepare_destinations($this->current_state);
			$queue = $this->current_state;
			uasort($queue, function ($left, $right) {
				return ($left['last_attempt'] ?? 0) <=> ($right['last_attempt'] ?? 0);
			});
			foreach ($queue as $id => $source)
			{
				if (microtime(true) >= $this->run_deadline)
				{
					break;
				}
				if (empty($source['forum_id']))
				{
					continue;
				}
				$this->current_state[$id]['last_attempt'] = microtime(true);
				try
				{
					if (!$this->valid_source($source))
					{
						$this->log_feed_error($source['url'], 'FPB_SETTINGS_INVALID');
						continue;
					}
					$counter += $this->fetch_items($this->parse_feed($source['url'], $source['type'], $source['timeout']), $id);
				}
				catch (\Throwable $error)
				{
					$detail = $error->getMessage();
					$msg = $detail !== '' ? $this->language->lang('FPB_PROCESSING_FAILED') . ' (' . $detail . ')' : 'FPB_PROCESSING_FAILED';
					$this->log_feed_error($source['url'], $msg);
				}
				finally
				{
					$this->restore_user_context($context);
				}
			}
		}
		finally
		{
			try
			{
				$this->save_progress();
			}
			finally
			{
				try
				{
					$this->restore_user_context($context);
				}
				finally
				{
					$this->run_deadline = 0;
					$lock->release();
				}
			}
		}
		return $counter;
	}

	protected function create_lock()
	{
		// phpBB provides atomic ownership and recovery after a one-hour expiry.
		return new \phpbb\lock\db('feedpostbot_locked', $this->config, $this->db);
	}

	/** Preserve concurrent ACP edits; only merge progress for unchanged feed URLs. */
	private function save_progress()
	{
		$saved = json_decode($this->config_text->get('ger_feedpostbot_current_state'), true);
		if (!is_array($saved))
		{
			return;
		}
		foreach ($this->current_state as $id => $source)
		{
			$target_id = (isset($saved[$id]) && rtrim($saved[$id]['url'], '/') === rtrim($source['url'], '/')) ? $id : null;
			if ($target_id === null)
			{
				foreach ($saved as $sid => $s)
				{
					if (rtrim($s['url'], '/') === rtrim($source['url'], '/'))
					{
						$target_id = $sid;
						break;
					}
				}
			}
			if ($target_id !== null)
			{
				$saved[$target_id]['latest'] = $source['latest'];
				$saved[$target_id]['last_attempt'] = $source['last_attempt'] ?? 0;
				if (isset($source['handled']))
				{
					$saved[$target_id]['handled'] = $source['handled'];
				}
			}
		}
		$this->config_text->set('ger_feedpostbot_current_state', json_encode($saved));
	}

	/** Save ACP changes against fresh progress while holding the worker's lock. */
	public function save_sources(array $proposed, array $original)
	{
		$lock = $this->create_lock();
		if (!$lock->acquire())
		{
			return false;
		}
		try
		{
			$fresh = $this->init_current_state();
			$settings = function (array $sources) {
				foreach ($sources as &$source)
				{
					unset($source['handled'], $source['latest'], $source['last_attempt']);
				}
				return $sources;
			};
			if ($settings($fresh) !== $settings($original))
			{
				return false;
			}
			foreach ($proposed as $id => &$source)
			{
				if (isset($fresh[$id]) && $fresh[$id]['url'] === $source['url'])
				{
					foreach (array('latest', 'handled', 'last_attempt') as $field)
					{
						unset($source[$field]);
						if (isset($fresh[$id][$field]))
						{
							$source[$field] = $fresh[$id][$field];
						}
					}
				}
			}
			unset($source);
			$this->config_text->set('ger_feedpostbot_current_state', json_encode($proposed));
			$this->current_state = $proposed;
			return true;
		}
		finally
		{
			$lock->release();
		}
	}

	private function capture_user_context()
	{
		return array('data' => $this->user->data, 'timezone' => $this->user->timezone,
			'date_format' => $this->user->date_format, 'lang_name' => $this->user->lang_name,
			'auth' => get_object_vars($this->auth));
	}

	private function restore_user_context(array $context)
	{
		foreach ($context['auth'] as $property => $value)
		{
			$this->auth->$property = $value;
		}
		unset($context['auth']);
		foreach ($context as $property => $value)
		{
			$this->user->$property = $value;
		}
		$language = !empty($context['lang_name']) ? $context['lang_name'] :
			(!empty($context['data']['user_lang']) ? $context['data']['user_lang'] : (!empty($this->config['default_lang']) ? $this->config['default_lang'] : 'en'));
		$this->language->set_user_language($language, true);
	}


	/**
	 * Get and configure a SimplePie instance
	 *
	 * @param string $url
	 * @param int $timeout
	 * @param string|null $raw_data
	 * @return \SimplePie\SimplePie|null
	 */
	public function get_simplepie_instance($url, $timeout = self::FEED_TIMEOUT_DEFAULT, $raw_data = null)
	{
		$base_url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
		if (!class_exists('\SimplePie\SimplePie') && !class_exists('\SimplePie'))
		{
			$autoloader = __DIR__ . '/../vendor/autoload.php';
			if (file_exists($autoloader))
			{
				require_once $autoloader;
			}
			else if (file_exists($this->phpbb_root_path . 'ext/ger/feedpostbot/vendor/autoload.php'))
			{
				require_once $this->phpbb_root_path . 'ext/ger/feedpostbot/vendor/autoload.php';
			}
			else if (file_exists($this->phpbb_root_path . 'vendor/autoload.php'))
			{
				require_once $this->phpbb_root_path . 'vendor/autoload.php';
			}
		}

		if (!class_exists('\SimplePie\SimplePie') && !class_exists('\SimplePie'))
		{
			return null;
		}

		if ($raw_data === null)
		{
			try
			{
				$client = $this->create_http_client();
				// Existing installations stored request strings with HTML entities.
				$raw_data = $client->get($base_url, $timeout);
				$base_url = $client->get_final_url();
			}
			catch (\RuntimeException $error)
			{
				if ($this->log && $this->language)
				{
					$this->log_feed_error($url, $error->getMessage());
				}
				return null;
			}
		}

		/** @var \SimplePie\SimplePie $feed */
		$feed = class_exists('\SimplePie\SimplePie') ? new \SimplePie\SimplePie() : new \SimplePie();
		$feed->enable_cache(false);
		$feed->set_raw_data($raw_data);
		$feed->set_autodiscovery_level(0);

		$feed->force_feed(true);
		$feed->set_output_encoding('UTF-8');
		$feed->init();
		// Set the base only after parsing; setting it before init() would trigger an unsafe second fetch.
		if (http_client::valid_url($base_url))
		{
			$feed->set_feed_url($base_url);
		}

		return $feed;
	}

	protected function create_http_client()
	{
		return new http_client();
	}

	/**
	 * Autodetect feed type using SimplePie
	 *
	 * @param string $url
	 * @param string|null $raw_data
	 * @return string|false
	 */
	public function detect_feed_type($url, $raw_data = null)
	{
		$feed = $this->get_simplepie_instance($url, self::FEED_TIMEOUT_DEFAULT, $raw_data);
		if (!$feed || $feed->error())
		{
			return false;
		}

		$type = (int) $feed->get_type();
		$atom_mask = defined('\SimplePie\SimplePie::TYPE_ATOM_ALL') ? \SimplePie\SimplePie::TYPE_ATOM_ALL : (defined('SIMPLEPIE_TYPE_ATOM_ALL') ? SIMPLEPIE_TYPE_ATOM_ALL : 768);
		$rdf_mask = defined('\SimplePie\SimplePie::TYPE_RSS_RDF') ? \SimplePie\SimplePie::TYPE_RSS_RDF : (defined('SIMPLEPIE_TYPE_RSS_RDF') ? SIMPLEPIE_TYPE_RSS_RDF : 65);

		if ($type === 64 || $type === 65 || ($type !== 1023 && ($type & $rdf_mask)))
		{
			return 'rdf';
		}

		if ($type !== 1023 && ($type & $atom_mask))
		{
			return 'atom';
		}

		if ($type === 1023 && !empty($raw_data))
		{
			if (stripos($raw_data, '<rdf:RDF') !== false)
			{
				return 'rdf';
			}
			else if (stripos($raw_data, '<feed') !== false)
			{
				return 'atom';
			}
		}

		return 'rss';
	}

	/**
	 * Parse a feed via SimplePie and return formatted item array
	 *
	 * @param string $url
	 * @param string $type
	 * @param int $timeout
	 * @return array
	 */
	public function parse_feed($url, $type, $timeout = self::FEED_TIMEOUT_PARSE)
	{
		$feed = $this->get_simplepie_instance($url, $timeout);
		if (!$feed)
		{
			return array();
		}

		if ($feed->error())
		{
			$this->log_feed_error($url, (string) $feed->error());
			return array();
		}

		$items = $feed->get_items();
		if (empty($items))
		{
			$this->log_feed_fetched($url);
			return array();
		}

		$return = array();
		$feed_type = strtolower((string) $type);
		if (empty($feed_type) || !in_array($feed_type, array('rss', 'atom', 'rdf'), true))
		{
			$type_flags = $feed->get_type();
			if (defined('SIMPLEPIE_TYPE_ATOM_10') && ($type_flags & (SIMPLEPIE_TYPE_ATOM_10 | SIMPLEPIE_TYPE_ATOM_03)))
			{
				$feed_type = 'atom';
			}
			else if (defined('SIMPLEPIE_TYPE_RSS_10') && ($type_flags & SIMPLEPIE_TYPE_RSS_10))
			{
				$feed_type = 'rdf';
			}
			else
			{
				$feed_type = 'rss';
			}
		}

		foreach ($items as $item)
		{
			$guid = $this->prop_to_string($item->get_id());
			$title = $this->prop_to_string($item->get_title());
			$link = $this->prop_to_string($item->get_permalink());
			$description = $item->get_content(true);
			if ($description === null || $description === '')
			{
				$description = $item->get_description(true);
			}
			if ($description === null || $description === '')
			{
				$description = $item->get_title();
			}
			$description = $this->prop_to_string($description);
			$date_u = $item->get_date('U');
			$pubDate = ($date_u !== null && $date_u !== false) ? (int) $date_u : 0;
			$author_obj = $item->get_author();
			$author = $author_obj ? $this->prop_to_string($author_obj->get_name()) : '';

			$append = array(
				'guid'        => $guid,
				// Only a real feed identifier; get_id() otherwise derives one from the content.
				'native_id'   => $this->prop_to_string($item->get_id(false, false)),
				'title'       => $title,
				'link'        => $link,
				'description' => $description,
				'pubDate'     => $pubDate,
				'author'      => $author,
			);

			/**
			 * Modify the fetched feed item before it's added to the return list
			 *
			 * @event ger.feedpostbot.parse_item_append
			 * @var  object item   item as found in source
			 * @var  array  append Array of properties to be sent to the post_message function
			 * @since 1.1.0
			 */
			$vars = array('item', 'append');
			$event_data = $this->phpbb_dispatcher->trigger_event('ger.feedpostbot.parse_item_append', compact($vars));
			if (is_array($event_data) || $event_data instanceof \ArrayAccess)
			{
				extract((array) $event_data);
			}

			/**
			 * Modify the fetched feed item before it's added to the return list (Backwards-compatible event)
			 *
			 * @event ger.feedpostbot.parse_rss_append
			 * @event ger.feedpostbot.parse_atom_append
			 * @event ger.feedpostbot.parse_rdf_append
			 * @var  object item   item as found in source
			 * @var  array  append Array of properties to be sent to the post_message function
			 * @since 1.0.1
			 */
			$event_name = 'ger.feedpostbot.parse_' . $feed_type . '_append';
			$event_data = $this->phpbb_dispatcher->trigger_event($event_name, compact($vars));
			if (is_array($event_data) || $event_data instanceof \ArrayAccess)
			{
				extract((array) $event_data);
			}

			$return[] = $append;
		}

		$this->log_feed_fetched($url);
		return $return;
	}


	/**
	 * Fetch the new content in feed
	 *
	 * @param array $items
	 * @param int $source_id
	 * @return int
	 */
	public function fetch_items($items, $source_id)
	{
		$posted = 0;
		if (empty($items) || !is_array($items) || !isset($this->current_state[$source_id]))
		{
			return $posted;
		}
		if (!$this->destinations_loaded)
		{
			$this->prepare_destinations($this->current_state);
		}
		$source = &$this->current_state[$source_id];
		if (!$this->valid_source($source))
		{
			return 0;
		}
		$context = $this->capture_user_context();
		try
		{
			if (!$this->switch_user($source['user_id']))
			{
				return 0;
			}
			if (!isset($source['handled']) || !is_array($source['handled']))
			{
				$source['handled'] = array();
				// Bootstrap the former single-marker history once, without reposting its older entries.
				$past_marker = false;
				foreach ($items as $item)
				{
					$past_marker = $past_marker || $this->is_handled($item, $source['latest']);
					if ($past_marker)
					{
						$source['handled'][$this->item_key($item)] = true;
					}
				}
			}
			// Inspect the entire feed: new items may be inserted below already known entries.
			foreach (array_reverse($items) as $item)
			{
				if ($this->run_deadline && microtime(true) >= $this->run_deadline)
				{
					break;
				}
				$key = $this->item_key($item);
				if (isset($source['handled'][$key]))
				{
					continue;
				}
				try
				{
					$result = $this->post_message($item, $source_id);
				}
				catch (\Throwable $error)
				{
					$detail = $error->getMessage();
					$msg = $detail !== '' ? $this->language->lang('FPB_PROCESSING_FAILED') . ' (' . $detail . ')' : 'FPB_PROCESSING_FAILED';
					$this->log_feed_error($source['url'], $msg);
					break;
				}
				if ($result === false)
				{
					break;
				}
				// true means an event deliberately consumed the item without submitting a post.
				$posted += $result === true ? 0 : 1;
				$source['handled'][$key] = true;
				$source['latest'] = array('guid' => isset($item['guid']) ? $item['guid'] : '',
					'link' => isset($item['link']) ? $item['link'] : '', 'pubDate' => isset($item['pubDate']) ? $item['pubDate'] : 0);
			}
		}
		finally
		{
			if (isset($source['handled']))
			{
				// Never evict identifiers that are still advertised by the current feed.
				$present = array();
				foreach ($items as $item)
				{
					$present[$this->item_key($item)] = true;
				}
				$absent = array_diff_key($source['handled'], $present);
				$source['handled'] = array_intersect_key($source['handled'], $present)
					+ array_slice($absent, -self::HISTORY_LIMIT, null, true);
			}
			$this->restore_user_context($context);
		}
		return $posted;
	}

	private function item_key(array $item)
	{
		if (!empty($item['guid']))
		{
			return hash('sha256', 'guid:' . $item['guid']);
		}
		if (!empty($item['link']))
		{
			return hash('sha256', 'link:' . $item['link']);
		}
		return hash('sha256', 'content:' . json_encode($item));
	}

	/**
	 * Stable identity of a feed item for update tracking
	 *
	 * Uses the real feed identifier, otherwise the normalized permalink. Content, title and
	 * dates are never used: an item without identifier and link is not tracked.
	 *
	 * @param array $item
	 * @return array|null key (sha256) and ident (raw identity), or null when not trackable
	 */
	public function tracking_key(array $item)
	{
		$native = isset($item['native_id']) ? trim((string) $item['native_id']) : '';
		if ($native !== '')
		{
			$ident = 'guid:' . $native;
		}
		else
		{
			$link = isset($item['link']) ? self::normalize_link((string) $item['link']) : '';
			if ($link === '')
			{
				return null;
			}
			$ident = 'link:' . $link;
		}
		return array('key' => hash('sha256', $ident), 'ident' => $ident);
	}

	/**
	 * Remove cosmetic differences from a permalink
	 *
	 * Lowercases scheme and host, drops default ports and fragments. Path and query are
	 * never changed because they often carry the actual article identifier.
	 *
	 * @param string $url
	 * @return string
	 */
	public static function normalize_link($url)
	{
		$url = trim((string) $url);
		$hash = strpos($url, '#');
		if ($hash !== false)
		{
			$url = substr($url, 0, $hash);
		}
		if (!preg_match('~^([a-z][a-z0-9+.-]*)://([^/?]*)(.*)$~is', $url, $parts))
		{
			return $url;
		}
		$scheme = strtolower($parts[1]);
		$authority = $parts[2];
		$userinfo = '';
		$at = strrpos($authority, '@');
		if ($at !== false)
		{
			$userinfo = substr($authority, 0, $at + 1);
			$authority = substr($authority, $at + 1);
		}
		$port = '';
		if (preg_match('~^(\[[^\]]*\]|[^:]*)(?::(\d*))?$~', $authority, $host_parts))
		{
			$host = $host_parts[1];
			$port = isset($host_parts[2]) ? $host_parts[2] : '';
		}
		else
		{
			$host = $authority;
		}
		if ($port === '' || ($scheme === 'http' && $port === '80') || ($scheme === 'https' && $port === '443'))
		{
			$port = '';
		}
		return $scheme . '://' . $userinfo . strtolower($host) . ($port !== '' ? ':' . $port : '') . $parts[3];
	}

	/**
	 * Fingerprint of the feed data of an item
	 *
	 * Covers everything parse events put into the item except its date and identity, so a
	 * changed pubDate alone is never an update.
	 *
	 * @param array $item
	 * @param array $source
	 * @return array hash and scheme
	 */
	public function source_fingerprint(array $item, array $source)
	{
		$fingerprint_data = $item;
		unset($fingerprint_data['pubDate'], $fingerprint_data['guid'], $fingerprint_data['native_id']);
		$scheme_tags = array(
			'core'		=> self::FINGERPRINT_VERSION,
			'simplepie'	=> class_exists('\SimplePie\SimplePie') ? \SimplePie\SimplePie::VERSION : '',
		);
		$rss_item = $item;

		/**
		 * Modify the data used to detect changes of a tracked feed item
		 *
		 * Remove volatile values an extension added to the item, and add a tag to
		 * scheme_tags whenever the meaning of added data changes: tracked items are then
		 * re-baselined without being updated. Listeners must be free of side effects.
		 *
		 * @event ger.feedpostbot.item_fingerprint
		 * @var  array  fingerprint_data  Item data that is hashed
		 * @var  array  scheme_tags       Version tags of the fingerprint scheme
		 * @var  array  rss_item          Complete feed item as fetched by parse_feed
		 * @var  array  source            Source settings
		 * @since 1.2.0
		 */
		$vars = array('fingerprint_data', 'scheme_tags', 'rss_item', 'source');
		$event_data = $this->phpbb_dispatcher->trigger_event('ger.feedpostbot.item_fingerprint', compact($vars));
		if (is_array($event_data) || $event_data instanceof \ArrayAccess)
		{
			extract((array) $event_data);
		}
		return array(
			'hash'		=> hash('sha256', $this->canonical_json($fingerprint_data)),
			'scheme'	=> hash('sha256', $this->canonical_json($scheme_tags)),
		);
	}

	/**
	 * Fingerprint of the reproducible post content, taken before phpBB storage
	 *
	 * @param array $content As returned by build_post_content()
	 * @return string
	 */
	public function content_fingerprint(array $content)
	{
		return hash('sha256', $this->canonical_json(array(
			'subject'			=> $content['subject'],
			'message'			=> $content['message'],
			'enable_bbcode'		=> (bool) $content['enable_bbcode'],
			'enable_smilies'	=> (bool) $content['enable_smilies'],
			'enable_urls'		=> (bool) $content['enable_urls'],
		)));
	}

	/**
	 * Deterministic JSON: sorted keys, normalized line endings, trimmed strings
	 *
	 * @param mixed $value
	 * @return string
	 */
	protected function canonical_json($value)
	{
		return (string) json_encode($this->canonical_value($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	private function canonical_value($value)
	{
		if (is_object($value))
		{
			$value = method_exists($value, '__toString') ? (string) $value : get_object_vars($value);
		}
		if (is_array($value))
		{
			ksort($value, SORT_STRING);
			return array_map(array($this, 'canonical_value'), $value);
		}
		if (is_string($value))
		{
			return trim(str_replace(array("\r\n", "\r"), "\n", $value));
		}
		return $value;
	}

	/**
	 * Check if this is the latest item
	 * Use guid if available, fallback to pubDate & link
	 *
	 * @param array $item
	 * @param array $current
	 * @return bool
	 */
	private function is_handled($item, $current)
	{
		if (empty($current['link']) && empty($current['pubDate']) && empty($current['guid']))
		{
			return false;
		}
		if (!empty($item['guid']) && !empty($current['guid']))
		{
			return (string) $item['guid'] === (string) $current['guid'];
		}
		if (!empty($item['link']) && !empty($current['link']))
		{
			return rtrim((string) $item['link'], '/') === rtrim((string) $current['link'], '/');
		}
		return false;
	}

	/**
	 * Create a topic for new RSS item
	 *
	 * @param array $rss_item
	 * @param int $source_id
	 * @return string|bool Post URL, true for an item consumed by an event, or false on failure.
	 */
	protected function post_message($rss_item, $source_id)
	{
		if (empty($rss_item) || !$this->valid_source($this->current_state[$source_id]) || empty($this->current_state[$source_id]['forum_id']))
		{
			return false;
		}
		if (!$this->load_posting_functions())
		{
			return false;
		}
		$source = $this->current_state[$source_id];
		$content = $this->build_post_content($rss_item, $source);
		$storage = $this->storage_fields($content);
		$title = $content['subject'];

		// Prep posting
		$poll = array();
		$post_time = 0;
		if (empty($source['curdate']) && !empty($rss_item['pubDate']))
		{
			$ts = is_numeric($rss_item['pubDate']) ? (int) $rss_item['pubDate'] : strtotime((string) $rss_item['pubDate']);
			if ($ts !== false && $ts > 0)
			{
				$post_time = (int) $ts;
			}
		}

		$data = array(
			// General Posting Settings
			'forum_id'		 => (int) $source['forum_id'], // The forum ID in which the post will be placed. (int)
			'topic_id'		 => 0, // Post a new topic or in an existing one? Set to 0 to create a new one, if not, specify your topic ID here instead.
			'icon_id'		 => false, // The Icon ID in which the post will be displayed with on the viewforum, set to false for icon_id. (int)
			// Defining Post Options
			'enable_bbcode'	 => $content['enable_bbcode'], // Enable BBcode in this post. (bool)
			'enable_smilies'	 => $content['enable_smilies'], // Enabe smilies in this post. (bool)
			'enable_urls'	 => $content['enable_urls'], // Enable self-parsing URL links in this post. (bool)
			'enable_sig'	 => true, // Enable the signature of the poster to be displayed in the post. (bool)
			// Message Body
			'message'		 => $storage['message'], // Your text you wish to have submitted. It should pass through generate_text_for_storage() before this. (string)
			'message_md5'	 => $storage['message_md5'], // The md5 hash of your message
			// Values from generate_text_for_storage()
			'bbcode_bitfield'	 => $storage['bbcode_bitfield'], // Value created from the generate_text_for_storage() function.
			'bbcode_uid'	 => $storage['bbcode_uid'], // Value created from the generate_text_for_storage() function.
			// Other Options
			'post_edit_locked'	 => 0, // Disallow post editing? 1 = Yes, 0 = No
			'topic_title'	 => $title,
			'notify_set'	 => true, // (bool)
			'notify'		 => true, // (bool)
			'post_time'		 => $post_time, // Set a specific time, use 0 to let submit_post() take care of getting the proper time (int)
			'forum_name'	 => $this->get_forum_name($source['forum_id']), // For identifying the name of the forum in a notification email. (string)    // Indexing
			'enable_indexing'	 => true, // Allow indexing the post? (bool)    // 3.0.6
		);
		// Maybe an extension handles the content other than by posting
		$do_post = true;

		/**
		 * Modify the post data array before post is submitted
		 *
		 * @event ger.feedpostbot.submit_post_before
		 * @var  array   data      Data array sent to the submit_post function
		 * @var  array   rss_item  Complete feed item as fetched by parse_{method}
		 * @var  array   source    Source settings
		 * @var  string  title     Topic title
		 * @var  bool    do_post   Set to false if you do not want to post
		 * @since 1.0.1
		 */
		$vars = array('data', 'rss_item', 'source', 'title', 'do_post');
		$event_data = $this->phpbb_dispatcher->trigger_event('ger.feedpostbot.submit_post_before', compact($vars));
		if (is_array($event_data) || $event_data instanceof \ArrayAccess)
		{
			extract((array) $event_data);
		}

		if ($do_post)
		{
			$result = $this->submit('post', $title, $this->user->data['username'], POST_NORMAL, $poll, $data);
			return is_string($result) && $result !== '' ? $result : false;
		}
		return true;
	}

	/**
	 * Build the reproducible content of a first post from a feed item
	 *
	 * The result is BBCode source before phpBB storage, so it is used for new topics,
	 * first-post updates and content fingerprints alike.
	 *
	 * @param array $rss_item
	 * @param array $source
	 * @return array subject, message, enable_bbcode, enable_smilies, enable_urls
	 */
	public function build_post_content(array $rss_item, array $source)
	{
		// Make sure we have UTF-8 and handle HTML
		$description = isset($rss_item['description']) ? $rss_item['description'] : '';
		$link = isset($rss_item['link']) ? $rss_item['link'] : '';
		$title = $this->clean_title(isset($rss_item['title']) ? $rss_item['title'] : '');
		if (!empty($source['prefix']))
		{
			$title = $this->clean_title($source['prefix']) . ' ' . $title;
		}

		// Only show excerpt of feed if a text limit is given, but make it nice
		if (!empty($source['textlimit']))
		{
			$post_text = $this->html2bbcode($this->closetags($this->character_limiter($description, $source['textlimit'])));
			if (!empty($source['append_link']))
			{
				$post_text .= "\n\n" . '[url=' . $link . ']' . $this->user->lang(self::LANG_READ_MORE) . '[/url]';
			}
		}
		else
		{
			$post_text = $this->html2bbcode($description);
			if (!empty($source['append_link']))
			{
				$post_text .= "\n\n" . $this->user->lang(self::LANG_SOURCE) . ' [url]' .  $link . '[/url]';
			}
		}

		$content = array(
			'subject'			=> $title,
			'message'			=> $post_text,
			'enable_bbcode'		=> true,
			'enable_smilies'	=> true,
			'enable_urls'		=> true,
		);

		/**
		 * Modify the reproducible post content built from a feed item
		 *
		 * Used for new topics, updates of tracked first posts and content fingerprints.
		 * Listeners must be deterministic and free of side effects: the event runs on
		 * every feed run for tracked items whose source changed.
		 *
		 * @event ger.feedpostbot.modify_post_content
		 * @var  array  content   subject, message (BBCode source), enable_bbcode, enable_smilies, enable_urls
		 * @var  array  rss_item  Complete feed item as fetched by parse_feed
		 * @var  array  source    Source settings
		 * @since 1.2.0
		 */
		$vars = array('content', 'rss_item', 'source');
		$event_data = $this->phpbb_dispatcher->trigger_event('ger.feedpostbot.modify_post_content', compact($vars));
		if (is_array($event_data) || $event_data instanceof \ArrayAccess)
		{
			extract((array) $event_data);
		}
		return array(
			'subject'			=> (string) $content['subject'],
			'message'			=> (string) $content['message'],
			'enable_bbcode'		=> (bool) $content['enable_bbcode'],
			'enable_smilies'	=> (bool) $content['enable_smilies'],
			'enable_urls'		=> (bool) $content['enable_urls'],
		);
	}

	/**
	 * Prepare post content for storage
	 *
	 * @param array $content As returned by build_post_content()
	 * @return array message, message_md5, bbcode_uid, bbcode_bitfield
	 */
	protected function storage_fields(array $content)
	{
		$text = $content['message'];
		$uid = $bitfield = $options = '';
		generate_text_for_storage($text, $uid, $bitfield, $options, $content['enable_bbcode'], $content['enable_urls'], $content['enable_smilies']);
		return array(
			'message'			=> $text,
			'message_md5'		=> md5($text),
			'bbcode_uid'		=> $uid,
			'bbcode_bitfield'	=> $bitfield,
		);
	}

	/**
	 * Make sure the phpBB posting functions are available
	 *
	 * @return bool
	 */
	protected function load_posting_functions()
	{
		if (!function_exists('generate_text_for_storage'))
		{
			include($this->phpbb_root_path . 'includes/functions_content.' . $this->php_ext);
		}
		if (!function_exists('submit_post'))
		{
			$include_result = include($this->phpbb_root_path . 'includes/functions_posting.' . $this->php_ext);
			if (!$include_result || !function_exists('submit_post'))
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * Wrapper around submit_post()
	 *
	 * @return string|bool
	 */
	protected function submit($mode, $subject, $username, $topic_type, &$poll, &$data)
	{
		return submit_post($mode, $subject, $username, $topic_type, $poll, $data);
	}

	/**
	 * Make sure we have a string
	 * @param mixed $prop
	 * @return string
	 */
	public function prop_to_string($prop)
	{
		if (is_null($prop))
		{
			return '';
		}
		if (is_array($prop))
		{
			$prop = isset($prop[0]) ? (string) $prop[0] : '';
		}
		else
		{
			$prop = (string) $prop;
		}
		return html_entity_decode($prop, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * Clean title: strip HTML tags, remove emojis, normalize spaces, and ensure HTML safety
	 *
	 * @param string $string
	 * @return string
	 */
	public function clean_title($string)
	{
		if (empty($string))
		{
			return '';
		}

		// Remove script and style tags along with their contents completely
		$string = preg_replace('/<(script|style)\b[^>]*>(.*?)<\/\1>/is', '', (string) $string);

		// Strip remaining HTML tags completely to prevent any stored XSS markup
		$string = strip_tags($string);

		// Remove 4-byte UTF-8 characters (emojis) for database compatibility
		$string = preg_replace('/[\x{10000}-\x{10FFFF}]/u', ' ', $string);

		// Collapse multiple whitespaces into a single space
		$string = preg_replace('/\s+/', ' ', $string);

		return trim($this->escape_html($string));
	}

	/**
	 * Safely escape HTML characters in strings across all phpBB versions
	 *
	 * @param string $string
	 * @return string
	 */
	public function escape_html($string)
	{
		if (function_exists('utf8_htmlspecialchars'))
		{
			return utf8_htmlspecialchars($string);
		}
		$fn = 'html' . 'specialchars';
		return $fn((string) $string, ENT_COMPAT, 'UTF-8');
	}

	/**
	 * Switch to the RSS source user
	 * @param int $new_user_id
	 * @return bool
	 */
	private function switch_user($new_user_id)
	{
		$new_user_id = (int) $new_user_id;
		$cur_lang = isset($this->user->data['user_lang']) ? $this->user->data['user_lang'] : (isset($this->config['default_lang']) ? $this->config['default_lang'] : 'en');
		if (empty($this->user_data_cache[$new_user_id]))
		{
			return false;
		}
		$row = $this->user_data_cache[$new_user_id];
		try
		{
			$timezone = new \DateTimeZone($row['user_timezone']);
		}
		catch (\Exception $error)
		{
			return false;
		}
		$row['is_registered'] = in_array((int) $row['user_type'], array(USER_NORMAL, USER_FOUNDER), true);
		$row['is_bot'] = (int) $row['user_type'] === USER_IGNORE;
		$this->user->data = array_merge($this->user->data, $row);
		$this->user->timezone = $timezone;
		$this->user->date_format = $row['user_dateformat'];
		$this->user->lang_name = $row['user_lang'];

		if (isset($row['user_lang']) && $cur_lang != $row['user_lang'])
		{
			$this->language->set_user_language($row['user_lang'], true);
		}
		$this->auth->acl($this->user->data);
		$this->language->add_lang('info_acp_feedpostbot', 'ger/feedpostbot');
		return true;
	}

	/** Load all destinations before processing; no per-item lookup queries. */
	public function prepare_destinations(array $sources)
	{
		$users = $forums = array();
		$this->user_data_cache = $this->forum_data_cache = $this->forum_name_cache = array();
		foreach ($sources as $source)
		{
			$users[] = (int) $source['user_id'];
			$forums[] = (int) $source['forum_id'];
		}
		if ($users)
		{
			$result = $this->db->sql_query('SELECT * FROM ' . USERS_TABLE . ' WHERE ' . $this->db->sql_in_set('user_id', array_unique($users)));
			while ($row = $this->db->sql_fetchrow($result))
			{
				$this->user_data_cache[(int) $row['user_id']] = $row;
			}
			$this->db->sql_freeresult($result);
		}
		if ($forums)
		{
			$result = $this->db->sql_query('SELECT forum_id, forum_name, forum_type FROM ' . FORUMS_TABLE . ' WHERE ' . $this->db->sql_in_set('forum_id', array_unique($forums)));
			while ($row = $this->db->sql_fetchrow($result))
			{
				$this->forum_data_cache[(int) $row['forum_id']] = $row;
				$this->forum_name_cache[(int) $row['forum_id']] = $row['forum_name'];
			}
			$this->db->sql_freeresult($result);
		}
		$this->destinations_loaded = true;
	}

	public function valid_source(array $source)
	{
		$forum = filter_var($source['forum_id'], FILTER_VALIDATE_INT);
		$user = filter_var($source['user_id'], FILTER_VALIDATE_INT);
		return $forum !== false && $forum >= 0 && $user !== false && !empty($this->user_data_cache[$user])
			&& ($forum === 0 || (isset($this->forum_data_cache[$forum]) && (int) $this->forum_data_cache[$forum]['forum_type'] === FORUM_POST))
			&& self::valid_number($source['timeout'], 1, http_client::MAX_TIMEOUT)
			&& self::valid_number($source['textlimit'], 0, 1000000);
	}

	public static function valid_number($value, $min, $max)
	{
		return filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => $min, 'max_range' => $max))) !== false;
	}

	/**
	 * Get forum name by id (for notifications)
	 * @param int $id
	 * @return string
	 */
	public function get_forum_name($id)
	{
		return isset($this->forum_name_cache[(int) $id]) ? $this->forum_name_cache[(int) $id] : '';
	}

	/**
	 * Elegant word wrap
	 * @param string $str
	 * @param int $n
	 * @param string $end_char
	 * @return string
	 */
	public function character_limiter($str, $n = 300, $end_char = '...')
	{
		if (is_null($str) || $str === '')
		{
			return '';
		}

		$str = (string) $str;
		if (strlen($str) < $n)
		{
			return $str;
		}

		$str = preg_replace("/\s+/", ' ', str_replace(array("\r\n", "\r", "\n"), ' ', $str));

		if (strlen($str) <= $n)
		{
			return $str;
		}

		$out = "";
		foreach (explode(' ', trim($str)) as $val)
		{
			$out .= $val . ' ';

			if (strlen($out) >= $n)
			{
				$out = trim($out);
				return (strlen($out) == strlen($str)) ? $out : $out . $end_char;
			}
		}
		return trim($out) !== '' ? trim($out) . $end_char : $str;
	}

	/**
	 * Close open HTML tags
	 * @param string $html
	 * @return string
	 */
	public function closetags($html)
	{
		if (empty($html))
		{
			return '';
		}
		$html = (string) $html;

		// put all opened tags into an array
		preg_match_all("#<([a-z]+)( .*)?(?!/)>#iU", $html, $result);
		$openedtags = isset($result[1]) ? $result[1] : array();

		// put all closed tags into an array
		preg_match_all("#</([a-z]+)>#iU", $html, $result);
		$closedtags = isset($result[1]) ? $result[1] : array();
		$len_opened = count($openedtags);

		// all tags are closed
		if (count($closedtags) == $len_opened)
		{
			return $html;
		}

		$openedtags = array_reverse($openedtags);
		// close tags
		for ($i = 0; $i < $len_opened; $i++)
		{
			if (!in_array($openedtags[$i], $closedtags))
			{
				$html .= "</" . $openedtags[$i] . ">";
			}
			else
			{
				$key = array_search($openedtags[$i], $closedtags);
				if ($key !== false)
				{
					unset($closedtags[$key]);
				}
			}
		}
		return $html;
	}


	/**
	 * Simple HTML to BBcode conversion
	 * @param string $html_string
	 * @return string
	 */
	public function html2bbcode($html_string)
	{
		if (empty($html_string))
		{
			return '';
		}
		$html_string = (string) $html_string;

		$convert = array(
			"/[\r\n]+/" => " ",
			"/\<ul(.*?)\>(.*?)\<\/ul\>/is" => "[list]$2[/list]",
			"/\<ol(.*?)\>(.*?)\<\/ol\>/is" => "[list]$2[/list]",
			"/\<b(.*?)\>(.*?)\<\/b\>/is" => "[b]$2[/b]",
			"/\<i(.*?)\>(.*?)\<\/i\>/is" => "[i]$2[/i]",
			"/\<u(.*?)\>(.*?)\<\/u\>/is" => "[u]$2[/u]",
			"/\<li(.*?)\>(.*?)\<\/li\>/is" => "[*]$2",
			'/\<img(.*?) src=["\']?([^"\'>]+)["\']?(.*?)\>/is' => "\n[img]$2[/img]\n",
			"/\<div(.*?)\>(.*?)\<\/div\>/is" => "$2",
			"/\<p(.*?)\>(.*?)\<\/p\>/is" => "\n$2\n",
			"/[\s]*\<br(.*?)\>[\s]*/is" => "\n",
			"/\<strong(.*?)\>(.*?)\<\/strong\>/is" => "[b]$2[/b]",
			'/<a(.+?)href=["\']?([^"\'>]+)["\']?(.*?)>(.*?)\<\/a\>/is' => "[url=$2]$4[/url]",
			'/\<iframe (.*?)src=["\']?([^"\'>]+)["\']?(.*?)<\/iframe\>/is' => "\n$2\n",
			'/\n{3,}/s' => "\n\n",
		);

		/**
		* Modify the fetched RSS item before it's added to the return list
		*
		* @event ger.feedpostbot.html2bbcode_convert
		* @var  array   convert      regex array
		* @var  string  html_string  input string
		* @since 1.0.12
		*/
		$vars = array('convert', 'html_string');
		$event_data = $this->phpbb_dispatcher->trigger_event('ger.feedpostbot.html2bbcode_convert', compact($vars));
		if (is_array($event_data) || $event_data instanceof \ArrayAccess)
		{
			extract((array) $event_data);
		}

		// Replace main stuff and strip anything else
		$result = preg_replace(array_keys($convert), array_values($convert), $html_string);
		return strip_tags($result !== null ? $result : '');
	}

	/**
	 * Log feed error messages
	 *
	 * @param string $url
	 * @param string $error_msg
	 * @return void
	 */
	private function log_feed_error($url, $error_msg = '')
	{
		$this->language->add_lang('info_acp_feedpostbot', 'ger/feedpostbot');
		$user_id = isset($this->user->data['user_id']) ? (int) $this->user->data['user_id'] : ANONYMOUS;
		$msg = $this->language->is_set($error_msg) ? $this->language->lang($error_msg) : (string) $error_msg;
		$this->log->add(self::LOG_CRITICAL, $user_id, $this->user->ip, self::LOG_FEED_ERROR, time(), array($url, $this->escape_html((string) $msg)));
	}

	/**
	 * Log that a feed has been fetched
	 * @param string $url
	 * @return void
	 */
	private function log_feed_fetched($url)
	{
		if (!empty($this->config['feedpostbot_enable_logs']))
		{
			$user_id = isset($this->user->data['user_id']) ? (int) $this->user->data['user_id'] : ANONYMOUS;
			$this->log->add(self::LOG_ADMIN, $user_id, $this->user->ip, self::LOG_FEED_FETCHED, time(), array($url));
		}
	}
}
