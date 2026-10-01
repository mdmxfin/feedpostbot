<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

require_once __DIR__ . '/fixtures/tracking.php';

use ger\feedpostbot\classes\item_tracker;

class update_test extends fixtures\tracking_test_case
{
	/** Publish item a and return its tracking row */
	protected function publish($body = 'Body')
	{
		$this->assertSame(1, $this->run_feed(array($this->item('a', $body))));
		return $this->row();
	}

	protected function first_post()
	{
		$row = $this->row();
		return $this->forum->posts[$row['post_id']];
	}

	protected function assert_state($state, $reason)
	{
		$row = $this->row();
		$this->assertSame($state, $row['item_state']);
		$this->assertSame($reason, $row['state_reason']);
	}

	// --- Updating -------------------------------------------------------------------------

	public function test_changed_item_updates_only_the_mapped_first_post()
	{
		$row = $this->publish();
		$this->assertSame(0, $this->run_feed(array($this->item('a', 'Corrected'))));
		$edits = $this->calls('edit');
		$this->assertCount(1, $edits);
		$this->assertSame($row['post_id'], $edits[0]['data']['post_id']);
		$this->assertSame(3, $edits[0]['user_id']);
		$this->assertSame(0, $edits[0]['data']['post_time']);
		$this->assertStringContainsString('Corrected', $this->first_post()['post_text']);
		$this->assertCount(1, $this->topics());
		$after = $this->row();
		$this->assertNotSame($row['content_hash'], $after['content_hash']);
		$this->assertSame($after['content_hash'], $after['notified_hash']);
		$this->assertNotSame($row['guard_hash'], $after['guard_hash']);
		$this->assertGreaterThan(0, $after['updated_time']);
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		// The bot's own edit refreshed the guard: further updates are not mistaken for manual edits.
		$this->run_feed(array($this->item('a', 'Corrected')));
		$this->run_feed(array($this->item('a', 'Corrected again')));
		$this->assertCount(2, $this->calls('edit'));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	public function test_edit_in_a_fresh_run_loads_the_posting_functions()
	{
		$row = $this->publish();
		$this->driver->loader_calls = 0;
		// A new cron run: only the update happens, nothing creates a topic first.
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertGreaterThan(0, $this->driver->loader_calls);
		$this->assertCount(1, $this->calls('edit'));
		$this->assertStringContainsString('Changed', $this->first_post()['post_text']);
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$this->assertNotSame($row['content_hash'], $this->row()['content_hash']);
	}

	public function test_unavailable_posting_functions_leave_the_change_pending()
	{
		$row = $this->publish();
		$this->driver->functions_available = false;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(0, $this->calls('edit'));
		$after = $this->row();
		$this->assertSame($row['source_hash'], $after['source_hash']);
		$this->assertSame($row['content_hash'], $after['content_hash']);
		$this->assertSame(0, $after['pending_op']);
		$this->driver->functions_available = true;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('edit'));
		$this->assertStringContainsString('Changed', $this->first_post()['post_text']);
	}

	public function test_title_change_updates_topic_title()
	{
		$this->publish();
		$this->run_feed(array($this->item('a', 'Body', array('title' => 'New title'))));
		$this->assertSame('New title', reset($this->forum->topics)['topic_title']);
		$this->assertSame('New title', $this->first_post()['post_subject']);
	}

	public function test_guidless_change_while_off_is_applied_after_reenabling()
	{
		$this->run_feed(array($this->guidless_item('news')));
		$row = $this->row();
		$this->settings(array('update_existing' => 0));
		$this->run_feed(array($this->guidless_item('news', 'Changed body')));
		$this->assertCount(1, $this->topics());
		$this->assertCount(0, $this->calls('edit'));
		$this->assertSame($row, $this->row());
		$this->settings(array('update_existing' => 1));
		$this->run_feed(array($this->guidless_item('news', 'Changed body')));
		$this->assertCount(1, $this->topics());
		$this->assertCount(1, $this->calls('edit'));
		$this->assertSame($row['post_id'], $this->calls('edit')[0]['data']['post_id']);
		$this->assertStringContainsString('Changed body', $this->first_post()['post_text']);
	}

