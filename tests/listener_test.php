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
 * phpBB extensions may rewrite the stored message and checksum while submit_post() runs, through
 * core.modify_submit_post_data (data copied back to the caller) or core.submit_post_modify_sql_data
 * (only the written row). Feed post bot must still recognise its own successful posts.
 */
class listener_test extends fixtures\tracking_test_case
{
	public function surfaces()
	{
		return array('submit data' => array('data'), 'sql data' => array('sql'));
	}

	/** Install a listener rewriting the stored message of the given posting modes */
	protected function rewrite($surface, array $modes)
	{
		if ($surface === 'data')
		{
			$this->forum->modify_submit_post_data = function ($mode, array &$data) use ($modes) {
				if (in_array($mode, $modes, true))
				{
					$data['message'] = str_replace('</r>', ' [listener]</r>', $data['message']);
					$data['message_md5'] = md5($data['message']);
				}
			};
		}
		else
		{
			$this->forum->modify_sql_data = function ($mode, array &$post) use ($modes) {
				if (in_array($mode, $modes, true))
				{
					$post['post_text'] = str_replace('</r>', ' [listener]</r>', $post['post_text']);
					$post['post_checksum'] = md5($post['post_text']);
				}
			};
		}
	}

	protected function created()
	{
		return $this->driver->get_run_result()['created'];
	}

	protected function guard_of_stored_post(array $row)
	{
		$method = new \ReflectionMethod(\ger\feedpostbot\classes\driver::class, 'guard_hash');
		$method->setAccessible(true);
		return $method->invoke($this->driver, $this->forum->target($row['topic_id'], $row['post_id']));
	}

