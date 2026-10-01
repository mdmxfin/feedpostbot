<?php
/**
 *
 * Feed post bot. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace ger\feedpostbot\migrations;

/**
 * Adopt the cron settings of Feed post bot 1.0.x.
 *
 * Old releases stored these values as feedpostbot_cron_gc and feedpostbot_cron_last_gc
 * using the same migration class names as today, so upgraded boards never received the
 * current keys. Values are only copied into missing keys; existing keys are never touched.
 */
class adopt_legacy_cron_config extends \phpbb\db\migration\migration
{
	public const DEFAULT_FREQUENCY = 1800;
	public const MAX_FREQUENCY = 31536000;

	public function effectively_installed()
	{
		return isset($this->config['feedpostbot_cron_frequency'], $this->config['feedpostbot_cron_last_run']);
	}

	public static function depends_on()
	{
		return array(
			'\ger\feedpostbot\migrations\cron_frequency_config',
			'\ger\feedpostbot\migrations\enable_logs_config',
		);
	}

	public function update_data()
	{
		// A custom step is never reversed, so reverting cannot remove current keys it did not create.
		return array(
			array('custom', array(array($this, 'adopt_legacy_config'))),
		);
	}

	public function adopt_legacy_config()
	{
		if (!isset($this->config['feedpostbot_cron_frequency']))
		{
			$frequency = self::DEFAULT_FREQUENCY;
			if (isset($this->config['feedpostbot_cron_gc']))
			{
				$legacy = filter_var($this->config['feedpostbot_cron_gc'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => self::MAX_FREQUENCY)));
				$frequency = $legacy !== false ? $legacy : self::DEFAULT_FREQUENCY;
			}
			$this->config->set('feedpostbot_cron_frequency', $frequency);
		}
		if (!isset($this->config['feedpostbot_cron_last_run']))
		{
			$last_run = 0;
			if (isset($this->config['feedpostbot_cron_last_gc']))
			{
				$legacy = filter_var($this->config['feedpostbot_cron_last_gc'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
				$last_run = $legacy !== false ? $legacy : 0;
			}
			// Updated by the cron task on every run, so keep it out of the config cache.
			$this->config->set('feedpostbot_cron_last_run', $last_run, false);
		}
	}
}
