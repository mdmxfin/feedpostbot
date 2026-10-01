<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

require_once __DIR__ . '/fixtures/tracking.php';

use ger\feedpostbot\classes\item_tracker;

class reply_test extends fixtures\tracking_test_case
{
	public function setUp(): void
	{
		parent::setUp();
		$this->settings(array('update_reply' => 1));
	}

	protected function publish()
	{
		$this->assertSame(1, $this->run_feed(array($this->item('a'))));
		return $this->row();
	}

	protected function owed()
	{
		$row = $this->row();
		return $row['notified_hash'] !== $row['content_hash'];
	}

	/** Let a delayed reply become due */
	protected function make_due()
	{
		foreach ($this->tracker->rows as $id => $row)
		{
			$this->tracker->rows[$id]['reply_next_retry'] = 0;
		}
	}

	protected function reply_posts($topic_id)
	{
		return array_values(array_filter($this->forum->topic_posts($topic_id), function ($post) {
			return $post['poster_id'] === 3 && strpos($post['post_text'], 'FPB_ITEM_UPDATED_REPLY') !== false;
		}));
	}

	public function test_update_reply_is_posted_once_after_the_edit()
	{
		$row = $this->publish();
		$this->run_feed(array($this->item('a', 'Changed')));
		$replies = $this->calls('reply');
		$this->assertCount(1, $replies);
		$this->assertSame(3, $replies[0]['user_id']);
		$this->assertSame('Re: Title a', $replies[0]['subject']);
		$this->assertSame($row['topic_id'], $replies[0]['data']['topic_id']);
		$after = $this->row();
		$this->assertStringContainsString('FPB_ITEM_UPDATED_REPLY|' . $this->absolute_date($after['updated_time']), $replies[0]['data']['message']);
		$this->assertFalse($this->owed());
		$this->assertSame(0, $after['reply_retry_count']);
		$topic = $this->forum->topics[$row['topic_id']];
		$this->assertSame($this->reply_posts($row['topic_id'])[0]['post_id'], $topic['topic_last_post_id']);
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('reply'));
		// The bot's own reply does not affect the guard of its first post.
		$this->run_feed(array($this->item('a', 'Changed again')));
		$this->assertCount(2, $this->calls('edit'));
		$this->assertCount(2, $this->calls('reply'));
		$this->assertSame(item_tracker::STATE_ACTIVE, $this->row()['item_state']);
	}

	public function test_reply_date_is_absolute_even_with_a_relative_board_date_format()
	{
		$row = $this->publish();
		$this->run_feed(array($this->item('a', 'Changed')));
		$updated = $this->row()['updated_time'];
		// The same formatter renders a fresh time relatively unless absolute output is forced.
		$this->assertSame('less than a minute ago', $this->user->format_date($updated));
		$text = $this->reply_posts($row['topic_id'])[0]['post_text'];
		$this->assertSame('<r>FPB_ITEM_UPDATED_REPLY|' . $this->absolute_date($updated) . '</r>', $text);
		$this->assertSame(1, preg_match('/\|\d{1,2}\.\d{1,2}\.\d{4}, klo \d{1,2}\.\d{2}</', $text));
		$this->assertStringNotContainsString('ago', $text);
		$this->assertStringNotContainsString('Today', $text);
	}

	public function test_reply_date_of_an_older_update_stays_absolute()
	{
		$row = $this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		// The update happened earlier today; the reply is posted later.
		$updated = time() - 1800;
		$this->tracker->rows[$this->row()['tracking_id']]['updated_time'] = $updated;
		$this->assertSame('30 minutes ago', $this->user->format_date($updated));
		$this->release_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$text = $this->reply_posts($row['topic_id'])[0]['post_text'];
		$this->assertStringContainsString($this->absolute_date($updated), $text);
		$this->assertStringNotContainsString('ago', $text);
	}

	public function test_reply_only_run_counts_no_update()
	{
		$row = $this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertSame(1, $this->driver->get_run_result()['updated']);
		$this->release_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$result = $this->driver->get_run_result();
		$this->assertSame(array(0, 0), array($result['created'], $result['updated']));
	}

	public function test_reply_is_appended_after_user_comments()
	{
		$row = $this->publish();
		$comments = array();
		foreach (array('One', 'Two', 'Three') as $text)
		{
			$comments[] = $this->forum->user_reply($row['topic_id'], $text);
		}
		$before = $this->forum->topic_posts($row['topic_id']);
		$this->run_feed(array($this->item('a', 'Changed')));
		$posts = $this->forum->topic_posts($row['topic_id']);
		$this->assertCount(5, $posts);
		foreach ($comments as $post_id)
		{
			$this->assertSame($before[$post_id], $posts[$post_id]);
		}
		$ids = array_keys($posts);
		$this->assertSame(end($ids), $this->reply_posts($row['topic_id'])[0]['post_id']);
		$this->assertGreaterThan(end($comments), end($ids));
		$this->assertSame(item_tracker::STATE_ACTIVE, $this->row()['item_state']);
	}

	public function test_user_reply_before_delayed_update_reply_does_not_detach()
	{
		$row = $this->publish();
		$this->forum->fail['reply'] = new \RuntimeException('Temporary failure');
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertTrue($this->owed());
		$this->assertGreaterThan(time(), $this->row()['reply_next_retry']);
		$this->forum->user_reply($row['topic_id'], 'Interesting!');
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('reply'));
		$this->make_due();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertFalse($this->owed());
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertSame(item_tracker::STATE_ACTIVE, $this->row()['item_state']);
	}

	public function test_failing_reply_stays_owed_indefinitely_with_capped_backoff()
	{
		$this->publish();
		$this->forum->fail_always['reply'] = true;
		$this->run_feed(array($this->item('a', 'Changed')));
		for ($run = 0; $run < 9; $run++)
		{
			$this->make_due();
			$this->run_feed(array($this->item('a', 'Changed')));
		}
		$row = $this->row();
		$this->assertTrue($this->owed());
		$this->assertSame(10, $row['reply_retry_count']);
		$this->assertSame(item_tracker::REASON_REPLY_ERROR, $row['reply_reason']);
		$this->assertLessThanOrEqual(time() + 86400, $row['reply_next_retry']);
		$this->assertGreaterThan(time() + 86000, $row['reply_next_retry']);
		// Logged on the 1st, 2nd, 4th and 8th consecutive failure.
		$this->assertCount(4, array_filter($this->state_logs(), function ($log) { return strpos($log['data'][3], 'FPB_REASON_21') === 0; }));
		$this->forum->fail_always['reply'] = false;
		$this->make_due();
		$this->run_feed(array($this->item('a', 'Changed')));
		$row = $this->row();
		$this->assertFalse($this->owed());
		$this->assertSame(0, $row['reply_retry_count']);
		$this->assertSame(0, $row['reply_next_retry']);
		$this->assertSame(item_tracker::REASON_NONE, $row['reply_reason']);
	}

	public function test_first_failures_back_off_exponentially()
	{
		$this->publish();
		$this->forum->fail_always['reply'] = true;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertEqualsWithDelta(time() + 300, $this->row()['reply_next_retry'], 2);
		$this->make_due();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertEqualsWithDelta(time() + 600, $this->row()['reply_next_retry'], 2);
		// Not due yet: no attempt.
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(2, $this->calls('reply'));
	}

	public function test_reply_is_posted_without_forum_permissions()
	{
		$row = $this->publish();
		$this->revoke_all();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('edit'));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
	}

	public function test_locked_topic_or_forum_still_gets_its_update_reply()
	{
		$row = $this->publish();
		$this->revoke_all();
		$this->forum->topics[$row['topic_id']]['topic_status'] = ITEM_LOCKED;
		$this->forum->forums[5]['forum_status'] = ITEM_LOCKED;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
	}

	public function test_unapproved_topic_keeps_reply_owed_without_backoff_until_approved()
	{
		$row = $this->publish();
		$this->forum->topics[$row['topic_id']]['topic_visibility'] = ITEM_UNAPPROVED;
		$this->forum->posts[$row['post_id']]['post_visibility'] = ITEM_UNAPPROVED;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('edit'));
		$after = $this->row();
		$this->assertTrue($this->owed());
		$this->assertSame(item_tracker::REASON_UNAPPROVED, $after['reply_reason']);
		$this->assertSame(0, $after['reply_retry_count']);
		$this->assertSame(0, $after['reply_next_retry']);
		$this->assertCount(1, $this->state_logs());
		$this->assertCount(0, $this->calls('reply'));
		$this->forum->topics[$row['topic_id']]['topic_visibility'] = ITEM_APPROVED;
		$this->forum->posts[$row['post_id']]['post_visibility'] = ITEM_APPROVED;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
	}

	public function test_newer_update_is_edited_immediately_despite_reply_backoff_and_announced_once()
	{
		// v1 published
		$row = $this->publish();
		// v2 edit succeeds, its reply fails and enters a long backoff
		$this->forum->fail['reply'] = new \RuntimeException('Temporary failure');
		$this->run_feed(array($this->item('a', 'Version 2')));
		$this->assertCount(1, $this->calls('edit'));
		$this->assertTrue($this->owed());
		$this->tracker->rows[$row['tracking_id']]['reply_next_retry'] = time() + 80000;
		$this->tracker->rows[$row['tracking_id']]['reply_retry_count'] = 7;
		$v2 = $this->row();
		// v3 arrives before the retry time: the first post is edited at once
		$this->run_feed(array($this->item('a', 'Version 3')));
		$this->assertCount(2, $this->calls('edit'));
		$this->assertStringContainsString('Version 3', $this->forum->posts[$row['post_id']]['post_text']);
		$this->assertCount(1, $this->topics());
		// ... and one reply announces the latest update
		$replies = $this->reply_posts($row['topic_id']);
		$this->assertCount(1, $replies);
		$v3 = $this->row();
		$this->assertSame($v3['content_hash'], $v3['notified_hash']);
		$this->assertNotSame($v2['content_hash'], $v3['content_hash']);
		$this->assertStringContainsString($this->absolute_date($v3['updated_time']), $replies[0]['post_text']);
		$this->make_due();
		$this->run_feed(array($this->item('a', 'Version 3')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
	}

	public function test_newer_version_does_not_inherit_the_retry_history()
	{
		$row = $this->publish();
		$this->forum->fail_always['reply'] = true;
		$this->run_feed(array($this->item('a', 'Version 2')));
		for ($run = 0; $run < 8; $run++)
		{
			$this->make_due();
			$this->run_feed(array($this->item('a', 'Version 2')));
		}
		$this->assertSame(9, $this->row()['reply_retry_count']);
		// 300 s doubled eight times.
		$this->assertEqualsWithDelta(time() + 76800, $this->row()['reply_next_retry'], 2);
		// v3 is published while the announcement still fails: it starts a fresh history.
		$this->run_feed(array($this->item('a', 'Version 3')));
		$v3 = $this->row();
		$this->assertStringContainsString('Version 3', $this->forum->posts[$row['post_id']]['post_text']);
		$this->assertSame(1, $v3['reply_retry_count']);
		$this->assertEqualsWithDelta(time() + 300, $v3['reply_next_retry'], 2);
		$this->assertTrue($this->owed());
		$this->forum->fail_always['reply'] = false;
		$this->make_due();
		$this->run_feed(array($this->item('a', 'Version 3')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
	}

	public function test_owed_reply_is_posted_while_a_newer_edit_keeps_failing()
	{
		$row = $this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Version 2')));
		$this->assertTrue($this->owed());
		$this->forum->fail_always['edit'] = true;
		$this->release_replies();
		$this->run_feed(array($this->item('a', 'Version 3')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
		$this->assertSame(item_tracker::REASON_EDIT_ERROR, $this->row()['state_reason']);
		$this->forum->fail_always['edit'] = false;
		$this->run_feed(array($this->item('a', 'Version 3')));
		$this->assertCount(2, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
	}

	public function test_updates_switched_off_freeze_owed_reply()
	{
		$row = $this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$owed = $this->row();
		$this->settings(array('update_existing' => 0));
		$this->release_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(0, $this->calls('reply'));
		$this->assertSame($owed, $this->row());
		$this->settings(array('update_existing' => 1));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
	}

	public function test_switching_update_replies_off_cancels_owed_replies()
	{
		$row = $this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertTrue($this->owed());
		$original = $this->driver->init_current_state();
		$proposed = $original;
		$proposed[0]['update_reply'] = 0;
		$this->assertTrue($this->driver->save_sources($proposed, $original));
		$this->assertFalse($this->owed());
		$this->release_replies();
		$original = $this->driver->init_current_state();
		$proposed = $original;
		$proposed[0]['update_reply'] = 1;
		$this->assertTrue($this->driver->save_sources($proposed, $original));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(0, $this->reply_posts($row['topic_id']));
	}

	public function test_switching_replies_off_cancels_even_while_updates_are_off()
	{
		$this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->settings(array('update_existing' => 0));
		$original = $this->driver->init_current_state();
		$proposed = $original;
		$proposed[0]['update_reply'] = 0;
		$this->assertTrue($this->driver->save_sources($proposed, $original));
		$this->assertFalse($this->owed());
	}

	public function test_saving_settings_of_a_feed_without_identity_yet()
	{
		unset($this->saved[0]['source_uid']);
		$this->saved[0]['update_reply'] = 1;
		$original = $this->driver->init_current_state();
		$proposed = $original;
		$proposed[0]['update_reply'] = 0;
		$this->assertTrue($this->driver->save_sources($proposed, $original));
		$this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $this->saved[0]['source_uid']));
	}

	public function test_listener_cannot_turn_off_the_owed_reply_through_event_variables()
	{
		$row = $this->publish();
		$this->listeners['ger.feedpostbot.update_post_before'] = function ($data) {
			$data['source']['update_reply'] = 0;
			return $data;
		};
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertTrue($this->owed());
	}

	public function test_worker_waives_owed_replies_while_replies_are_off()
	{
		$this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertTrue($this->owed());
		$this->settings(array('update_reply' => 0));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertFalse($this->owed());
	}

	public function test_edit_without_update_replies_owes_nothing()
	{
		$this->settings(array('update_reply' => 0));
		$this->publish();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertFalse($this->owed());
		$this->settings(array('update_reply' => 1));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(0, $this->calls('reply'));
	}

	public function test_vetoed_update_keeps_the_reply_owed_for_the_published_version()
	{
		$row = $this->publish();
		// v2 is published, its reply cannot be posted yet.
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Version 2')));
		$v2 = $this->row();
		$this->assertTrue($this->owed());
		// v3 arrives and a listener vetoes its update.
		$vetoes = 0;
		$this->listeners['ger.feedpostbot.update_post_before'] = function ($data) use (&$vetoes) {
			$vetoes++;
			$data['do_update'] = false;
			return $data;
		};
		$this->run_feed(array($this->item('a', 'Version 3')));
		$after = $this->row();
		$this->assertSame($v2['content_hash'], $after['content_hash']);
		$this->assertSame($v2['notified_hash'], $after['notified_hash']);
		$this->assertNotSame($v2['source_hash'], $after['source_hash']);
		$this->assertTrue($this->owed());
		$this->assertStringContainsString('Version 2', $this->forum->posts[$row['post_id']]['post_text']);
		// v3 is not retried, and the v2 reply is still posted once possible.
		$this->release_replies();
		$this->run_feed(array($this->item('a', 'Version 3')));
		$this->assertSame(1, $vetoes);
		$replies = $this->reply_posts($row['topic_id']);
		$this->assertCount(1, $replies);
		$this->assertStringContainsString($this->absolute_date($v2['updated_time']), $replies[0]['post_text']);
		$this->assertFalse($this->owed());
	}

	public function test_listener_can_cancel_or_customize_the_reply()
	{
		$row = $this->publish();
		$this->listeners['ger.feedpostbot.update_reply_before'] = function ($data) {
			$data['do_reply'] = false;
			return $data;
		};
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(0, $this->calls('reply'));
		$this->assertFalse($this->owed());
		$this->listeners['ger.feedpostbot.update_reply_before'] = function ($data) {
			$data['reply_text'] = 'Custom notice';
			$data['subject'] = 'Updated';
			return $data;
		};
		$this->run_feed(array($this->item('a', 'Changed again')));
		$reply = $this->calls('reply')[0];
		$this->assertSame('Updated', $reply['subject']);
		$this->assertSame('<r>Custom notice</r>', $reply['data']['message']);
	}

	public function test_owed_reply_is_posted_when_the_feed_is_unavailable()
	{
		$row = $this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->release_replies();
		$this->run_feed(array());
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
	}

	public function test_owed_reply_for_deleted_topic_is_cancelled()
	{
		$row = $this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		unset($this->forum->topics[$row['topic_id']], $this->forum->posts[$row['post_id']]);
		$this->release_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertFalse($this->owed());
		$this->assertSame(item_tracker::STATE_GONE, $this->row()['item_state']);
		$this->assertCount(0, $this->calls('reply'));
	}

	public function test_owed_reply_waits_while_topic_is_soft_deleted()
	{
		$row = $this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->release_replies();
		$this->forum->topics[$row['topic_id']]['topic_visibility'] = ITEM_DELETED;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertTrue($this->owed());
		$this->assertCount(0, $this->calls('reply'));
		$this->forum->topics[$row['topic_id']]['topic_visibility'] = ITEM_APPROVED;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
	}

	/** Edit a published item while replies are blocked, leaving a reply owed */
	protected function owe_reply()
	{
		$row = $this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertTrue($this->owed());
		$this->release_replies();
		return $row;
	}

	public function test_reply_in_a_fresh_run_loads_the_posting_functions()
	{
		$row = $this->owe_reply();
		$this->driver->loader_calls = 0;
		// A new cron run whose feed is unavailable: only the owed reply is posted.
		$this->run_feed(array());
		$this->assertGreaterThan(0, $this->driver->loader_calls);
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
	}

	public function test_unavailable_posting_functions_keep_the_reply_owed()
	{
		$row = $this->owe_reply();
		$before = $this->row();
		$this->driver->functions_available = false;
		$this->run_feed(array());
		$this->assertCount(0, $this->calls('reply'));
		$after = $this->row();
		$this->assertTrue($this->owed());
		$this->assertSame($before['notified_hash'], $after['notified_hash']);
		$this->assertSame(0, $after['pending_op']);
		$this->driver->functions_available = true;
		$this->run_feed(array());
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
	}

	public function test_reply_reported_but_not_stored_stays_owed()
	{
		// Models the reviewed markread() case: phpBB reports success but the reply is gone.
		$row = $this->owe_reply();
		$this->forum->lose['reply'] = true;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertTrue($this->owed());
		$this->assertSame(0, $this->row()['pending_op']);
		$this->assertSame(1, $this->row()['reply_retry_count']);
		$this->assertCount(0, $this->reply_posts($row['topic_id']));
		$this->make_due();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
	}

	public function test_interrupted_reply_is_recovered_without_a_duplicate()
	{
		$row = $this->owe_reply();
		// The reply is committed, then settling the debt fails (as if the process died there).
		$this->tracker->fail_on = function ($fields) { return isset($fields['notified_hash']); };
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertTrue($this->owed());
		$this->assertSame(item_tracker::PENDING_REPLY, $this->row()['pending_op']);
		$this->tracker->fail_on = null;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
		$this->assertSame(0, $this->row()['pending_op']);
	}

	public function test_reply_interrupted_before_posting_stays_owed()
	{
		$row = $this->owe_reply();
		// A process recorded the attempt and died before submit_post(): the reply must not be lost.
		$this->tracker->rows[$row['tracking_id']]['pending_op'] = item_tracker::PENDING_REPLY;
		$this->tracker->rows[$row['tracking_id']]['pending_checksum'] = md5('never posted');
		$this->tracker->rows[$row['tracking_id']]['pending_content_hash'] = $this->row()['content_hash'];
		$this->tracker->rows[$row['tracking_id']]['pending_after_post_id'] = $this->forum->max_post_id();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
	}

	public function test_error_after_reply_was_committed_settles_the_debt()
	{
		$row = $this->owe_reply();
		$this->forum->fail_after['reply'] = new \RuntimeException('Notification failed');
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->make_due();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
	}

	public function test_open_phpbb_transaction_of_a_failed_reply_is_rolled_back_and_stays_owed()
	{
		$row = $this->owe_reply();
		$this->forum->fail_open['reply'] = new \RuntimeException('Failed inside submit_post');
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertSame(1, $this->rollbacks);
		$this->assertTrue($this->owed());
		$this->make_due();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
	}

	public function test_debt_update_failing_before_posting_posts_nothing()
	{
		$row = $this->owe_reply();
		$this->tracker->fail_update = true;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(0, $this->calls('reply'));
		$this->tracker->fail_update = false;
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->reply_posts($row['topic_id']));
		$this->assertFalse($this->owed());
	}

	public function test_manually_edited_first_post_stops_owed_reply()
	{
		$row = $this->publish();
		$this->hold_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->forum->posts[$row['post_id']]['post_subject'] = 'Moderated';
		$this->release_replies();
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertSame(item_tracker::STATE_DETACHED, $this->row()['item_state']);
		$this->assertCount(0, $this->calls('reply'));
	}
}
