<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests\fixtures;

use ger\feedpostbot\classes\item_tracker;

/**
 * Test harness for update tracking: in-memory tracked item storage, an in-memory forum that
 * emulates submit_post() and a database mock whose transactions snapshot both stores.
 */
class memory_tracker extends item_tracker
{
	public $rows = array();
	public $next_id = 1;
	public $fail_insert = false;
	public $fail_update = false;
	public $fail_delete = false;
	/** @var callable|null fn(array $fields): bool, throw for matching updates (e.g. only settling ones) */
	public $fail_on;
	public $writes = array();

	public function __construct()
	{
	}

	public function load($source_uid, array $item_keys)
	{
		$rows = array();
		foreach ($this->rows as $row)
		{
			if ($row['source_uid'] === $source_uid && in_array($row['item_key'], $item_keys, true))
			{
				$rows[$row['item_key']] = $row;
			}
		}
		return $rows;
	}

	public function pending_replies($source_uid, $now)
	{
		$rows = array();
		foreach ($this->rows as $row)
		{
			if ($row['source_uid'] === $source_uid && $row['notified_hash'] !== $row['content_hash']
				&& in_array($row['item_state'], array(self::STATE_ACTIVE, self::STATE_BLOCKED), true) && $row['reply_next_retry'] <= $now)
			{
				$rows[] = $row;
			}
		}
		return $rows;
	}

	public function insert(array $row)
	{
		if ($this->fail_insert)
		{
			throw new \RuntimeException('insert failed');
		}
		foreach ($this->rows as $existing)
		{
			if ($existing['source_uid'] === $row['source_uid'] && $existing['item_key'] === $row['item_key'])
			{
				throw new \RuntimeException('duplicate key');
			}
		}
		$row['tracking_id'] = $this->next_id++;
		$this->rows[$row['tracking_id']] = $row;
		return $row['tracking_id'];
	}

	public function update($tracking_id, array $fields, array $expected = array())
	{
		if ($this->fail_update || ($this->fail_on !== null && call_user_func($this->fail_on, $fields)))
		{
			throw new \RuntimeException('update failed');
		}
		if (!$this->matches($tracking_id, $expected))
		{
			return empty($expected);
		}
		$this->writes[] = $fields;
		$this->rows[$tracking_id] = array_merge($this->rows[$tracking_id], $fields);
		return true;
	}

	public function delete($tracking_id, array $expected = array())
	{
		if (!$this->matches($tracking_id, $expected))
		{
			return empty($expected);
		}
		unset($this->rows[$tracking_id]);
		return true;
	}

	protected function matches($tracking_id, array $expected)
	{
		if (!isset($this->rows[$tracking_id]))
		{
			return false;
		}
		foreach ($expected as $column => $value)
		{
			if ($this->rows[$tracking_id][$column] !== $value)
			{
				return false;
			}
		}
		return true;
	}

	public function cancel_replies($source_uid)
	{
		foreach ($this->rows as $id => $row)
		{
			if ($row['source_uid'] === $source_uid && $row['notified_hash'] !== $row['content_hash'])
			{
				$this->rows[$id] = array_merge($row, array('notified_hash' => $row['content_hash'], 'reply_retry_count' => 0,
					'reply_next_retry' => 0, 'reply_reason' => 0));
			}
		}
	}

	public function delete_sources(array $source_uids)
	{
		if ($this->fail_delete)
		{
			throw new \RuntimeException('delete failed');
		}
		foreach ($this->rows as $id => $row)
		{
			if (in_array($row['source_uid'], $source_uids, true))
			{
				unset($this->rows[$id]);
			}
		}
	}

	public function only()
	{
		return reset($this->rows);
	}
}

/**
 * Enough of phpBB's topics/posts behaviour for Feed post bot's use of submit_post().
 */
