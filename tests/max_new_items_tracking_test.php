<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

require_once __DIR__ . '/fixtures/tracking.php';

use ger\feedpostbot\classes\item_tracker;

/**
 * The new topic limit together with update tracking: only genuinely new items take a slot.
 */
class max_new_items_tracking_test extends fixtures\tracking_test_case
{
	/** Subjects of the topics posted so far, in posting order */
	protected function posted()
	{
		return array_map(function ($call) { return $call['subject']; }, $this->calls('post'));
	}

	protected function tracked($id)
	{
		$rows = $this->tracker->load($this->saved[0]['source_uid'], array(hash('sha256', ($id[0] === '/' ? 'link:https://example.com' : 'guid:') . $id)));
		return $rows ? reset($rows) : null;
	}

	/** Tracking row of an item identified by its permalink (guidless feeds) */
	protected function tracked_link($id)
	{
		return $this->tracked('/' . $id);
	}

	protected function result()
	{
		$result = $this->driver->get_run_result();
		return array($result['created'], $result['updated']);
	}

	/** Publish an item with an unlimited cap and return its tracking row */
	protected function publish(array $item)
	{
		$this->assertSame(1, $this->run_feed(array($item)));
		return $this->row();
	}

	public function tracked_kinds()
	{
		return array(
			'native identifier'	=> array(false),
			'guidless feed'		=> array(true),
		);
	}

	protected function tracked_item($id, $body, $guidless)
	{
		return $guidless ? $this->guidless_item($id, $body) : $this->item($id, $body);
	}

	// --- 1./2. Tracked items take no slot --------------------------------------------------

	/**
	 * @dataProvider tracked_kinds
	 */
	public function test_tracked_item_takes_no_slot_while_updates_are_off($guidless)
	{
		$this->settings(array('update_existing' => 0));
		$row = $this->publish($this->tracked_item('t', 'Body', $guidless));
		$this->settings(array('max_new_items' => 2));
		// The tracked item is at the top of the feed, where it would take the first slot.
		$feed = array($this->tracked_item('t', 'Changed', $guidless), $this->item('n1'), $this->item('n2'));
		$this->assertSame(2, $this->run_feed($feed));
		$this->assertSame(array('Title t', 'Title n2', 'Title n1'), $this->posted());
		$this->assertCount(0, $this->calls('edit'));
		$this->assertSame($row, $this->row_of($row));
		$this->assertCount(3, $this->tracker->rows);
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertCount(3, $this->topics());
	}

	/**
	 * @dataProvider tracked_kinds
	 */
	public function test_tracked_update_takes_no_slot($guidless)
	{
		$row = $this->publish($this->tracked_item('t', 'Body', $guidless));
		$this->settings(array('max_new_items' => 2));
		$feed = array($this->tracked_item('t', 'Changed', $guidless), $this->item('n1'), $this->item('n2'));
		$this->assertSame(2, $this->run_feed($feed));
		$this->assertSame(array(2, 1), $this->result());
		$this->assertSame(array('Title t', 'Title n2', 'Title n1'), $this->posted());
		$edits = $this->calls('edit');
		$this->assertCount(1, $edits);
		$this->assertSame($row['post_id'], $edits[0]['data']['post_id']);
		$this->assertStringContainsString('Changed', $this->forum->posts[$row['post_id']]['post_text']);
		$this->assertNotSame($row['content_hash'], $this->row_of($row)['content_hash']);
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertCount(1, $this->calls('edit'));
		$this->assertCount(3, $this->topics());
	}

	/** Current tracking row of a row loaded earlier */
	protected function row_of(array $row)
	{
		return $this->tracker->rows[$row['tracking_id']];
	}

	// --- 3. Historical key changed with the content ---------------------------------------

