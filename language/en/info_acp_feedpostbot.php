<?php
/**
 *
 * Feed post bot. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}
$lang = array_merge($lang, array(
	'FPB_ACP_FORUM_ID'					=> 'Feed forum',
	'FPB_ACP_FORUM_ID_EXPLAIN'			=> 'The forum to post the new feed items in.',
	'FPB_ACP_SETTINGS_EXPLAIN'			=> 'You can add RSS, ATOM or RDF feeds using the form below. Start with posting a feed URL. When you have entered feeds, you find a table with these parameters:',
	'FPB_ACP_FEEDPOSTBOT_SETTING_SAVED'	=> 'Feed post bot settings saved',
	'FPB_ACP_FEEDPOSTBOT_TITLE'			=> 'Feed post bot',
	'FPB_ACP_FETCHED_ITEMS'             => array(
		1	=> 'All feeds fetched: %d new post created',
		2	=> 'All feeds fetched: %d new posts created',
	),
	'FPB_ACP_NO_FETCHED_ITEMS'          => 'No (new) items to fetch',
	'FPB_ADD_FEED'						=> 'Add feed',
	'FPB_CONFIGURED_FEEDS'				=> 'Configured feeds',
	'FPB_APPEND_LINK'					=> 'Append link',
	'FPB_APPEND_LINK_EXPLAIN'			=> 'Append a link to the source of the feed item',
	'FPB_CRON_FREQUENCY'				=> 'Automatic feed processing interval',
	'FPB_CRON_FREQUENCY_EXPLAIN'		=> 'Set the interval in seconds between automatic feed processing runs. Set to 0 to disable automated fetching.<br />Note: Automatic execution relies on phpBB’s cron system. If "Run periodic tasks from operating system cron" is enabled in board configuration, ensure your system cron calls bin/phpbbcli.php cron:run at regular intervals. <a href="https://www.phpbb.com/customise/db/extension/feedpostbot/faq/2446" target="_blank" rel="noopener">Learn more in the FAQ</a>.',
	'FPB_ENABLE_LOGS'					=> 'Enable fetch logging',
	'FPB_ENABLE_LOGS_EXPLAIN'			=> 'Log an entry in the administrator log every time a feed is fetched. Keep disabled to avoid overloading database logs.',
	'FPB_CURDATE'						=> 'Local date/time',
	'FPB_CURDATE_EXPLAIN'				=> 'Check to use the feed fetch time as post time. Uncheck to use the feed PubDate as post time.',
	'FPB_FETCH_ALL_FEEDS'				=> 'Fetch all feeds manually',
	'FPB_FEED_TYPE'						=> 'Feed type',
	'FPB_FEED_TYPE_EXPLAIN'				=> 'The detected feed format (ATOM, RDF or RSS), automatically determined by the feed engine.',
	'FPB_FEED_URL'						=> 'Feed URL',
	'FPB_FEED_URL_EXPLAIN'				=> 'The URL to the actual feed, e.g. <code>https://www.phpbb.com/feeds/rss/</code>. Each feed URL should be unique',
	'FPB_FEED_URL_INVALID'				=> 'Invalid feed URL. This may be the result of a duplicate in your feed list or simply an URL that does not meet the specifications',
	'FPB_FEED_URL_PLACEHOLDER'			=> 'https://www.phpbb.com/feeds/rss/',
	'FPB_FEEDS'                         => 'Feeds',
	'FPB_LOCKED_EXPLAIN'                => 'Feed processing has started but not completed and therefore cannot start again. If this persists you can release the process by clicking this button',
	'FPB_LOG_FEED_ERROR'				=> 'XML error in feed source<br />» %1$s<br />» %2$s',
	'FPB_LOG_FEED_FETCHED'				=> 'Feed fetched<br />» %s',
	'FPB_PREFIX'						=> 'Topic prefix',
	'FPB_PREFIX_EXPLAIN'				=> 'You can choose to add a prefix to your topics, eg. “[phpBB RSS]”. Leave empty for no prefix.',
	'FPB_NO_FEEDS'						=> 'There are no feeds yet.',
	'FPB_READ_MORE'						=> 'Read more',
	'FPB_REQUIRE_URL_FOPEN'				=> 'Feed downloads require either cURL or the PHP setting allow_url_fopen. Neither is available on this server.',
	'FPB_SOURCE'						=> 'Source:',
	'FPB_TEXTLIMIT'						=> 'Text limit',
	'FPB_TEXTLIMIT_EXPLAIN'				=> 'The feed text is limited to given number of characters. Note that this value is applied to the raw feed text and words will be kept intact. Afterwards any broken HTML from the feed will be mended and converted to BBcode and a link with “Read more” is appended. The limit is therefore only an incidation for the resulting post text. <br> Set to 0 to disable text limiting.',
	'FPB_TIMEOUT'						=> 'Timeout',
	'FPB_TIMEOUT_EXPLAIN'				=> 'Timeout for requesting the Feed URL. If this time has passed without retrieving the feed content, the request is cancelled.',
	'FPB_MAX_NEW_ITEMS'					=> 'Maximum new topics per run',
	'FPB_MAX_NEW_ITEMS_EXPLAIN'			=> 'Upper limit on how many previously unseen feed items may create new topics from this feed in a single run. When the feed contains more unseen items, the ones nearest the top of the feed (normally the newest) are posted and the remaining older ones are marked as handled; they will not be posted on later runs. Set to 0 for no limit.',
	'FPB_USER_ID'						=> 'Feed user ID',
	'FPB_USER_ID_EXPLAIN'				=> 'The id of the user that will be used to post new items.',
	'FPB_SETTINGS_INVALID'				=> 'Choose an existing user and a posting forum (or disable the feed). Timeout must be 1–60 seconds, text limit 0–1000000, and the automatic interval 0–31536000 seconds.',
	'FPB_PROCESSING_FAILED'				=> 'This feed could not be processed. Check the destination and try again.',
	'FPB_HTTP_UNSAFE'					=> 'The feed URL resolves to an unsafe or unsupported destination.',
	'FPB_HTTP_FAILED'					=> 'The feed could not be downloaded within the request and size limits.',
	'FPB_HTTP_TIMEOUT'					=> 'The feed request timed out.',
	'FPB_SAVE_CONFLICT'					=> 'The feeds changed or processing is running. Reload this page and try saving again.',
	'FPB_LOCK_ACTIVE'					=> 'Feed processing may still be running. Abandoned locks are recovered automatically after one hour.',
));