class memory_forum
{
	public $topics = array();
	public $posts = array();
	public $forums = array();
	public $calls = array();
	/** @var array mode => Throwable thrown by the next submit in that mode */
	public $fail = array();
	/** @var array mode => bool: fail every time */
	public $fail_always = array();
	/** @var array mode => Throwable thrown after the post was committed (e.g. by a notification listener) */
	public $fail_after = array();
	/** @var array mode => true: report success although nothing was stored */
	public $lose = array();
	/** @var array mode => Throwable thrown while phpBB's own transaction is still open */
	public $fail_open = array();
	/** @var callable|null Called with 'begin' when submit_post() opens its transaction */
	public $transactions;
	/**
	 * @var callable|null Like a core.modify_submit_post_data listener: fn($mode, array &$data).
	 * phpBB copies the event's data back into the caller's by-reference array.
	 */
	public $modify_submit_post_data;
	/**
	 * @var callable|null Like a core.submit_post_modify_sql_data listener changing the row written:
	 * fn($mode, array &$post). The caller's data is not changed.
	 */
	public $modify_sql_data;
	/** @var callable|null Runs right after submit_post() returned (also when nothing was stored): fn($mode, array $data) */
	public $after_submit;
	public $next_topic = 1;
	public $next_post = 1;
	public $approved = true;

	public function __construct()
	{
		$this->forums[5] = array('forum_id' => 5, 'forum_name' => 'News', 'forum_type' => FORUM_POST, 'forum_status' => ITEM_UNLOCKED);
		$this->forums[6] = array('forum_id' => 6, 'forum_name' => 'Archive', 'forum_type' => FORUM_POST, 'forum_status' => ITEM_UNLOCKED);
	}

	public function submit($mode, $subject, $username, $topic_type, array &$data, array $user)
	{
		$this->calls[] = array('mode' => $mode, 'subject' => $subject, 'username' => $username, 'topic_type' => $topic_type, 'data' => $data, 'user_id' => $user['user_id']);
		if (!empty($this->fail_always[$mode]))
		{
			throw new \RuntimeException($mode . ' failed');
		}
		if (isset($this->fail[$mode]))
		{
			$error = $this->fail[$mode];
			unset($this->fail[$mode]);
			throw $error;
		}
		if (isset($this->fail_open[$mode]))
		{
			$error = $this->fail_open[$mode];
			unset($this->fail_open[$mode]);
			call_user_func($this->transactions, 'begin');
			throw $error;
		}
		if (!empty($this->lose[$mode]))
		{
			unset($this->lose[$mode]);
			if ($mode !== 'edit')
			{
				$data['topic_id'] = $mode === 'post' ? $this->next_topic : $data['topic_id'];
				$data['post_id'] = $this->next_post;
			}
			if ($this->after_submit !== null)
			{
				call_user_func($this->after_submit, $mode, $data);
			}
			return './viewtopic.php?t=0';
		}
		if ($this->modify_submit_post_data !== null)
		{
			call_user_func_array($this->modify_submit_post_data, array($mode, &$data));
		}
		$url = $this->write($mode, $subject, $topic_type, $data, $user);
		if ($this->modify_sql_data !== null && isset($this->posts[(int) $data['post_id']]))
		{
			call_user_func_array($this->modify_sql_data, array($mode, &$this->posts[(int) $data['post_id']]));
		}
		if (isset($this->fail_after[$mode]))
		{
			$error = $this->fail_after[$mode];
			unset($this->fail_after[$mode]);
			throw $error;
		}
		if ($this->after_submit !== null)
		{
			call_user_func($this->after_submit, $mode, $data);
		}
		return $url;
	}