	public function test_update_veto_consumes_the_version()
	{
		$row = $this->publish();
		$this->listeners['ger.feedpostbot.update_post_before'] = function ($data) {
			$data['do_update'] = false;
			return $data;
		};
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(0, $this->calls('edit'));
		// The feed version is seen, but nothing new was published.
		$this->assertNotSame($row['source_hash'], $this->row()['source_hash']);
		$this->assertSame($row['content_hash'], $this->row()['content_hash']);
		$this->assertSame($row['notified_hash'], $this->row()['notified_hash']);
		unset($this->listeners['ger.feedpostbot.update_post_before']);
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(0, $this->calls('edit'));
	}

	public function test_listener_cannot_redirect_the_edit()
	{
		$row = $this->publish();
		$other = $this->forum->user_reply($row['topic_id'], 'A comment');
		$this->listeners['ger.feedpostbot.update_post_before'] = function ($data) use ($other) {
			$data['data']['post_id'] = $other;
			$data['data']['topic_id'] = 99;
			$data['data']['forum_id'] = 6;
			$data['data']['poster_id'] = 50;
			$data['data']['topic_first_post_id'] = $other;
			$data['data']['post_visibility'] = ITEM_DELETED;
			$data['data']['force_visibility'] = ITEM_DELETED;
			$data['data']['force_approved_state'] = ITEM_UNAPPROVED;
			// Content changes are supported.
			$data['data']['message'] = '<r>Listener text</r>';
			$data['data']['message_md5'] = md5('<r>Listener text</r>');
			return $data;
		};
		$this->run_feed(array($this->item('a', 'Changed')));
		$edit = $this->calls('edit')[0]['data'];
		$this->assertSame($row['post_id'], $edit['post_id']);
		$this->assertSame($row['topic_id'], $edit['topic_id']);
		$this->assertSame(5, $edit['forum_id']);
		$this->assertSame(3, $edit['poster_id']);
		$this->assertSame($row['post_id'], $edit['topic_first_post_id']);
		$this->assertSame(ITEM_APPROVED, $edit['post_visibility']);
		$this->assertArrayNotHasKey('force_visibility', $edit);
		$this->assertArrayNotHasKey('force_approved_state', $edit);
		$this->assertSame('<r>Listener text</r>', $this->first_post()['post_text']);
		$this->assertSame('A comment', $this->forum->posts[$other]['post_text']);
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	public function test_placement_is_preserved()
	{
		$row = $this->publish();
		$this->forum->topics[$row['topic_id']]['topic_type'] = POST_STICKY;
		$this->forum->topics[$row['topic_id']]['icon_id'] = 7;
		$this->forum->posts[$row['post_id']]['icon_id'] = 7;
		$this->run_feed(array($this->item('a', 'Changed')));
		$edit = $this->calls('edit')[0];
		$this->assertSame(POST_STICKY, $edit['topic_type']);
		$this->assertSame(7, $edit['data']['icon_id']);
		$this->assertSame(POST_STICKY, $this->forum->topics[$row['topic_id']]['topic_type']);
	}

	public function test_moved_topic_is_updated_in_its_current_forum()
	{
		$row = $this->publish();
		$this->forum->topics[$row['topic_id']]['forum_id'] = 6;
		$this->forum->posts[$row['post_id']]['forum_id'] = 6;
		// The feed user has no permissions at all in the new forum.
		$this->revoke_all(array(6));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertSame(6, $this->calls('edit')[0]['data']['forum_id']);
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$this->assertCount(1, $this->topics());
	}

	// --- Run result counting -------------------------------------------------------------

	protected function result()
	{
		return $this->driver->get_run_result();
	}

	protected function assert_result($created, $updated)
	{
		$result = $this->result();
		$this->assertSame(array($created, $updated), array($result['created'], $result['updated']));
		$this->assertFalse($result['locked']);
	}

	public function test_run_result_without_changes()
	{
		$this->assertSame(0, $this->run_feed(array()));
		$this->assert_result(0, 0);
		$this->publish();
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
		$this->assert_result(0, 0);
	}

	public function test_run_result_counts_created_topics()
	{
		$this->assertSame(1, $this->run_feed(array($this->item('a'))));
		$this->assert_result(1, 0);
		$this->assertSame(3, $this->run_feed(array($this->item('b'), $this->item('c'), $this->item('d'), $this->item('a'))));
		$this->assert_result(3, 0);
	}

	public function test_run_result_counts_updated_topics()
	{
		$this->publish();
		$this->assertSame(0, $this->run_feed(array($this->item('a', 'Changed'))));
		$this->assert_result(0, 1);
		$this->run_feed(array($this->item('a', 'Changed'), $this->item('b'), $this->item('c')));
		$this->assert_result(2, 0);
		$this->run_feed(array($this->item('a', 'Changed again'), $this->item('b', 'Changed'), $this->item('c', 'Changed'), $this->item('d')));
		$this->assert_result(1, 3);
	}

	public function test_source_only_change_is_not_counted_as_update()
	{
		$this->assertSame(1, $this->run_feed(array($this->item('a', 'Body', array('description' => '<p class="x">Body</p>')))));
		$row = $this->row();
		$this->run_feed(array($this->item('a', 'Body', array('description' => '<p class="y">Body</p>'))));
		$this->assert_result(0, 0);
		$this->assertNotSame($row['source_hash'], $this->row()['source_hash']);
	}

	public function test_blocked_update_is_not_counted()
	{
		$row = $this->publish();
		$this->forum->posts[$row['post_id']]['post_edit_locked'] = 1;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_result(0, 0);
		$this->assert_state(item_tracker::STATE_BLOCKED, item_tracker::REASON_POST_EDIT_LOCKED);
	}

	public function test_updates_that_did_not_happen_are_not_counted()
	{
		// Detached, soft deleted, vetoed, failed and permanently deleted without recreation.
		$this->run_feed(array($this->item('a'), $this->item('b'), $this->item('c'), $this->item('d'), $this->item('e')));
		$rows = array();
		foreach ($this->tracker->rows as $row)
		{
			$rows[$row['item_key']] = $row;
		}
		$post = function ($id) use ($rows) { return $rows[hash('sha256', 'guid:' . $id)]; };
		$this->forum->posts[$post('a')['post_id']]['post_text'] = '<r>Moderated</r>';
		$this->forum->topics[$post('b')['topic_id']]['topic_visibility'] = ITEM_DELETED;
		$this->forum->posts[$post('b')['post_id']]['post_visibility'] = ITEM_DELETED;
		$this->listeners['ger.feedpostbot.update_post_before'] = function ($data) {
			$data['do_update'] = $data['mapping']['item_key'] !== hash('sha256', 'guid:c');
			return $data;
		};
		$this->forum->lose['edit'] = true;
		unset($this->forum->topics[$post('e')['topic_id']], $this->forum->posts[$post('e')['post_id']]);
		$changed = array();
		foreach (array('a', 'b', 'c', 'd', 'e') as $id)
		{
			$changed[] = $this->item($id, 'Changed');
		}
		$this->assertSame(0, $this->run_feed($changed));
		$this->assert_result(0, 0);
	}

	public function test_recreated_topic_counts_as_created_not_updated()
	{
		$this->settings(array('recreate_deleted' => 1));
		$row = $this->publish();
		unset($this->forum->topics[$row['topic_id']], $this->forum->posts[$row['post_id']]);
		$this->assertSame(1, $this->run_feed(array($this->item('a', 'Changed'))));
		$this->assert_result(1, 0);
	}

	public function test_recovered_edit_is_not_counted_again()
	{
		$this->publish();
		$this->tracker->fail_on = function ($fields) { return isset($fields['guard_hash']); };
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_result(0, 0);
		$this->tracker->fail_on = null;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_result(0, 0);
	}

	public function test_busy_lock_is_reported()
	{
		$this->driver->lock_available = false;
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
		$this->assertTrue($this->result()['locked']);
		$this->assertCount(0, $this->topics());
	}

	// --- Discussion -----------------------------------------------------------------------

	public function test_user_replies_survive_first_post_update()
	{
		$row = $this->publish();
		$replies = array();
		foreach (array('First comment', 'Second comment', 'Third comment') as $text)
		{
			$replies[] = $this->forum->user_reply($row['topic_id'], $text, $text === 'Second comment');
		}
		$before = $this->forum->topic_posts($row['topic_id']);
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$edit = $this->calls('edit')[0];
		$this->assertSame($row['post_id'], $edit['data']['post_id']);
		// Live topic counters are passed so phpBB treats this as a first-post edit.
		$this->assertSame(end($replies), $edit['data']['topic_last_post_id']);
		$this->assertSame(4, $edit['data']['topic_posts_approved']);
		$after = $this->forum->topic_posts($row['topic_id']);
		foreach ($replies as $post_id)
		{
			$this->assertSame($before[$post_id], $after[$post_id]);
		}
		$this->assertStringContainsString('Changed', $after[$row['post_id']]['post_text']);
	}

	public function test_reply_driven_topic_data_never_trips_the_guard()
	{
		$row = $this->publish();
		$topic = &$this->forum->topics[$row['topic_id']];
		$topic['topic_last_post_id'] = 99;
		$topic['topic_posts_approved'] = 12;
		$topic['topic_last_poster_id'] = 77;
		$topic['topic_last_post_time'] = 123456;
		$topic['topic_attachment'] = 1;
		$topic['topic_bumped'] = 1;
		unset($topic);
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$this->assertCount(1, $this->calls('edit'));
	}

	// --- Manual modification --------------------------------------------------------------

	public function manual_changes()
	{
		return array(
			'body'			=> array(function ($forum, $row) { $forum->posts[$row['post_id']]['post_text'] = '<r>Edited by a moderator</r>'; }),
			'post subject'	=> array(function ($forum, $row) { $forum->posts[$row['post_id']]['post_subject'] = 'Moderated subject'; }),
			'topic title'	=> array(function ($forum, $row) { $forum->topics[$row['topic_id']]['topic_title'] = 'Moderated title'; }),
			'attachment'	=> array(function ($forum, $row) { $forum->posts[$row['post_id']]['post_attachment'] = 1; }),
			'poll'			=> array(function ($forum, $row) { $forum->topics[$row['topic_id']]['poll_title'] = 'Poll?'; $forum->topics[$row['topic_id']]['poll_start'] = 5; }),
			'poster'		=> array(function ($forum, $row) { $forum->posts[$row['post_id']]['poster_id'] = 50; }),
			'text options'	=> array(function ($forum, $row) { $forum->posts[$row['post_id']]['enable_smilies'] = 0; }),
		);
	}

	/**
	 * @dataProvider manual_changes
	 */
	public function test_manual_modification_detaches_permanently($change)
	{
		$row = $this->publish();
		$change($this->forum, $row);
		$snapshot = $this->forum->snapshot();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_DETACHED, item_tracker::REASON_MANUAL_EDIT);
		$this->assertCount(0, $this->calls('edit'));
		$this->assertSame($snapshot, $this->forum->snapshot());
		$this->assertCount(1, $this->state_logs());
		$this->run_feed(array($this->item('a', 'Changed again')));
		$this->run_feed(array($this->item('a', 'And again')));
		$this->assertCount(0, $this->calls('edit'));
		$this->assertCount(1, $this->topics());
		$this->assertCount(1, $this->state_logs());
	}

