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
	/** A lock may be reset manually once it is this much older than the longest regular run (seconds). */
	public const LOCK_RESET_MARGIN = 300;
	public const HISTORY_LIMIT = 10000;
	public const LOG_FEED_ERROR = 'FPB_LOG_FEED_ERROR';
	public const LANG_READ_MORE = 'FPB_READ_MORE';
	public const LANG_SOURCE = 'FPB_SOURCE';
	/** Bump when the meaning of source/content fingerprints changes; tracked items are then re-baselined silently. */
	public const FINGERPRINT_VERSION = 1;
	/** Bump when the meaning of the first-post integrity guard changes. */
	public const GUARD_VERSION = 1;
	/** First retry delay of a failed update reply, doubled per failure up to the maximum (seconds). */
	public const REPLY_RETRY_DELAY = 300;
	public const REPLY_RETRY_MAX_DELAY = 86400;

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
	/** @var \phpbb\textformatter\utils_interface|null */
	protected $text_formatter_utils;
	/** @var array|null Topic created by the last post_message() call */
	protected $last_submission;
	/** @var callable|null Called by post_message() right before submit_post() */
	protected $submit_hook;
	/** @var array Outcome of the last fetch_all(), see get_run_result() */
	protected $run_result = array('created' => 0, 'updated' => 0, 'locked' => false);
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
	 * @param \phpbb\textformatter\utils_interface|null		$text_formatter_utils
	 */
	public function __construct(\phpbb\config\config $config, \phpbb\config\db_text $config_text, \phpbb\user $user, \phpbb\language\language $language, \phpbb\auth\auth $auth, \phpbb\db\driver\driver_interface $db, \phpbb\log\log $log, $phpbb_root_path, $php_ext, \phpbb\event\dispatcher $phpbb_dispatcher, ?item_tracker $tracker = null, ?\phpbb\textformatter\utils_interface $text_formatter_utils = null)
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
		$this->text_formatter_utils = $text_formatter_utils;
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

		$this->current_state = $this->with_defaults($this->current_state);
	}

	/**
	 * Add settings missing from older configurations; new features default to off
	 *
	 * @param array $sources
	 * @return array
	 */
	private function with_defaults(array $sources)
	{
		$defaults = array('append_link' => 1, 'update_existing' => 0, 'update_reply' => 0, 'recreate_deleted' => 0);
		$new_state = array();
		foreach ($sources as $id => $source)
		{
			if (is_array($source))
			{
				$new_state[$id] = $source + $defaults;
			}
		}
		return $new_state;
	}

	/**
	 * Fetch all feeds
	 * This is called by the cron handler
	 * @return int
	 */
	public function fetch_all()
	{
		$this->run_result = array('created' => 0, 'updated' => 0, 'locked' => false);
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
			// Another run is processing the feeds; nothing was done here.
			$this->run_result['locked'] = true;
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
			$this->ensure_source_uids();
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
				// Owed update replies do not depend on the feed being reachable.
				try
				{
					$this->flush_pending_replies($id);
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
		$this->run_result['created'] = $counter;
		return $counter;
	}

	/**
	 * Outcome of the last fetch_all()
	 *
	 * created: topics created (including recreated permanently deleted topics), updated: first
	 * posts of tracked topics edited in that run, locked: another run held the processing lock.
	 *
	 * @return array
	 */
	public function get_run_result()
	{
		return $this->run_result;
	}

	/**
	 * Release the processing lock from the ACP, but only when no worker can still be running
	 *
	 * The lock value is "<acquire time> <owner id>". Workers stop starting new work after
	 * MAX_RUN_SECONDS, so a lock older than that plus a margin belongs to a dead worker.
	 * phpBB still expires abandoned locks automatically after one hour.
	 *
	 * @return bool False when the lock may belong to a running worker
	 */
	public function reset_stale_lock()
	{
		$value = isset($this->config['feedpostbot_locked']) ? (string) $this->config['feedpostbot_locked'] : '0';
		if ($value === '' || $value === '0')
		{
			return true;
		}
		$acquired = (int) strtok($value, ' ');
		if ($acquired + self::MAX_RUN_SECONDS + self::LOCK_RESET_MARGIN >= time())
		{
			return false;
		}
		// Only if nobody acquired the lock in the meantime.
		return (bool) $this->config->set_atomic('feedpostbot_locked', $value, '0', false);
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
				// A feed identity is only ever added, never replaced: the ACP renews it on URL changes.
				if (!empty($source['source_uid']) && empty($saved[$target_id]['source_uid']) && $saved[$target_id]['url'] === $source['url'])
				{
					$saved[$target_id]['source_uid'] = $source['source_uid'];
				}
			}
		}
		$this->config_text->set('ger_feedpostbot_current_state', json_encode($saved));
	}

	/**
	 * Give every feed a persistent identity before it is processed
	 *
	 * Identities are written immediately, so tracked items are never stored under an
	 * identity that could be lost. Feeds whose identity cannot be stored are processed
	 * without update tracking during this run.
	 */
	protected function ensure_source_uids()
	{
		if ($this->tracker === null)
		{
			return;
		}
		$assigned = array();
		try
		{
			foreach ($this->current_state as $id => $source)
			{
				if (empty($source['source_uid']))
				{
					$assigned[$id] = $this->current_state[$id]['source_uid'] = $this->unique_source_uid($this->current_state);
				}
			}
			if (empty($assigned))
			{
				return;
			}
			$saved = json_decode($this->config_text->get('ger_feedpostbot_current_state'), true);
			if (!is_array($saved))
			{
				throw new \RuntimeException('FPB_PROCESSING_FAILED');
			}
			foreach ($assigned as $id => $uid)
			{
				if (isset($saved[$id]) && $saved[$id]['url'] === $this->current_state[$id]['url'] && empty($saved[$id]['source_uid']))
				{
					$saved[$id]['source_uid'] = $uid;
				}
				else
				{
					unset($this->current_state[$id]['source_uid'], $assigned[$id]);
				}
			}
			$this->config_text->set('ger_feedpostbot_current_state', json_encode($saved));
		}
		catch (\Throwable $error)
		{
			foreach ($assigned as $id => $uid)
			{
				unset($this->current_state[$id]['source_uid']);
			}
			$this->log_feed_error('', 'FPB_PROCESSING_FAILED');
		}
	}

	/**
	 * Create a random feed identity (128 bits)
	 *
	 * @return string 32 hexadecimal characters
	 */
	public static function new_source_uid()
	{
		return bin2hex(random_bytes(16));
	}

	/**
	 * @param array $sources Sources whose identities must not be reused
	 * @return string
	 */
	protected function unique_source_uid(array $sources)
	{
		$used = array();
		foreach ($sources as $source)
		{
			if (!empty($source['source_uid']))
			{
				$used[$source['source_uid']] = true;
			}
		}
		do
		{
			$uid = static::new_source_uid();
		}
		while (isset($used[$uid]));
		return $uid;
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
					// The worker may have assigned a missing identity since the form was loaded.
					unset($source['handled'], $source['latest'], $source['last_attempt'], $source['source_uid']);
				}
				return $sources;
			};
			$proposed = $this->with_defaults($proposed);
			if ($settings($fresh) !== $settings($this->with_defaults($original)))
			{
				return false;
			}
			foreach ($proposed as $id => &$source)
			{
				$same_feed = isset($fresh[$id]) && $fresh[$id]['url'] === $source['url'];
				if ($same_feed)
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
				// A changed URL is a new feed: it gets a new identity and never inherits tracked items.
				$source['source_uid'] = ($same_feed && !empty($fresh[$id]['source_uid'])) ? $fresh[$id]['source_uid'] : '';
			}
			unset($source);
			foreach ($proposed as $id => $source)
			{
				if ($source['source_uid'] === '')
				{
					$proposed[$id]['source_uid'] = $this->unique_source_uid(array_merge(array_values($fresh), array_values($proposed)));
				}
			}
			$this->config_text->set('ger_feedpostbot_current_state', json_encode($proposed));
			$this->current_state = $proposed;
			$this->cleanup_sources($fresh, $proposed);
			$this->cancel_waived_replies($fresh, $proposed);
			return true;
		}
		finally
		{
			$lock->release();
		}
	}

	/**
	 * Remove tracked items of feeds that were deleted or repointed, after the new settings were saved
	 *
	 * @param array $fresh Settings before the change
	 * @param array $proposed Saved settings
	 */
	protected function cleanup_sources(array $fresh, array $proposed)
	{
		if ($this->tracker === null)
		{
			return;
		}
		$kept = $removed = array();
		foreach ($proposed as $source)
		{
			$kept[$source['source_uid']] = true;
		}
		foreach ($fresh as $source)
		{
			if (!empty($source['source_uid']) && !isset($kept[$source['source_uid']]))
			{
				$removed[] = $source['source_uid'];
			}
		}
		try
		{
			$this->tracker->delete_sources($removed);
		}
		catch (\Throwable $error)
		{
			// The settings are saved; unreferenced rows are harmless because identities are never reused.
			$this->log_feed_error('', 'FPB_PROCESSING_FAILED');
		}
	}

	/**
	 * Waive owed update replies of feeds whose update replies were just switched off
	 *
	 * @param array $fresh Settings before the change
	 * @param array $proposed Saved settings
	 */
	protected function cancel_waived_replies(array $fresh, array $proposed)
	{
		if ($this->tracker === null)
		{
			return;
		}
		foreach ($proposed as $id => $source)
		{
			if (isset($fresh[$id]['source_uid']) && $fresh[$id]['source_uid'] === $source['source_uid'] && !empty($fresh[$id]['update_reply']) && empty($source['update_reply']))
			{
				try
				{
					$this->tracker->cancel_replies($source['source_uid']);
				}
				catch (\Throwable $error)
				{
					// The worker waives them as well while update replies stay off.
					$this->log_feed_error($source['url'], 'FPB_PROCESSING_FAILED');
				}
			}
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
			// Tracked items are always recognised, even while updating them is switched off:
			// their historical key may change with the content when a feed has no identifiers.
			$tracking = $this->tracker !== null && !empty($source['source_uid']);
			$tracking_keys = $tracked = $ambiguous = array();
			if ($tracking)
			{
				$counts = array();
				foreach ($items as $index => $item)
				{
					$tracking_key = $this->tracking_key($item);
					if ($tracking_key !== null)
					{
						$tracking_keys[$index] = $tracking_key;
						$counts[$tracking_key['key']] = isset($counts[$tracking_key['key']]) ? $counts[$tracking_key['key']] + 1 : 1;
					}
				}
				// Several current items with one identity: which one a topic belongs to cannot be told.
				$ambiguous = array_filter($counts, function ($count) {
					return $count > 1;
				});
				$tracked = $this->tracker->load($source['source_uid'], array_keys($counts));
			}
			// Inspect the entire feed: new items may be inserted below already known entries.
			foreach (array_reverse($items, true) as $index => $item)
			{
				if ($this->run_deadline && microtime(true) >= $this->run_deadline)
				{
					break;
				}
				$key = $this->item_key($item);
				$tracking_key = isset($tracking_keys[$index]) ? $tracking_keys[$index] : null;
				if ($tracking_key !== null && isset($tracked[$tracking_key['key']]) && $tracked[$tracking_key['key']]['pending_op'] !== item_tracker::PENDING_NONE)
				{
					$pending_op = $tracked[$tracking_key['key']]['pending_op'];
					// An interrupted creation is always finished; edits and replies stay frozen while updates are off.
					if (!empty($source['update_existing']) || $pending_op === item_tracker::PENDING_CREATE || $pending_op === item_tracker::PENDING_RECREATE)
					{
						try
						{
							$resolved = $this->resolve_pending($source, $tracked[$tracking_key['key']]);
						}
						catch (\Throwable $error)
						{
							$this->log_exception($source['url'], $error);
							$source['handled'][$key] = true;
							continue;
						}
						if ($resolved === null)
						{
							unset($tracked[$tracking_key['key']]);
						}
						else
						{
							$tracked[$tracking_key['key']] = $resolved;
						}
					}
				}
				if ($tracking_key !== null && isset($tracked[$tracking_key['key']]))
				{
					$source['handled'][$key] = true;
					if (!empty($source['update_existing']) && $tracked[$tracking_key['key']]['pending_op'] === item_tracker::PENDING_NONE)
					{
						try
						{
							$this->handle_tracked_item($item, $tracked[$tracking_key['key']], $source_id, isset($ambiguous[$tracking_key['key']]), $posted);
						}
						catch (\Throwable $error)
						{
							// Updates are independent of each other and never block new items.
							$this->log_exception($source['url'], $error);
						}
					}
					continue;
				}
				if (isset($source['handled'][$key]))
				{
					continue;
				}
				try
				{
					// New items with a stable, unambiguous identity are always mapped, also while updates are
					// switched off: the mapping keeps deduplicating them. Ambiguous identities are not tracked.
					$track = $tracking_key !== null && !isset($ambiguous[$tracking_key['key']]);
					$result = $track ? $this->create_tracked($item, $source_id, $tracking_key) : $this->post_message($item, $source_id);
					if ($track)
					{
						// Known from now on, also for later items of this run.
						$tracked += $this->tracker->load($source['source_uid'], array($tracking_key['key']));
					}
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

	/**
	 * Process an item that already has a tracked topic
	 *
	 * @param array $item
	 * @param array $row Tracking row, kept current for further items of the run
	 * @param int $source_id
	 * @param bool $ambiguous Several current items share the item's identity
	 * @param int $posted Topics created, incremented on recreation
	 */
	protected function handle_tracked_item(array $item, array &$row, $source_id, $ambiguous, &$posted)
	{
		if ($ambiguous)
		{
			// Never let two items take turns on one topic, and never fall back to posting them.
			if ($row['item_state'] === item_tracker::STATE_ACTIVE || $row['item_state'] === item_tracker::STATE_BLOCKED)
			{
				$this->set_item_state($this->current_state[$source_id], $row, item_tracker::STATE_BLOCKED, item_tracker::REASON_AMBIGUOUS);
				$row['item_state'] = item_tracker::STATE_BLOCKED;
				$row['state_reason'] = item_tracker::REASON_AMBIGUOUS;
			}
			return;
		}
		if ($row['state_reason'] === item_tracker::REASON_AMBIGUOUS)
		{
			// The identity is unique again.
			$this->tracker->update($row['tracking_id'], array('item_state' => item_tracker::STATE_ACTIVE, 'state_reason' => item_tracker::REASON_NONE));
			$row['item_state'] = item_tracker::STATE_ACTIVE;
			$row['state_reason'] = item_tracker::REASON_NONE;
		}
		$posted += $this->process_tracked_item($item, $row, $source_id);
	}

	/**
	 * Create a topic for a new item and remember it for later updates
	 *
	 * phpBB commits the topic itself, so the tracking row is recorded before posting and is
	 * settled only once the topic was found in the database (see publish_tracked_topic()).
	 *
	 * @param array $item
	 * @param int $source_id
	 * @param array $tracking_key
	 * @return string|bool As post_message()
	 */
	protected function create_tracked(array $item, $source_id, array $tracking_key)
	{
		$source = $this->current_state[$source_id];
		$fingerprint = $this->source_fingerprint($item, $source);
		$row = array(
			'source_uid'			=> $source['source_uid'],
			'item_key'				=> $tracking_key['key'],
			'item_ident'			=> function_exists('utf8_substr') ? utf8_substr($tracking_key['ident'], 0, 2000) : substr($tracking_key['ident'], 0, 2000),
			'topic_id'				=> 0,
			'post_id'				=> 0,
			'guard_hash'			=> '',
			'source_hash'			=> '',
			'content_hash'			=> '',
			'notified_hash'			=> '',
			'fingerprint_scheme'	=> $fingerprint['scheme'],
			'item_state'			=> item_tracker::STATE_ACTIVE,
			'state_reason'			=> item_tracker::REASON_NONE,
			'reply_retry_count'		=> 0,
			'reply_next_retry'		=> 0,
			'reply_reason'			=> item_tracker::REASON_NONE,
			'created_time'			=> time(),
			'updated_time'			=> 0,
			'pending_op'			=> item_tracker::PENDING_CREATE,
			'pending_source_hash'	=> $fingerprint['hash'],
			'pending_content_hash'	=> '',
			'pending_checksum'		=> '',
			'pending_after_post_id'	=> $this->max_post_id(),
		);
		$row['tracking_id'] = $this->tracker->insert($row);
		return $this->publish_tracked_topic($item, $source_id, $row);
	}

	/**
	 * Post the topic of a pending create or recreate row and settle the row
	 *
	 * The row only becomes a regular mapping when the topic is found in the database;
	 * otherwise the pending operation is withdrawn. A process that dies in between leaves
	 * the pending row behind, which resolve_pending() settles on the next run.
	 *
	 * @param array $item
	 * @param int $source_id
	 * @param array $row Row with pending_op set
	 * @return string|bool As post_message()
	 */
	protected function publish_tracked_topic(array $item, $source_id, array $row)
	{
		$source = $this->current_state[$source_id];
		// Runs right before submit_post(), after submit_post_before listeners: record what is written.
		$this->submit_hook = function (array $data, array $content, $customized) use (&$row) {
			$fields = array(
				'pending_checksum'		=> (string) $data['message_md5'],
				// Empty: listeners changed the content, so updates could not reproduce it.
				'pending_content_hash'	=> $customized ? '' : $this->content_fingerprint($content),
			);
			if (!$this->tracker->update($row['tracking_id'], $fields, array('pending_op' => $row['pending_op'], 'pending_checksum' => '')))
			{
				throw new \RuntimeException('FPB_TRACKING_CONFLICT');
			}
			$row = array_merge($row, $fields);
		};
		$error = null;
		$result = false;
		try
		{
			$result = $this->post_message($item, $source_id);
		}
		catch (\Throwable $exception)
		{
			$error = $exception;
		}
		finally
		{
			$this->submit_hook = null;
		}
		$this->discard_open_transaction();
		if ($result === true)
		{
			// Consumed by an extension without posting.
			$this->abandon_publication($source, $row, $item);
			return true;
		}
		$found = $this->find_publication($row, $this->last_submission);
		if ($found === null)
		{
			$this->abandon_publication($source, $row);
			if ($error !== null)
			{
				throw $error;
			}
			return false;
		}
		$this->settle_publication($source, $row, $found['topic_id'], $found['post_id'], $found['stored_as_submitted']);
		if ($error !== null)
		{
			// The topic exists: the error happened after phpBB had committed it.
			$this->log_exception($source['url'], $error);
		}
		return (is_string($result) && $result !== '') ? $result : 'viewtopic.' . $this->php_ext . '?t=' . $found['topic_id'];
	}

	/**
	 * Find the topic written for a pending create or recreate row
	 *
	 * In the process that called submit_post(), the ids it reported prove the topic structurally:
	 * phpBB listeners (core.modify_submit_post_data, core.submit_post_modify_sql_data) may have
	 * rewritten the stored message and its checksum. A later process can only search by checksum.
	 *
	 * @param array $row
	 * @param array|null $submission Ids reported by submit_post() in this process
	 * @return array|null topic_id, post_id and stored_as_submitted (checksum as recorded)
	 */
	protected function find_publication(array $row, $submission = null)
	{
		if ($row['pending_checksum'] === '')
		{
			// Nothing was submitted.
			return null;
		}
		if (!empty($submission['post_id']) && !empty($submission['topic_id']) && (int) $submission['post_id'] > (int) $row['pending_after_post_id'])
		{
			$target = $this->load_target($submission['topic_id'], $submission['post_id']);
			if (
				$target['post'] !== null && $target['topic'] !== null
				&& (int) $target['post']['topic_id'] === (int) $submission['topic_id']
				&& (int) $target['topic']['topic_first_post_id'] === (int) $submission['post_id']
				&& (int) $target['post']['poster_id'] === (int) $this->user->data['user_id']
			)
			{
				return array('topic_id' => (int) $submission['topic_id'], 'post_id' => (int) $submission['post_id'],
					'stored_as_submitted' => (string) $target['post']['post_checksum'] === $row['pending_checksum']);
			}
		}
		$found = $this->find_post($row['pending_after_post_id'], $row['pending_checksum'], 0, true);
		return $found === null ? null : $found + array('stored_as_submitted' => true);
	}

	/**
	 * Turn a pending create or recreate row into the mapping of the topic that was found
	 *
	 * @param array $source
	 * @param array $row
	 * @param int $topic_id
	 * @param int $post_id
	 * @param bool $stored_as_submitted False when a phpBB listener changed the stored message
	 */
	protected function settle_publication(array $source, array $row, $topic_id, $post_id, $stored_as_submitted = true)
	{
		// Either a submit_post_before listener or a phpBB posting listener customized the post:
		// updates could not reproduce it, so the topic is only kept for deduplication.
		$customized = $row['pending_content_hash'] === '' || !$stored_as_submitted;
		$fields = array(
			'topic_id'				=> (int) $topic_id,
			'post_id'				=> (int) $post_id,
			'guard_hash'			=> $this->guard_hash($this->load_target($topic_id, $post_id)),
			'source_hash'			=> $row['pending_source_hash'],
			'content_hash'			=> $row['pending_content_hash'],
			// A new topic shows the current version: there is nothing to announce.
			'notified_hash'			=> $row['pending_content_hash'],
			'item_state'			=> $customized ? item_tracker::STATE_DETACHED : item_tracker::STATE_ACTIVE,
			'state_reason'			=> $customized ? item_tracker::REASON_CUSTOMIZED_AT_CREATION : item_tracker::REASON_NONE,
			'reply_retry_count'		=> 0,
			'reply_next_retry'		=> 0,
			'reply_reason'			=> item_tracker::REASON_NONE,
			'created_time'			=> time(),
			'updated_time'			=> 0,
		) + $this->no_pending();
		if (!$this->tracker->update($row['tracking_id'], $fields, array('pending_op' => $row['pending_op'], 'pending_checksum' => $row['pending_checksum'])))
		{
			throw new \RuntimeException('FPB_TRACKING_CONFLICT');
		}
		if ($customized)
		{
			$this->log_item_state($source, array_merge($row, $fields), $fields['item_state'], $fields['state_reason']);
		}
	}

	/**
	 * Withdraw a pending create or recreate whose topic does not exist
	 *
	 * @param array $source
	 * @param array $row
	 * @param array|null $item Item consumed by an extension instead of being posted
	 */
	protected function abandon_publication(array $source, array $row, $item = null)
	{
		$expected = array('pending_op' => $row['pending_op'], 'pending_checksum' => $row['pending_checksum']);
		if ($row['pending_op'] === item_tracker::PENDING_CREATE)
		{
			$this->tracker->delete($row['tracking_id'], $expected);
			return;
		}
		$fields = $this->no_pending();
		if ($item !== null)
		{
			// Recreation consumed by an extension: remember the version, the topic stays deleted.
			$content_hash = $this->content_fingerprint($this->build_post_content($item, $source));
			$fields += array('source_hash' => $row['pending_source_hash'], 'content_hash' => $content_hash, 'notified_hash' => $content_hash);
		}
		$this->tracker->update($row['tracking_id'], $fields, $expected);
	}

	/**
	 * Settle an operation that was interrupted between recording it and confirming it
	 *
	 * @param array $source
	 * @param array $row Row with pending_op set
	 * @return array|null The current row, null when it was removed
	 */
	protected function resolve_pending(array $source, array $row)
	{
		switch ($row['pending_op'])
		{
			case item_tracker::PENDING_CREATE:
			case item_tracker::PENDING_RECREATE:
				$found = $this->find_publication($row);
				if ($found !== null)
				{
					$this->settle_publication($source, $row, $found['topic_id'], $found['post_id'], $found['stored_as_submitted']);
				}
				else
				{
					$this->abandon_publication($source, $row);
				}
				break;

			case item_tracker::PENDING_EDIT:
				$target = $this->load_target($row['topic_id'], $row['post_id']);
				if ($this->edit_landed($row, $target))
				{
					$this->settle_edit($row, $target, !empty($source['update_reply']));
				}
				else
				{
					$this->clear_pending($row);
				}
				break;

			case item_tracker::PENDING_REPLY:
				if ($this->find_post($row['pending_after_post_id'], $row['pending_checksum'], $row['topic_id']) !== null)
				{
					$this->settle_reply($row);
				}
				else
				{
					// Not proven posted: the reply stays owed.
					$this->clear_pending($row);
				}
				break;
		}
		$rows = $this->tracker->load($row['source_uid'], array($row['item_key']));
		return isset($rows[$row['item_key']]) ? $rows[$row['item_key']] : null;
	}

	/**
	 * Change a row that has no pending operation, unless it changed since it was loaded
	 *
	 * @param array $row Row as loaded
	 * @param array $fields
	 */
	protected function update_row(array $row, array $fields)
	{
		if (
			!$this->tracker->update($row['tracking_id'], $fields, array('pending_op' => item_tracker::PENDING_NONE,
			'source_hash' => $row['source_hash'], 'content_hash' => $row['content_hash'], 'notified_hash' => $row['notified_hash']))
		)
		{
			throw new \RuntimeException('FPB_TRACKING_CONFLICT');
		}
	}

	/**
	 * @return array Fields of a row without pending operation
	 */
	protected function no_pending()
	{
		return array('pending_op' => item_tracker::PENDING_NONE, 'pending_source_hash' => '', 'pending_content_hash' => '',
			'pending_checksum' => '', 'pending_after_post_id' => 0);
	}

	/**
	 * Withdraw a pending edit or reply that did not happen
	 *
	 * @param array $row
	 * @param array $fields Additional fields to write
	 */
	protected function clear_pending(array $row, array $fields = array())
	{
		$this->tracker->update(
			$row['tracking_id'],
			$fields + $this->no_pending(),
			array('pending_op' => $row['pending_op'], 'pending_checksum' => $row['pending_checksum'])
		);
	}

	/**
	 * Did the recorded first-post edit reach the database?
	 *
	 * @param array $row Row with a pending edit
	 * @param array $target
	 * @return bool
	 */
	protected function edit_landed(array $row, array $target)
	{
		return $target['post'] !== null && $row['pending_checksum'] !== '' && (string) $target['post']['post_checksum'] === $row['pending_checksum'];
	}

	/**
	 * Did phpBB record this process's edit of the target post, whatever listeners did to its text?
	 *
	 * Proof from the post's edit metadata: one more edit, by the feed user, not before the edit started.
	 *
	 * @param array $target Live rows after the edit
	 * @param array $edit_mark Edit count and time taken right before submit_post()
	 * @return bool
	 */
	protected function edit_recorded(array $target, array $edit_mark)
	{
		$post = $target['post'];
		return $post !== null && (int) $post['post_edit_count'] === $edit_mark['count'] + 1
			&& (int) $post['post_edit_user'] === (int) $this->user->data['user_id'] && (int) $post['post_edit_time'] >= $edit_mark['time'];
	}

	/**
	 * Record a first-post edit that was found in the database
	 *
	 * @param array $row Row with a pending edit
	 * @param array $target Live rows after the edit
	 * @param bool $announce Whether update replies are enabled
	 */
	protected function settle_edit(array $row, array $target, $announce)
	{
		$fields = array(
			'source_hash'		=> $row['pending_source_hash'],
			'content_hash'		=> $row['pending_content_hash'],
			'guard_hash'		=> $this->guard_hash($target),
			// With update replies, one reply stays owed for the newest version.
			'notified_hash'		=> $announce ? $row['notified_hash'] : $row['pending_content_hash'],
			'item_state'		=> item_tracker::STATE_ACTIVE,
			'state_reason'		=> item_tracker::REASON_NONE,
			// The newest version is announced at once and does not inherit the failures of an
			// earlier, superseded announcement.
			'reply_next_retry'	=> 0,
			'reply_retry_count'	=> 0,
			'reply_reason'		=> item_tracker::REASON_NONE,
			'updated_time'		=> time(),
		) + $this->no_pending();
		if (!$this->tracker->update($row['tracking_id'], $fields, array('pending_op' => item_tracker::PENDING_EDIT, 'pending_checksum' => $row['pending_checksum'])))
		{
			throw new \RuntimeException('FPB_TRACKING_CONFLICT');
		}
	}

	/**
	 * Record an update reply that was found in the database
	 *
	 * @param array $row Row with a pending reply
	 */
	protected function settle_reply(array $row)
	{
		$fields = array('notified_hash' => $row['pending_content_hash'], 'reply_retry_count' => 0, 'reply_next_retry' => 0,
			'reply_reason' => item_tracker::REASON_NONE) + $this->no_pending();
		if (!$this->tracker->update($row['tracking_id'], $fields, array('pending_op' => item_tracker::PENDING_REPLY, 'pending_checksum' => $row['pending_checksum'])))
		{
			throw new \RuntimeException('FPB_TRACKING_CONFLICT');
		}
	}

	/**
	 * Highest post id, the lower bound for finding a post submitted afterwards
	 *
	 * @return int
	 */
	protected function max_post_id()
	{
		$result = $this->db->sql_query('SELECT MAX(post_id) AS max_post_id FROM ' . POSTS_TABLE);
		$max = (int) $this->db->sql_fetchfield('max_post_id');
		$this->db->sql_freeresult($result);
		return $max;
	}

	/**
	 * Find a post written after a known post id with the given checksum
	 *
	 * The checksum is the message_md5 Feed post bot passed to submit_post(); together with
	 * the post id bound it identifies the post of one recorded operation.
	 *
	 * @param int $after_post_id
	 * @param string $checksum
	 * @param int $topic_id Limit to one topic, 0 for any
	 * @param bool $first_post Only posts that start their topic
	 * @return array|null topic_id and post_id
	 */
	protected function find_post($after_post_id, $checksum, $topic_id = 0, $first_post = false)
	{
		if ($checksum === '')
		{
			return null;
		}
		$sql = 'SELECT p.post_id, p.topic_id
			FROM ' . POSTS_TABLE . ' p' . ($first_post ? ', ' . TOPICS_TABLE . ' t' : '') . '
			WHERE p.post_id > ' . (int) $after_post_id . "
				AND p.post_checksum = '" . $this->db->sql_escape($checksum) . "'" .
				($topic_id ? ' AND p.topic_id = ' . (int) $topic_id : '') .
				($first_post ? ' AND t.topic_id = p.topic_id AND t.topic_first_post_id = p.post_id' : '') . '
			ORDER BY p.post_id ASC';
		$result = $this->db->sql_query_limit($sql, 1);
		$post = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $post ? array('topic_id' => (int) $post['topic_id'], 'post_id' => (int) $post['post_id']) : null;
	}

	/**
	 * Roll back a transaction submit_post() left open when it failed before committing
	 *
	 * Feed post bot never wraps submit_post() in a transaction of its own: phpBB finishes a
	 * post after its commit with statements that may fail on purpose (markread() and search
	 * indexing use sql_return_on_error()), which would roll back an outer transaction and
	 * silently discard the post. Instead, every result is confirmed from the database.
	 */
	protected function discard_open_transaction()
	{
		if ($this->db->get_transaction())
		{
			$this->db->sql_transaction('rollback');
		}
	}

	/**
	 * @param string $url
	 * @param \Throwable $error
	 */
	protected function log_exception($url, \Throwable $error)
	{
		$detail = $error->getMessage();
		if ($detail !== '' && $this->language->is_set($detail))
		{
			$detail = (string) $this->language->lang($detail);
		}
		$this->log_feed_error($url, $detail !== '' ? $this->language->lang('FPB_PROCESSING_FAILED') . ' (' . $detail . ')' : 'FPB_PROCESSING_FAILED');
	}

	/**
	 * Check a known item for changes
	 *
	 * @param array $item
	 * @param array $row Tracking row
	 * @param int $source_id
	 * @return int Number of topics created
	 */
	protected function process_tracked_item(array $item, array $row, $source_id)
	{
		if ($row['item_state'] === item_tracker::STATE_DETACHED)
		{
			return 0;
		}
		$source = $this->current_state[$source_id];
		$fingerprint = $this->source_fingerprint($item, $source);
		if ($fingerprint['scheme'] !== $row['fingerprint_scheme'])
		{
			// The meaning of the fingerprints changed: adopt the current item as baseline without updating.
			$content_hash = $this->content_fingerprint($this->build_post_content($item, $source));
			$this->update_row($row, array(
				'source_hash'			=> $fingerprint['hash'],
				'content_hash'			=> $content_hash,
				'fingerprint_scheme'	=> $fingerprint['scheme'],
				// Keep an owed update reply owed.
				'notified_hash'			=> $row['notified_hash'] === $row['content_hash'] ? $content_hash : $row['notified_hash'],
			));
			return 0;
		}
		if ($fingerprint['hash'] === $row['source_hash'])
		{
			return 0;
		}
		$content = $this->build_post_content($item, $source);
		$content_hash = $this->content_fingerprint($content);
		if ($content_hash === $row['content_hash'])
		{
			// The feed changed in a way that renders identically: no edit, no reply.
			$this->update_row($row, array('source_hash' => $fingerprint['hash']));
			return 0;
		}
		return $this->update_tracked_item($item, $row, $source_id, $fingerprint, $content, $content_hash);
	}

	/**
	 * Apply a changed item to its tracked topic
	 *
	 * @param array $item
	 * @param array $row
	 * @param int $source_id
	 * @param array $fingerprint
	 * @param array $content
	 * @param string $content_hash
	 * @return int Number of topics created (recreation of a permanently deleted topic)
	 */
	protected function update_tracked_item(array $item, array $row, $source_id, array $fingerprint, array $content, $content_hash)
	{
		$source = $this->current_state[$source_id];
		if ($row['item_state'] === item_tracker::STATE_GONE && empty($source['recreate_deleted']))
		{
			$this->observe_gone($source, $row, $fingerprint, $content_hash);
			return 0;
		}
		$target = $this->load_target($row['topic_id'], $row['post_id']);
		list($state, $reason) = $this->inspect_target($row, $target);
		if ($state === item_tracker::STATE_GONE)
		{
			if (!empty($source['recreate_deleted']))
			{
				return $this->recreate_tracked($item, $row, $source_id, $fingerprint);
			}
			$this->observe_gone($source, $row, $fingerprint, $content_hash);
			return 0;
		}
		if ($state === item_tracker::STATE_ACTIVE)
		{
			list($state, $reason) = $this->check_edit_target($target);
		}
		if ($state !== item_tracker::STATE_ACTIVE)
		{
			// Nothing is advanced: a blocked change is applied once the topic may be edited again.
			$this->set_item_state($source, $row, $state, $reason);
			return 0;
		}

		// The phpBB content and posting functions are not loaded on every request (cron, CLI).
		if (!$this->load_posting_functions())
		{
			throw new \RuntimeException('FPB_PROCESSING_FAILED');
		}
		$post = $target['post'];
		$topic = $target['topic'];
		$title = $content['subject'];
		$storage = $this->storage_fields($content);
		$data = array(
			'forum_id'					=> (int) $topic['forum_id'],
			'topic_id'					=> (int) $topic['topic_id'],
			'post_id'					=> (int) $post['post_id'],
			'poster_id'					=> (int) $post['poster_id'],
			'icon_id'					=> (int) $post['icon_id'],
			'enable_bbcode'				=> $content['enable_bbcode'],
			'enable_smilies'			=> $content['enable_smilies'],
			'enable_urls'				=> $content['enable_urls'],
			'enable_sig'				=> (bool) $post['enable_sig'],
			'message'					=> $storage['message'],
			'message_md5'				=> $storage['message_md5'],
			'bbcode_bitfield'			=> $storage['bbcode_bitfield'],
			'bbcode_uid'				=> $storage['bbcode_uid'],
			'post_edit_locked'			=> 0,
			'post_edit_reason'			=> (string) $this->language->lang('FPB_EDIT_REASON'),
			'post_edit_user'			=> (int) $this->user->data['user_id'],
			'topic_title'				=> $title,
			'notify_set'				=> true,
			'notify'					=> true,
			'post_time'					=> 0,
			'forum_name'				=> (string) $target['forum']['forum_name'],
			'enable_indexing'			=> true,
			'post_visibility'			=> (int) $post['post_visibility'],
			'topic_visibility'			=> (int) $topic['topic_visibility'],
			'topic_posts_approved'		=> (int) $topic['topic_posts_approved'],
			'topic_posts_unapproved'	=> (int) $topic['topic_posts_unapproved'],
			'topic_posts_softdeleted'	=> (int) $topic['topic_posts_softdeleted'],
			'topic_first_post_id'		=> (int) $topic['topic_first_post_id'],
			'topic_last_post_id'		=> (int) $topic['topic_last_post_id'],
			'topic_attachment'			=> (int) $topic['topic_attachment'],
			'topic_time_limit'			=> (int) ((int) $topic['topic_time_limit'] / 86400),
		);
		$rss_item = $item;
		$mapping = $row;
		$do_update = true;
		// Decided by the settings, not by what listeners do with the event variables.
		$announce = !empty($source['update_reply']);
		// Listeners may change the content, never which post is edited or how phpBB classifies the edit.
		$identity = array_intersect_key($data, array_flip(array('forum_id', 'topic_id', 'post_id', 'poster_id', 'post_edit_user',
			'post_visibility', 'topic_visibility', 'topic_posts_approved', 'topic_posts_unapproved', 'topic_posts_softdeleted',
			'topic_first_post_id', 'topic_last_post_id', 'topic_attachment', 'topic_time_limit')));

		/**
		 * Modify or veto the update of a tracked first post
		 *
		 * @event ger.feedpostbot.update_post_before
		 * @var  array   data       Data array sent to submit_post() in edit mode. Content fields may be
		 *                          changed (keep message_md5 in line with message); the ids, counters and
		 *                          visibility of the target are restored afterwards
		 * @var  array   rss_item   Complete feed item as fetched by parse_feed
		 * @var  array   source     Source settings
		 * @var  string  title      Topic title
		 * @var  array   mapping    Tracking row of the topic
		 * @var  array   target     Live post, topic and forum rows
		 * @var  bool    do_update  Set to false to skip this version of the feed item: it is not retried,
		 *                          while the published first post and any owed update reply stay as they are
		 * @since 1.2.0
		 */
		$vars = array('data', 'rss_item', 'source', 'title', 'mapping', 'target', 'do_update');
		$event_data = $this->phpbb_dispatcher->trigger_event('ger.feedpostbot.update_post_before', compact($vars));
		if (is_array($event_data) || $event_data instanceof \ArrayAccess)
		{
			extract((array) $event_data);
		}
		if (!$do_update)
		{
			// Only the observed feed version advances: nothing new was published, so the
			// published content and an update reply still owed for it are left alone.
			if (
				!$this->tracker->update($row['tracking_id'], array('source_hash' => $fingerprint['hash'], 'item_state' => item_tracker::STATE_ACTIVE,
				'state_reason' => item_tracker::REASON_NONE), array('pending_op' => item_tracker::PENDING_NONE, 'source_hash' => $row['source_hash']))
			)
			{
				throw new \RuntimeException('FPB_TRACKING_CONFLICT');
			}
			return 0;
		}

		// Only the mapped first post is ever edited, in its current place, without forcing a visibility.
		$data = array_merge($data, $identity);
		unset($data['force_approved_state'], $data['force_visibility']);
		// Record the edit before submitting: phpBB commits it on its own.
		$pending = array(
			'pending_op'			=> item_tracker::PENDING_EDIT,
			'pending_source_hash'	=> $fingerprint['hash'],
			'pending_content_hash'	=> $content_hash,
			'pending_checksum'		=> (string) $data['message_md5'],
			'pending_after_post_id'	=> 0,
		);
		if (
			!$this->tracker->update($row['tracking_id'], $pending, array('pending_op' => item_tracker::PENDING_NONE,
			'source_hash' => $row['source_hash'], 'content_hash' => $row['content_hash']))
		)
		{
			throw new \RuntimeException('FPB_TRACKING_CONFLICT');
		}
		$row = array_merge($row, $pending);
		// What phpBB records for this edit: the edit reason is never empty, so submit_post() sets the edit
		// time and user and increments the edit count of the fixed target post.
		$edit_mark = array('count' => (int) $post['post_edit_count'], 'time' => time());
		$poll = array();
		$error = null;
		try
		{
			$this->submit('edit', $title, (string) $topic['topic_first_poster_name'], (int) $topic['topic_type'], $poll, $data);
		}
		catch (\Throwable $exception)
		{
			$error = $exception;
		}
		$this->discard_open_transaction();
		$target = $this->load_target($row['topic_id'], $row['post_id']);
		if ($this->edit_landed($row, $target) || $this->edit_recorded($target, $edit_mark))
		{
			$this->settle_edit($row, $target, $announce);
			$this->run_result['updated']++;
			if ($error !== null)
			{
				// The edit is stored: the error happened after phpBB had committed it.
				$this->log_exception($source['url'], $error);
			}
			return 0;
		}
		// The change stays pending and is retried on the next run; log only the first failure.
		$failure = array();
		if ($row['state_reason'] !== item_tracker::REASON_EDIT_ERROR)
		{
			$failure = array('item_state' => item_tracker::STATE_ACTIVE, 'state_reason' => item_tracker::REASON_EDIT_ERROR);
		}
		$this->clear_pending($row, $failure);
		if (!empty($failure))
		{
			$this->log_item_state($source, $row, item_tracker::STATE_ACTIVE, item_tracker::REASON_EDIT_ERROR);
			$this->log_exception($source['url'], $error !== null ? $error : new \RuntimeException(''));
		}
		return 0;
	}

	/**
	 * Classify the live topic of a tracking row
	 *
	 * @param array $row
	 * @param array $target As returned by load_target()
	 * @return array state and reason
	 */
	protected function inspect_target(array $row, array $target)
	{
		$post = $target['post'];
		$topic = $target['topic'];
		if ($post === null && $topic === null)
		{
			return array(item_tracker::STATE_GONE, item_tracker::REASON_HARD_DELETED);
		}
		if ($post === null)
		{
			return array(item_tracker::STATE_DETACHED, item_tracker::REASON_FIRST_POST_REMOVED);
		}
		if ($topic === null || (int) $post['topic_id'] !== (int) $row['topic_id'] || !empty($topic['topic_moved_id']))
		{
			return array(item_tracker::STATE_DETACHED, item_tracker::REASON_STRUCTURE);
		}
		$guard = $this->guard_hash($target);
		if ($guard !== $row['guard_hash'])
		{
			if (strpos($row['guard_hash'], 'g' . self::GUARD_VERSION . ':') === 0)
			{
				return array(item_tracker::STATE_DETACHED, item_tracker::REASON_MANUAL_EDIT);
			}
			// Guard of an older version: adopt the current post as it cannot be compared.
			$this->tracker->update($row['tracking_id'], array('guard_hash' => $guard));
		}
		if ((int) $post['post_visibility'] === ITEM_DELETED || (int) $topic['topic_visibility'] === ITEM_DELETED)
		{
			return array(item_tracker::STATE_BLOCKED, item_tracker::REASON_SOFT_DELETED);
		}
		// Checked after soft deletion: soft deleting the first post moves topic_first_post_id.
		if ((int) $topic['topic_first_post_id'] !== (int) $row['post_id'])
		{
			return array(item_tracker::STATE_DETACHED, item_tracker::REASON_STRUCTURE);
		}
		return array(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	/**
	 * Can the tracked first post be updated now?
	 *
	 * Like topic creation, updates do not check the feed user's forum permissions. Only the
	 * forum must accept posts, and a post a moderator locked against editing is never changed.
	 *
	 * @param array $target
	 * @return array state and reason
	 */
	protected function check_edit_target(array $target)
	{
		if ($target['forum'] === null || (int) $target['forum']['forum_type'] !== FORUM_POST)
		{
			return array(item_tracker::STATE_BLOCKED, item_tracker::REASON_FORUM_NOT_POSTABLE);
		}
		if (!empty($target['post']['post_edit_locked']))
		{
			return array(item_tracker::STATE_BLOCKED, item_tracker::REASON_POST_EDIT_LOCKED);
		}
		return array(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	/**
	 * Record a blocked or detached topic, logging each change of state once
	 *
	 * @param array $source
	 * @param array $row
	 * @param int $state
	 * @param int $reason
	 */
	protected function set_item_state(array $source, array $row, $state, $reason)
	{
		if ($row['item_state'] === $state && $row['state_reason'] === $reason)
		{
			return;
		}
		$this->tracker->update($row['tracking_id'], array('item_state' => $state, 'state_reason' => $reason));
		$this->log_item_state($source, $row, $state, $reason);
	}

	/**
	 * A changed item whose topic was permanently deleted and will not be recreated
	 *
	 * The observed version becomes the baseline, so enabling recreation later only reacts to
	 * newer changes. An owed update reply has nothing left to announce.
	 *
	 * @param array $source
	 * @param array $row
	 * @param array $fingerprint
	 * @param string $content_hash
	 */
	protected function observe_gone(array $source, array $row, array $fingerprint, $content_hash)
	{
		$this->update_row($row, array(
			'source_hash'		=> $fingerprint['hash'],
			'content_hash'		=> $content_hash,
			'notified_hash'		=> $content_hash,
			'item_state'		=> item_tracker::STATE_GONE,
			'state_reason'		=> item_tracker::REASON_HARD_DELETED,
			'reply_retry_count'	=> 0,
			'reply_next_retry'	=> 0,
			'reply_reason'		=> item_tracker::REASON_NONE,
		));
		if ($row['item_state'] !== item_tracker::STATE_GONE)
		{
			$this->log_item_state($source, $row, item_tracker::STATE_GONE, item_tracker::REASON_HARD_DELETED);
		}
	}

	/**
	 * Create a new topic for a changed item whose topic was permanently deleted
	 *
	 * @param array $item
	 * @param array $row
	 * @param int $source_id
	 * @param array $fingerprint
	 * @return int Number of topics created
	 */
	protected function recreate_tracked(array $item, array $row, $source_id, array $fingerprint)
	{
		$pending = array(
			'pending_op'			=> item_tracker::PENDING_RECREATE,
			'pending_source_hash'	=> $fingerprint['hash'],
			'pending_content_hash'	=> '',
			'pending_checksum'		=> '',
			'pending_after_post_id'	=> $this->max_post_id(),
			'fingerprint_scheme'	=> $fingerprint['scheme'],
		);
		if (!$this->tracker->update($row['tracking_id'], $pending, array('pending_op' => item_tracker::PENDING_NONE, 'topic_id' => (int) $row['topic_id'])))
		{
			throw new \RuntimeException('FPB_TRACKING_CONFLICT');
		}
		$result = $this->publish_tracked_topic($item, $source_id, array_merge($row, $pending));
		if ($result === false)
		{
			throw new \RuntimeException('FPB_PROCESSING_FAILED');
		}
		return is_string($result) ? 1 : 0;
	}

	/**
	 * Post the update replies still owed for a feed
	 *
	 * Runs independently of the feed download: a reply only needs the tracked topic.
	 *
	 * @param int $source_id
	 */
	public function flush_pending_replies($source_id)
	{
		if ($this->tracker === null || !isset($this->current_state[$source_id]))
		{
			return;
		}
		$source = $this->current_state[$source_id];
		// While updates are switched off, tracked topics are frozen, owed replies included.
		if (empty($source['source_uid']) || empty($source['update_existing']))
		{
			return;
		}
		if (empty($source['update_reply']))
		{
			// Update replies were switched off: owed replies are deliberately waived.
			$this->tracker->cancel_replies($source['source_uid']);
			return;
		}
		$rows = $this->tracker->pending_replies($source['source_uid'], time());
		if (empty($rows) || !$this->valid_source($source))
		{
			return;
		}
		$context = $this->capture_user_context();
		try
		{
			if (!$this->switch_user($source['user_id']))
			{
				return;
			}
			foreach ($rows as $row)
			{
				if ($this->run_deadline && microtime(true) >= $this->run_deadline)
				{
					break;
				}
				if ($row['pending_op'] !== item_tracker::PENDING_NONE)
				{
					$row = $this->resolve_pending($source, $row);
					if (
						$row === null || $row['pending_op'] !== item_tracker::PENDING_NONE || $row['notified_hash'] === $row['content_hash']
						|| $row['reply_next_retry'] > time() || !in_array($row['item_state'], array(item_tracker::STATE_ACTIVE, item_tracker::STATE_BLOCKED), true)
					)
					{
						continue;
					}
				}
				$this->post_update_reply($source, $row);
			}
		}
		finally
		{
			$this->restore_user_context($context);
		}
	}

	/**
	 * Announce the latest update of a tracked topic with a reply
	 *
	 * @param array $source
	 * @param array $row
	 */
	protected function post_update_reply(array $source, array $row)
	{
		$target = $this->load_target($row['topic_id'], $row['post_id']);
		list($state, $reason) = $this->inspect_target($row, $target);
		if ($state === item_tracker::STATE_GONE)
		{
			// Nothing left to announce.
			$this->tracker->update($row['tracking_id'], array('notified_hash' => $row['content_hash'], 'item_state' => $state,
				'state_reason' => $reason, 'reply_retry_count' => 0, 'reply_next_retry' => 0, 'reply_reason' => item_tracker::REASON_NONE));
			if ($row['item_state'] !== $state)
			{
				$this->log_item_state($source, $row, $state, $reason);
			}
			return;
		}
		if ($state === item_tracker::STATE_DETACHED)
		{
			$this->set_item_state($source, $row, $state, $reason);
			return;
		}
		if ($state === item_tracker::STATE_ACTIVE)
		{
			list($state, $reason) = $this->check_reply_target($target);
		}
		if ($state !== item_tracker::STATE_ACTIVE)
		{
			// Still owed: posted once the topic accepts replies again. Not an error, so no backoff.
			if ($row['reply_reason'] !== $reason)
			{
				$this->tracker->update($row['tracking_id'], array('reply_reason' => $reason));
				if (!($row['item_state'] === $state && $row['state_reason'] === $reason))
				{
					$this->log_item_state($source, $row, $state, $reason);
				}
			}
			return;
		}

		$topic = $target['topic'];
		$forum_name = (string) $target['forum']['forum_name'];
		$title = (string) $topic['topic_title'];
		$subject = (strpos($title, 'Re: ') === 0 ? '' : 'Re: ') . $title;
		// The reply is stored, so the date must be absolute: never phpBB's relative "x minutes ago".
		$reply_text = (string) $this->language->lang('FPB_ITEM_UPDATED_REPLY', $this->user->format_date($row['updated_time'] ?: time(), false, true));
		$mapping = $row;
		$do_reply = true;

		/**
		 * Modify or cancel the reply announcing an update of a tracked topic
		 *
		 * @event ger.feedpostbot.update_reply_before
		 * @var  string  subject     Reply subject
		 * @var  string  reply_text  Reply text (BBCode source)
		 * @var  array   source      Source settings
		 * @var  array   mapping     Tracking row of the topic
		 * @var  array   target      Live post, topic and forum rows
		 * @var  bool    do_reply    Set to false to cancel this reply
		 * @since 1.2.0
		 */
		$vars = array('subject', 'reply_text', 'source', 'mapping', 'target', 'do_reply');
		$event_data = $this->phpbb_dispatcher->trigger_event('ger.feedpostbot.update_reply_before', compact($vars));
		if (is_array($event_data) || $event_data instanceof \ArrayAccess)
		{
			extract((array) $event_data);
		}
		if (!$do_reply)
		{
			$this->tracker->update($row['tracking_id'], array('notified_hash' => $row['content_hash'], 'reply_retry_count' => 0,
				'reply_next_retry' => 0, 'reply_reason' => item_tracker::REASON_NONE));
			return;
		}

		// The phpBB content and posting functions are not loaded on every request (cron, CLI).
		if (!$this->load_posting_functions())
		{
			throw new \RuntimeException('FPB_PROCESSING_FAILED');
		}
		$storage = $this->storage_fields(array('message' => $reply_text, 'enable_bbcode' => true, 'enable_smilies' => true, 'enable_urls' => true));
		$poll = array();
		$data = array(
			'forum_id'			=> (int) $topic['forum_id'],
			'topic_id'			=> (int) $topic['topic_id'],
			'icon_id'			=> 0,
			'enable_bbcode'		=> true,
			'enable_smilies'	=> true,
			'enable_urls'		=> true,
			'enable_sig'		=> true,
			'message'			=> $storage['message'],
			'message_md5'		=> $storage['message_md5'],
			'bbcode_bitfield'	=> $storage['bbcode_bitfield'],
			'bbcode_uid'		=> $storage['bbcode_uid'],
			'post_edit_locked'	=> 0,
			'topic_title'		=> $title,
			'notify_set'		=> true,
			'notify'			=> true,
			'post_time'			=> 0,
			'forum_name'		=> $forum_name,
			'enable_indexing'	=> true,
		);
		// Record the attempt without settling the debt: only a reply found afterwards settles it.
		$pending = array(
			'pending_op'			=> item_tracker::PENDING_REPLY,
			'pending_source_hash'	=> '',
			'pending_content_hash'	=> $row['content_hash'],
			'pending_checksum'		=> $storage['message_md5'],
			'pending_after_post_id'	=> $this->max_post_id(),
		);
		if (
			!$this->tracker->update($row['tracking_id'], $pending, array('pending_op' => item_tracker::PENDING_NONE,
			'content_hash' => $row['content_hash'], 'notified_hash' => $row['notified_hash']))
		)
		{
			throw new \RuntimeException('FPB_TRACKING_CONFLICT');
		}
		$row = array_merge($row, $pending);
		$error = null;
		try
		{
			$this->submit('reply', $subject, $this->user->data['username'], POST_NORMAL, $poll, $data);
		}
		catch (\Throwable $exception)
		{
			$error = $exception;
		}
		$this->discard_open_transaction();
		if (
			$this->reply_posted($row, isset($data['post_id']) ? (int) $data['post_id'] : 0)
			|| $this->find_post($row['pending_after_post_id'], $row['pending_checksum'], $row['topic_id']) !== null
		)
		{
			// The reply announces the newest successfully published version.
			$this->settle_reply($row);
			if ($error !== null)
			{
				$this->log_exception($source['url'], $error);
			}
			return;
		}
		// A reply that cannot be found is still owed: retry later with a growing delay.
		$attempts = $row['reply_retry_count'] + 1;
		$this->clear_pending($row, array(
			'reply_retry_count'	=> min($attempts, 65535),
			'reply_next_retry'	=> time() + (int) min(self::REPLY_RETRY_DELAY * pow(2, min($attempts - 1, 20)), self::REPLY_RETRY_MAX_DELAY),
			'reply_reason'		=> item_tracker::REASON_REPLY_ERROR,
		));
		// Log the 1st, 2nd, 4th, 8th... consecutive failure.
		if (($attempts & ($attempts - 1)) === 0)
		{
			$this->log_item_state($source, $row, $row['item_state'], item_tracker::REASON_REPLY_ERROR);
			$this->log_exception($source['url'], $error !== null ? $error : new \RuntimeException(''));
		}
	}

	/**
	 * Is the post submit_post() reported in this process the update reply?
	 *
	 * Listeners may have rewritten its stored message and checksum, so it is identified by the id phpBB
	 * reported, the tracked topic, the feed user and being newer than the recorded bound.
	 *
	 * @param array $row Row with a pending reply
	 * @param int $post_id
	 * @return bool
	 */
	protected function reply_posted(array $row, $post_id)
	{
		if ($post_id <= (int) $row['pending_after_post_id'])
		{
			return false;
		}
		$post = $this->load_target($row['topic_id'], $post_id)['post'];
		return $post !== null && (int) $post['topic_id'] === (int) $row['topic_id'] && (int) $post['poster_id'] === (int) $this->user->data['user_id'];
	}

	/**
	 * Can the update reply be posted now?
	 *
	 * Like topic creation, replies do not check the feed user's forum permissions. The forum
	 * must accept posts, and a topic awaiting approval keeps its reply owed until approved.
	 *
	 * @param array $target
	 * @return array state and reason
	 */
	protected function check_reply_target(array $target)
	{
		if ($target['forum'] === null || (int) $target['forum']['forum_type'] !== FORUM_POST)
		{
			return array(item_tracker::STATE_BLOCKED, item_tracker::REASON_FORUM_NOT_POSTABLE);
		}
		if ((int) $target['topic']['topic_visibility'] !== ITEM_APPROVED)
		{
			return array(item_tracker::STATE_BLOCKED, item_tracker::REASON_UNAPPROVED);
		}
		return array(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	/**
	 * Load the live rows of a tracked topic
	 *
	 * @param int $topic_id
	 * @param int $post_id
	 * @return array post, topic and forum rows, each null when missing
	 */
	protected function load_target($topic_id, $post_id)
	{
		$target = array('post' => null, 'topic' => null, 'forum' => null);
		$queries = array(
			'post'	=> 'SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id = ' . (int) $post_id,
			'topic'	=> 'SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . (int) $topic_id,
		);
		foreach ($queries as $name => $sql)
		{
			$result = $this->db->sql_query($sql);
			$target[$name] = $this->db->sql_fetchrow($result) ?: null;
			$this->db->sql_freeresult($result);
		}
		if ($target['topic'] !== null)
		{
			$result = $this->db->sql_query('SELECT forum_id, forum_name, forum_type, forum_status
				FROM ' . FORUMS_TABLE . '
				WHERE forum_id = ' . (int) $target['topic']['forum_id']);
			$target['forum'] = $this->db->sql_fetchrow($result) ?: null;
			$this->db->sql_freeresult($result);
		}
		return $target;
	}

	/**
	 * Integrity guard of a tracked first post
	 *
	 * Covers only what belongs to the first post itself. Topic data maintained by replies
	 * (last post, counters, bumps, topic_attachment) is deliberately excluded, as is the
	 * storage representation of the text.
	 *
	 * @param array $target As returned by load_target()
	 * @return string
	 */
	protected function guard_hash(array $target)
	{
		if ($target['post'] === null || $target['topic'] === null)
		{
			return '';
		}
		$post = $target['post'];
		$topic = $target['topic'];
		return 'g' . self::GUARD_VERSION . ':' . hash('sha256', (string) json_encode(array(
			'poster_id'			=> (int) $post['poster_id'],
			'post_subject'		=> (string) $post['post_subject'],
			'topic_title'		=> (string) $topic['topic_title'],
			'body'				=> hash('sha256', $this->unparse((string) $post['post_text'])),
			'post_attachment'	=> (int) $post['post_attachment'],
			'poll'				=> (int) $topic['poll_start'] > 0 || (string) $topic['poll_title'] !== '',
			'enable_bbcode'		=> (int) $post['enable_bbcode'],
			'enable_smilies'	=> (int) $post['enable_smilies'],
			'enable_magic_url'	=> (int) $post['enable_magic_url'],
		), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	}

	/**
	 * Source text of a stored message, independent of BBCode UID and parser configuration
	 *
	 * @param string $text
	 * @return string
	 */
	protected function unparse($text)
	{
		if (!preg_match('#^<[rt][ >]#', $text))
		{
			return $text;
		}
		if ($this->text_formatter_utils === null)
		{
			global $phpbb_container;
			$this->text_formatter_utils = $phpbb_container->get('text_formatter.utils');
		}
		return $this->text_formatter_utils->unparse($text);
	}

	/**
	 * Log a change of the tracking state of a topic
	 *
	 * @param array $source
	 * @param array $row
	 * @param int $state
	 * @param int $reason
	 */
	protected function log_item_state(array $source, array $row, $state, $reason)
	{
		$this->language->add_lang('info_acp_feedpostbot', 'ger/feedpostbot');
		$user_id = isset($this->user->data['user_id']) ? (int) $this->user->data['user_id'] : ANONYMOUS;
		$this->log->add(self::LOG_ADMIN, $user_id, $this->user->ip, 'FPB_LOG_ITEM_STATE', time(), array(
			$source['url'],
			(int) $row['topic_id'],
			$this->language->lang('FPB_STATE_' . (int) $state),
			$this->language->lang('FPB_REASON_' . (int) $reason),
		));
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
		$this->last_submission = null;
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
		$reproducible = $this->submitted_content($title, $data);

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
			// A listener changed the content; Feed post bot cannot reproduce it on updates.
			$customized = $reproducible !== $this->submitted_content($title, $data);
			if ($this->submit_hook !== null)
			{
				call_user_func($this->submit_hook, $data, $content, $customized);
			}
			try
			{
				$result = $this->submit('post', $title, $this->user->data['username'], POST_NORMAL, $poll, $data);
			}
			finally
			{
				// submit_post() fills in the ids as soon as it inserted the rows, even if it fails later.
				$this->last_submission = array(
					'topic_id'		=> isset($data['topic_id']) ? (int) $data['topic_id'] : 0,
					'post_id'		=> isset($data['post_id']) ? (int) $data['post_id'] : 0,
					'content'		=> $content,
					'customized'	=> $customized,
				);
			}
			return is_string($result) && $result !== '' ? $result : false;
		}
		return true;
	}

	/**
	 * Content fields of a submission, for detecting changes made by submit_post_before listeners
	 *
	 * @param string $title
	 * @param array $data
	 * @return array
	 */
	protected function submitted_content($title, array $data)
	{
		$fields = array('title' => (string) $title);
		foreach (array('topic_title', 'message', 'bbcode_uid', 'bbcode_bitfield') as $field)
		{
			$fields[$field] = isset($data[$field]) ? (string) $data[$field] : '';
		}
		foreach (array('enable_bbcode', 'enable_smilies', 'enable_urls') as $field)
		{
			$fields[$field] = !empty($data[$field]);
		}
		return $fields;
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