	public function test_tracked_item_with_changed_history_key_is_not_counted_as_new()
	{
		$first = $this->guidless_item('t');
		$row = $this->publish($first);
		$changed = $this->guidless_item('t', 'Changed');
		// The historical key follows the content, the tracking identity does not.
		$this->assertNotSame($first['guid'], $changed['guid']);
		$this->assertArrayNotHasKey(hash('sha256', 'guid:' . $changed['guid']), $this->saved[0]['handled']);
		$this->settings(array('max_new_items' => 1));
		$this->assertSame(1, $this->run_feed(array($changed, $this->item('n1'), $this->item('n2'))));
		// n1 got the only slot; the tracked item neither took it nor was consumed as excess.
		$this->assertSame(array('Title t', 'Title n1'), $this->posted());
		$this->assertCount(1, $this->calls('edit'));
		$this->assertSame($row['post_id'], $this->calls('edit')[0]['data']['post_id']);
		$this->assertStringContainsString('Changed', $this->forum->posts[$row['post_id']]['post_text']);
		$after = $this->row_of($row);
		$this->assertNotSame($row['source_hash'], $after['source_hash']);
		$this->assertSame(item_tracker::STATE_ACTIVE, $after['item_state']);
		$this->assertArrayHasKey(hash('sha256', 'guid:' . $changed['guid']), $this->saved[0]['handled']);
		$this->assertArrayHasKey(hash('sha256', 'guid:n2'), $this->saved[0]['handled']);
		$this->assertSame(0, $this->run_feed(array($changed, $this->item('n1'), $this->item('n2'))));
		// Later changes are still applied: the item was never swallowed.
		$this->assertSame(0, $this->run_feed(array($this->guidless_item('t', 'Changed again'), $this->item('n1'), $this->item('n2'))));
		$this->assertCount(2, $this->calls('edit'));
		$this->assertStringContainsString('Changed again', $this->forum->posts[$row['post_id']]['post_text']);
		$this->assertCount(2, $this->topics());
	}

	public function test_tracked_item_below_the_cap_with_changed_history_key_is_not_swallowed()
	{
		$this->settings(array('update_existing' => 0));
		$row = $this->publish($this->guidless_item('t'));
		$this->settings(array('max_new_items' => 1));
		// Below the excess new items: a counting cap would mark it handled as discarded.
		$changed = $this->guidless_item('t', 'Changed');
		$this->assertSame(1, $this->run_feed(array($this->item('n1'), $this->item('n2'), $changed)));
		$this->assertSame(array('Title t', 'Title n1'), $this->posted());
		$this->assertSame($row, $this->row_of($row));
		// The frozen change is applied once updates are enabled again.
		$this->settings(array('update_existing' => 1));
		$this->assertSame(0, $this->run_feed(array($this->item('n1'), $this->item('n2'), $changed)));
		$this->assertCount(1, $this->calls('edit'));
		$this->assertStringContainsString('Changed', $this->forum->posts[$row['post_id']]['post_text']);
		$this->assertCount(2, $this->topics());
	}

	public function test_identically_rendering_guidless_change_only_rebaselines_and_takes_no_slot()
	{
		$row = $this->publish($this->guidless_item('t', 'Body'));
		$this->settings(array('max_new_items' => 1));
		$same = $this->item('t', 'Body', array('native_id' => '', 'description' => '<p class="x">Body</p>'));
		$same['guid'] = md5($same['link'] . $same['title'] . $same['description']);
		$this->assertSame(1, $this->run_feed(array($same, $this->item('n1'), $this->item('n2'))));
		$this->assertSame(array('Title t', 'Title n1'), $this->posted());
		$this->assertCount(0, $this->calls('edit'));
		$after = $this->row_of($row);
		$this->assertNotSame($row['source_hash'], $after['source_hash']);
		$this->assertSame($row['content_hash'], $after['content_hash']);
	}

	// --- 4. Pending creates ----------------------------------------------------------------