	protected function reply_posts($topic_id)
	{
		return array_values(array_filter($this->forum->topic_posts($topic_id), function ($post) {
			return $post['poster_id'] === 3 && strpos($post['post_text'], 'FPB_ITEM_UPDATED_REPLY') !== false;
		}));
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_customized_creation_is_tracked_once($surface)
	{
		$this->rewrite($surface, array('post'));
		$created = array();
		foreach (array('Body', 'Body', 'Body', 'Changed', 'Changed again') as $body)
		{
			$this->run_feed(array($this->item('a', $body)));
			$created[] = $this->created();
		}
		$this->assertCount(1, $this->topics(), 'topics after 5 runs: ' . count($this->topics()));
		$this->assertSame(array(1, 0, 0, 0, 0), $created, 'created per run');
		$this->assertCount(1, $this->calls('post'));
		$row = $this->row();
		$this->assertSame(reset($this->forum->topics)['topic_id'], $row['topic_id']);
		$this->assertSame(item_tracker::STATE_DETACHED, $row['item_state']);
		$this->assertSame(item_tracker::REASON_CUSTOMIZED_AT_CREATION, $row['state_reason']);
		$this->assertSame(0, $row['pending_op']);
		$this->assertSame($row['content_hash'], $row['notified_hash']);
		$this->assertSame($this->guard_of_stored_post($row), $row['guard_hash']);
		$this->assertCount(0, $this->calls('edit'));
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_customized_recreation_happens_once($surface)
	{
		$this->settings(array('recreate_deleted' => 1));
		$this->run_feed(array($this->item('a')));
		$row = $this->row();
		unset($this->forum->topics[$row['topic_id']], $this->forum->posts[$row['post_id']]);
		$this->rewrite($surface, array('post'));
		$created = array();
		foreach (array('Changed', 'Changed', 'Changed again', 'And again') as $body)
		{
			$this->run_feed(array($this->item('a', $body)));
			$created[] = $this->created();
		}
		$this->assertCount(1, $this->topics(), 'replacement topics after 4 runs: ' . count($this->topics()));
		$this->assertSame(array(1, 0, 0, 0), $created, 'created per run');
		$this->assertCount(2, $this->calls('post'));
		$recreated = $this->row();
		$this->assertNotSame($row['topic_id'], $recreated['topic_id']);
		$this->assertSame(item_tracker::STATE_DETACHED, $recreated['item_state']);
		$this->assertSame(item_tracker::REASON_CUSTOMIZED_AT_CREATION, $recreated['state_reason']);
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_customized_update_reply_settles_once($surface)
	{
		$this->settings(array('update_reply' => 1));
		$this->run_feed(array($this->item('a')));
		$row = $this->row();
		$this->rewrite($surface, array('reply'));
		$this->run_feed(array($this->item('a', 'Changed')));
		for ($run = 0; $run < 3; $run++)
		{
			foreach ($this->tracker->rows as $id => $tracked)
			{
				$this->tracker->rows[$id]['reply_next_retry'] = 0;
			}
			$this->run_feed(array($this->item('a', 'Changed')));
		}
		$replies = $this->reply_posts($row['topic_id']);
		$this->assertCount(1, $replies, 'update replies after 4 runs: ' . count($replies));
		$this->assertStringContainsString('[listener]', $replies[0]['post_text']);
		$after = $this->row();
		$this->assertSame($after['content_hash'], $after['notified_hash']);
		$this->assertSame(0, $after['pending_op']);
		$this->assertSame(0, $after['reply_retry_count']);
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_customized_first_post_edit_is_recognized($surface)
	{
		$this->run_feed(array($this->item('a')));
		$row = $this->row();
		$this->rewrite($surface, array('edit'));
		$this->run_feed(array($this->item('a', 'Changed')));
		$after = $this->row();
		$post = $this->forum->posts[$row['post_id']];
		$this->assertStringContainsString('Changed', $post['post_text']);
		$this->assertStringContainsString('[listener]', $post['post_text']);
		$this->assertSame(item_tracker::STATE_ACTIVE, $after['item_state']);
		$this->assertSame(item_tracker::REASON_NONE, $after['state_reason'], 'state reason after the edit: ' . $after['state_reason']);
		$this->assertNotSame($row['content_hash'], $after['content_hash']);
		// The guard describes the stored post, so later runs do not mistake it for a manual edit.
		$this->assertSame($this->guard_of_stored_post($after), $after['guard_hash']);
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertSame(item_tracker::STATE_ACTIVE, $this->row()['item_state']);
		$this->run_feed(array($this->item('a', 'Changed again')));
		$this->assertCount(2, $this->calls('edit'));
		$this->assertSame(item_tracker::STATE_ACTIVE, $this->row()['item_state']);
		$this->assertSame(item_tracker::REASON_NONE, $this->row()['state_reason']);
		$this->assertStringContainsString('Changed again', $this->forum->posts[$row['post_id']]['post_text']);
		$this->assertStringContainsString('[listener]', $this->forum->posts[$row['post_id']]['post_text']);
	}

	// --- Same-process verification: an update reply ---------------------------------------

	/** Publish item a with update replies, then make the next reply be reported but not stored */
	protected function lose_next_reply(callable $after)
	{
		$this->settings(array('update_reply' => 1));
		$this->run_feed(array($this->item('a')));
		$row = $this->row();
		$this->forum->lose['reply'] = true;
		$this->forum->after_submit = function ($mode, array $data) use ($after, $row) {
			if ($mode === 'reply')
			{
				$after($row, (int) $data['post_id']);
			}
		};
		$this->run_feed(array($this->item('a', 'Changed')));
		return $row;
	}

	public function test_unrelated_user_reply_at_the_reported_id_does_not_settle_the_reply()
	{
		// After the recorded bound, a user's reply takes the id phpBB reported for the lost update reply.
		$this->lose_next_reply(function ($row, $reported) {
			$this->assertSame($reported, $this->forum->user_reply($row['topic_id'], 'User comment'));
		});
		$after = $this->row();
		$this->assertNotSame($after['content_hash'], $after['notified_hash']);
		$this->assertSame(1, $after['reply_retry_count']);
		$this->assertSame(0, $after['pending_op']);
	}

	public function test_newer_stray_post_by_the_feed_user_does_not_settle_the_reply()
	{
		// The reported id stays empty; the newest post of the topic is a post by the feed user, not the reply.
		$this->lose_next_reply(function ($row, $reported) {
			$this->forum->next_post++;
			$this->assertGreaterThan($reported, $this->forum->insert_post($row['topic_id'], 3, 'Posted by hand'));
		});
		$after = $this->row();
		$this->assertNotSame($after['content_hash'], $after['notified_hash']);
		$this->assertSame(1, $after['reply_retry_count']);
	}

	/** Call a protected verification helper as the feed user */
	protected function verify($method, array $arguments)
	{
		$this->user->data['user_id'] = 3;
		$reflection = new \ReflectionMethod(\ger\feedpostbot\classes\driver::class, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($this->driver, $arguments);
	}

	public function test_reply_verification_requires_a_newer_post_by_the_feed_user_in_the_topic()
	{
		$this->run_feed(array($this->item('a'), $this->item('b')));
		$rows = array_values($this->tracker->rows);
		$row = $rows[0] + array('pending_after_post_id' => 0);
		$other = $rows[1];
		$row['pending_after_post_id'] = $this->forum->max_post_id();
		$reply = $this->forum->insert_post($row['topic_id'], 3, 'Update reply');
		$this->assertTrue($this->verify('reply_posted', array($row, $reply)));
		// Not newer than the bound: the feed user's own first post of the topic.
		$this->assertFalse($this->verify('reply_posted', array($row, $row['post_id'])));
		// Another author.
		$this->assertFalse($this->verify('reply_posted', array($row, $this->forum->insert_post($row['topic_id'], 50, 'User comment'))));
		// Another topic.
		$this->assertFalse($this->verify('reply_posted', array($row, $this->forum->insert_post($other['topic_id'], 3, 'Elsewhere'))));
		// Missing post.
		$this->assertFalse($this->verify('reply_posted', array($row, 999)));
	}

	// --- Same-process verification: creation and recreation ---------------------------------

	/** A topic in forum 5 whose first post has the given author */
	protected function stored_topic($poster_id)
	{
		$topic_id = $this->forum->next_topic++;
		$post_id = $this->forum->next_post++;
		$this->forum->topics[$topic_id] = array('topic_id' => $topic_id, 'forum_id' => 5, 'topic_title' => 'T', 'topic_first_post_id' => $post_id,
			'topic_last_post_id' => $post_id, 'topic_posts_approved' => 1, 'topic_last_poster_id' => $poster_id, 'topic_last_post_time' => 0,
			'topic_attachment' => 0, 'topic_visibility' => ITEM_APPROVED, 'poll_start' => 0, 'poll_title' => '', 'topic_moved_id' => 0);
		$this->forum->posts[$post_id] = array('post_id' => $post_id, 'topic_id' => $topic_id, 'forum_id' => 5, 'poster_id' => $poster_id,
			'post_subject' => 'T', 'post_text' => '<r>x</r>', 'post_checksum' => 'stored', 'post_attachment' => 0, 'enable_bbcode' => 1,
			'enable_smilies' => 1, 'enable_magic_url' => 1, 'post_visibility' => ITEM_APPROVED);
		return array('topic_id' => $topic_id, 'post_id' => $post_id);
	}

	public function test_publication_verification_requires_the_new_first_post_by_the_feed_user()
	{
		$old = $this->stored_topic(3);
		// The checksum matches nothing, so only the reported ids can prove the topic.
		$row = array('pending_checksum' => 'recorded', 'pending_after_post_id' => $this->forum->max_post_id());
		$created = $this->stored_topic(3);
		$found = $this->verify('find_publication', array($row, $created));
		$this->assertSame($created, array('topic_id' => $found['topic_id'], 'post_id' => $found['post_id']));
		$this->assertFalse($found['stored_as_submitted']);
		// Not newer than the bound.
		$this->assertNull($this->verify('find_publication', array($row, $old)));
		// Another author.
		$this->assertNull($this->verify('find_publication', array($row, $this->stored_topic(50))));
		// Not the first post of the topic.
		$reply = $this->forum->insert_post($created['topic_id'], 3, 'Reply');
		$this->assertNull($this->verify('find_publication', array($row, array('topic_id' => $created['topic_id'], 'post_id' => $reply))));
		// The topic names the post as first post, but the post belongs to another topic.
		$elsewhere = $this->stored_topic(3);
		$this->forum->posts[$elsewhere['post_id']]['topic_id'] = $created['topic_id'];
		$this->assertNull($this->verify('find_publication', array($row, $elsewhere)));
		// Unknown ids.
		$this->assertNull($this->verify('find_publication', array($row, array('topic_id' => 999, 'post_id' => 999))));
	}

	// --- Same-process verification: first-post edit ------------------------------------------

	public function test_edit_verification_requires_one_new_edit_by_the_feed_user_after_the_start()
	{
		$this->run_feed(array($this->item('a')));
		$row = $this->row();
		$mark = array('count' => 0, 'time' => time());
		$edited = function (array $values) use ($row) {
			$target = $this->forum->target($row['topic_id'], $row['post_id']);
			$target['post'] = array_merge($target['post'], $values);
			return $target;
		};
		$this->assertTrue($this->verify('edit_recorded', array($edited(array('post_edit_count' => 1, 'post_edit_user' => 3, 'post_edit_time' => $mark['time'])), $mark)));
		// Edited by someone else.
		$this->assertFalse($this->verify('edit_recorded', array($edited(array('post_edit_count' => 1, 'post_edit_user' => 50, 'post_edit_time' => $mark['time'])), $mark)));
		// An edit from before this operation started.
		$this->assertFalse($this->verify('edit_recorded', array($edited(array('post_edit_count' => 1, 'post_edit_user' => 3, 'post_edit_time' => $mark['time'] - 1)), $mark)));
		// Not exactly one new edit.
		$this->assertFalse($this->verify('edit_recorded', array($edited(array('post_edit_count' => 0, 'post_edit_user' => 3, 'post_edit_time' => $mark['time'])), $mark)));
		$this->assertFalse($this->verify('edit_recorded', array($edited(array('post_edit_count' => 2, 'post_edit_user' => 3, 'post_edit_time' => $mark['time'])), $mark)));
	}

	public function test_manual_edit_after_a_customized_edit_still_detaches()
	{
		$this->run_feed(array($this->item('a')));
		$row = $this->row();
		$this->rewrite('sql', array('edit'));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertSame(item_tracker::STATE_ACTIVE, $this->row()['item_state']);
		$this->assertSame($this->guard_of_stored_post($this->row()), $this->row()['guard_hash']);
		// A moderator now edits the first post by hand.
		$this->forum->posts[$row['post_id']]['post_text'] = '<r>Edited by a moderator</r>';
		$this->run_feed(array($this->item('a', 'Changed again')));
		$this->assertSame(item_tracker::STATE_DETACHED, $this->row()['item_state']);
		$this->assertSame(item_tracker::REASON_MANUAL_EDIT, $this->row()['state_reason']);
		$this->assertCount(1, $this->calls('edit'));
		$this->assertSame('<r>Edited by a moderator</r>', $this->forum->posts[$row['post_id']]['post_text']);
	}
}
