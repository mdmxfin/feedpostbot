<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

use ger\feedpostbot\classes\driver;

class fingerprint_test extends \phpbb_test_case
{
	/** @var fingerprint_fixture */
	protected $driver;
	protected $listeners = array();
	protected $events = array();

	public function setUp(): void
	{
		parent::setUp();
		$config = new \phpbb\config\config(array());
		$config_text = $this->getMockBuilder('\phpbb\config\db_text')->disableOriginalConstructor()->getMock();
		$user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
		$user->method('lang')->willReturnArgument(0);
		$user->data = array('user_id' => 3, 'username' => 'Feed bot');
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();
		$auth = $this->getMockBuilder('\phpbb\auth\auth')->disableOriginalConstructor()->getMock();
		$db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$log = $this->getMockBuilder('\phpbb\log\log')->disableOriginalConstructor()->getMock();
		$dispatcher = $this->getMockBuilder('\phpbb\event\dispatcher')->disableOriginalConstructor()->getMock();
		$dispatcher->method('trigger_event')->willReturnCallback(function ($event, $data) {
			$this->events[] = $event;
			if (isset($this->listeners[$event]))
			{
				$data = call_user_func($this->listeners[$event], $data);
			}
			return $data;
		});
		$this->driver = new fingerprint_fixture($config, $config_text, $user, $language, $auth, $db, $log, './', 'php', $dispatcher);
	}

	protected function source(array $settings = array())
	{
		return array_merge(array('url' => 'https://example.com/feed', 'type' => 'rss', 'user_id' => 3, 'forum_id' => 5,
			'timeout' => 3, 'textlimit' => 0, 'append_link' => 1, 'prefix' => '', 'curdate' => 0), $settings);
	}

	protected function item(array $values = array())
	{
		return array_merge(array('guid' => 'g1', 'native_id' => 'g1', 'title' => 'Title', 'link' => 'https://example.com/a',
			'description' => '<p>Body</p>', 'pubDate' => 100, 'author' => 'A'), $values);
	}

	public function test_native_id_is_preferred_over_link()
	{
		$key = $this->driver->tracking_key($this->item());
		$this->assertSame('guid:g1', $key['ident']);
		$this->assertSame(hash('sha256', 'guid:g1'), $key['key']);
		$this->assertSame($key, $this->driver->tracking_key($this->item(array('link' => 'https://example.com/other'))));
	}

	public function test_link_identity_and_untrackable_items()
	{
		$key = $this->driver->tracking_key($this->item(array('native_id' => '', 'guid' => md5('x'))));
		$this->assertSame('link:https://example.com/a', $key['ident']);
		$this->assertNull($this->driver->tracking_key($this->item(array('native_id' => '', 'link' => ''))));
		$this->assertNull($this->driver->tracking_key(array('title' => 'Only a title', 'description' => 'Text')));
	}

	public function test_link_normalization_only_touches_cosmetic_parts()
	{
		$this->assertSame('https://example.com/Path/?p=1&A=B', driver::normalize_link(' HTTPS://Example.COM:443/Path/?p=1&A=B#comments '));
		$this->assertSame('http://example.com:8080/a', driver::normalize_link('http://EXAMPLE.com:8080/a'));
		$this->assertSame('http://example.com/a', driver::normalize_link('http://example.com:80/a'));
		$this->assertSame('https://user@[::1]:8443/x', driver::normalize_link('https://user@[::1]:8443/x'));
		$this->assertNotSame(driver::normalize_link('https://example.com/a/'), driver::normalize_link('https://example.com/a'));
		$this->assertNotSame(driver::normalize_link('http://example.com/a'), driver::normalize_link('https://example.com/a'));
		$this->assertSame('relative/path', driver::normalize_link('relative/path'));
	}