	public function test_interrupted_creation_is_completed_without_taking_a_slot()
	{
		// The topic is committed, then recording its ids fails (as if the process died there).
		$this->tracker->fail_on = function ($fields) { return isset($fields['topic_id']) && $fields['topic_id'] > 0; };
		$this->run_feed(array($this->item('p')));
		$this->assertSame(item_tracker::PENDING_CREATE, $this->row()['pending_op']);
		$this->tracker->fail_on = null;
		$this->settings(array('max_new_items' => 2));
		$this->assertSame(2, $this->run_feed(array($this->item('p'), $this->item('n1'), $this->item('n2'), $this->item('n3'))));
		$this->assertSame(array('Title p', 'Title n2', 'Title n1'), $this->posted());
		$pending = $this->tracked('p');
		$this->assertSame(item_tracker::PENDING_NONE, $pending['pending_op']);
		$this->assertSame(1, $pending['topic_id']);
		$this->assertSame(0, $this->run_feed(array($this->item('p'), $this->item('n1'), $this->item('n2'), $this->item('n3'))));
		$this->assertCount(3, $this->topics());
	}

	public function test_creation_interrupted_before_posting_is_retried_without_taking_a_slot()
	{
		$row = $this->publish($this->item('a'));
		// A process recorded a creation for p and died before submit_post().
		$this->tracker->insert(array_merge($row, array('item_key' => hash('sha256', 'guid:p'), 'item_ident' => 'guid:p', 'topic_id' => 0,
			'post_id' => 0, 'pending_op' => item_tracker::PENDING_CREATE, 'pending_checksum' => '', 'pending_after_post_id' => $this->forum->max_post_id())));
		$this->settings(array('max_new_items' => 2));
		// p is at the top of the feed; it was admitted by the run that recorded it.
		$this->assertSame(3, $this->run_feed(array($this->item('p'), $this->item('n1'), $this->item('n2'), $this->item('n3'), $this->item('a'))));
		$this->assertSame(array('Title a', 'Title n2', 'Title n1', 'Title p'), $this->posted());
		$this->assertSame(item_tracker::PENDING_NONE, $this->tracked('p')['pending_op']);
		$this->assertGreaterThan(0, $this->tracked('p')['topic_id']);
		$this->assertNull($this->tracked('n3'));
		$this->assertSame(0, $this->run_feed(array($this->item('p'), $this->item('n1'), $this->item('n2'), $this->item('n3'), $this->item('a'))));
		$this->assertCount(4, $this->topics());
	}

	// --- 5. Recreation of permanently deleted topics ---------------------------------------

	public function test_recreation_takes_no_slot()
	{
		$this->settings(array('recreate_deleted' => 1));
		$row = $this->publish($this->item('d'));
		unset($this->forum->topics[$row['topic_id']], $this->forum->posts[$row['post_id']]);
		$this->settings(array('max_new_items' => 1));
		// Without a new source update the deleted topic is not recreated, and takes no slot either.
		$this->assertSame(1, $this->run_feed(array($this->item('d'), $this->item('n1'), $this->item('n2'))));
		$this->assertSame(array('Title d', 'Title n1'), $this->posted());
		$this->assertSame(item_tracker::STATE_ACTIVE, $this->row_of($row)['item_state']);
		// A real source update recreates it, while a further new item gets the slot.
		$this->assertSame(2, $this->run_feed(array($this->item('d', 'Changed'), $this->item('n3'), $this->item('n4'), $this->item('n1'), $this->item('n2'))));
		$this->assertSame(array(2, 0), $this->result());
		$this->assertSame(array('Title d', 'Title n1', 'Title n3', 'Title d'), $this->posted());
		$recreated = $this->row_of($row);
		$this->assertNotSame($row['topic_id'], $recreated['topic_id']);
		$this->assertSame(item_tracker::STATE_ACTIVE, $recreated['item_state']);
		$this->assertArrayHasKey(hash('sha256', 'guid:n4'), $this->saved[0]['handled']);
		$this->assertSame(0, $this->run_feed(array($this->item('d', 'Changed'), $this->item('n3'), $this->item('n4'), $this->item('n1'), $this->item('n2'))));
		$this->assertCount(3, $this->topics());
	}

