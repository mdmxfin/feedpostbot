<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

// Reuses the ACP form/response stubs of the CSRF test.
require_once __DIR__ . '/csrf_test.php';

/**
 * Per-feed "maximum new topics per run" (max_new_items) flood cap.
 */
class max_new_items_test extends \phpbb_test_case
{
	protected $driver;
	protected $config_text;
	protected $db;
	protected $log;
	protected $saved;

	public function setUp(): void
	{
		parent::setUp();
		$config = new \phpbb\config\config(array('feedpostbot_locked' => 0));
		$this->config_text = $this->getMockBuilder('\phpbb\config\db_text')->disableOriginalConstructor()->getMock();
		$this->config_text->method('get')->willReturnCallback(function () { return json_encode($this->saved); });
		$this->config_text->method('set')->willReturnCallback(function ($key, $value) { $this->saved = json_decode($value, true); });
		$user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
		$user->data = array('user_id' => 2, 'username' => 'Administrator', 'user_lang' => 'en', 'is_registered' => true);
		$user->timezone = new \DateTimeZone('UTC');
		$user->date_format = 'Y-m-d';
		$user->lang_name = 'en';
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();
		$language->method('lang')->willReturnArgument(0);
		$auth = $this->getMockBuilder('\phpbb\auth\auth')->disableOriginalConstructor()->getMock();
		$this->db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$user_row = array('user_id' => 3, 'username' => 'Feed bot', 'user_lang' => 'en', 'user_type' => USER_NORMAL,
			'user_timezone' => 'UTC', 'user_dateformat' => 'd/m/Y');
		$forum_row = array('forum_id' => 5, 'forum_name' => 'News', 'forum_type' => FORUM_POST);
		// Every prepare_destinations() call reads one user and one forum row.
		$rows = array();
		$this->db->method('sql_fetchrow')->willReturnCallback(function () use (&$rows, $user_row, $forum_row) {
			if (!$rows)
			{
				$rows = array($user_row, false, $forum_row, false);
			}
			return array_shift($rows);
		});
		$this->log = $this->getMockBuilder('\phpbb\log\log')->disableOriginalConstructor()->getMock();
		$dispatcher = $this->getMockBuilder('\phpbb\event\dispatcher')->disableOriginalConstructor()->getMock();
		$this->driver = new max_new_items_fixture($config, $this->config_text, $user, $language, $auth, $this->db, $this->log, './', 'php', $dispatcher);
		$this->saved = array($this->source());
		$this->driver->current_state = $this->saved;
	}

	/** A source row as stored by baseline installations, i.e. without max_new_items. */
	protected function source()
	{
		return array('url' => 'https://example.com/feed', 'type' => 'rss', 'prefix' => '', 'user_id' => 3,
			'forum_id' => 5, 'timeout' => 3, 'textlimit' => 0, 'curdate' => 0, 'append_link' => 1,
			'latest' => array('guid' => '', 'link' => '', 'pubDate' => 0), 'handled' => array());
	}

	protected function item($id, $date = 100)
	{
		return array('guid' => $id, 'link' => 'https://example.com/' . $id, 'pubDate' => $date);
	}

	/** Build a feed in parsed order (first entry = top of the feed). */
	protected function feed(array $ids)
	{
		return array_map(function ($id) { return $this->item($id); }, $ids);
	}

	protected function limit($value)
	{
		$this->driver->current_state[0]['max_new_items'] = $value;
	}

	/** Guids submitted so far, in submission order. */
	protected function posted()
	{
		return array_map(function ($entry) { return $entry['guid']; }, $this->driver->posted);
	}

	protected function run_feed(array $items)
	{
		return $this->driver->fetch_items($items, 0);
	}

	protected function is_handled($id)
	{
		return isset($this->driver->current_state[0]['handled'][hash('sha256', 'guid:' . $id)]);
	}

