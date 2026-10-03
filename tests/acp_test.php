<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

// Reuses the ACP form/response stubs of the CSRF test.
require_once __DIR__ . '/csrf_test.php';

class acp_test extends \phpbb_test_case
{
	protected function submit(array $state, array $post)
	{
		$previous = array();
		foreach (array('request', 'template', 'user', 'phpbb_container', 'config') as $name)
		{
			$previous[$name] = array(array_key_exists($name, $GLOBALS), isset($GLOBALS[$name]) ? $GLOBALS[$name] : null);
		}
		$driver = new acp_driver_fixture();
		$driver->current_state = $state;
		csrf_driver_fixture::$valid_token = true;
		try
		{
			$request = $this->getMockBuilder('\phpbb\request\request_interface')->getMock();
			$request->method('is_set_post')->willReturnCallback(function ($name) { return $name === 'submit'; });
			$request->method('variable')->willReturnCallback(function ($name, $default) use ($post) {
				return array_key_exists($name, $post) ? $post[$name] : $default;
			});
			$user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
			$user->method('lang')->willReturnArgument(0);
			$user->data = array('user_id' => 2);
			$container = $this->getMockBuilder('\Symfony\Component\DependencyInjection\ContainerInterface')->getMock();
			$container->method('get')->willReturnCallback(function ($name) use ($driver) {
				return $name === 'ger.feedpostbot.classes.driver' ? $driver : null;
			});
			$GLOBALS['request'] = $request;
			$GLOBALS['user'] = $user;
			$GLOBALS['phpbb_container'] = $container;
			$module = new \ger\feedpostbot\acp\main_module();
			$module->main('', 'settings');
			$this->fail('Expected ACP response');
		}
		catch (\RuntimeException $response)
		{
			$this->assertSame('FPB_ACP_FEEDPOSTBOT_SETTING_SAVED', $response->getMessage());
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
		return $driver->saved;
	}

	protected function state()
	{
		return array(array('url' => 'https://example.com/feed', 'type' => 'rss', 'prefix' => '', 'forum_id' => 5, 'user_id' => 3,
			'textlimit' => 0, 'timeout' => 3, 'curdate' => 0, 'append_link' => 1, 'update_existing' => 0, 'update_reply' => 0,
			'recreate_deleted' => 0, 'source_uid' => str_repeat('a', 32), 'handled' => array('x' => true),
			'latest' => array('guid' => 'g', 'link' => '', 'pubDate' => 0)));
	}

	protected function post(array $values = array())
	{
		return array_merge(array('0_url' => 'https://example.com/feed', '0_forum_id' => 5, '0_user_id' => 3, '0_textlimit' => 0,
			'0_timeout' => 3, '0_max_new_items' => 0, '0_append_link' => 'on'), $values);
	}

	public function test_update_settings_are_saved_per_feed()
	{
		$saved = $this->submit($this->state(), $this->post(array('0_update_existing' => 'on', '0_update_reply' => 'on')));
		$this->assertSame(1, $saved[0][0]['update_existing']);
		$this->assertSame(1, $saved[0][0]['update_reply']);
		$this->assertSame(0, $saved[0][0]['recreate_deleted']);
		$this->assertSame(str_repeat('a', 32), $saved[0][0]['source_uid']);
		$saved = $this->submit($this->state(), $this->post(array('0_recreate_deleted' => 'on')));
		$this->assertSame(0, $saved[0][0]['update_existing']);
		$this->assertSame(1, $saved[0][0]['recreate_deleted']);
	}

	public function test_url_change_drops_history_and_identity()
	{
		$saved = $this->submit($this->state(), $this->post(array('0_url' => 'https://example.com/other', '0_update_existing' => 'on')));
		$this->assertArrayNotHasKey('source_uid', $saved[0][0]);
		$this->assertArrayNotHasKey('handled', $saved[0][0]);
		$this->assertSame(1, $saved[0][0]['update_existing']);
	}
}

class acp_driver_fixture
{
	public $current_state = array();
	public $saved = array();

	public function init_current_state()
	{
		return $this->current_state;
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
		$this->saved = array($proposed, $original);
		return true;
	}
}