	protected function write($mode, $subject, $topic_type, array &$data, array $user)
	{
		$visibility = $this->approved ? ITEM_APPROVED : ITEM_UNAPPROVED;
		if ($mode === 'post')
		{
			$topic_id = $this->next_topic++;
			$post_id = $this->next_post++;
			$this->topics[$topic_id] = array('topic_id' => $topic_id, 'forum_id' => (int) $data['forum_id'], 'topic_title' => $subject,
				'topic_poster' => $user['user_id'], 'topic_first_post_id' => $post_id, 'topic_last_post_id' => $post_id,
				'topic_posts_approved' => $visibility == ITEM_APPROVED ? 1 : 0, 'topic_posts_unapproved' => $visibility == ITEM_APPROVED ? 0 : 1,
				'topic_posts_softdeleted' => 0, 'topic_visibility' => $visibility, 'topic_status' => ITEM_UNLOCKED, 'topic_type' => $topic_type,
				'topic_time_limit' => 0, 'topic_attachment' => 0, 'icon_id' => 0, 'poll_start' => 0, 'poll_title' => '',
				'topic_first_poster_name' => $user['username'], 'topic_last_poster_id' => $user['user_id'], 'topic_last_post_time' => 1000,
				'topic_moved_id' => 0);
			$this->posts[$post_id] = $this->new_post($post_id, $topic_id, $subject, $data, $user, $visibility);
			$data['topic_id'] = $topic_id;
			$data['post_id'] = $post_id;
			return './viewtopic.php?t=' . $topic_id;
		}
		if ($mode === 'reply')
		{
			$topic_id = (int) $data['topic_id'];
			$post_id = $this->next_post++;
			$this->posts[$post_id] = $this->new_post($post_id, $topic_id, $subject, $data, $user, $visibility);
			$this->topics[$topic_id]['topic_last_post_id'] = $post_id;
			$this->topics[$topic_id]['topic_posts_approved']++;
			$this->topics[$topic_id]['topic_last_poster_id'] = $user['user_id'];
			$this->topics[$topic_id]['topic_last_post_time'] = 2000 + $post_id;
			$data['post_id'] = $post_id;
			return './viewtopic.php?p=' . $post_id;
		}
		// edit
		$post_id = (int) $data['post_id'];
		$post = &$this->posts[$post_id];
		$post['post_subject'] = $subject;
		$post['post_text'] = $data['message'];
		$post['post_checksum'] = $data['message_md5'];
		$post['bbcode_uid'] = $data['bbcode_uid'];
		$post['enable_bbcode'] = (int) $data['enable_bbcode'];
		$post['enable_smilies'] = (int) $data['enable_smilies'];
		$post['enable_magic_url'] = (int) $data['enable_urls'];
		$post['enable_sig'] = (int) $data['enable_sig'];
		$post['icon_id'] = (int) $data['icon_id'];
		// Like submit_post(): edit information is recorded when an edit reason is given.
		if ((string) $data['post_edit_reason'] !== '')
		{
			$post['post_edit_reason'] = $data['post_edit_reason'];
			$post['post_edit_user'] = $data['post_edit_user'];
			$post['post_edit_time'] = time();
			$post['post_edit_count']++;
		}
		$topic = &$this->topics[(int) $data['topic_id']];
		if ($topic['topic_first_post_id'] == $post_id)
		{
			$topic['topic_title'] = $subject;
			$topic['topic_type'] = $topic_type;
			$topic['icon_id'] = (int) $data['icon_id'];
		}
		return './viewtopic.php?p=' . $post_id;
	}

	protected function new_post($post_id, $topic_id, $subject, array $data, array $user, $visibility)
	{
		return array('post_id' => $post_id, 'topic_id' => $topic_id, 'forum_id' => (int) $data['forum_id'], 'poster_id' => $user['user_id'],
			'post_subject' => $subject, 'post_text' => $data['message'], 'post_checksum' => $data['message_md5'],
			'bbcode_uid' => $data['bbcode_uid'], 'post_visibility' => $visibility, 'post_edit_locked' => 0, 'post_attachment' => 0,
			'enable_bbcode' => (int) $data['enable_bbcode'], 'enable_smilies' => (int) $data['enable_smilies'],
			'enable_magic_url' => (int) $data['enable_urls'], 'enable_sig' => (int) $data['enable_sig'], 'icon_id' => 0,
			'post_time' => isset($data['post_time']) ? (int) $data['post_time'] : 0, 'post_edit_reason' => '', 'post_edit_user' => 0,
			'post_edit_time' => 0, 'post_edit_count' => 0);
	}

	public function target($topic_id, $post_id)
	{
		$topic = isset($this->topics[$topic_id]) ? $this->topics[$topic_id] : null;
		return array(
			'post'	=> isset($this->posts[$post_id]) ? $this->posts[$post_id] : null,
			'topic'	=> $topic,
			'forum'	=> ($topic !== null && isset($this->forums[$topic['forum_id']])) ? $this->forums[$topic['forum_id']] : null,
		);
	}

