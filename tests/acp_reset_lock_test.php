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
 * "Reset lock" in the ACP must not release the lock of a worker that may still be running.
 */
class acp_reset_lock_test extends \phpbb_test_case
{
	protected function reset_from_acp(\phpbb\config\config $config)
	{
		$previous = array();
		foreach (array('request', 'user', 'phpbb_container', 'config') as $name)
		{
			$previous[$name] = array(array_key_exists($name, $GLOBALS), isset($GLOBALS[$name]) ? $GLOBALS[$name] : null);
		}
		$mock = function ($class) { return $this->getMockBuilder($class)->disableOriginalConstructor()->getMock(); };
		$driver = new \ger\feedpostbot\classes\driver($config, $mock('\phpbb\config\db_text'), $mock('\phpbb\user'), $mock('\phpbb\language\language'),
			$mock('\phpbb\auth\auth'), $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock(), $mock('\phpbb\log\log'), './', 'php',
			$mock('\phpbb\event\dispatcher'));
		csrf_driver_fixture::$valid_token = true;
		try
		{
			$request = $this->getMockBuilder('\phpbb\request\request_interface')->getMock();
			$request->method('is_set_post')->willReturnCallback(function ($name) { return $name === 'reset_lock'; });
			$user = $mock('\phpbb\user');
			$user->method('lang')->willReturnArgument(0);
			$container = $this->getMockBuilder('\Symfony\Component\DependencyInjection\ContainerInterface')->getMock();
			$container->method('get')->willReturnCallback(function ($name) use ($driver) {
				return $name === 'ger.feedpostbot.classes.driver' ? $driver : null;
			});
			$GLOBALS['request'] = $request;
			$GLOBALS['user'] = $user;
			$GLOBALS['phpbb_container'] = $container;
			$GLOBALS['config'] = $config;
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

	public function test_lock_of_a_possibly_running_worker_is_kept()
	{
		$lock = time() . ' worker';
		$config = new \phpbb\config\config(array('feedpostbot_locked' => $lock));
		$this->assertSame('FPB_LOCK_ACTIVE', $this->reset_from_acp($config));
		$this->assertSame($lock, $config['feedpostbot_locked']);
	}

	public function test_stale_lock_is_reset()
	{
		$stale = time() - \ger\feedpostbot\classes\driver::MAX_RUN_SECONDS - \ger\feedpostbot\classes\driver::LOCK_RESET_MARGIN - 1;
		$config = new \phpbb\config\config(array('feedpostbot_locked' => $stale . ' worker'));
		$this->assertSame('FPB_ACP_FEEDPOSTBOT_SETTING_SAVED', $this->reset_from_acp($config));
		$this->assertSame('0', $config['feedpostbot_locked']);
	}
}
