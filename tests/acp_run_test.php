<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

require_once __DIR__ . '/fixtures/tracking.php';
// Reuses the ACP form/response stubs of the CSRF test.
require_once __DIR__ . '/csrf_test.php';

/**
 * "Fetch all feeds" in the ACP with the real worker.
 */
class acp_run_test extends fixtures\tracking_test_case
{
	protected function fetch_all_from_acp()
	{
		$previous = array();
		foreach (array('request', 'user', 'phpbb_container') as $name)
		{
			$previous[$name] = array(array_key_exists($name, $GLOBALS), isset($GLOBALS[$name]) ? $GLOBALS[$name] : null);
		}
		csrf_driver_fixture::$valid_token = true;
		try
		{
			$request = $this->getMockBuilder('\phpbb\request\request_interface')->getMock();
			$request->method('is_set_post')->willReturnCallback(function ($name) { return $name === 'run_all'; });
			$user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
			$user->method('lang')->willReturnCallback(function () { return implode('|', func_get_args()); });
			$driver = $this->driver;
			$container = $this->getMockBuilder('\Symfony\Component\DependencyInjection\ContainerInterface')->getMock();
			$container->method('get')->willReturnCallback(function ($name) use ($driver) {
				return $name === 'ger.feedpostbot.classes.driver' ? $driver : null;
			});
			$GLOBALS['request'] = $request;
			$GLOBALS['user'] = $user;
			$GLOBALS['phpbb_container'] = $container;
			$this->driver->functions_loaded = false;
			$this->driver->current_state = array();
			(new \ger\feedpostbot\acp\main_module())->main('', 'settings');
			$this->fail('Expected ACP response');
		}
		catch (\RuntimeException $response)
		{
			return $response->getMessage();
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
	}

	public function test_created_tracked_topic_is_reported_as_created()
	{
		$this->driver->items = array($this->item('a'));
		$this->assertSame('FPB_ACP_FETCHED_ITEMS|1', $this->fetch_all_from_acp());
		$this->assertCount(1, $this->topics());
		$this->assertCount(1, $this->tracker->rows);
	}

	public function test_nothing_new_is_reported_as_such()
	{
		$this->driver->items = array();
		$this->assertSame('FPB_ACP_NO_FETCHED_ITEMS', $this->fetch_all_from_acp());
	}

	public function test_updates_are_reported_next_to_created_topics()
	{
		$this->run_feed(array($this->item('a'), $this->item('b')));
		$this->driver->items = array($this->item('a', 'Changed'), $this->item('b', 'Changed'));
		$this->assertSame('FPB_ACP_NO_FETCHED_ITEMS FPB_ACP_UPDATED_ITEMS|2', $this->fetch_all_from_acp());
		$this->driver->items = array($this->item('a', 'Again'), $this->item('b', 'Changed'), $this->item('c'));
		$this->assertSame('FPB_ACP_FETCHED_ITEMS|1 FPB_ACP_UPDATED_ITEMS|1', $this->fetch_all_from_acp());
	}

	public function test_busy_worker_is_reported_instead_of_no_new_items()
	{
		$this->driver->lock_available = false;
		$this->driver->items = array($this->item('a'));
		$this->assertSame('FPB_LOCK_ACTIVE', $this->fetch_all_from_acp());
	}
}
