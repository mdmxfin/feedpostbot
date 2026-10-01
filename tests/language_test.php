<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

class language_test extends \phpbb_test_case
{
	protected function load($language)
	{
		$lang = array();
		include __DIR__ . '/../language/' . $language . '/info_acp_feedpostbot.php';
		return $lang;
	}

	public function test_all_languages_define_every_key()
	{
		$english = array_keys($this->load('en'));
		foreach (glob(__DIR__ . '/../language/*', GLOB_ONLYDIR) as $directory)
		{
			$keys = array_keys($this->load(basename($directory)));
			$this->assertSame(array(), array_values(array_diff($english, $keys)), basename($directory) . ' is missing keys');
		}
	}

	public function test_every_tracking_state_and_reason_has_a_message()
	{
		$english = $this->load('en');
		$class = new \ReflectionClass(\ger\feedpostbot\classes\item_tracker::class);
		foreach ($class->getConstants() as $name => $value)
		{
			if (strpos($name, 'STATE_') === 0)
			{
				$this->assertArrayHasKey('FPB_STATE_' . $value, $english);
			}
			else if (strpos($name, 'REASON_') === 0)
			{
				$this->assertArrayHasKey('FPB_REASON_' . $value, $english);
			}
		}
	}
}
