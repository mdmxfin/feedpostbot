<?php
/**
 *
 * Feed post bot. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace ger\feedpostbot\acp;

/**
 * Feed post bot ACP module.
 */
class main_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $request, $template, $user, $phpbb_container, $config;
		$feedpostbot = $phpbb_container->get('ger.feedpostbot.classes.driver');
		$phpbb_dispatcher = $phpbb_container->get('dispatcher');

		$this->tpl_name		= 'acp_feedpostbot_body';
		$this->page_title	= $user->lang('FPB_ACP_FEEDPOSTBOT_TITLE');
		add_form_key('ger/feedpostbot');

		// Fetch current feeds
		$feedpostbot->init_current_state();
		$current_state = $feedpostbot->current_state;
		$original_state = $current_state;
		if ($request->is_set_post('run_all'))
		{
			if (!check_form_key('ger/feedpostbot'))
			{
				trigger_error('FORM_INVALID');
			}
			$fetched = $feedpostbot->fetch_all();
			$result = $feedpostbot->get_run_result();
			if (!empty($result['locked']))
			{
				// Another run (usually cron) is processing the feeds: this request did nothing.
				trigger_error($user->lang('FPB_LOCK_ACTIVE') . adm_back_link($this->u_action), E_USER_WARNING);
			}
			if ($fetched > 0)
			{
				$message = $user->lang('FPB_ACP_FETCHED_ITEMS', $fetched);
			}
			else
			{
				$message = $user->lang('FPB_ACP_NO_FETCHED_ITEMS');
			}
			if (!empty($result['updated']))
			{
				$message .= ' ' . $user->lang('FPB_ACP_UPDATED_ITEMS', (int) $result['updated']);
			}
			trigger_error($message . adm_back_link($this->u_action));
		}
		// Set main config. Might be expanded in the future
		else if ($request->is_set_post('set_config'))
		{
			if (!check_form_key('ger/feedpostbot'))
			{
				trigger_error('FORM_INVALID');
			}
			if (!\ger\feedpostbot\classes\driver::valid_number($request->variable('cron_frequency', ''), 0, 31536000))
			{
				trigger_error($user->lang('FPB_SETTINGS_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}
			$config->set('feedpostbot_cron_frequency', $request->variable('cron_frequency', 0));
			$config->set('feedpostbot_enable_logs', $request->variable('enable_logs', 0));
			trigger_error($user->lang('FPB_ACP_FEEDPOSTBOT_SETTING_SAVED') . adm_back_link($this->u_action));
		}
		else if ($request->is_set_post('reset_lock'))
		{
			if (!check_form_key('ger/feedpostbot'))
			{
				trigger_error('FORM_INVALID');
			}
			if (!$feedpostbot->reset_stale_lock())
			{
				trigger_error($user->lang('FPB_LOCK_ACTIVE') . adm_back_link($this->u_action), E_USER_WARNING);
			}
			trigger_error($user->lang('FPB_ACP_FEEDPOSTBOT_SETTING_SAVED') . adm_back_link($this->u_action));
		}
		else if ($request->is_set_post('submit'))
		{
			if (!check_form_key('ger/feedpostbot'))
			{
				trigger_error('FORM_INVALID');
			}

			// Is a new feed added?
			if ($request->is_set_post('add_feed'))
			{
				$url = $request->variable('add_feed', '', true);
				if (!$this->validate_feed($current_state, $url))
				{
					trigger_error($user->lang('FPB_FEED_URL_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
				}
				$type = $feedpostbot->detect_feed_type($url);
				if ($type === false || !in_array($type, array('rss', 'atom', 'rdf'), true))
				{
					trigger_error($user->lang('FPB_FEED_URL_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
				}
				$current_state[] = array(
					'url' => $url,
					'type' => $type,
					'prefix' => '',
					'forum_id' => 0,
					'user_id' => $user->data['user_id'],
					'textlimit' => 0,
					'timeout' => 3,
					'max_new_items' => 0,
					'curdate' => 0,
					'append_link' => 1,
					'update_existing' => 0,
					'update_reply' => 0,
					'recreate_deleted' => 0,
					'latest' => array(
						'link' => '',
						'pubDate' => '',
						'guid' => '',
					),
				);
				if (!$feedpostbot->save_sources($current_state, $original_state))
				{
					trigger_error($user->lang('FPB_SAVE_CONFLICT') . adm_back_link($this->u_action), E_USER_WARNING);
				}
			}
			else
			{
				$new_state = array();
				if (is_array($current_state))
				{
					foreach ($current_state as $id => $source)
					{
						$url = $request->variable($id . '_url', '', true);
						if (!$this->validate_feed($new_state, $url))
						{
							trigger_error($user->lang('FPB_FEED_URL_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
						}
						$type = $current_state[$id]['type'];
						foreach (array('forum_id' => array(0, 2147483647), 'user_id' => array(1, 2147483647), 'textlimit' => array(0, 1000000), 'timeout' => array(1, 60), 'max_new_items' => array(0, 1000000)) as $field => $limits)
						{
							if (!\ger\feedpostbot\classes\driver::valid_number($request->variable($id . '_' . $field, ''), $limits[0], $limits[1]))
							{
								trigger_error($user->lang('FPB_SETTINGS_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
							}
						}
						if ($url !== $current_state[$id]['url'])
						{
							$detected_type = $feedpostbot->detect_feed_type($url);
							if ($detected_type === false)
							{
								trigger_error($user->lang('FPB_FEED_URL_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
							}
							$type = $detected_type;
							// A new URL is a new feed: history and tracked topics are not inherited.
							unset($source['handled'], $source['source_uid']);
							$source['latest'] = array('guid' => '', 'link' => '', 'pubDate' => 0);
						}
						$new_state[$id] = array_merge($source, array(
							'url' => $url,
							'type' => $type,
							'prefix' => $request->variable($id . '_prefix', '', true),
							'forum_id' => $request->variable($id . '_forum_id', 0),
							'user_id' => $request->variable($id . '_user_id', $user->data['user_id']),
							'textlimit' => $request->variable($id . '_textlimit', 0),
							'timeout' => $request->variable($id . '_timeout', 3),
							'max_new_items' => $request->variable($id . '_max_new_items', 0),
							'curdate' => strlen($request->variable($id . '_curdate', '')) > 0 ? 1 : 0,
							'append_link' => strlen($request->variable($id . '_append_link', '')) > 0 ? 1 : 0,
							'update_existing' => strlen($request->variable($id . '_update_existing', '')) > 0 ? 1 : 0,
							'update_reply' => strlen($request->variable($id . '_update_reply', '')) > 0 ? 1 : 0,
							'recreate_deleted' => strlen($request->variable($id . '_recreate_deleted', '')) > 0 ? 1 : 0,
							'latest' => $source['latest'],
						));
					}
				}
				$feedpostbot->prepare_destinations($new_state);
				foreach ($new_state as $source)
				{
					if (!$feedpostbot->valid_source($source))
					{
						trigger_error($user->lang('FPB_SETTINGS_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
					}
				}
				if (!$feedpostbot->save_sources($new_state, $original_state))
				{
					trigger_error($user->lang('FPB_SAVE_CONFLICT') . adm_back_link($this->u_action), E_USER_WARNING);
				}
			}
			trigger_error($user->lang('FPB_ACP_FEEDPOSTBOT_SETTING_SAVED') . adm_back_link($this->u_action));
		}
		else if ($request->variable('action', '') == 'delete')
		{
			if (confirm_box(true))
			{
				$id = $request->variable('id', 0);
				if (is_array($current_state) && isset($current_state[$id]))
				{
					unset($current_state[$id]);
					if (!$feedpostbot->save_sources($current_state, $original_state))
					{
						trigger_error($user->lang('FPB_SAVE_CONFLICT') . adm_back_link($this->u_action), E_USER_WARNING);
					}
				}
				trigger_error($user->lang('FPB_ACP_FEEDPOSTBOT_SETTING_SAVED') . adm_back_link($this->u_action));
			}
			else
			{
				// Confirm
				confirm_box(false, $user->lang('CONFIRM_OPERATION'), build_hidden_fields(array(
					'id'        => $request->variable('id', 0),
					'action'    => 'delete',
				)));
			}
		}

		// List current
		if (!empty($current_state))
		{
			foreach ($current_state as $id => $source)
			{
				$block_vars = array(
					'ID'		=> $id,
					'URL'		=> html_entity_decode($source['url'], ENT_QUOTES, 'UTF-8'),
					'TYPE'		=> $source['type'],
					'PREFIX'	=> $source['prefix'],
					'U_DELETE'	=> $this->u_action . "&amp;action=delete&amp;id=" . $id,
					'S_FORUMS'	=> make_forum_select($source['forum_id'], false, false, false, false),
					'USER_ID'	=> $source['user_id'],
					'TEXTLIMIT'	=> $source['textlimit'],
					'TIMEOUT'	=> $source['timeout'],
					'MAX_NEW_ITEMS'	=> isset($source['max_new_items']) ? (int) $source['max_new_items'] : 0,
					'S_CURDATE'	=> empty($source['curdate']) ? false : true,
					'S_APPEND_LINK'	=> empty($source['append_link']) ? false : true,
					'S_UPDATE_EXISTING'		=> !empty($source['update_existing']),
					'S_UPDATE_REPLY'		=> !empty($source['update_reply']),
					'S_RECREATE_DELETED'	=> !empty($source['recreate_deleted']),
				);

				/**
				 * Modify ACP feed block variables before they are assigned to the template
				 *
				 * @event ger.feedpostbot.acp_override_feed_block_vars
				 * @var  int    id          Feed ID
				 * @var  array  source      Feed source data array
				 * @var  array  block_vars  Template block variables
				 * @since 1.0.1
				 */
				$vars = array('id', 'source', 'block_vars');
				$event_data = $phpbb_dispatcher->trigger_event('ger.feedpostbot.acp_override_feed_block_vars', compact($vars));
				if (is_array($event_data) || $event_data instanceof \ArrayAccess)
				{
					extract((array) $event_data);
				}

				$template->assign_block_vars('feeds', $block_vars);
			}
		}

		$template->assign_vars(array(
			'FPB_LOCKED'        => $config['feedpostbot_locked'],
			'CRON_FREQUENCY'    => $config['feedpostbot_cron_frequency'],
			'ENABLE_LOGS'       => (bool) $config['feedpostbot_enable_logs'],
			'U_ACTION'          => $this->u_action,
			'U_ADD_ACTION'      => $this->u_action . "&amp;action=add",
		));
	}

	/**
	 * Check if provided feed url is unique and a valid HTTP/HTTPS url scheme
	 *
	 * @param array $current_state
	 * @param string $url
	 * @param int|null $id
	 * @return bool
	 */
	private function validate_feed($current_state, $url, $id = null)
	{
		if (!\ger\feedpostbot\classes\http_client::valid_url(html_entity_decode($url, ENT_QUOTES, 'UTF-8')))
		{
			return false;
		}

		if (is_array($current_state))
		{
			foreach ($current_state as $source_id => $source)
			{
				if ($url === $source['url'] && ($id === null || (int) $id !== (int) $source_id))
				{
					return false;
				}
			}
		}
		return true;
	}
}
