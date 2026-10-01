<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

class item_tracking_migration_test extends \phpbb_test_case
{
	public function test_item_tracking_schema()
	{
		$config = new \phpbb\config\config(array());
		$db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$tools = $this->getMockBuilder('\phpbb\db\tools\tools_interface')->getMock();
		$tools->method('sql_table_exists')->willReturnCallback(function ($table) { return $table === 'phpbb_feedpostbot_items'; });
		$migration = new \ger\feedpostbot\migrations\add_item_tracking($config, $db, $tools, './', 'php', 'phpbb_');
		$this->assertTrue($migration->effectively_installed());
		$this->assertSame(array('\ger\feedpostbot\migrations\enable_logs_config'), \ger\feedpostbot\migrations\add_item_tracking::depends_on());
		$schema = $migration->update_schema();
		$table = $schema['add_tables']['phpbb_feedpostbot_items'];
		$this->assertSame('tracking_id', $table['PRIMARY_KEY']);
		$this->assertSame(array('UNIQUE', array('source_uid', 'item_key')), $table['KEYS']['src_item']);
		$this->assertSame(array('tracking_id', 'source_uid', 'item_key', 'item_ident', 'topic_id', 'post_id', 'guard_hash', 'source_hash',
			'content_hash', 'notified_hash', 'fingerprint_scheme', 'item_state', 'state_reason', 'reply_retry_count', 'reply_next_retry',
			'reply_reason', 'created_time', 'updated_time', 'pending_op', 'pending_source_hash', 'pending_content_hash', 'pending_checksum',
			'pending_after_post_id'), array_keys($table['COLUMNS']));
		$this->assertSame(array('drop_tables' => array('phpbb_feedpostbot_items')), $migration->revert_schema());
	}
}