	/** A post by any user in a topic, outside Feed post bot */
	public function insert_post($topic_id, $poster_id, $text)
	{
		$post_id = $this->user_reply($topic_id, $text);
		$this->posts[$post_id]['poster_id'] = $poster_id;
		return $post_id;
	}

	/** A normal discussion reply by another user */
	public function user_reply($topic_id, $text, $attachment = false)
	{
		$post_id = $this->next_post++;
		$this->posts[$post_id] = array('post_id' => $post_id, 'topic_id' => $topic_id, 'forum_id' => $this->topics[$topic_id]['forum_id'],
			'poster_id' => 50, 'post_subject' => 'Re: ' . $this->topics[$topic_id]['topic_title'], 'post_text' => $text,
			'post_checksum' => md5($text), 'bbcode_uid' => '', 'post_visibility' => ITEM_APPROVED, 'post_edit_locked' => 0,
			'post_attachment' => $attachment ? 1 : 0, 'enable_bbcode' => 1, 'enable_smilies' => 1, 'enable_magic_url' => 1, 'enable_sig' => 1,
			'icon_id' => 0, 'post_time' => 3000 + $post_id, 'post_edit_reason' => '', 'post_edit_user' => 0, 'post_edit_time' => 0, 'post_edit_count' => 0);
		$topic = &$this->topics[$topic_id];
		$topic['topic_last_post_id'] = $post_id;
		$topic['topic_posts_approved']++;
		$topic['topic_last_poster_id'] = 50;
		$topic['topic_last_post_time'] = 3000 + $post_id;
		if ($attachment)
		{
			$topic['topic_attachment'] = 1;
		}
		return $post_id;
	}

	public function max_post_id()
	{
		return $this->posts ? max(array_keys($this->posts)) : 0;
	}

	public function find_post($after_post_id, $checksum, $topic_id, $first_post)
	{
		ksort($this->posts);
		foreach ($this->posts as $post)
		{
			if ($post['post_id'] > $after_post_id && $post['post_checksum'] === $checksum && (!$topic_id || $post['topic_id'] == $topic_id)
				&& (!$first_post || (isset($this->topics[$post['topic_id']]) && $this->topics[$post['topic_id']]['topic_first_post_id'] == $post['post_id'])))
			{
				return array('topic_id' => (int) $post['topic_id'], 'post_id' => (int) $post['post_id']);
			}
		}
		return null;
	}

	public function snapshot()
	{
		return serialize(array($this->topics, $this->posts, $this->next_topic, $this->next_post));
	}

	public function restore($snapshot)
	{
		list($this->topics, $this->posts, $this->next_topic, $this->next_post) = unserialize($snapshot);
	}

	/** Posts of a topic in order */
	public function topic_posts($topic_id)
	{
		$posts = array();
		foreach ($this->posts as $post)
		{
			if ($post['topic_id'] == $topic_id)
			{
				$posts[$post['post_id']] = $post;
			}
		}
		ksort($posts);
		return $posts;
	}
}

/**
 * Feed post bot driver wired to the in-memory stores.
 */
class tracking_driver extends \ger\feedpostbot\classes\driver
{
	/** @var memory_forum */
	public $forum;
	public $items = array();
	public $lock;
	public $lock_available = true;
	public $bot_users = array();
	public static $uid_failure = false;
	/** @var bool Whether this process loaded phpBB's content and posting functions (reset per cron run) */
	public $functions_loaded = false;
	public $functions_available = true;
	public $loader_calls = 0;

	public function parse_feed($url, $type, $timeout = self::FEED_TIMEOUT_PARSE)
	{
		return $this->items;
	}

	protected function create_lock()
	{
		$this->lock = new tracking_lock();
		$this->lock->available = $this->lock_available;
		return $this->lock;
	}

	public function prepare_destinations(array $sources)
	{
		$this->user_data_cache = $this->bot_users;
		$this->forum_data_cache = $this->forum_name_cache = array();
		foreach ($this->forum->forums as $forum_id => $forum)
		{
			$this->forum_data_cache[$forum_id] = $forum;
			$this->forum_name_cache[$forum_id] = $forum['forum_name'];
		}
		$this->destinations_loaded = true;
	}