	public function test_storage_representation_changes_do_not_detach()
	{
		$row = $this->publish();
		$post = &$this->forum->posts[$row['post_id']];
		$post['post_text'] = '<r><s></s>' . substr($post['post_text'], 3);
		$post['bbcode_uid'] = 'reparsed';
		$post['post_checksum'] = 'other';
		unset($post);
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$this->assertCount(1, $this->calls('edit'));
	}

	public function test_older_guard_version_is_adopted_without_detaching()
	{
		$row = $this->publish();
		$this->tracker->rows[$row['tracking_id']]['guard_hash'] = 'g0:old';
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$this->assertCount(1, $this->calls('edit'));
	}

	// --- Permissions are not checked (like topic creation) --------------------------------

	public function test_update_works_without_any_forum_permissions()
	{
		$row = $this->publish();
		$this->revoke_all();
		$this->forum->posts[$row['post_id']]['post_time'] = 1;
		$this->config['edit_time'] = 1;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('edit'));
		$this->assertSame('FPB_EDIT_REASON', $this->calls('edit')[0]['data']['post_edit_reason']);
		$this->assertStringContainsString('Changed', $this->first_post()['post_text']);
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$this->assertCount(0, $this->state_logs());
	}

	public function test_changed_feed_user_updates_without_moderator_permission()
	{
		$row = $this->publish();
		$this->settings(array('user_id' => 4));
		$this->revoke_all();
		$this->run_feed(array($this->item('a', 'Changed')));
		$edit = $this->calls('edit')[0];
		$this->assertSame(4, $edit['user_id']);
		$this->assertSame(3, $edit['data']['poster_id']);
		$this->assertSame(4, $edit['data']['post_edit_user']);
		$this->assertSame(3, $this->forum->posts[$row['post_id']]['poster_id']);
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	public function test_locked_topic_or_forum_is_still_updated()
	{
		$row = $this->publish();
		$this->revoke_all();
		$this->forum->topics[$row['topic_id']]['topic_status'] = ITEM_LOCKED;
		$this->forum->forums[5]['forum_status'] = ITEM_LOCKED;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('edit'));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	public function test_unapproved_topic_is_updated_and_stays_unapproved()
	{
		$this->forum->approved = false;
		$this->publish();
		$this->revoke_all();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('edit'));
		$this->assertSame(ITEM_UNAPPROVED, $this->first_post()['post_visibility']);
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	public function test_post_locked_against_editing_is_never_changed()
	{
		$row = $this->publish();
		$this->forum->posts[$row['post_id']]['post_edit_locked'] = 1;
		$this->grant(array('m_edit'), array(5));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_BLOCKED, item_tracker::REASON_POST_EDIT_LOCKED);
		$this->assertCount(0, $this->calls('edit'));
		$this->forum->posts[$row['post_id']]['post_edit_locked'] = 0;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('edit'));
	}

	public function test_forum_that_no_longer_accepts_posts_blocks_the_update()
	{
		$row = $this->publish();
		// The topic was moved to a forum that has since become a link forum.
		$this->forum->topics[$row['topic_id']]['forum_id'] = 6;
		$this->forum->forums[6]['forum_type'] = FORUM_LINK;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_BLOCKED, item_tracker::REASON_FORUM_NOT_POSTABLE);
		$this->assertCount(0, $this->calls('edit'));
		$this->assertSame($row['content_hash'], $this->row()['content_hash']);
	}

	public function test_guard_mismatch_detaches_regardless_of_permissions()
	{
		$row = $this->publish();
		$this->grant(array('m_edit'), array(5));
		$this->forum->posts[$row['post_id']]['post_text'] = '<r>Moderated</r>';
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_DETACHED, item_tracker::REASON_MANUAL_EDIT);
		$this->assertCount(0, $this->calls('edit'));
	}

	// --- Deletion and structure -----------------------------------------------------------

	public function test_soft_deleted_topic_is_never_restored_and_resumes_after_restore()
	{
		$row = $this->publish();
		$this->forum->topics[$row['topic_id']]['topic_visibility'] = ITEM_DELETED;
		$this->forum->posts[$row['post_id']]['post_visibility'] = ITEM_DELETED;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_BLOCKED, item_tracker::REASON_SOFT_DELETED);
		$this->assertCount(0, $this->calls('edit'));
		$this->assertCount(1, $this->topics());
		$this->assertSame(ITEM_DELETED, $this->forum->topics[$row['topic_id']]['topic_visibility']);
		$this->forum->topics[$row['topic_id']]['topic_visibility'] = ITEM_APPROVED;
		$this->forum->posts[$row['post_id']]['post_visibility'] = ITEM_APPROVED;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('edit'));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	public function test_soft_deleted_first_post_with_replies_is_blocked_not_detached()
	{
		$row = $this->publish();
		$reply = $this->forum->user_reply($row['topic_id'], 'Comment');
		$this->forum->posts[$row['post_id']]['post_visibility'] = ITEM_DELETED;
		// phpBB syncs the first approved post as topic starter.
		$this->forum->topics[$row['topic_id']]['topic_first_post_id'] = $reply;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_BLOCKED, item_tracker::REASON_SOFT_DELETED);
	}

	public function test_hard_deleted_topic_without_recreation()
	{
		$row = $this->publish();
		unset($this->forum->topics[$row['topic_id']], $this->forum->posts[$row['post_id']]);
		$this->run_feed(array($this->item('a')));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_GONE, item_tracker::REASON_HARD_DELETED);
		$gone = $this->row();
		$this->assertNotSame($row['content_hash'], $gone['content_hash']);
		$this->assertSame($gone['content_hash'], $gone['notified_hash']);
		$this->assertCount(0, $this->topics());
		$this->assertCount(1, $this->state_logs());
		// Enabling recreation later reacts only to newer changes.
		$this->settings(array('recreate_deleted' => 1));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(0, $this->topics());
		$this->run_feed(array($this->item('a', 'Changed twice')));
		$this->assertCount(1, $this->topics());
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	public function test_hard_deleted_topic_is_recreated_only_after_a_change()
	{
		$this->settings(array('recreate_deleted' => 1, 'update_reply' => 1));
		$row = $this->publish();
		unset($this->forum->topics[$row['topic_id']], $this->forum->posts[$row['post_id']]);
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
		$this->assertCount(0, $this->topics());
		$this->assertSame(1, $this->run_feed(array($this->item('a', 'Changed'))));
		$this->assertCount(1, $this->topics());
		$new = $this->row();
		$this->assertNotSame($row['topic_id'], $new['topic_id']);
		$this->assertSame(reset($this->forum->topics)['topic_first_post_id'], $new['post_id']);
		$this->assertSame($new['content_hash'], $new['notified_hash']);
		$this->assertCount(0, $this->calls('reply'));
		$this->assertCount(2, $this->calls('post'));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->topics());
	}

	public function test_first_post_removed_while_topic_remains_detaches()
	{
		$row = $this->publish();
		$reply = $this->forum->user_reply($row['topic_id'], 'Comment');
		unset($this->forum->posts[$row['post_id']]);
		$this->forum->topics[$row['topic_id']]['topic_first_post_id'] = $reply;
		$this->settings(array('recreate_deleted' => 1));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_DETACHED, item_tracker::REASON_FIRST_POST_REMOVED);
		$this->assertCount(1, $this->topics());
		$this->assertCount(0, $this->calls('edit'));
	}

	public function test_merged_topic_detaches()
	{
		$row = $this->publish();
		$this->forum->posts[$row['post_id']]['topic_id'] = 99;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_DETACHED, item_tracker::REASON_STRUCTURE);
	}

	public function test_other_topic_merged_in_with_older_post_detaches()
	{
		$row = $this->publish();
		$this->forum->topics[$row['topic_id']]['topic_first_post_id'] = 0;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_DETACHED, item_tracker::REASON_STRUCTURE);
		$this->assertCount(0, $this->calls('edit'));
	}

	// --- Failures -------------------------------------------------------------------------

	public function test_failed_mapping_update_rolls_back_the_edit_and_retries_next_run()
	{
		$row = $this->publish();
		$original = $this->forum->posts[$row['post_id']];
		$this->tracker->fail_update = true;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertSame($original, $this->forum->posts[$row['post_id']]);
		$this->assertSame($row, $this->row());
		$this->tracker->fail_update = false;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertStringContainsString('Changed', $this->first_post()['post_text']);
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	public function test_failed_edit_is_retried_every_run_and_logged_once()
	{
		$row = $this->publish();
		$this->forum->fail_always['edit'] = true;
		for ($run = 0; $run < 3; $run++)
		{
			$this->run_feed(array($this->item('a', 'Changed')));
		}
		$this->assertCount(3, $this->calls('edit'));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_EDIT_ERROR);
		$this->assertSame($row['content_hash'], $this->row()['content_hash']);
		$this->assertCount(1, $this->state_logs());
		$this->forum->fail_always['edit'] = false;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$this->assertStringContainsString('Changed', $this->first_post()['post_text']);
	}

	public function test_failed_update_does_not_block_new_items()
	{
		$this->publish();
		$this->forum->fail_always['edit'] = true;
		$this->assertSame(1, $this->run_feed(array($this->item('b'), $this->item('a', 'Changed'))));
		$this->assertCount(2, $this->topics());
	}

	public function test_edit_reported_but_not_stored_stays_pending()
	{
		$row = $this->publish();
		$this->forum->lose['edit'] = true;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_EDIT_ERROR);
		$this->assertSame($row['content_hash'], $this->row()['content_hash']);
		$this->assertSame(0, $this->row()['pending_op']);
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertStringContainsString('Changed', $this->first_post()['post_text']);
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	public function test_interrupted_edit_is_recovered_without_detaching()
	{
		$row = $this->publish();
		// The edit is committed, then recording it fails (as if the process died there).
		$this->tracker->fail_on = function ($fields) { return isset($fields['guard_hash']); };
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertStringContainsString('Changed', $this->first_post()['post_text']);
		$this->assertSame(item_tracker::PENDING_EDIT, $this->row()['pending_op']);
		$this->tracker->fail_on = null;
		$this->run_feed(array($this->item('a', 'Changed')));
		$after = $this->row();
		$this->assertSame(0, $after['pending_op']);
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$this->assertNotSame($row['content_hash'], $after['content_hash']);
		// Recovered from the database: not edited a second time, and later edits are not mistaken for manual ones.
		$this->assertCount(1, $this->calls('edit'));
		$this->run_feed(array($this->item('a', 'Changed again')));
		$this->assertCount(2, $this->calls('edit'));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

	public function test_edit_interrupted_before_submitting_is_retried()
	{
		$row = $this->publish();
		$this->tracker->rows[$row['tracking_id']]['pending_op'] = item_tracker::PENDING_EDIT;
		$this->tracker->rows[$row['tracking_id']]['pending_checksum'] = md5('never written');
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('edit'));
		$this->assertStringContainsString('Changed', $this->first_post()['post_text']);
		$this->assertSame(0, $this->row()['pending_op']);
	}

	public function test_error_after_edit_was_committed_is_recorded_as_success()
	{
		$this->publish();
		$this->forum->fail_after['edit'] = new \RuntimeException('Search index failed');
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('edit'));
	}

	public function test_open_phpbb_transaction_of_a_failed_edit_is_rolled_back()
	{
		$row = $this->publish();
		$this->forum->fail_open['edit'] = new \RuntimeException('Failed inside submit_post');
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertSame(1, $this->rollbacks);
		$this->assertSame($row['content_hash'], $this->row()['content_hash']);
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertStringContainsString('Changed', $this->first_post()['post_text']);
	}

	public function test_interrupted_recreation_is_recovered_without_a_duplicate()
	{
		$this->settings(array('recreate_deleted' => 1));
		$row = $this->publish();
		unset($this->forum->topics[$row['topic_id']], $this->forum->posts[$row['post_id']]);
		$this->tracker->fail_on = function ($fields) { return isset($fields['topic_id']) && $fields['topic_id'] > 0; };
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->topics());
		$this->assertSame(item_tracker::PENDING_RECREATE, $this->row()['pending_op']);
		$this->tracker->fail_on = null;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->topics());
		$this->assertSame(reset($this->forum->topics)['topic_id'], $this->row()['topic_id']);
		$this->assert_state(item_tracker::STATE_ACTIVE, item_tracker::REASON_NONE);
	}

}
