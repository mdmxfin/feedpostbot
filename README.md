# Feed Post Bot [![Tests](https://github.com/vinny/feedpostbot/actions/workflows/tests.yml/badge.svg)](https://github.com/vinny/feedpostbot/actions/workflows/tests.yml)

**Feed Post Bot** is a phpBB extension that reads RSS, ATOM, and RDF feeds on a scheduled interval and automatically publishes new feed items as topics in specified forums.

## Features

- **Multi-format Support:** Automatically detects and parses RSS, ATOM, and RDF feeds.
- **Customizable Per Feed:** Set specific target forums, posting user accounts, topic title prefixes, text length limits, and timeouts for each feed URL.
- **Automated & Manual Fetching:** Processes feeds automatically via phpBB's native cron system or manually through the Admin Control Panel (ACP).
- **Source Link Attribution:** Optionally appends a link back to the original source article or a "Read more" link.
- **Modern PHP Compatibility:** Fully tested and optimized for PHP 7.2 up to PHP 8.4 and phpBB 3.3.x.

## Requirements

- phpBB 3.3.0 or higher
- PHP 7.2 to PHP 8.4
- PHP XML and cURL/allow_url_fopen support
- `simplepie/simplepie` (bundled/managed via Composer)

## Installation

### 1. Download & Extract
Download the [latest release zip](https://github.com/vinny/feedpostbot/archive/refs/heads/master.zip) or clone the repository.

### 2. Copy Files
Extract or copy the files into your phpBB installation directory under `ext/ger/feedpostbot`:
```text
phpBB_root/ext/ger/feedpostbot/
```

### 3. Install Dependencies
Run Composer in the extension directory to install required dependencies:
```bash
composer install --no-dev --optimize-autoloader
```

### 4. Enable Extension
1. Log into your phpBB **Admin Control Panel (ACP)**.
2. Navigate to **Customise** > **Extension Management** > **Extensions**.
3. Locate **Feed post bot** under Disabled Extensions and click **Enable**.

## Usage & Configuration

1. In the ACP, navigate to **ACP** > **Extensions** > **Feed post bot**.
2. Add a feed by entering its full URL (e.g. `https://www.phpbb.com/feeds/rss/`).
3. Configure the feed parameters:
   - **Feed forum:** Select the forum where new topics should be posted.
   - **Feed user ID:** The ID of the forum user account used as the topic author.
   - **Topic prefix:** Optional prefix prepended to topic titles (e.g. `[phpBB News]`).
   - **Text limit:** Set a character limit for post body excerpts (set `0` for full content).
   - **Local date/time:** Toggle whether post time uses the feed fetch time or original publication date.
   - **Append link:** Toggle appending the original article URL to the post.
   - **Updates:** Optional handling of feed items that change after they were published (see below). All options are off by default.
4. Set the **cron interval** (in seconds) for automated fetching, or click **Fetch all feeds manually** to test immediately.

### Upgrading from Feed Post Bot 1.0.x

Old releases stored the cron settings under different names. The migration `adopt_legacy_cron_config` copies them into the current settings when these are missing; existing settings are never overwritten.

## Updating existing topics

Feed items are sometimes corrected after publication. With **Update topic** enabled for a feed, Feed Post Bot remembers the topic it created for each new item and updates that topic's first post when the item changes, instead of ignoring the change.

- **Update topic:** Edit the first post of the tracked topic when its feed item changes.
- **Reply on update:** After updating, post a reply announcing the update. This also moves the topic to the top of the forum. Requires *Update topic*.
- **Recreate deleted:** When a tracked item changes after its topic was permanently deleted, create a new topic for it. Requires *Update topic*.

### What is tracked

- Topics Feed Post Bot creates from now on are tracked, also while *Update topic* is switched off, as long as their feed item can be identified (see below). Topics created earlier, including those of older Feed Post Bot versions, are never matched or changed.
- Items are identified by the feed's own item identifier (RSS `guid`, Atom `id`), otherwise by their permalink. Items with neither are not tracked. Titles, content and dates are never used to identify items.
- Changing a feed's URL starts a new feed: its tracked topics are forgotten, just like its posting history. Deleting a feed forgets its tracked topics.
- When *Update topic* is switched off, tracked items are still recognised and not posted again, but their topics are not edited or replied to. Changes are applied when the option is enabled again.

### What counts as a change

A topic is updated only when the feed data of an item changes **and** the post Feed Post Bot would create from it changes. So:

- a changed publication date alone is not an update;
- a feed change that renders identically (for example changed HTML attributes) is not an update;
- changing the feed's formatting settings (prefix, text limit, source link) or language does not update existing topics; the new formatting is used the next time an item really changes.

### Permissions

As for topic creation, Feed Post Bot adds no forum permission checks of its own to updates and update replies: it posts through phpBB's `submit_post()` as the configured feed user. phpBB's own rules in `submit_post()` still apply, so if the feed user cannot post without approval, an updated first post may be put back into the approval queue. Locked topics and forums do not stop updates or update replies either. Updates also continue when the feed user was changed after a topic was created; the first post keeps its original author.

### Protection of moderation and discussion

- Only the first post created by Feed Post Bot is ever edited. Replies by users are never touched; the update reply is added after them.
- If the first post was changed by someone else (text, subject, topic title, attachments or a poll), or a merge or split moved the tracked first post or made another post the first post of its topic, the topic is never updated again.
- Posts locked against editing are never changed.
- Soft-deleted topics are never restored or recreated. They are left alone until a moderator restores them.
- Permanently deleted topics are only recreated when *Recreate deleted* is enabled, and only when the item changes again. Topics removed by pruning are therefore not recreated just because the item is still in the feed.
- Update replies that cannot be posted yet (for example while the topic awaits approval, or because of a temporary error) are retried later until they succeed. Several updates before a reply is posted result in a single reply. Switching *Reply on update* off cancels replies that were not posted yet.

Changes of the update state of a topic are recorded once in the administration log.

### Consistency

phpBB commits every post itself and finishes it afterwards (read tracking, search index, notifications), so Feed Post Bot does not wrap posting in a transaction of its own. Instead, before each topic creation, first-post update or update reply it records the operation in its tracking data, and it only marks the operation as done after finding the result in the database. If the process stops in between, the next run checks the database and completes or withdraws the recorded operation. As a result:

- an interrupted run does not create a second topic or a second update reply;
- an update reply is only considered posted once the reply was found; until then it stays owed and is retried;
- a first-post update that cannot be confirmed is retried on the next run.

Within a run, Feed Post Bot recognises its own topics, edits and replies from what phpBB reports, even if another extension changed the stored message while posting (`core.modify_submit_post_data`, `core.submit_post_modify_sql_data`). A topic whose message was changed this way when it was created is kept so it is not posted again, but it is not updated later. After an interrupted run, results are recognised by the message checksum Feed Post Bot passed to phpBB: if such an extension changed it and the process stopped before Feed Post Bot recorded the result, that topic or update reply can be posted again.

Side effects outside the database cannot be undone: notification e-mails and the actions of other extensions may already have happened for an operation whose result is later found missing and retried.

### For extension authors

Feed Post Bot reuses the same content for new topics, updates and change detection. To take part in updates, shape the post content deterministically in one of these events:

- `ger.feedpostbot.parse_item_append` (and the older `parse_{type}_append`): change or add item data;
- `ger.feedpostbot.html2bbcode_convert`: adjust the HTML to BBCode conversion;
- `ger.feedpostbot.modify_post_content` (since 1.2.0): change the final subject, BBCode source and text options.

These listeners run on every feed run for changed items, so they must not have side effects.

If a `ger.feedpostbot.submit_post_before` listener changes the title, message or text options of a new topic, Feed Post Bot cannot reproduce that content, so the topic is not updated later (it is still recognised and not posted again). Setting `do_post` to false keeps consuming an item without creating a topic.

Further events since 1.2.0:

- `ger.feedpostbot.item_fingerprint`: remove volatile values your extension added to items from change detection, and add a version tag to `scheme_tags` when the meaning of your data changes (tracked items are then re-baselined without updates);
- `ger.feedpostbot.update_post_before`: adjust the data of a first-post update, or set `do_update` to false to skip that version;
- `ger.feedpostbot.update_reply_before`: adjust the subject and text of the update reply, or set `do_reply` to false to cancel it.

## Support & Contributing

- For questions, feature discussions, or official releases, visit the extension page on [phpBB.com](https://www.phpbb.com/customise/db/extension/feedpostbot/).
- Report bugs or contribute code on [GitHub](https://github.com/vinny/feedpostbot).

## Credits

- **Original Author:** [Ger](https://github.com/GerB) — If you appreciate his original work, feel free to buy him a [coffee](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=2YBSSF68LXBAN).
- **Maintainer:** [Vinny](https://github.com/vinny) — Continuing development and PHP 8+ maintenance.

If you find this extension useful, consider supporting its development:

<a href="https://ko-fi.com/J3J51IS0BE" target="_blank" rel="noopener"><img height="36" style="border:0px;height:36px;" src="https://storage.ko-fi.com/cdn/kofi6.png?v=6" border="0" alt="Buy Me a Coffee at ko-fi.com" /></a>

## License

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](license.txt)