	public static function new_source_uid()
	{
		if (self::$uid_failure)
		{
			throw new \Exception('No entropy');
		}
		return parent::new_source_uid();
	}

	protected function load_posting_functions()
	{
		$this->loader_calls++;
		$this->functions_loaded = $this->functions_loaded || $this->functions_available;
		return $this->functions_loaded;
	}

	/** Like PHP: phpBB's functions only exist once includes/functions_*.php was loaded */
	protected function require_posting_functions($function)
	{
		if (!$this->functions_loaded)
		{
			throw new \Error('Call to undefined function ' . $function . '()');
		}
	}

	protected function storage_fields(array $content)
	{
		$this->require_posting_functions('generate_text_for_storage');
		// Like phpBB: the stored form carries a random BBCode UID.
		$text = '<r>' . $content['message'] . '</r>';
		return array('message' => $text, 'message_md5' => md5($text), 'bbcode_uid' => substr(md5(random_bytes(8)), 0, 8), 'bbcode_bitfield' => '');
	}

	protected function submit($mode, $subject, $username, $topic_type, &$poll, &$data)
	{
		$this->require_posting_functions('submit_post');
		return $this->forum->submit($mode, $subject, $username, $topic_type, $data, $this->user->data);
	}

	protected function load_target($topic_id, $post_id)
	{
		return $this->forum->target($topic_id, $post_id);
	}

	protected function max_post_id()
	{
		return $this->forum->max_post_id();
	}

	protected function find_post($after_post_id, $checksum, $topic_id = 0, $first_post = false)
	{
		return $checksum === '' ? null : $this->forum->find_post($after_post_id, $checksum, $topic_id, $first_post);
	}

	protected function unparse($text)
	{
		// Like s9e: the stored markup around the source text does not matter.
		return preg_match('#^<[rt][ >]#', $text) ? strip_tags($text) : $text;
	}

	public function state()
	{
		return $this->current_state;
	}
}

/**
 * Minimal user for phpBB's datetime class: a relative date format and its language strings.
 */
class date_user
{
	public $lang_name = 'fpbtest';
	public $date_format = '|j.n.Y|, \\k\\l\\o G.i';
	public $timezone;
	public $lang = array('datetime' => array(
		'AGO'		=> array(0 => 'less than a minute ago', 1 => '%d minute ago', 2 => '%d minutes ago'),
		'TODAY'		=> 'Today',
		'TOMORROW'	=> 'Tomorrow',
		'YESTERDAY'	=> 'Yesterday',
	));

	public function __construct()
	{
		$this->timezone = new \DateTimeZone('Europe/Helsinki');
	}

	public function lang($key, $count)
	{
		$forms = $this->lang['datetime']['AGO'];
		return sprintf($forms[min($count, 2)], $count);
	}
}

class tracking_lock
{
	public $available = true;
	public $released = false;

	public function acquire()
	{
		return $this->available;
	}

	public function release()
	{
		$this->released = true;
	}
}

/**
 * Base test case wiring the driver, forum and tracker together.
 */
abstract class tracking_test_case extends \phpbb_test_case
{
	/** @var tracking_driver */
	protected $driver;
	/** @var memory_forum */
	protected $forum;
	/** @var memory_tracker */
	protected $tracker;
	protected $saved;
	protected $user;
	protected $auth;
	protected $db;
	protected $config;
	protected $config_text;
	protected $logs = array();
	protected $listeners = array();
	/** @var array forum_id => permission => bool, for the current bot */
	protected $permissions = array();
	/** @var bool Whether submit_post() left its own transaction open */
	public $phpbb_transaction = false;
	public $rollbacks = 0;
	/** @var int Transactions Feed post bot opened itself (must stay 0, see driver::discard_open_transaction()) */
	public $own_transactions = 0;

