<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

class csrf_test extends \phpbb_test_case
{
	public function test_missing_token_prevents_manual_fetch()
	{
		$this->exercise(false, 0, 'FORM_INVALID');
	}

	public function test_valid_token_allows_manual_fetch()
	{
		$this->exercise(true, 1, 'FPB_ACP_NO_FETCHED_ITEMS');
	}

	private function exercise($valid, $expected_fetches, $expected_message)
	{
		$previous = array();
		foreach (array('request', 'template', 'user', 'phpbb_container', 'config') as $name)
		{
			$previous[$name] = array(array_key_exists($name, $GLOBALS), isset($GLOBALS[$name]) ? $GLOBALS[$name] : null);
		}
		$driver = new csrf_driver_fixture();
		csrf_driver_fixture::$valid_token = $valid;
		try
		{
			$request = $this->getMockBuilder('\phpbb\request\request_interface')->getMock();
			$request->method('is_set_post')->willReturnCallback(function ($name) { return $name === 'run_all'; });
			$user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
			$user->method('lang')->willReturnArgument(0);
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
			$this->assertSame($expected_message, $response->getMessage());
			$this->assertSame($expected_fetches, $driver->fetches);
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
}

class csrf_driver_fixture
{
	public static $valid_token;
	public $current_state = array();
	public $fetches = 0;
	public function init_current_state()
	{
	}
	public function fetch_all()
	{
		$this->fetches++;
		return 0;
	}
	public function get_run_result()
	{
		return array('created' => 0, 'updated' => 0, 'locked' => false);
	}
}

// Isolate the ACP form/response boundary; the actual main() branch is exercised above.
namespace ger\feedpostbot\acp;

function add_form_key($name)
{
}

function check_form_key($name)
{
	return $name === 'ger/feedpostbot' && \ger\feedpostbot\tests\csrf_driver_fixture::$valid_token;
}

function trigger_error($message, $level = E_USER_NOTICE)
{
	throw new \RuntimeException($message);
}

function adm_back_link($url)
{
	return '';
}
