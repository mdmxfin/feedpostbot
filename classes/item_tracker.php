<?php
/**
 *
 * Feed post bot. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace ger\feedpostbot\classes;

/**
 * Storage for tracked feed items: which topic and first post were created for a feed item,
 * which content version they hold and whether an update reply is still owed.
 *
 * All writes happen while the feed worker or ACP holds the shared feed post bot lock.
 */
class item_tracker
{
	/** Topic is tracked and may be updated. */
	public const STATE_ACTIVE = 0;
	/** Topic is temporarily not updatable (moderation); re-checked later. */
	public const STATE_BLOCKED = 1;
	/** Mapping integrity was lost; never touched again. */
	public const STATE_DETACHED = 2;
	/** Topic and first post were permanently deleted. */
	public const STATE_GONE = 3;

	public const REASON_NONE = 0;
	public const REASON_SOFT_DELETED = 1;
	public const REASON_POST_EDIT_LOCKED = 5;
	public const REASON_UNAPPROVED = 7;
	public const REASON_FORUM_NOT_POSTABLE = 8;
	public const REASON_MANUAL_EDIT = 10;
	public const REASON_CUSTOMIZED_AT_CREATION = 11;
	public const REASON_FIRST_POST_REMOVED = 12;
	public const REASON_STRUCTURE = 13;
	public const REASON_HARD_DELETED = 14;
	public const REASON_AMBIGUOUS = 16;
	public const REASON_EDIT_ERROR = 20;
	public const REASON_REPLY_ERROR = 21;

	/**
	 * Operation recorded before submit_post() runs and settled only after its result was
	 * found in the database. phpBB commits posts itself, so a crash in between is resolved
	 * from this marker on the next run instead of being guessed.
	 */
	public const PENDING_NONE = 0;
	public const PENDING_CREATE = 1;
	public const PENDING_EDIT = 2;
	public const PENDING_REPLY = 3;
	public const PENDING_RECREATE = 4;

	/** Rows are loaded in chunks to keep IN () lists small. */
	public const CHUNK_SIZE = 500;

	protected $db;
	protected $table;

	/**
	 * @param \phpbb\db\driver\driver_interface	$db
	 * @param string								$table	Full table name
	 */
	public function __construct(\phpbb\db\driver\driver_interface $db, $table)
	{
		$this->db = $db;
		$this->table = $table;
	}

	/**
	 * Load the rows of one feed for the given item keys
	 *
	 * @param string $source_uid
	 * @param array $item_keys
	 * @return array Rows indexed by item_key
	 */
	public function load($source_uid, array $item_keys)
	{
		$rows = array();
		$item_keys = array_values(array_unique(array_map('strval', $item_keys)));
		foreach (array_chunk($item_keys, self::CHUNK_SIZE) as $chunk)
		{
			$sql = 'SELECT *
				FROM ' . $this->table . "
				WHERE source_uid = '" . $this->db->sql_escape($source_uid) . "'
					AND " . $this->db->sql_in_set('item_key', $chunk);
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$rows[$row['item_key']] = $this->normalize($row);
			}
			$this->db->sql_freeresult($result);
		}
		return $rows;
	}

	/**
	 * Rows of one feed that still owe an update reply and are due for an attempt
	 *
	 * @param string $source_uid
	 * @param int $now
	 * @return array
	 */
	public function pending_replies($source_uid, $now)
	{
		$rows = array();
		$sql = 'SELECT *
			FROM ' . $this->table . "
			WHERE source_uid = '" . $this->db->sql_escape($source_uid) . "'
				AND notified_hash <> content_hash
				AND " . $this->db->sql_in_set('item_state', array(self::STATE_ACTIVE, self::STATE_BLOCKED)) . '
				AND reply_next_retry <= ' . (int) $now . '
			ORDER BY tracking_id';
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $this->normalize($row);
		}
		$this->db->sql_freeresult($result);
		return $rows;
	}

	/**
	 * @param array $row
	 * @return int tracking_id
	 */
	public function insert(array $row)
	{
		$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $row));
		return (int) $this->db->sql_nextid();
	}

	/**
	 * Update a row, optionally only when it still has the expected values
	 *
	 * phpBB's database drivers report matched rows (mysqli connects with
	 * MYSQLI_CLIENT_FOUND_ROWS), so an unchanged value still counts as a match.
	 *
	 * @param int $tracking_id
	 * @param array $fields
	 * @param array $expected Column => value that must still hold
	 * @return bool False when the row no longer had the expected values
	 */
	public function update($tracking_id, array $fields, array $expected = array())
	{
		if (empty($fields))
		{
			return true;
		}
		$this->db->sql_query('UPDATE ' . $this->table . '
			SET ' . $this->db->sql_build_array('UPDATE', $fields) . '
			WHERE tracking_id = ' . (int) $tracking_id . $this->conditions($expected));
		return empty($expected) || (int) $this->db->sql_affectedrows() === 1;
	}

	/**
	 * Delete a row, optionally only when it still has the expected values
	 *
	 * @param int $tracking_id
	 * @param array $expected
	 * @return bool
	 */
	public function delete($tracking_id, array $expected = array())
	{
		$this->db->sql_query('DELETE FROM ' . $this->table . '
			WHERE tracking_id = ' . (int) $tracking_id . $this->conditions($expected));
		return empty($expected) || (int) $this->db->sql_affectedrows() === 1;
	}

	protected function conditions(array $expected)
	{
		$sql = '';
		foreach ($expected as $column => $value)
		{
			$sql .= ' AND ' . $column . ' = ' . (is_int($value) ? (int) $value : "'" . $this->db->sql_escape((string) $value) . "'");
		}
		return $sql;
	}

	/**
	 * Deliberately waive every owed update reply of one feed
	 *
	 * @param string $source_uid
	 * @return void
	 */
	public function cancel_replies($source_uid)
	{
		$this->db->sql_query('UPDATE ' . $this->table . '
			SET notified_hash = content_hash, reply_retry_count = 0, reply_next_retry = 0, reply_reason = 0
			WHERE source_uid = \'' . $this->db->sql_escape($source_uid) . '\'
				AND notified_hash <> content_hash');
	}

	/**
	 * Remove all rows of feeds that no longer exist or were repointed to another URL
	 *
	 * @param array $source_uids
	 * @return void
	 */
	public function delete_sources(array $source_uids)
	{
		$source_uids = array_values(array_filter(array_unique(array_map('strval', $source_uids)), 'strlen'));
		if (empty($source_uids))
		{
			return;
		}
		$this->db->sql_query('DELETE FROM ' . $this->table . '
			WHERE ' . $this->db->sql_in_set('source_uid', $source_uids));
	}

	protected function normalize(array $row)
	{
		foreach (array('tracking_id', 'topic_id', 'post_id', 'item_state', 'state_reason', 'reply_retry_count', 'reply_next_retry', 'reply_reason', 'created_time', 'updated_time', 'pending_op', 'pending_after_post_id') as $field)
		{
			$row[$field] = isset($row[$field]) ? (int) $row[$field] : 0;
		}
		foreach (array('item_key', 'source_hash', 'content_hash', 'notified_hash', 'fingerprint_scheme', 'pending_source_hash', 'pending_content_hash', 'pending_checksum') as $field)
		{
			// CHAR columns may be returned space-padded by some databases.
			$row[$field] = isset($row[$field]) ? rtrim((string) $row[$field]) : '';
		}
		return $row;
	}
}