	public function test_deleted_topic_without_recreation_takes_no_slot()
	{
		$row = $this->publish($this->item('d'));
		unset($this->forum->topics[$row['topic_id']], $this->forum->posts[$row['post_id']]);
		$this->settings(array('max_new_items' => 1));
		$this->assertSame(1, $this->run_feed(array($this->item('d', 'Changed'), $this->item('n1'), $this->item('n2'))));
		$this->assertSame(array('Title d', 'Title n1'), $this->posted());
		$this->assertSame(item_tracker::STATE_GONE, $this->row_of($row)['item_state']);
		$this->assertSame(item_tracker::REASON_HARD_DELETED, $this->row_of($row)['state_reason']);
	}

	public function test_interrupted_recreation_is_completed_without_taking_a_slot()
	{
		$this->settings(array('recreate_deleted' => 1));
		$row = $this->publish($this->item('d'));
		unset($this->forum->topics[$row['topic_id']], $this->forum->posts[$row['post_id']]);
		$this->tracker->fail_on = function ($fields) { return isset($fields['topic_id']) && $fields['topic_id'] > 0; };
		$this->run_feed(array($this->item('d', 'Changed')));
		$this->assertSame(item_tracker::PENDING_RECREATE, $this->row()['pending_op']);
		$this->tracker->fail_on = null;
		$this->settings(array('max_new_items' => 1));
		$this->assertSame(1, $this->run_feed(array($this->item('d', 'Changed'), $this->item('n1'), $this->item('n2'))));
		$this->assertSame(array('Title d', 'Title d', 'Title n1'), $this->posted());
		$this->assertSame(item_tracker::PENDING_NONE, $this->row_of($row)['pending_op']);
		$this->assertCount(2, $this->topics());
	}

	// --- 6. Update replies ---------------------------------------------------------------

	public function test_owed_update_reply_has_no_effect_on_the_cap()
	{
		$this->settings(array('update_reply' => 1));
		$row = $this->publish($this->item('a'));
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertNotSame($this->row()['content_hash'], $this->row()['notified_hash']);
		$this->release_replies();
		$this->settings(array('max_new_items' => 2));
		$this->assertSame(2, $this->run_feed(array($this->item('a', 'Changed'), $this->item('n1'), $this->item('n2'), $this->item('n3'))));
		$this->assertCount(1, $this->calls('reply'));
		$this->assertSame($row['topic_id'], $this->calls('reply')[0]['data']['topic_id']);
		$this->assertSame(array('Title a', 'Title n2', 'Title n1'), $this->posted());
		$after = $this->row_of($row);
		$this->assertSame($after['content_hash'], $after['notified_hash']);
	}

	public function test_update_with_reply_takes_no_slot()
	{
		$this->settings(array('update_reply' => 1));
		$row = $this->publish($this->guidless_item('a'));
		$this->settings(array('max_new_items' => 1));
		$this->assertSame(1, $this->run_feed(array($this->guidless_item('a', 'Changed'), $this->item('n1'), $this->item('n2'))));
		$this->assertSame(array(1, 1), $this->result());
		$this->assertCount(1, $this->calls('edit'));
		$this->assertCount(1, $this->calls('reply'));
		$this->assertSame($row['topic_id'], $this->calls('reply')[0]['data']['topic_id']);
		$this->assertSame(array('Title a', 'Title n1'), $this->posted());
	}

	public function test_interrupted_update_reply_is_settled_without_taking_a_slot()
	{
		$this->settings(array('update_reply' => 1));
		$row = $this->publish($this->item('a'));
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->release_replies();
		// The reply is committed, then settling the debt fails (as if the process died there).
		$this->tracker->fail_on = function ($fields) { return isset($fields['notified_hash']); };
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertSame(item_tracker::PENDING_REPLY, $this->row()['pending_op']);
		$this->tracker->fail_on = null;
		$this->settings(array('max_new_items' => 1));
		$this->assertSame(1, $this->run_feed(array($this->item('a', 'Changed'), $this->item('n1'), $this->item('n2'))));
		$this->assertCount(1, $this->calls('reply'));
		$this->assertSame(array('Title a', 'Title n1'), $this->posted());
		$after = $this->row_of($row);
		$this->assertSame(item_tracker::PENDING_NONE, $after['pending_op']);
		$this->assertSame($after['content_hash'], $after['notified_hash']);
	}

