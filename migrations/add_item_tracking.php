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
 * Table mapping tracked feed items to the topic and first post created for them.
 */
class add_item_tracking extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'feedpostbot_items');
	}

	public static function depends_on()
	{
		return array('\ger\feedpostbot\migrations\enable_logs_config');
	}

	public function update_schema()
	{
		return array(
			'add_tables' => array(
				$this->table_prefix . 'feedpostbot_items' => array(
					'COLUMNS' => array(
						'tracking_id'			=> array('ULINT', null, 'auto_increment'),
						'source_uid'			=> array('VCHAR:32', ''),
						'item_key'				=> array('CHAR:64', ''),
						'item_ident'			=> array('TEXT_UNI', ''),
						'topic_id'				=> array('ULINT', 0),
						'post_id'				=> array('ULINT', 0),
						'guard_hash'			=> array('VCHAR:70', ''),
						'source_hash'			=> array('CHAR:64', ''),
						'content_hash'			=> array('CHAR:64', ''),
						'notified_hash'			=> array('CHAR:64', ''),
						'fingerprint_scheme'	=> array('CHAR:64', ''),
						'item_state'			=> array('TINT:2', 0),
						'state_reason'			=> array('TINT:2', 0),
						'reply_retry_count'		=> array('USINT', 0),
						'reply_next_retry'		=> array('TIMESTAMP', 0),
						'reply_reason'			=> array('TINT:2', 0),
						'created_time'			=> array('TIMESTAMP', 0),
						'updated_time'			=> array('TIMESTAMP', 0),
						'pending_op'			=> array('TINT:2', 0),
						'pending_source_hash'	=> array('CHAR:64', ''),
						'pending_content_hash'	=> array('CHAR:64', ''),
						'pending_checksum'		=> array('VCHAR:32', ''),
						'pending_after_post_id'	=> array('ULINT', 0),
					),
					'PRIMARY_KEY' => 'tracking_id',
					'KEYS' => array(
						'src_item' => array('UNIQUE', array('source_uid', 'item_key')),
					),
				),
			),
		);
	}

	public function revert_schema()
	{
		return array(
			'drop_tables' => array(
				$this->table_prefix . 'feedpostbot_items',
			),
		);
	}
}
