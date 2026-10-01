<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

use ger\feedpostbot\classes\item_tracker;

class item_tracker_test extends \phpbb_test_case
{
	protected $queries = array();
	protected $rows = array();
	protected $tracker;
	protected $affected = 1;

	public function setUp(): void
	{
		parent::setUp();
		$db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$db->method('sql_escape')->willReturnCallback(function ($value) { return str_replace("'", "''", $value); });
		$db->method('sql_in_set')->willReturnCallback(function ($field, $values) {
			return $field . " IN ('" . implode("', '", array_map('strval', $values)) . "')";
		});
		$db->method('sql_build_array')->willReturnCallback(function ($type, $fields) {
			return $type . ':' . implode(',', array_keys($fields));
		});
		$db->method('sql_query')->willReturnCallback(function ($sql) { $this->queries[] = preg_replace('/\s+/', ' ', $sql); return true; });
		$db->method('sql_fetchrow')->willReturnCallback(function () { return array_shift($this->rows) ?: false; });
		$db->method('sql_nextid')->willReturn(42);
		$db->method('sql_affectedrows')->willReturnCallback(function () { return $this->affected; });
		$this->tracker = new item_tracker($db, 'phpbb_feedpostbot_items');
	}

	public function test_load_is_scoped_to_one_source_and_chunked()
	{
		$keys = array();
		for ($i = 0; $i < item_tracker::CHUNK_SIZE + 1; $i++)
		{
			$keys[] = 'k' . $i;
		}
		$this->tracker->load("uid'x", $keys);
		$this->assertCount(2, $this->queries);
		$this->assertStringContainsString("source_uid = 'uid''x'", $this->queries[0]);
		$this->assertStringContainsString("'k500'", $this->queries[1]);
	}

	public function test_rows_are_normalized_and_indexed_by_item_key()
	{
		$this->rows = array(array('tracking_id' => '7', 'item_key' => 'abc', 'topic_id' => '3', 'content_hash' => str_pad('', 64),
			'notified_hash' => 'n  ', 'item_state' => '2'));
		$rows = $this->tracker->load('uid', array('abc'));
		$this->assertSame(7, $rows['abc']['tracking_id']);
		$this->assertSame(3, $rows['abc']['topic_id']);
		$this->assertSame('', $rows['abc']['content_hash']);
		$this->assertSame('n', $rows['abc']['notified_hash']);
		$this->assertSame(item_tracker::STATE_DETACHED, $rows['abc']['item_state']);
	}

	public function test_pending_replies_only_selects_owed_due_updatable_rows()
	{
		$this->tracker->pending_replies('uid', 1000);
		$this->assertStringContainsString('notified_hash <> content_hash', $this->queries[0]);
		$this->assertStringContainsString("item_state IN ('0', '1')", $this->queries[0]);
		$this->assertStringContainsString('reply_next_retry <= 1000', $this->queries[0]);
	}

	public function test_cancel_and_delete_are_scoped()
	{
		$this->tracker->cancel_replies('uid');
		$this->assertStringContainsString("SET notified_hash = content_hash, reply_retry_count = 0, reply_next_retry = 0, reply_reason = 0 WHERE source_uid = 'uid' AND notified_hash <> content_hash", $this->queries[0]);
		$this->tracker->delete_sources(array());
		$this->tracker->delete_sources(array('', 'a', 'a', 'b'));
		$this->assertCount(2, $this->queries);
		$this->assertStringContainsString("source_uid IN ('a', 'b')", $this->queries[1]);
	}

	public function test_insert_and_update()
	{
		$this->assertSame(42, $this->tracker->insert(array('source_uid' => 'u', 'item_key' => 'k')));
		$this->tracker->update(42, array());
		$this->tracker->update(42, array('item_state' => 1));
		$this->assertCount(2, $this->queries);
		$this->assertStringContainsString('WHERE tracking_id = 42', $this->queries[1]);
	}

	public function test_compare_and_set_requires_exactly_one_matched_row()
	{
		$this->assertTrue($this->tracker->update(7, array('content_hash' => 'new'), array('pending_op' => 2, 'pending_checksum' => "a'b")));
		$this->assertStringContainsString("WHERE tracking_id = 7 AND pending_op = 2 AND pending_checksum = 'a''b'", $this->queries[0]);
		$this->affected = 0;
		$this->assertFalse($this->tracker->update(7, array('content_hash' => 'new'), array('pending_op' => 2)));
		$this->assertFalse($this->tracker->delete(7, array('pending_op' => 1)));
		$this->assertStringContainsString('DELETE FROM phpbb_feedpostbot_items WHERE tracking_id = 7 AND pending_op = 1', $this->queries[2]);
		// Without expectations the result does not depend on matched rows.
		$this->assertTrue($this->tracker->update(7, array('content_hash' => 'new')));
	}
}