	// --- Other tracked states ------------------------------------------------------------

	public function test_pending_edit_frozen_while_updates_are_off_takes_no_slot()
	{
		$row = $this->publish($this->guidless_item('a'));
		// The edit is committed, then recording it fails (as if the process died there).
		$this->tracker->fail_on = function ($fields) { return isset($fields['pending_op']) && $fields['pending_op'] === item_tracker::PENDING_NONE; };
		$this->run_feed(array($this->guidless_item('a', 'Changed')));
		$this->tracker->fail_on = null;
		$this->assertSame(item_tracker::PENDING_EDIT, $this->row()['pending_op']);
		$this->settings(array('update_existing' => 0, 'max_new_items' => 1));
		$this->assertSame(1, $this->run_feed(array($this->guidless_item('a', 'Changed'), $this->item('n1'), $this->item('n2'))));
		$this->assertSame(array('Title a', 'Title n1'), $this->posted());
		$this->assertSame(item_tracker::PENDING_EDIT, $this->row_of($row)['pending_op']);
	}

	public function test_soft_deleted_and_detached_topics_take_no_slot()
	{
		$soft = $this->publish($this->guidless_item('s'));
		$this->assertSame(1, $this->run_feed(array($this->guidless_item('m'), $this->guidless_item('s'))));
		$manual = $this->tracked_link('m');
		$this->forum->topics[$soft['topic_id']]['topic_visibility'] = ITEM_DELETED;
		$this->forum->posts[$soft['post_id']]['post_visibility'] = ITEM_DELETED;
		$this->forum->posts[$manual['post_id']]['post_text'] = 'Edited by a moderator';
		$this->settings(array('max_new_items' => 1));
		$feed = array($this->guidless_item('m', 'Changed'), $this->guidless_item('s', 'Changed'), $this->item('n1'), $this->item('n2'));
		$this->assertSame(1, $this->run_feed($feed));
		$this->assertSame(array('Title s', 'Title m', 'Title n1'), $this->posted());
		$this->assertCount(0, $this->calls('edit'));
		$this->assertSame(item_tracker::STATE_BLOCKED, $this->row_of($soft)['item_state']);
		$this->assertSame(item_tracker::REASON_SOFT_DELETED, $this->row_of($soft)['state_reason']);
		$this->assertSame(item_tracker::STATE_DETACHED, $this->row_of($manual)['item_state']);
		$this->assertSame(ITEM_DELETED, $this->forum->topics[$soft['topic_id']]['topic_visibility']);
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertCount(3, $this->topics());
	}

	public function test_ambiguous_tracked_identity_takes_no_slot_and_is_never_posted()
	{
		$this->publish($this->item('a'));
		$this->settings(array('max_new_items' => 1));
		$clash = $this->item('a', 'Another story', array('title' => 'Other', 'guid' => 'a2'));
		$this->assertSame(1, $this->run_feed(array($this->item('a', 'Changed'), $clash, $this->item('n1'), $this->item('n2'))));
		$this->assertSame(array('Title a', 'Title n1'), $this->posted());
		$this->assertSame(item_tracker::REASON_AMBIGUOUS, $this->tracked('a')['state_reason']);
	}

	public function test_untracked_ambiguous_new_items_are_counted_as_new()
	{
		$this->settings(array('max_new_items' => 2));
		// Two new items sharing a permalink cannot be tracked; each would be a new topic.
		$first = $this->guidless_item('x', 'One');
		$second = $this->guidless_item('x', 'Two');
		$this->assertSame(2, $this->run_feed(array($first, $second, $this->item('n1'))));
		$this->assertCount(0, $this->tracker->rows);
		$this->assertArrayHasKey(hash('sha256', 'guid:n1'), $this->saved[0]['handled']);
		$this->assertSame(0, $this->run_feed(array($first, $second, $this->item('n1'))));
		$this->assertCount(2, $this->topics());
	}

