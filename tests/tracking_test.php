<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

require_once __DIR__ . '/fixtures/tracking.php';

use ger\feedpostbot\classes\item_tracker;
use ger\feedpostbot\tests\fixtures\tracking_driver;

class tracking_test extends fixtures\tracking_test_case
{
	// --- Feed identity -------------------------------------------------------------------

	public function test_source_uid_is_128_random_bits()
	{
		$uid = tracking_driver::new_source_uid();
		$this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $uid));
		$this->assertNotSame($uid, tracking_driver::new_source_uid());
	}

	public function test_worker_persists_missing_identity_before_tracking()
	{
		$this->run_feed(array($this->item('a')));
		$uid = $this->saved[0]['source_uid'];
		$this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $uid));
		$this->assertSame($uid, $this->row()['source_uid']);
		$this->run_feed(array($this->item('a')));
		$this->assertSame($uid, $this->saved[0]['source_uid']);
	}

	public function test_identity_that_cannot_be_generated_disables_tracking_for_the_run()
	{
		tracking_driver::$uid_failure = true;
		$this->assertSame(1, $this->run_feed(array($this->item('a'))));
		$this->assertCount(0, $this->tracker->rows);
		$this->assertArrayNotHasKey('source_uid', $this->saved[0]);
		$this->assertCount(1, $this->topics());
	}

	public function test_settings_edit_keeps_identity_and_url_change_renews_it_and_drops_mappings()
	{
		$this->run_feed(array($this->item('a')));
		$uid = $this->saved[0]['source_uid'];
		$this->driver->init_current_state();
		$original = $this->driver->current_state;
		$proposed = $original;
		$proposed[0]['prefix'] = '[News]';
		$this->assertTrue($this->driver->save_sources($proposed, $original));
		$this->assertSame($uid, $this->saved[0]['source_uid']);
		$this->assertCount(1, $this->tracker->rows);

		$original = $this->driver->init_current_state();
		$proposed = $original;
		$proposed[0]['url'] = 'https://example.com/feed/';
		unset($proposed[0]['handled']);
		$this->assertTrue($this->driver->save_sources($proposed, $original));
		$this->assertNotSame($uid, $this->saved[0]['source_uid']);
		$this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $this->saved[0]['source_uid']));
		$this->assertCount(0, $this->tracker->rows);
	}

	public function test_stale_identity_in_the_form_is_not_kept_for_a_new_url()
	{
		$this->run_feed(array($this->item('a')));
		$uid = $this->saved[0]['source_uid'];
		$original = $this->driver->init_current_state();
		// The ACP copies the old row (including its identity) when the URL is edited.
		$proposed = array(0 => array_merge($original[0], array('url' => 'https://other.example.com/rss')));
		$this->assertTrue($this->driver->save_sources($proposed, $original));
		$this->assertNotSame($uid, $this->saved[0]['source_uid']);
	}

	public function test_deleted_feed_drops_mappings_and_reused_index_gets_new_identity()
	{
		$this->saved[1] = $this->source(array('url' => 'https://example.com/second'));
		$this->run_feed(array($this->item('a')));
		$this->assertCount(2, $this->tracker->rows);
		$second = $this->saved[1]['source_uid'];
		$original = $this->driver->init_current_state();
		$proposed = $original;
		unset($proposed[1]);
		$this->assertTrue($this->driver->save_sources($proposed, $original));
		$this->assertCount(1, $this->tracker->rows);
		$this->assertSame($this->saved[0]['source_uid'], $this->tracker->only()['source_uid']);

		$original = $this->driver->init_current_state();
		$proposed = $original;
		$proposed[1] = $this->source(array('url' => 'https://example.com/second'));
		unset($proposed[1]['source_uid']);
		$this->assertTrue($this->driver->save_sources($proposed, $original));
		$this->assertNotSame($second, $this->saved[1]['source_uid']);
		$this->assertNotSame($this->saved[0]['source_uid'], $this->saved[1]['source_uid']);
	}

	public function test_failed_settings_save_keeps_mappings()
	{
		$this->run_feed(array($this->item('a')));
		$original = $this->driver->init_current_state();
		$failing = $this->getMockBuilder('\phpbb\config\db_text')->disableOriginalConstructor()->getMock();
		$failing->method('get')->willReturnCallback(function () { return json_encode($this->saved); });
		$failing->method('set')->willThrowException(new \RuntimeException('Storage failure'));
		$property = new \ReflectionProperty(\ger\feedpostbot\classes\driver::class, 'config_text');
		$property->setAccessible(true);
		$property->setValue($this->driver, $failing);
		try
		{
			$this->driver->save_sources(array(), $original);
			$this->fail('Expected storage failure');
		}
		catch (\RuntimeException $error)
		{
			$this->assertCount(1, $this->tracker->rows);
			$this->assertTrue($this->driver->lock->released);
		}
	}

	public function test_failed_mapping_cleanup_still_reports_saved_settings()
	{
		$this->run_feed(array($this->item('a')));
		$this->tracker->fail_delete = true;
		$original = $this->driver->init_current_state();
		$this->assertTrue($this->driver->save_sources(array(), $original));
		$this->assertSame(array(), $this->saved);
		$this->assertCount(1, $this->tracker->rows);
	}

	public function test_acp_form_loaded_before_worker_assigned_identity_saves_without_conflict()
	{
		$original = $this->driver->init_current_state();
		$this->run_feed(array($this->item('a')));
		$uid = $this->saved[0]['source_uid'];
		$proposed = $original;
		$proposed[0]['prefix'] = 'Edited';
		$this->assertTrue($this->driver->save_sources($proposed, $original));
		$this->assertSame($uid, $this->saved[0]['source_uid']);
		$this->assertSame('Edited', $this->saved[0]['prefix']);
	}

	public function test_worker_progress_never_replaces_an_identity()
	{
		$this->saved[0]['source_uid'] = str_repeat('a', 32);
		$this->driver->items = array($this->item('a'));
		$this->driver->current_state = array();
		$this->listeners['ger.feedpostbot.submit_post_before'] = function ($data) {
			// A forced lock reset lets the ACP renew the identity while the worker runs.
			$this->saved[0]['source_uid'] = str_repeat('b', 32);
			return $data;
		};
		$this->driver->fetch_all();
		$this->assertSame(str_repeat('b', 32), $this->saved[0]['source_uid']);
	}

	// --- Tracked creation ----------------------------------------------------------------

	public function test_tracked_creation_stores_ids_hashes_and_guard()
	{
		$this->assertSame(1, $this->run_feed(array($this->item('a'))));
		$row = $this->row();
		$topic = reset($this->forum->topics);
		$this->assertSame($topic['topic_id'], $row['topic_id']);
		$this->assertSame($topic['topic_first_post_id'], $row['post_id']);
		$this->assertSame(hash('sha256', 'guid:a'), $row['item_key']);
		$this->assertSame('guid:a', $row['item_ident']);
		$this->assertSame(item_tracker::STATE_ACTIVE, $row['item_state']);
		$this->assertSame($row['content_hash'], $row['notified_hash']);
		$this->assertSame(64, strlen($row['source_hash']));
		$this->assertSame(1, preg_match('/^g1:[0-9a-f]{64}$/', $row['guard_hash']));
		$this->assertSame(0, $row['pending_op']);
		$this->assertFalse($this->phpbb_transaction);
	}

	public function test_consumed_item_creates_no_mapping()
	{
		$this->listeners['ger.feedpostbot.submit_post_before'] = function ($data) {
			$data['do_post'] = false;
			return $data;
		};
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
		$this->assertCount(0, $this->tracker->rows);
		$this->assertCount(0, $this->topics());
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
	}

	public function test_content_customized_by_submit_post_before_is_detached_and_never_duplicated()
	{
		$this->listeners['ger.feedpostbot.submit_post_before'] = function ($data) {
			$data['title'] .= ' (custom)';
			return $data;
		};
		$this->run_feed(array($this->item('a')));
		$row = $this->row();
		$this->assertSame(item_tracker::STATE_DETACHED, $row['item_state']);
		$this->assertSame(item_tracker::REASON_CUSTOMIZED_AT_CREATION, $row['state_reason']);
		$this->assertCount(1, $this->state_logs());
		unset($this->listeners['ger.feedpostbot.submit_post_before']);
		$this->run_feed(array($this->item('a', 'Changed body')));
		$this->assertCount(1, $this->topics());
		$this->assertCount(1, $this->forum->calls);
		$this->assertSame($row, $this->row());
	}

	public function test_message_customization_is_detected()
	{
		$this->listeners['ger.feedpostbot.submit_post_before'] = function ($data) {
			$data['data']['message'] .= 'extra';
			return $data;
		};
		$this->run_feed(array($this->item('a')));
		$this->assertSame(item_tracker::STATE_DETACHED, $this->row()['item_state']);
	}

	public function test_placement_changes_by_submit_post_before_keep_tracking_active()
	{
		$this->listeners['ger.feedpostbot.submit_post_before'] = function ($data) {
			$data['data']['forum_id'] = 6;
			$data['data']['enable_bbcode'] = 1;
			return $data;
		};
		$this->run_feed(array($this->item('a')));
		$this->assertSame(item_tracker::STATE_ACTIVE, $this->row()['item_state']);
		$this->assertSame(6, reset($this->forum->topics)['forum_id']);
	}

	public function test_failed_mapping_insert_rolls_back_the_topic_and_retries()
	{
		$this->tracker->fail_insert = true;
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
		$this->assertCount(0, $this->topics());
		$this->assertArrayNotHasKey(hash('sha256', 'guid:a'), $this->saved[0]['handled']);
		$this->tracker->fail_insert = false;
		$this->assertSame(1, $this->run_feed(array($this->item('a'))));
		$this->assertCount(1, $this->topics());
		$this->assertCount(1, $this->tracker->rows);
	}

	// --- Creation failure windows (phpBB commits the topic itself) ------------------------

	public function test_topic_failing_before_commit_withdraws_the_pending_row()
	{
		$this->forum->fail['post'] = new \RuntimeException('Rejected');
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
		$this->assertCount(0, $this->tracker->rows);
		$this->assertCount(0, $this->topics());
		$this->assertSame(1, $this->run_feed(array($this->item('a'))));
		$this->assertCount(1, $this->topics());
		$this->assertSame(0, $this->row()['pending_op']);
	}

	public function test_open_phpbb_transaction_is_rolled_back_before_tracking_is_settled()
	{
		$this->forum->fail_open['post'] = new \RuntimeException('Failed inside submit_post');
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
		$this->assertSame(1, $this->rollbacks);
		$this->assertFalse($this->phpbb_transaction);
		$this->assertCount(0, $this->tracker->rows);
		$this->assertSame(1, $this->run_feed(array($this->item('a'))));
		$this->assertCount(1, $this->topics());
	}

	public function test_topic_reported_but_not_stored_is_never_mapped()
	{
		// submit_post() returned a URL, but the topic is not in the database.
		$this->forum->lose['post'] = true;
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
		$this->assertCount(0, $this->tracker->rows);
		$this->assertArrayNotHasKey(hash('sha256', 'guid:a'), $this->saved[0]['handled']);
		$this->assertSame(1, $this->run_feed(array($this->item('a'))));
		$this->assertCount(1, $this->topics());
		$this->assertSame(reset($this->forum->topics)['topic_id'], $this->row()['topic_id']);
	}

	public function test_error_after_topic_was_committed_still_maps_it()
	{
		$this->forum->fail_after['post'] = new \RuntimeException('Notification failed');
		$this->assertSame(1, $this->run_feed(array($this->item('a'))));
		$row = $this->row();
		$this->assertSame(0, $row['pending_op']);
		$this->assertSame(reset($this->forum->topics)['topic_id'], $row['topic_id']);
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
		$this->assertCount(1, $this->topics());
	}

	public function test_interrupted_creation_is_recovered_without_a_duplicate()
	{
		// The topic is committed, then recording its ids fails (as if the process died there).
		$this->tracker->fail_on = function ($fields) { return isset($fields['topic_id']) && $fields['topic_id'] > 0; };
		$this->run_feed(array($this->item('a')));
		$row = $this->row();
		$this->assertSame(\ger\feedpostbot\classes\item_tracker::PENDING_CREATE, $row['pending_op']);
		$this->assertCount(1, $this->topics());
		$this->tracker->fail_on = null;
		// Even with updates switched off meanwhile, the interrupted creation is completed.
		$this->settings(array('update_existing' => 0));
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
		$this->assertCount(1, $this->topics());
		$row = $this->row();
		$this->assertSame(0, $row['pending_op']);
		$this->assertSame(reset($this->forum->topics)['topic_id'], $row['topic_id']);
		$this->assertSame(item_tracker::STATE_ACTIVE, $row['item_state']);
		$this->assertSame($row['content_hash'], $row['notified_hash']);
	}

	public function test_creation_interrupted_before_posting_is_retried_once()
	{
		$this->run_feed(array($this->item('a')));
		$row = $this->row();
		// Simulate a process that recorded a creation for b and died before submit_post().
		$this->tracker->insert(array_merge($row, array('item_key' => hash('sha256', 'guid:b'), 'topic_id' => 0, 'post_id' => 0,
			'pending_op' => item_tracker::PENDING_CREATE, 'pending_checksum' => '', 'pending_after_post_id' => $this->forum->max_post_id())));
		$this->assertSame(1, $this->run_feed(array($this->item('b'), $this->item('a'))));
		$this->assertCount(2, $this->topics());
		$this->assertCount(2, $this->tracker->rows);
		foreach ($this->tracker->rows as $tracked)
		{
			$this->assertSame(0, $tracked['pending_op']);
			$this->assertGreaterThan(0, $tracked['topic_id']);
		}
	}

	public function test_recovery_never_adopts_an_older_topic_with_the_same_content()
	{
		$this->run_feed(array($this->item('a')));
		$old = $this->row();
		$checksum = $this->forum->posts[$old['post_id']]['post_checksum'];
		// A pending creation of b with a checksum that an earlier post happens to have.
		$this->tracker->insert(array_merge($old, array('item_key' => hash('sha256', 'guid:b'), 'topic_id' => 0, 'post_id' => 0,
			'pending_op' => item_tracker::PENDING_CREATE, 'pending_checksum' => $checksum, 'pending_after_post_id' => $this->forum->max_post_id())));
		$this->run_feed(array($this->item('b'), $this->item('a')));
		$rows = $this->tracker->load($old['source_uid'], array(hash('sha256', 'guid:b')));
		$this->assertNotSame($old['topic_id'], $rows[hash('sha256', 'guid:b')]['topic_id']);
		$this->assertCount(2, $this->topics());
	}

	public function test_items_without_stable_identity_use_historical_behaviour()
	{
		$item = array('guid' => md5('content'), 'native_id' => '', 'title' => 'T', 'link' => '', 'description' => 'x', 'pubDate' => 1);
		$this->assertSame(1, $this->run_feed(array($item)));
		$this->assertCount(0, $this->tracker->rows);
		$this->assertSame(0, $this->run_feed(array($item)));
	}

	public function test_legacy_handled_items_without_mapping_are_never_touched()
	{
		$this->saved[0]['handled'] = array(hash('sha256', 'guid:old') => true);
		$this->assertSame(1, $this->run_feed(array($this->item('new'), $this->item('old', 'Changed'))));
		$this->assertCount(1, $this->topics());
		$this->assertCount(1, $this->tracker->rows);
		$this->assertSame(hash('sha256', 'guid:new'), $this->row()['item_key']);
	}

	public function test_legacy_marker_bootstrap_is_not_tracked()
	{
		unset($this->saved[0]['handled']);
		$this->saved[0]['latest'] = array('guid' => 'old', 'link' => '', 'pubDate' => 0);
		$this->assertSame(1, $this->run_feed(array($this->item('new'), $this->item('old'), $this->item('older'))));
		$this->assertSame(hash('sha256', 'guid:new'), $this->row()['item_key']);
		$this->assertSame(0, $this->run_feed(array($this->item('new'), $this->item('old', 'x'), $this->item('older', 'y'))));
		$this->assertCount(1, $this->forum->calls);
	}

	public function test_row_changed_since_loading_is_not_overwritten()
	{
		$this->run_feed(array($this->item('a', 'Body', array('description' => '<p class="x">Body</p>'))));
		$row = $this->row();
		// Another writer changes the row between loading and updating it.
		$this->listeners['ger.feedpostbot.item_fingerprint'] = function ($data) use ($row) {
			$this->tracker->rows[$row['tracking_id']]['source_hash'] = 'changed elsewhere';
			return $data;
		};
		$this->run_feed(array($this->item('a', 'Body', array('description' => '<p class="y">Body</p>'))));
		$this->assertSame('changed elsewhere', $this->row()['source_hash']);
		$this->assertCount(1, array_filter($this->logs, function ($log) { return $log['operation'] === 'FPB_LOG_FEED_ERROR'; }));
	}

	// --- Ambiguous identities -------------------------------------------------------------

	public function test_items_sharing_a_native_guid_are_not_tracked()
	{
		$items = array($this->item('same', 'First story'), $this->item('same', 'Second story', array('title' => 'Other')));
		// Historical behaviour: both share the historical key as well, so one topic.
		$this->assertSame(1, $this->run_feed($items));
		$this->assertCount(0, $this->tracker->rows);
		$this->assertSame(0, $this->run_feed(array($this->item('same', 'Changed'), $this->item('same', 'Second story'))));
		$this->assertCount(1, $this->forum->calls);
	}

	public function test_guidless_items_sharing_a_link_are_not_tracked()
	{
		$first = $this->guidless_item('home', 'First story');
		$second = $this->guidless_item('home', 'Second story');
		$this->assertSame(2, $this->run_feed(array($first, $second)));
		$this->assertCount(0, $this->tracker->rows);
		$this->assertSame(0, $this->run_feed(array($first, $second)));
		$this->assertCount(2, $this->topics());
		$this->assertCount(0, $this->calls('edit'));
	}

	public function test_links_differing_only_in_fragment_are_ambiguous()
	{
		$first = $this->guidless_item('page', 'Part A');
		$first['link'] = 'https://example.com/page#a';
		$second = $this->guidless_item('page', 'Part B');
		$second['link'] = 'https://example.com/page#b';
		$this->assertSame($this->driver->tracking_key($first), $this->driver->tracking_key($second));
		$this->assertSame(2, $this->run_feed(array($first, $second)));
		$this->assertCount(0, $this->tracker->rows);
	}

	public function test_collision_with_an_existing_mapping_pauses_updates_without_duplicates()
	{
		$this->run_feed(array($this->item('a')));
		$row = $this->row();
		$clash = $this->item('a', 'Another story', array('title' => 'Other'));
		for ($run = 0; $run < 3; $run++)
		{
			$this->assertSame(0, $this->run_feed(array($this->item('a', 'Changed'), $clash)));
		}
		$this->assertCount(1, $this->topics());
		$this->assertCount(1, $this->forum->calls);
		$after = $this->row();
		$this->assertSame(item_tracker::STATE_BLOCKED, $after['item_state']);
		$this->assertSame(item_tracker::REASON_AMBIGUOUS, $after['state_reason']);
		$this->assertSame($row['content_hash'], $after['content_hash']);
		$this->assertSame($row['source_hash'], $after['source_hash']);
		$this->assertCount(1, $this->state_logs());
		// Once the identity is unique again, updates resume.
		$this->run_feed(array($this->item('a', 'Changed')));
		$this->assertCount(1, $this->calls('edit'));
		$this->assertSame(item_tracker::STATE_ACTIVE, $this->row()['item_state']);
		$this->assertSame(item_tracker::REASON_NONE, $this->row()['state_reason']);
	}

	public function test_guidless_collision_with_existing_mapping_neither_edits_nor_posts()
	{
		$this->run_feed(array($this->guidless_item('news')));
		$this->run_feed(array($this->guidless_item('news', 'Changed'), $this->guidless_item('news', 'Unrelated')));
		$this->assertCount(1, $this->topics());
		$this->assertCount(0, $this->calls('edit'));
		$this->settings(array('update_existing' => 0));
		$row = $this->row();
		$this->run_feed(array($this->guidless_item('news', 'Changed'), $this->guidless_item('news', 'Unrelated')));
		$this->assertCount(1, $this->topics());
		$this->assertSame($row, $this->row());
	}

	// --- Updates switched off ------------------------------------------------------------

	public function test_mapped_guidless_item_is_neither_duplicated_nor_edited_while_updates_are_off()
	{
		$first = $this->guidless_item('news');
		$this->run_feed(array($first));
		$row = $this->row();
		$this->assertSame('link:https://example.com/news', $row['item_ident']);
		$this->settings(array('update_existing' => 0));
		$changed = $this->guidless_item('news', 'Changed body');
		$this->assertNotSame($first['guid'], $changed['guid']);
		$this->assertSame(0, $this->run_feed(array($changed)));
		$this->assertCount(1, $this->topics());
		$this->assertCount(1, $this->forum->calls);
		$this->assertSame($row, $this->row());
		$this->assertArrayHasKey(hash('sha256', 'guid:' . $changed['guid']), $this->saved[0]['handled']);
	}

	public function test_feeds_saved_before_the_update_settings_existed_map_new_topics()
	{
		// Settings of an upgraded board: the new per-feed keys are missing and default to off.
		unset($this->saved[0]['update_existing'], $this->saved[0]['update_reply'], $this->saved[0]['recreate_deleted']);
		$this->assertSame(3, $this->run_feed(array($this->item('a'), $this->item('b'), $this->item('c'))));
		$this->assertArrayNotHasKey('update_existing', $this->saved[0]);
		$this->assertSame(0, $this->driver->state()[0]['update_existing']);
		$this->assertCount(3, $this->topics());
		$this->assertCount(3, $this->tracker->rows);
		$topics = array_keys($this->forum->topics);
		foreach ($this->tracker->rows as $row)
		{
			$this->assertContains($row['topic_id'], $topics);
			$this->assertSame(item_tracker::STATE_ACTIVE, $row['item_state']);
			$this->assertSame(0, $row['pending_op']);
		}
	}

	public function test_new_item_is_mapped_while_updates_are_off()
	{
		$this->settings(array('update_existing' => 0));
		$this->assertSame(1, $this->run_feed(array($this->item('a'))));
		$row = $this->row();
		$topic = reset($this->forum->topics);
		$this->assertSame($topic['topic_id'], $row['topic_id']);
		$this->assertSame($topic['topic_first_post_id'], $row['post_id']);
		$this->assertSame(hash('sha256', 'guid:a'), $row['item_key']);
		$this->assertSame($row['content_hash'], $row['notified_hash']);
		$this->assertSame(1, preg_match('/^g1:[0-9a-f]{64}$/', $row['guard_hash']));
		// The same item again: not posted twice.
		$this->assertSame(0, $this->run_feed(array($this->item('a'))));
		$this->assertCount(1, $this->topics());
		$this->assertCount(1, $this->forum->calls);
		$this->assertSame($row, $this->row());
	}

	public function test_item_mapped_while_off_stays_frozen_and_is_updated_after_enabling()
	{
		$this->settings(array('update_existing' => 0));
		// Without a feed identifier: the historical key changes with the content, the mapping does not.
		$this->run_feed(array($this->guidless_item('news')));
		$row = $this->row();
		$this->assertSame(0, $this->run_feed(array($this->guidless_item('news', 'Changed body'))));
		$this->assertCount(1, $this->topics());
		$this->assertCount(0, $this->calls('edit'));
		$this->assertSame($row, $this->row());
		$this->settings(array('update_existing' => 1));
		$this->assertSame(0, $this->run_feed(array($this->guidless_item('news', 'Changed body'))));
		$edits = $this->calls('edit');
		$this->assertCount(1, $edits);
		$this->assertSame($row['post_id'], $edits[0]['data']['post_id']);
		$this->assertCount(1, $this->topics());
		$this->assertStringContainsString('Changed body', $this->forum->posts[$row['post_id']]['post_text']);
		$this->assertNotSame($row['content_hash'], $this->row()['content_hash']);
		$this->assertSame(1, $this->driver->get_run_result()['updated']);
	}

	public function test_ambiguous_and_unidentifiable_items_stay_untracked_while_updates_are_off()
	{
		$this->settings(array('update_existing' => 0));
		$first = $this->guidless_item('home', 'First story');
		$second = $this->guidless_item('home', 'Second story');
		$anonymous = array('guid' => md5('content'), 'native_id' => '', 'title' => 'T', 'link' => '', 'description' => 'x', 'pubDate' => 1);
		$this->assertSame(3, $this->run_feed(array($first, $second, $anonymous)));
		$this->assertCount(0, $this->tracker->rows);
		$this->assertSame(0, $this->run_feed(array($first, $second, $anonymous)));
		$this->assertCount(3, $this->topics());
	}

	public function test_legacy_handled_items_stay_untouched_while_updates_are_off()
	{
		$this->settings(array('update_existing' => 0));
		$this->saved[0]['handled'] = array(hash('sha256', 'guid:old') => true);
		$this->assertSame(1, $this->run_feed(array($this->item('new'), $this->item('old', 'Changed'))));
		$this->assertCount(1, $this->topics());
		$this->assertSame(hash('sha256', 'guid:new'), $this->row()['item_key']);
	}

	public function test_tracking_without_repository_keeps_historical_behaviour()
	{
		$property = new \ReflectionProperty(\ger\feedpostbot\classes\driver::class, 'tracker');
		$property->setAccessible(true);
		$property->setValue($this->driver, null);
		$this->assertSame(1, $this->run_feed(array($this->item('a'))));
		$this->assertArrayNotHasKey('source_uid', $this->saved[0]);
		$this->assertCount(0, $this->tracker->rows);
	}

	// --- Baselines -----------------------------------------------------------------------

	public function test_pubdate_change_is_not_an_update()
	{
		$this->run_feed(array($this->item('a')));
		$row = $this->row();
		$this->run_feed(array($this->item('a', 'Body', array('pubDate' => 999))));
		$this->assertSame($row, $this->row());
		$this->assertCount(1, $this->forum->calls);
	}

	public function test_source_change_rendering_identically_only_advances_the_baseline()
	{
		$this->run_feed(array($this->item('a', 'Body', array('description' => '<p class="x">Body</p>'))));
		$row = $this->row();
		$this->run_feed(array($this->item('a', 'Body', array('description' => '<p class="y">Body</p>'))));
		$after = $this->row();
		$this->assertNotSame($row['source_hash'], $after['source_hash']);
		$this->assertSame($row['content_hash'], $after['content_hash']);
		$this->assertSame($row['notified_hash'], $after['notified_hash']);
		$this->assertCount(1, $this->forum->calls);
	}

	public function test_formatting_change_alone_causes_no_mass_update()
	{
		$items = array($this->item('a'), $this->item('b'), $this->item('c'));
		$this->run_feed($items);
		$rows = $this->tracker->rows;
		$this->settings(array('prefix' => '[News]', 'textlimit' => 5, 'append_link' => 0));
		$this->run_feed($items);
		$this->assertSame($rows, $this->tracker->rows);
		$this->assertCount(3, $this->forum->calls);
	}

	public function test_scheme_change_rebaselines_silently_and_keeps_owed_reply()
	{
		// The owed reply cannot be posted yet, so it must survive the re-baseline.
		$this->settings(array('update_reply' => 1));
		$this->run_feed(array($this->item('a'), $this->item('b')));
		$this->hold_replies();
		$ids = array_keys($this->tracker->rows);
		$this->tracker->rows[$ids[0]]['fingerprint_scheme'] = 'old';
		$this->tracker->rows[$ids[1]]['fingerprint_scheme'] = 'old';
		$this->tracker->rows[$ids[1]]['notified_hash'] = 'older';
		$this->run_feed(array($this->item('a', 'Changed'), $this->item('b', 'Changed')));
		$this->assertCount(2, $this->forum->calls);
		$first = $this->tracker->rows[$ids[0]];
		$second = $this->tracker->rows[$ids[1]];
		$this->assertNotSame('old', $first['fingerprint_scheme']);
		$this->assertSame($first['content_hash'], $first['notified_hash']);
		$this->assertSame('older', $second['notified_hash']);
		$this->assertNotSame($second['content_hash'], $second['notified_hash']);
	}
}