	public function test_guid_less_simplepie_item_keeps_link_identity_when_content_changes()
	{
		$feed = function ($body) {
			return '<rss version="2.0"><channel><title>Feed</title><item><title>News</title><link>https://example.com/news/1</link>'
				. '<description>' . $body . '</description></item></channel></rss>';
		};
		$first = $this->parse($feed('First version'));
		$second = $this->parse($feed('Corrected version'));
		$this->assertSame('', $first['native_id']);
		// The historical guid is derived from content by SimplePie, so it changes.
		$this->assertNotSame($first['guid'], $second['guid']);
		$this->assertSame($this->driver->tracking_key($first), $this->driver->tracking_key($second));
		$this->assertSame('link:https://example.com/news/1', $this->driver->tracking_key($first)['ident']);
	}

	public function test_native_guid_is_exposed_and_historical_keys_are_unchanged()
	{
		$item = $this->parse('<rss version="2.0"><channel><title>Feed</title><item><guid>urn:1</guid><title>News</title>'
			. '<link>https://example.com/n</link><description>B</description></item></channel></rss>');
		$this->assertSame('urn:1', $item['native_id']);
		$this->assertSame('urn:1', $item['guid']);
		$method = new \ReflectionMethod(driver::class, 'item_key');
		$method->setAccessible(true);
		$this->assertSame(hash('sha256', 'guid:urn:1'), $method->invoke($this->driver, $item));
		$this->assertSame(hash('sha256', 'link:https://example.com/x'), $method->invoke($this->driver, array('guid' => '', 'link' => 'https://example.com/x')));
	}

	public function test_pubdate_and_identity_changes_do_not_change_source_fingerprint()
	{
		$base = $this->driver->source_fingerprint($this->item(), $this->source());
		$this->assertSame($base, $this->driver->source_fingerprint($this->item(array('pubDate' => 999, 'guid' => 'other')), $this->source()));
		$this->assertSame($base, $this->driver->source_fingerprint($this->item(array('description' => "<p>Body</p>\r\n")), $this->source()));
		$this->assertNotSame($base['hash'], $this->driver->source_fingerprint($this->item(array('title' => 'Changed')), $this->source())['hash']);
		$this->assertNotSame($base['hash'], $this->driver->source_fingerprint($this->item(array('description' => 'New')), $this->source())['hash']);
		$reordered = array_reverse($this->item(), true);
		$this->assertSame($base, $this->driver->source_fingerprint($reordered, $this->source()));
	}

	public function test_extension_data_participates_and_volatile_data_can_be_excluded()
	{
		$base = $this->driver->source_fingerprint($this->item(array('image' => 'a.jpg')), $this->source());
		$this->assertNotSame($base['hash'], $this->driver->source_fingerprint($this->item(array('image' => 'b.jpg')), $this->source())['hash']);
		$this->listeners['ger.feedpostbot.item_fingerprint'] = function ($data) {
			unset($data['fingerprint_data']['token']);
			$data['scheme_tags']['my_ext'] = 2;
			return $data;
		};
		$one = $this->driver->source_fingerprint($this->item(array('token' => 't1')), $this->source());
		$two = $this->driver->source_fingerprint($this->item(array('token' => 't2')), $this->source());
		$this->assertSame($one, $two);
		$this->assertNotSame($base['scheme'], $one['scheme']);
	}

	public function test_formatting_settings_change_content_but_not_source()
	{
		$source_hash = $this->driver->source_fingerprint($this->item(), $this->source());
		$plain = $this->driver->content_fingerprint($this->driver->build_post_content($this->item(), $this->source()));
		$prefixed = $this->driver->content_fingerprint($this->driver->build_post_content($this->item(), $this->source(array('prefix' => '[News]'))));
		$this->assertNotSame($plain, $prefixed);
		$this->assertSame($source_hash, $this->driver->source_fingerprint($this->item(), $this->source(array('prefix' => '[News]'))));
	}

	public function test_invisible_source_change_renders_identically()
	{
		$old = $this->item(array('description' => '<p class="a">Body</p>'));
		$new = $this->item(array('description' => '<p class="b">Body</p>'));
		$this->assertNotSame($this->driver->source_fingerprint($old, $this->source())['hash'], $this->driver->source_fingerprint($new, $this->source())['hash']);
		$this->assertSame($this->driver->content_fingerprint($this->driver->build_post_content($old, $this->source())),
			$this->driver->content_fingerprint($this->driver->build_post_content($new, $this->source())));
	}