	/** Replace the driver with a fresh one that sees only the persisted state, like the next cron run. */
	protected function next_worker()
	{
		$saved = $this->saved;
		$this->setUp();
		$this->saved = $saved;
		$this->driver->current_state = array();
	}

	public function test_missing_setting_is_unlimited()
	{
		$this->assertArrayNotHasKey('max_new_items', $this->driver->current_state[0]);
		$this->assertSame(5, $this->run_feed($this->feed(array('A', 'B', 'C', 'D', 'E'))));
		$this->assertSame(array('E', 'D', 'C', 'B', 'A'), $this->posted());
	}

	public function test_zero_is_unlimited()
	{
		$this->limit(0);
		$this->assertSame(5, $this->run_feed($this->feed(array('A', 'B', 'C', 'D', 'E'))));
		$this->assertSame(array('E', 'D', 'C', 'B', 'A'), $this->posted());
	}

	/** Representative feeds for the unlimited compatibility check (two consecutive runs each). */
	public function unlimited_feeds()
	{
		return array(
			'fresh feed' => array(array(), array('A', 'B', 'C'), array('A', 'B', 'C')),
			'new items above known ones' => array(array('C'), array('A', 'B', 'C'), array('N', 'A', 'B', 'C')),
			'late insert below known ones' => array(array('A', 'C'), array('A', 'B', 'C'), array('A', 'B', 'C', 'D')),
			'nothing new' => array(array('A', 'B'), array('A', 'B'), array('A', 'B')),
			'legacy marker present' => array(null, array('N1', 'N2', 'OLD', 'X'), array('N0', 'N1', 'N2', 'OLD', 'X')),
			'legacy marker missing' => array(null, array('A', 'B', 'C', 'D'), array('A', 'B', 'C', 'D')),
		);
	}

	/**
	 * Setting 0 must behave exactly like a stored source without the setting (the baseline).
	 *
	 * @dataProvider unlimited_feeds
	 */
	public function test_zero_matches_baseline_behaviour($handled, array $first, array $second)
	{
		$outcomes = array();
		foreach (array('missing', 0) as $variant)
		{
			$this->setUp();
			if ($variant !== 'missing')
			{
				$this->limit($variant);
			}
			if ($handled === null)
			{
				unset($this->driver->current_state[0]['handled']);
				$this->driver->current_state[0]['latest'] = $this->item('OLD');
			}
			else
			{
				foreach ($handled as $id)
				{
					$this->driver->current_state[0]['handled'][hash('sha256', 'guid:' . $id)] = true;
				}
			}
			$counts = array($this->run_feed($this->feed($first)), $this->run_feed($this->feed($second)));
			$state = $this->driver->current_state[0];
			unset($state['max_new_items']);
			$outcomes[$variant] = array($counts, $this->posted(), $state);
		}
		$this->assertSame($outcomes['missing'], $outcomes[0]);
	}

	public function test_limit_one_keeps_only_the_top_item()
	{
		$this->limit(1);
		$feed = $this->feed(array('A', 'B', 'C'));
		$this->assertSame(1, $this->run_feed($feed));
		$this->assertSame(array('A'), $this->posted());
		$this->assertTrue($this->is_handled('B'));
		$this->assertTrue($this->is_handled('C'));
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertSame(array('A'), $this->posted());
	}

	public function test_limit_equal_to_new_items_posts_all()
	{
		$this->limit(3);
		$this->assertSame(3, $this->run_feed($this->feed(array('A', 'B', 'C'))));
		$this->assertSame(array('C', 'B', 'A'), $this->posted());
	}

	public function test_limit_above_new_items_posts_all()
	{
		$this->limit(10);
		$this->assertSame(3, $this->run_feed($this->feed(array('A', 'B', 'C'))));
		$this->assertSame(array('C', 'B', 'A'), $this->posted());
	}