	public function test_updates_and_capped_new_topics_need_no_forum_permissions()
	{
		$this->settings(array('update_reply' => 1));
		$row = $this->publish($this->item('a'));
		$this->revoke_all();
		$this->settings(array('max_new_items' => 1));
		$this->assertSame(1, $this->run_feed(array($this->item('a', 'Changed'), $this->item('n1'), $this->item('n2'))));
		$this->assertCount(1, $this->calls('edit'));
		$this->assertCount(1, $this->calls('reply'));
		$this->assertSame(array('Title a', 'Title n1'), $this->posted());
		$this->assertStringContainsString('Changed', $this->forum->posts[$row['post_id']]['post_text']);
	}

	// --- 7. Legacy marker missing, some items already tracked -----------------------------

	public function test_missing_marker_with_tracked_items_caps_only_unseen_items()
	{
		$this->run_feed(array($this->item('t1'), $this->guidless_item('t2')));
		$this->assertCount(2, $this->tracker->rows);
		// Like a migrated installation: only an old "latest" marker that has left the feed.
		unset($this->saved[0]['handled']);
		$this->saved[0]['latest'] = array('guid' => 'gone', 'link' => 'https://example.com/gone', 'pubDate' => 0);
		$this->settings(array('max_new_items' => 2));
		$feed = array($this->item('n1'), $this->item('t1'), $this->item('n2'), $this->guidless_item('t2', 'Changed'),
			$this->item('n3'), $this->item('n4'), $this->item('n5'));
		$this->assertSame(2, $this->run_feed($feed));
		$this->assertSame(array('Title t2', 'Title t1', 'Title n2', 'Title n1'), $this->posted());
		$this->assertCount(4, $this->tracker->rows);
		$this->assertCount(1, $this->calls('edit'));
		foreach (array('n3', 'n4', 'n5') as $id)
		{
			$this->assertArrayHasKey(hash('sha256', 'guid:' . $id), $this->saved[0]['handled']);
			$this->assertNull($this->tracked($id));
		}
		// The excess was consumed, not deferred.
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertCount(4, $this->topics());
		// A genuinely new item arriving later is posted normally.
		array_unshift($feed, $this->item('n6'));
		$this->assertSame(1, $this->run_feed($feed));
		$this->assertSame('Title n6', array_slice($this->posted(), -1)[0]);
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertCount(5, $this->topics());
	}

	// --- 8. Unlimited --------------------------------------------------------------------