	public function test_scheme_follows_simplepie_version()
	{
		$fingerprint = $this->driver->source_fingerprint($this->item(), $this->source());
		$this->assertSame(hash('sha256', json_encode(array('core' => driver::FINGERPRINT_VERSION, 'simplepie' => \SimplePie\SimplePie::VERSION))), $fingerprint['scheme']);
	}

	public function test_build_post_content_matches_historical_rendering_and_event_applies()
	{
		$content = $this->driver->build_post_content($this->item(array('title' => '<b>News</b> & more')), $this->source(array('prefix' => '[Feed]')));
		$this->assertSame('[Feed] News &amp; more', $content['subject']);
		$this->assertSame("\nBody\n\n\nFPB_SOURCE [url]https://example.com/a[/url]", $content['message']);
		$limited = $this->driver->build_post_content($this->item(array('description' => str_repeat('word ', 20))), $this->source(array('textlimit' => 12)));
		$this->assertSame("word word word...\n\n[url=https://example.com/a]FPB_READ_MORE[/url]", $limited['message']);
		$this->listeners['ger.feedpostbot.modify_post_content'] = function ($data) {
			$data['content']['message'] .= "\n[i]custom[/i]";
			return $data;
		};
		$custom = $this->driver->build_post_content($this->item(), $this->source());
		$this->assertStringEndsWith('[i]custom[/i]', $custom['message']);
		$this->assertNotSame($this->driver->content_fingerprint($content), $this->driver->content_fingerprint($custom));
	}

	public function test_create_path_keeps_submit_post_before_contract()
	{
		$this->driver->current_state = array($this->source(array('prefix' => '[Feed]')));
		$this->driver->valid = true;
		$this->listeners['ger.feedpostbot.submit_post_before'] = function ($data) {
			$this->assertSame(array('data', 'rss_item', 'source', 'title', 'do_post'), array_keys($data));
			$this->assertSame('[Feed] Title', $data['title']);
			$this->assertSame('stored:' . "\nBody\n\n\nFPB_SOURCE [url]https://example.com/a[/url]", $data['data']['message']);
			$this->assertSame(md5($data['data']['message']), $data['data']['message_md5']);
			return $data;
		};
		$this->assertSame('./viewtopic.php?t=1', $this->driver->create($this->item(), 0));
		$this->assertSame('post', $this->driver->submitted[0]['mode']);
		$this->assertSame(100, $this->driver->submitted[0]['data']['post_time']);
		$this->assertSame(array('ger.feedpostbot.html2bbcode_convert', 'ger.feedpostbot.modify_post_content', 'ger.feedpostbot.submit_post_before'), $this->events);
	}

	protected function parse($xml)
	{
		$feed = $this->driver->get_simplepie_instance('https://example.com/feed', 3, $xml);
		$this->driver->feed = $feed;
		$items = $this->driver->parse_feed('https://example.com/feed', 'rss');
		return $items[0];
	}
}

class fingerprint_fixture extends driver
{
	public $feed;
	public $valid = false;
	public $submitted = array();

	public function get_simplepie_instance($url, $timeout = self::FEED_TIMEOUT_DEFAULT, $raw_data = null)
	{
		return $raw_data === null ? $this->feed : parent::get_simplepie_instance($url, $timeout, $raw_data);
	}

	public function valid_source(array $source)
	{
		return $this->valid;
	}

	public function create($item, $source_id)
	{
		return $this->post_message($item, $source_id);
	}

	protected function load_posting_functions()
	{
		return true;
	}

	protected function storage_fields(array $content)
	{
		$text = 'stored:' . $content['message'];
		return array('message' => $text, 'message_md5' => md5($text), 'bbcode_uid' => 'uid', 'bbcode_bitfield' => '');
	}

	protected function submit($mode, $subject, $username, $topic_type, &$poll, &$data)
	{
		$this->submitted[] = array('mode' => $mode, 'subject' => $subject, 'data' => $data);
		return './viewtopic.php?t=1';
	}
}