	public function test_front_of_feed_survives_and_excess_is_consumed_permanently()
	{
		$this->limit(2);
		$feed = $this->feed(array('A', 'B', 'C', 'D', 'E'));
		$this->assertSame(2, $this->run_feed($feed));
		// The front of the feed is kept; the baseline oldest-first submission order is preserved.
		$this->assertSame(array('B', 'A'), $this->posted());
		foreach (array('C', 'D', 'E') as $id)
		{
			$this->assertTrue($this->is_handled($id), $id . ' consumed');
		}
		$this->assertSame('A', $this->driver->current_state[0]['latest']['guid']);
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertSame(array('B', 'A'), $this->posted());
	}

	public function test_no_new_items_changes_nothing()
	{
		$feed = $this->feed(array('A', 'B'));
		$this->run_feed($feed);
		$this->limit(1);
		$state = $this->driver->current_state[0];
		$this->driver->posted = array();
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertSame(array(), $this->posted());
		$this->assertSame($state, $this->driver->current_state[0]);
	}

	public function test_legacy_marker_present_caps_items_above_it()
	{
		unset($this->driver->current_state[0]['handled']);
		$this->driver->current_state[0]['latest'] = $this->item('OLD_MARKER');
		$this->limit(2);
		$feed = $this->feed(array('N1', 'N2', 'N3', 'OLD_MARKER', 'older1', 'older2'));
		$this->assertSame(2, $this->run_feed($feed));
		$this->assertSame(array('N2', 'N1'), $this->posted());
		// N3 is consumed by the cap; the marker and everything below it stay handled as before.
		foreach (array('N3', 'OLD_MARKER', 'older1', 'older2') as $id)
		{
			$this->assertTrue($this->is_handled($id), $id . ' handled');
		}
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertSame(array('N2', 'N1'), $this->posted());
	}