	/**
	 * A representative update tracking history: creation, guidless and native updates with
	 * replies, an owed reply, hard deletion and recreation, an interrupted creation, a
	 * collision, a manual edit and a burst of new items.
	 *
	 * @return array Normalised outcome
	 */
	protected function scenario($limit)
	{
		$this->logs = array();
		$this->settings(array('update_reply' => 1, 'recreate_deleted' => 1));
		if ($limit !== null)
		{
			$this->settings(array('max_new_items' => $limit));
		}
		$counts = array();
		$counts[] = $this->run_feed(array($this->item('a'), $this->guidless_item('g'), $this->item('m')));
		$counts[] = $this->run_feed(array($this->item('b'), $this->item('a', 'A2'), $this->guidless_item('g', 'G2'), $this->item('m')));
		$this->hold_replies();
		$counts[] = $this->run_feed(array($this->item('b', 'B2'), $this->item('a', 'A2'), $this->guidless_item('g', 'G2'), $this->item('m')));
		$this->release_replies();
		$m = $this->tracked('m');
		$this->forum->posts[$m['post_id']]['post_text'] = 'Edited by a moderator';
		$a = $this->tracked('a');
		unset($this->forum->topics[$a['topic_id']], $this->forum->posts[$a['post_id']]);
		$this->tracker->fail_on = function ($fields) { return isset($fields['topic_id']) && $fields['topic_id'] > 0; };
		$counts[] = $this->run_feed(array($this->item('c'), $this->item('b', 'B2'), $this->guidless_item('g', 'G2'), $this->item('m')));
		$this->tracker->fail_on = null;
		$burst = array();
		for ($i = 1; $i <= 6; $i++)
		{
			$burst[] = $this->item('x' . $i);
		}
		$feed = array_merge($burst, array($this->item('c'), $this->item('a', 'A3'), $this->item('b', 'B2'), $this->guidless_item('g', 'G3'),
			$this->item('m', 'M2'), $this->item('b', 'Clash', array('title' => 'Other', 'guid' => 'b2'))));
		$counts[] = $this->run_feed($feed);
		$counts[] = $this->run_feed($feed);
		$rows = array();
		foreach ($this->tracker->rows as $row)
		{
			unset($row['source_uid'], $row['created_time'], $row['updated_time'], $row['reply_next_retry']);
			$rows[] = $row;
		}
		$posts = array();
		foreach ($this->forum->posts as $post)
		{
			unset($post['bbcode_uid'], $post['post_edit_time']);
			$posts[] = $post;
		}
		$calls = array_map(function ($call) { return array($call['mode'], $call['subject']); }, $this->forum->calls);
		$handled = $this->saved[0]['handled'];
		ksort($handled);
		return array($counts, $calls, $rows, $posts, $this->forum->topics, $handled, $this->state_logs());
	}

	public function unlimited_settings()
	{
		return array(
			'zero'			=> array(0),
			'string zero'	=> array('0'),
			'above burst'	=> array(1000),
		);
	}

	/**
	 * @dataProvider unlimited_settings
	 */
	public function test_unlimited_matches_feeds_without_the_setting($limit)
	{
		$baseline = $this->scenario(null);
		$this->setUp();
		$this->assertSame($baseline, $this->scenario($limit));
		// The burst run creates six new topics and recreates a.
		$this->assertSame(array(3, 1, 0, 0, 7, 0), $baseline[0]);
	}

	public function test_capped_scenario_differs_only_in_discarded_new_items()
	{
		$baseline = $this->scenario(null);
		$this->setUp();
		$capped = $this->scenario(3);
		// Every tracked item's update, reply, recreation and pending creation happened as without a cap.
		$this->assertSame(array(3, 1, 0, 0, 4, 0), $capped[0]);
		$without_burst = function (array $calls) {
			return array_values(array_filter($calls, function ($call) { return !preg_match('/^Title x\d$/', $call[1]); }));
		};
		$this->assertSame($without_burst($baseline[1]), $without_burst($capped[1]));
		$this->assertContains(array('post', 'Title x1'), $capped[1]);
		$this->assertContains(array('post', 'Title x3'), $capped[1]);
		$this->assertNotContains(array('post', 'Title x4'), $capped[1]);
		$this->assertSame(array_keys($baseline[5]), array_keys($capped[5]));
		$this->assertSame($baseline[6], $capped[6]);
	}

	// --- 9. Event-consumed new items ------------------------------------------------------

	public function test_event_consumed_new_item_keeps_its_slot()
	{
		$this->listeners['ger.feedpostbot.submit_post_before'] = function ($data) {
			if ($data['rss_item']['guid'] === 'n1')
			{
				$data['do_post'] = false;
			}
			return $data;
		};
		$this->settings(array('max_new_items' => 2));
		$feed = array($this->item('n1'), $this->item('n2'), $this->item('n3'));
		// The limit is an upper bound: the consumed item's slot is not given to n3.
		$this->assertSame(1, $this->run_feed($feed));
		$this->assertSame(array('Title n2'), $this->posted());
		$this->assertNull($this->tracked('n1'));
		$this->assertNotNull($this->tracked('n2'));
		$this->assertNull($this->tracked('n3'));
		$this->assertSame(0, $this->run_feed($feed));
		$this->assertCount(1, $this->topics());
	}
}