	public function setUp(): void
	{
		parent::setUp();
		$this->forum = new memory_forum();
		$this->tracker = new memory_tracker();
		$this->config = new \phpbb\config\config(array('feedpostbot_locked' => 0, 'edit_time' => 0, 'display_unapproved_posts' => 1));
		$this->config_text = $this->getMockBuilder('\phpbb\config\db_text')->disableOriginalConstructor()->getMock();
		$this->config_text->method('get')->willReturnCallback(function () { return json_encode($this->saved); });
		$this->config_text->method('set')->willReturnCallback(function ($key, $value) { $this->saved = json_decode($value, true); });
		$this->user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
		$this->user->data = array('user_id' => 2, 'username' => 'Administrator', 'user_lang' => 'en', 'is_registered' => true);
		$this->user->timezone = new \DateTimeZone('UTC');
		$this->user->date_format = 'Y-m-d';
		$this->user->lang_name = 'en';
		$this->user->ip = '127.0.0.1';
		$this->user->method('lang')->willReturnArgument(0);
		// phpBB's real date formatting with a relative ("smart") date format, as boards commonly use.
		$this->user->method('format_date')->willReturnCallback(function ($time, $format = false, $forcedate = false) {
			$date = new \phpbb\datetime(new date_user(), '@' . (int) $time, new \DateTimeZone('UTC'));
			$date->setTimezone(new \DateTimeZone('Europe/Helsinki'));
			return $date->format($format, $forcedate);
		});
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();
		$language->method('lang')->willReturnCallback(function () { return implode('|', func_get_args()); });
		$language->method('is_set')->willReturn(true);
		$this->auth = $this->getMockBuilder('\phpbb\auth\auth')->disableOriginalConstructor()->getMock();
		$this->auth->method('acl')->willReturnCallback(function (&$data) {});
		$this->auth->method('acl_get')->willReturnCallback(function ($permission, $forum_id = 0) {
			return $this->allowed($permission, (int) $forum_id);
		});
		$this->db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$this->db->method('sql_transaction')->willReturnCallback(function ($status) {
			if ($status === 'begin')
			{
				$this->own_transactions++;
			}
			return $this->transaction($status);
		});
		$this->db->method('get_transaction')->willReturnCallback(function () { return $this->phpbb_transaction; });
		$this->forum->transactions = function ($status) { $this->transaction($status); };
		$log = $this->getMockBuilder('\phpbb\log\log')->disableOriginalConstructor()->getMock();
		$log->method('add')->willReturnCallback(function ($mode, $user_id, $ip, $operation, $time, $data) {
			$this->logs[] = array('operation' => $operation, 'data' => $data);
			return 1;
		});
		$dispatcher = $this->getMockBuilder('\phpbb\event\dispatcher')->disableOriginalConstructor()->getMock();
		$dispatcher->method('trigger_event')->willReturnCallback(function ($event, $data) {
			if (isset($this->listeners[$event]))
			{
				$data = call_user_func($this->listeners[$event], $data);
			}
			return $data;
		});
		tracking_driver::$uid_failure = false;
		$this->driver = new tracking_driver($this->config, $this->config_text, $this->user, $language, $this->auth, $this->db, $log, './', 'php', $dispatcher, $this->tracker);
		$this->driver->forum = $this->forum;
		$this->driver->bot_users = array(
			3 => array('user_id' => 3, 'username' => 'Feed bot', 'user_lang' => 'en', 'user_type' => USER_NORMAL, 'user_timezone' => 'UTC', 'user_dateformat' => 'Y-m-d', 'user_colour' => ''),
			4 => array('user_id' => 4, 'username' => 'Other bot', 'user_lang' => 'en', 'user_type' => USER_NORMAL, 'user_timezone' => 'UTC', 'user_dateformat' => 'Y-m-d', 'user_colour' => ''),
		);
		$this->saved = array($this->source());
		$this->grant(array('f_read', 'f_post', 'f_reply', 'f_edit', 'f_noapprove'), array(5, 6));
	}

	public function tearDown(): void
	{
		// submit_post() finishes posts after its own commit with statements that may roll back an
		// enclosing transaction, so Feed post bot must never wrap it in one.
		$this->assertSame(0, $this->own_transactions, 'Feed post bot opened a database transaction');
		parent::tearDown();
	}

