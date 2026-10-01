<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests\dbal;

// Requires phpBB's database test framework; the isolated runners have no database.
if (!class_exists('\phpbb_database_test_case'))
{
	return;
}

use ger\feedpostbot\classes\item_tracker;

class item_tracker_database_test extends \phpbb_database_test_case
{
	/** @var item_tracker */
	protected $tracker;
	protected $db;

	protected static function setup_extensions()
	{
		return array('ger/feedpostbot');
	}

	public function getDataSet()
	{
		return $this->createXMLDataSet(__DIR__ . '/fixtures/feedpostbot_items.xml');
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = $this->new_dbal();
		$this->tracker = new item_tracker($this->db, 'phpbb_feedpostbot_items');
	}

	public function test_load_is_scoped_to_source()
	{
		$key = str_repeat('1', 64);
		$rows = $this->tracker->load(str_repeat('a', 32), array($key, str_repeat('9', 64)));
		$this->assertSame(array($key), array_keys($rows));
		$this->assertSame(10, $rows[$key]['topic_id']);
		$this->assertSame('c1', $rows[$key]['content_hash']);
	}

	public function test_unique_source_item_key()
	{
		$this->db->sql_return_on_error(true);
		$this->tracker->insert(array('source_uid' => str_repeat('a', 32), 'item_key' => str_repeat('1', 64), 'item_ident' => ''));
		$error = $this->db->get_sql_error_triggered();
		$this->db->sql_return_on_error(false);
		$this->assertTrue((bool) $error);
		$this->assertGreaterThan(0, $this->tracker->insert(array('source_uid' => str_repeat('c', 32), 'item_key' => str_repeat('1', 64), 'item_ident' => '')));
	}

	public function test_pending_cancel_and_delete()
	{
		$pending = $this->tracker->pending_replies(str_repeat('a', 32), time());
		$this->assertSame(array(2), array_column($pending, 'tracking_id'));
		$this->assertSame(array(), $this->tracker->pending_replies(str_repeat('b', 32), time()));
		$this->tracker->cancel_replies(str_repeat('a', 32));
		$this->assertSame(array(), $this->tracker->pending_replies(str_repeat('a', 32), time()));
		$rows = $this->tracker->load(str_repeat('a', 32), array(str_repeat('3', 64)));
		$this->assertSame('c2', $rows[str_repeat('3', 64)]['notified_hash']);
		$this->tracker->delete_sources(array(str_repeat('a', 32)));
		$this->assertSame(array(), $this->tracker->load(str_repeat('a', 32), array(str_repeat('1', 64))));
		$this->assertCount(1, $this->tracker->load(str_repeat('b', 32), array(str_repeat('1', 64))));
	}

	public function test_update()
	{
		$this->tracker->update(1, array('item_state' => item_tracker::STATE_BLOCKED, 'state_reason' => item_tracker::REASON_LOCKED));
		$rows = $this->tracker->load(str_repeat('a', 32), array(str_repeat('1', 64)));
		$this->assertSame(item_tracker::STATE_BLOCKED, $rows[str_repeat('1', 64)]['item_state']);
		$this->assertSame(item_tracker::REASON_LOCKED, $rows[str_repeat('1', 64)]['state_reason']);
	}

	public function test_compare_and_set_counts_matched_rows()
	{
		// An unchanged value still counts as a match (mysqli connects with MYSQLI_CLIENT_FOUND_ROWS).
		$this->assertTrue($this->tracker->update(1, array('content_hash' => 'c1'), array('content_hash' => 'c1')));
		$this->assertFalse($this->tracker->update(1, array('content_hash' => 'x'), array('content_hash' => 'other')));
		$this->assertFalse($this->tracker->delete(1, array('item_state' => item_tracker::STATE_GONE)));
		$this->assertTrue($this->tracker->delete(1, array('item_state' => item_tracker::STATE_ACTIVE)));
		$this->assertSame(array(), $this->tracker->load(str_repeat('a', 32), array(str_repeat('1', 64))));
	}
}
