<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

class migration_test extends \phpbb_test_case
{
	protected function migration(array $values)
	{
		$config = new \phpbb\config\config($values);
		$db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$tools = $this->getMockBuilder('\phpbb\db\tools\tools_interface')->getMock();
		return array(new \ger\feedpostbot\migrations\adopt_legacy_cron_config($config, $db, $tools, './', 'php', 'phpbb_'), $config);
	}

	protected function run_update($migration)
	{
		foreach ($migration->update_data() as $step)
		{
			$this->assertSame('custom', $step[0]);
			call_user_func($step[1][0]);
		}
	}

	public function test_legacy_values_are_adopted()
	{
		list($migration, $config) = $this->migration(array('feedpostbot_cron_gc' => '3600', 'feedpostbot_cron_last_gc' => '1700000000'));
		$this->assertFalse($migration->effectively_installed());
		$this->run_update($migration);
		$this->assertSame(3600, (int) $config['feedpostbot_cron_frequency']);
		$this->assertSame(1700000000, (int) $config['feedpostbot_cron_last_run']);
		$this->assertSame('3600', $config['feedpostbot_cron_gc']);
		$this->assertSame('1700000000', $config['feedpostbot_cron_last_gc']);
	}

	public function test_modern_values_are_never_overwritten()
	{
		list($migration, $config) = $this->migration(array('feedpostbot_cron_frequency' => '600', 'feedpostbot_cron_gc' => '3600',
			'feedpostbot_cron_last_gc' => '5'));
		$this->run_update($migration);
		$this->assertSame('600', $config['feedpostbot_cron_frequency']);
		$this->assertSame(5, (int) $config['feedpostbot_cron_last_run']);
	}

	public function test_defaults_without_legacy_values()
	{
		list($migration, $config) = $this->migration(array());
		$this->run_update($migration);
		$this->assertSame(1800, (int) $config['feedpostbot_cron_frequency']);
		$this->assertSame(0, (int) $config['feedpostbot_cron_last_run']);
	}

	public function test_invalid_legacy_values_fall_back_to_defaults()
	{
		list($migration, $config) = $this->migration(array('feedpostbot_cron_gc' => 'abc', 'feedpostbot_cron_last_gc' => '-4'));
		$this->run_update($migration);
		$this->assertSame(1800, (int) $config['feedpostbot_cron_frequency']);
		$this->assertSame(0, (int) $config['feedpostbot_cron_last_run']);
		list($migration, $config) = $this->migration(array('feedpostbot_cron_gc' => '99999999999'));
		$this->run_update($migration);
		$this->assertSame(1800, (int) $config['feedpostbot_cron_frequency']);
	}

	public function test_fresh_install_is_a_no_op()
	{
		list($migration, $config) = $this->migration(array('feedpostbot_cron_frequency' => '1800', 'feedpostbot_cron_last_run' => '0'));
		$this->assertTrue($migration->effectively_installed());
	}

	public function test_dependency_graph_and_revert_do_not_touch_config()
	{
		$this->assertSame(array('\ger\feedpostbot\migrations\cron_frequency_config', '\ger\feedpostbot\migrations\enable_logs_config'),
			\ger\feedpostbot\migrations\adopt_legacy_cron_config::depends_on());
		list($migration) = $this->migration(array());
		// Only custom steps: the migrator never reverses them, so modern keys survive a revert.
		foreach ($migration->update_data() as $step)
		{
			$this->assertSame('custom', $step[0]);
		}
		$this->assertSame(array(), $migration->revert_data());
	}
}