	protected function source(array $settings = array())
	{
		return array_merge(array('url' => 'https://example.com/feed', 'type' => 'rss', 'prefix' => '', 'user_id' => 3, 'forum_id' => 5,
			'timeout' => 3, 'textlimit' => 0, 'curdate' => 0, 'append_link' => 1, 'update_existing' => 1, 'update_reply' => 0,
			'recreate_deleted' => 0, 'latest' => array('guid' => '', 'link' => '', 'pubDate' => 0), 'handled' => array()), $settings);
	}

	protected function item($id, $body = 'Body', array $values = array())
	{
		return array_merge(array('guid' => $id, 'native_id' => $id, 'title' => 'Title ' . $id, 'link' => 'https://example.com/' . $id,
			'description' => '<p>' . $body . '</p>', 'pubDate' => 100, 'author' => ''), $values);
	}

	/** An item of a feed without identifiers; SimplePie derives guid from the content */
	protected function guidless_item($id, $body = 'Body')
	{
		$item = $this->item($id, $body, array('native_id' => ''));
		$item['guid'] = md5($item['link'] . $item['title'] . $item['description']);
		return $item;
	}

	protected function grant(array $permissions, array $forums, $value = true)
	{
		foreach ($forums as $forum_id)
		{
			foreach ($permissions as $permission)
			{
				$this->permissions[$forum_id][$permission] = $value;
			}
		}
	}

	/** Revoke every forum and moderator permission of the feed user */
	protected function revoke_all(array $forums = array(5, 6))
	{
		$this->grant(array('f_read', 'f_post', 'f_reply', 'f_edit', 'f_noapprove', 'm_edit', 'm_lock', 'm_approve'), $forums, false);
	}

	/** Make update replies wait: they are only posted in approved topics */
	protected function hold_replies()
	{
		foreach ($this->forum->topics as $topic_id => $topic)
		{
			$this->forum->topics[$topic_id]['topic_visibility'] = ITEM_UNAPPROVED;
		}
	}

	protected function release_replies()
	{
		foreach ($this->forum->topics as $topic_id => $topic)
		{
			if ($topic['topic_visibility'] === ITEM_UNAPPROVED)
			{
				$this->forum->topics[$topic_id]['topic_visibility'] = ITEM_APPROVED;
			}
		}
	}

	protected function allowed($permission, $forum_id)
	{
		return !empty($this->permissions[$forum_id][$permission]);
	}

	protected function settings(array $settings)
	{
		$this->saved[0] = array_merge($this->saved[0], $settings);
	}

	/** One cron run with the given feed content */
	protected function run_feed(array $items)
	{
		// Every cron run is a fresh PHP process without phpBB's posting functions.
		$this->driver->functions_loaded = false;
		$this->driver->items = $items;
		$this->driver->current_state = array();
		return $this->driver->fetch_all();
	}

	/** Only phpBB's own transaction exists; Feed post bot must never open one around submit_post() */
	protected function transaction($status)
	{
		if ($status === 'begin')
		{
			$this->phpbb_transaction = true;
		}
		else
		{
			if ($status === 'rollback')
			{
				$this->rollbacks++;
			}
			$this->phpbb_transaction = false;
		}
		return true;
	}

	protected function topics()
	{
		return $this->forum->topics;
	}

	protected function row()
	{
		$this->assertCount(1, $this->tracker->rows);
		return $this->tracker->only();
	}

	protected function calls($mode)
	{
		return array_values(array_filter($this->forum->calls, function ($call) use ($mode) { return $call['mode'] === $mode; }));
	}

	/** The absolute date text phpBB produces for the date format of date_user */
	protected function absolute_date($time)
	{
		$date = new \DateTime('@' . (int) $time);
		$date->setTimezone(new \DateTimeZone('Europe/Helsinki'));
		return $date->format('j.n.Y, \\k\\l\\o G.i');
	}

	protected function state_logs()
	{
		return array_values(array_filter($this->logs, function ($log) { return $log['operation'] === 'FPB_LOG_ITEM_STATE'; }));
	}
}