	/**
	 * The motivating failure: the stored legacy marker has fallen out of the feed, so the
	 * baseline treats the whole visible feed as new and floods the forum.
	 */
	public function test_baseline_floods_when_legacy_marker_is_missing()
	{
		unset($this->driver->current_state[0]['handled']);
		$this->driver->current_state[0]['latest'] = $this->item('GONE');
		$this->assertSame(8, $this->run_feed($this->feed(array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'))));
	}

	public function test_missing_legacy_marker_is_capped_across_runs_without_date_guessing()
	{
		$this->saved[0]['max_new_items'] = 2;
		unset($this->saved[0]['handled']);
		$this->saved[0]['latest'] = $this->item('GONE');
		// Dates are deliberately out of order and far apart: neither ordering nor age may matter.
		$this->driver->items = array($this->item('A', 1000), $this->item('B', 5), $this->item('C', 2000000000),
			$this->item('D', 0), $this->item('E', 1), $this->item('F', 1700000000), $this->item('G', 3), $this->item('H', 4));
		$this->assertSame(2, $this->driver->fetch_all());
		$this->assertSame(array('B', 'A'), $this->posted());
		$this->assertTrue($this->driver->lock->released);
		$this->assertCount(8, $this->saved[0]['handled']);
		$this->assertSame(2, $this->saved[0]['max_new_items']);

		// Second, unchanged run from the persisted state: nothing reappears.
		$items = $this->driver->items;
		$this->next_worker();
		$this->driver->items = $items;
		$this->assertSame(0, $this->driver->fetch_all());
		$this->assertSame(array(), $this->posted());

		// A genuinely new item at the front is posted; the discarded older ones stay gone.
		array_unshift($items, $this->item('NEW', 50));
		array_pop($items);
		$this->next_worker();
		$this->driver->items = $items;
		$this->assertSame(1, $this->driver->fetch_all());
		$this->assertSame(array('NEW'), $this->posted());
		$this->next_worker();
		$this->driver->items = $items;
		$this->assertSame(0, $this->driver->fetch_all());
	}

	public function test_new_item_after_capped_run_is_posted_normally()
	{
		$this->limit(2);
		$this->run_feed($this->feed(array('A', 'B', 'C', 'D')));
		$this->assertSame(1, $this->run_feed($this->feed(array('NEW', 'A', 'B', 'C', 'D'))));
		$this->assertSame(array('B', 'A', 'NEW'), $this->posted());
		$this->assertSame(2, $this->run_feed($this->feed(array('N2', 'N1', 'NEW', 'A', 'B', 'C'))));
		$this->assertSame(array('B', 'A', 'NEW', 'N1', 'N2'), $this->posted());
	}

	public function test_known_items_do_not_count_against_the_limit()
	{
		$this->limit(1);
		$this->run_feed($this->feed(array('K1')));
		$this->limit(0);
		$this->run_feed($this->feed(array('K2', 'K1')));
		$this->limit(1);
		$this->driver->posted = array();
		// Known items sit between and above the new ones; only unhandled items take a slot.
		$this->assertSame(1, $this->run_feed($this->feed(array('K2', 'N1', 'K1', 'N2'))));
		$this->assertSame(array('N1'), $this->posted());
		$this->assertTrue($this->is_handled('N2'));
	}

	public function test_duplicate_feed_entries_use_one_slot()
	{
		$this->limit(2);
		$this->assertSame(2, $this->run_feed($this->feed(array('A', 'A', 'B', 'C'))));
		$this->assertSame(array('B', 'A'), $this->posted());
		$this->assertTrue($this->is_handled('C'));
	}

	public function test_event_skipped_item_still_uses_a_slot()
	{
		$this->limit(2);
		// post_message() is called oldest-first: B is consumed by an event, A is posted.
		$this->driver->results = array(true, '/post/1');
		$feed = $this->feed(array('A', 'B', 'C', 'D'));
		$this->assertSame(1, $this->run_feed($feed));
		$this->assertSame(array('B', 'A'), $this->posted());
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertSame(array('B', 'A'), $this->posted());
	}

	public function test_failed_admitted_item_is_retried_but_excess_stays_consumed()
	{
		$this->limit(2);
		$this->driver->results = array('/post/1', false);
		$feed = $this->feed(array('A', 'B', 'C', 'D'));
		$this->assertSame(1, $this->run_feed($feed));
		$this->assertSame(array('B', 'A'), $this->posted());
		$this->assertFalse($this->is_handled('A'));
		$this->assertTrue($this->is_handled('C'));
		$this->assertTrue($this->is_handled('D'));
		$this->assertSame(1, $this->run_feed($feed));
		$this->assertSame(array('B', 'A', 'A'), $this->posted());
		$this->assertSame(0, $this->run_feed($feed));
	}

	public function test_numeric_string_limit_is_honoured()
	{
		$this->limit('1');
		$this->assertSame(1, $this->run_feed($this->feed(array('A', 'B'))));
		$this->assertSame(array('A'), $this->posted());
	}

	public function invalid_stored_limits()
	{
		return array(array(-1), array('abc'), array('1.5'), array(''), array(1000001));
	}

	/**
	 * Invalid stored values follow the existing convention for timeout/textlimit: the source is invalid.
	 *
	 * @dataProvider invalid_stored_limits
	 */
	public function test_invalid_stored_limit_marks_source_invalid($value)
	{
		$this->driver->prepare_destinations($this->driver->current_state);
		$this->assertTrue($this->driver->valid_source($this->driver->current_state[0]));
		$this->limit($value);
		$this->assertFalse($this->driver->valid_source($this->driver->current_state[0]));
		$this->assertSame(0, $this->run_feed($this->feed(array('A'))));
		$this->assertSame(array(), $this->posted());
	}

	public function test_valid_stored_limits_keep_source_valid()
	{
		$this->driver->prepare_destinations($this->driver->current_state);
		foreach (array(0, 1, '25', 1000000) as $value)
		{
			$this->limit($value);
			$this->assertTrue($this->driver->valid_source($this->driver->current_state[0]), (string) $value);
		}
	}

	public function test_acp_save_preserves_setting_alongside_progress()
	{
		$original = $this->saved;
		$proposed = $original;
		$proposed[0]['max_new_items'] = 4;
		$this->saved[0]['handled'] = array('done' => true);
		$this->assertTrue($this->driver->save_sources($proposed, $original));
		$this->assertSame(4, $this->saved[0]['max_new_items']);
		$this->assertSame(array('done' => true), $this->saved[0]['handled']);
	}

	public function test_acp_stores_submitted_limit()
	{
		$state = array($this->source());
		$driver = $this->acp_submit($state, array('0_max_new_items' => '7'));
		$this->assertSame('FPB_ACP_FEEDPOSTBOT_SETTING_SAVED', $driver->message);
		$this->assertSame(7, $driver->stored[0]['max_new_items']);
		$this->assertSame(array(), $driver->stored[0]['handled']);
	}

	public function test_acp_stores_zero_for_unlimited()
	{
		$state = array($this->source());
		$state[0]['max_new_items'] = 3;
		$driver = $this->acp_submit($state, array('0_max_new_items' => '0'));
		$this->assertSame(0, $driver->stored[0]['max_new_items']);
	}

	public function invalid_acp_limits()
	{
		return array(array('-1'), array('abc'), array('2.5'), array(''), array('1000001'));
	}

	/**
	 * @dataProvider invalid_acp_limits
	 */
	public function test_acp_rejects_invalid_limit($value)
	{
		$driver = $this->acp_submit(array($this->source()), array('0_max_new_items' => $value));
		$this->assertSame('FPB_SETTINGS_INVALID', $driver->message);
		$this->assertNull($driver->stored);
	}

	public function test_acp_new_feed_defaults_to_unlimited()
	{
		$driver = $this->acp_submit(array(), array('add_feed' => 'https://example.org/feed'));
		$this->assertSame('FPB_ACP_FEEDPOSTBOT_SETTING_SAVED', $driver->message);
		$this->assertSame(0, $driver->stored[0]['max_new_items']);
	}

	public function test_acp_lists_missing_setting_as_zero()
	{
		$driver = $this->acp_submit(array($this->source()), array(), false);
		$this->assertSame(0, $driver->block_vars['MAX_NEW_ITEMS']);
	}

	public function test_template_renders_per_feed_input()
	{
		$loader = new \Twig\Loader\ArrayLoader(array(
			'body' => file_get_contents(__DIR__ . '/../adm/style/acp_feedpostbot_body.html'),
			'overall_header.html' => '', 'overall_footer.html' => '',
		));
		$twig = new \Twig\Environment($loader, array('autoescape' => false));
		$twig->setLexer(new \phpbb\template\twig\lexer($twig));
		$twig->addTokenParser(new \phpbb\template\twig\tokenparser\includeparser());
		$twig->addFunction(new \Twig\TwigFunction('lang', function ($key) { return $key; }));
		$html = $twig->render('body', array('loops' => array('feeds' => array(array('ID' => 4, 'MAX_NEW_ITEMS' => 3)))));
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try
		{
			$document->loadHTML($html);
			$input = (new \DOMXPath($document))->query('//input[@name="4_max_new_items"]')->item(0);
			$this->assertNotNull($input);
			$this->assertSame('number', $input->getAttribute('type'));
			$this->assertSame('0', $input->getAttribute('min'));
			$this->assertSame('3', $input->getAttribute('value'));
		}
		finally
		{
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}

	/** Drive the ACP module's feed form; returns the driver fixture with what it stored. */
	protected function acp_submit(array $state, array $post, $submit = true)
	{
		$post += array('0_url' => 'https://example.com/feed', '0_forum_id' => '5', '0_user_id' => '3',
			'0_textlimit' => '0', '0_timeout' => '3', '0_max_new_items' => '0');
		$previous = array();
		foreach (array('request', 'template', 'user', 'phpbb_container', 'config') as $name)
		{
			$previous[$name] = array(array_key_exists($name, $GLOBALS), isset($GLOBALS[$name]) ? $GLOBALS[$name] : null);
		}
		$driver = new max_new_items_acp_driver_fixture();
		$driver->current_state = $state;
		csrf_driver_fixture::$valid_token = true;
		try
		{
			$request = $this->getMockBuilder('\phpbb\request\request_interface')->getMock();
			$request->method('is_set_post')->willReturnCallback(function ($name) use ($post, $submit) {
				return $submit && ($name === 'submit' || ($name === 'add_feed' && isset($post['add_feed'])));
			});
			// Mirror phpBB's request: the default's type decides the returned type.
			$request->method('variable')->willReturnCallback(function ($name, $default) use ($post) {
				if (!array_key_exists($name, $post))
				{
					return $default;
				}
				return is_int($default) ? (int) $post[$name] : (string) $post[$name];
			});
			$user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
			$user->method('lang')->willReturnArgument(0);
			$user->data = array('user_id' => 2);
			$template = $this->getMockBuilder('\phpbb\template\template')->getMock();
			$template->method('assign_block_vars')->willReturnCallback(function ($block, $vars) use ($driver) {
				$driver->block_vars = $vars;
			});
			$container = $this->getMockBuilder('\Symfony\Component\DependencyInjection\ContainerInterface')->getMock();
			$container->method('get')->willReturnCallback(function ($name) use ($driver) {
				return $name === 'ger.feedpostbot.classes.driver' ? $driver : new max_new_items_dispatcher_fixture();
			});
			$GLOBALS['request'] = $request;
			$GLOBALS['user'] = $user;
			$GLOBALS['template'] = $template;
			$GLOBALS['config'] = array('feedpostbot_locked' => 0, 'feedpostbot_cron_frequency' => 0, 'feedpostbot_enable_logs' => 0);
			$GLOBALS['phpbb_container'] = $container;
			$module = new \ger\feedpostbot\acp\main_module();
			$module->main('', 'settings');
			if ($submit)
			{
				$this->fail('Expected ACP response');
			}
		}
		catch (\RuntimeException $response)
		{
			$driver->message = $response->getMessage();
		}
		finally
		{
			foreach ($previous as $name => $entry)
			{
				if ($entry[0])
				{
					$GLOBALS[$name] = $entry[1];
				}
				else
				{
					unset($GLOBALS[$name]);
				}
			}
		}
		return $driver;
	}
}

class max_new_items_fixture extends \ger\feedpostbot\classes\driver
{
	public $posted = array();
	public $results = array();
	public $items = array();
	public $lock;

	protected function post_message($item, $source_id)
	{
		$this->posted[] = $item;
		return $this->results ? array_shift($this->results) : '/post/1';
	}

	public function parse_feed($url, $type, $timeout = self::FEED_TIMEOUT_PARSE)
	{
		return $this->items;
	}

	protected function create_lock()
	{
		$this->lock = new max_new_items_lock_fixture();
		return $this->lock;
	}
}

class max_new_items_lock_fixture
{
	public $released = false;
	public function acquire()
	{
		return true;
	}
	public function release()
	{
		$this->released = true;
	}
}

class max_new_items_acp_driver_fixture
{
	public $current_state = array();
	public $stored;
	public $message;
	public $block_vars;
	public function init_current_state()
	{
	}
	public function detect_feed_type($url)
	{
		return 'rss';
	}
	public function prepare_destinations(array $sources)
	{
	}
	public function valid_source(array $source)
	{
		return true;
	}
	public function save_sources(array $proposed, array $original)
	{
		$this->stored = $proposed;
		return true;
	}
}

class max_new_items_dispatcher_fixture
{
	public function trigger_event($name, $vars = array())
	{
		return $vars;
	}
}

namespace ger\feedpostbot\acp;

if (!function_exists('ger\\feedpostbot\\acp\\make_forum_select'))
{
	// Only the ACP feed listing needs this; the real one lives in includes/functions_admin.php.
	function make_forum_select($select_id = false)
	{
		return '';
	}
}
