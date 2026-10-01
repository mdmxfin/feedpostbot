<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

class lock_test extends \phpbb_test_case
{
	public function test_abandoned_lock_expires_and_is_released_by_its_owner()
	{
		$config = new \phpbb\config\config(array('feedpostbot_locked' => time() - 3601));
		$db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$lock = new \phpbb\lock\db('feedpostbot_locked', $config, $db);
		$this->assertTrue($lock->acquire());
		$contender = new \phpbb\lock\db('feedpostbot_locked', $config, $db);
		$this->assertFalse($contender->acquire());
		$lock->release();
		$this->assertSame('0', $config['feedpostbot_locked']);
	}

	public function test_old_worker_cannot_release_new_owners_lock()
	{
		$config = new \phpbb\config\config(array('feedpostbot_locked' => 0));
		$db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$lock = new \phpbb\lock\db('feedpostbot_locked', $config, $db);
		$this->assertTrue($lock->acquire());
		$config['feedpostbot_locked'] = time() . ' replacement-owner';
		$lock->release();
		$this->assertStringContainsString('replacement-owner', $config['feedpostbot_locked']);
	}

	protected function driver(\phpbb\config\config $config)
	{
		$mock = function ($class) { return $this->getMockBuilder($class)->disableOriginalConstructor()->getMock(); };
		return new \ger\feedpostbot\classes\driver($config, $mock('\phpbb\config\db_text'), $mock('\phpbb\user'), $mock('\phpbb\language\language'),
			$mock('\phpbb\auth\auth'), $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock(), $mock('\phpbb\log\log'), './', 'php',
			$mock('\phpbb\event\dispatcher'));
	}

	public function test_lock_of_a_possibly_running_worker_cannot_be_reset()
	{
		$driver = $this->driver($config = new \phpbb\config\config(array()));
		foreach (array(time(), time() - \ger\feedpostbot\classes\driver::MAX_RUN_SECONDS - 60) as $acquired)
		{
			$config['feedpostbot_locked'] = $acquired . ' worker';
			$this->assertFalse($driver->reset_stale_lock());
			$this->assertSame($acquired . ' worker', $config['feedpostbot_locked']);
		}
	}

	public function test_stale_lock_can_be_reset()
	{
		$stale = time() - \ger\feedpostbot\classes\driver::MAX_RUN_SECONDS - \ger\feedpostbot\classes\driver::LOCK_RESET_MARGIN - 1;
		$config = new \phpbb\config\config(array('feedpostbot_locked' => $stale . ' worker'));
		$driver = $this->driver($config);
		$this->assertTrue($driver->reset_stale_lock());
		$this->assertSame('0', $config['feedpostbot_locked']);
		// Legacy value without owner, and no lock at all.
		$config['feedpostbot_locked'] = '1';
		$this->assertTrue($driver->reset_stale_lock());
		$this->assertSame('0', $config['feedpostbot_locked']);
		$this->assertTrue($driver->reset_stale_lock());
	}
}
