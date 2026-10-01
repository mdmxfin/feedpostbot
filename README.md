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
4. Set the **cron interval** (in seconds) for automated fetching, or click **Fetch all feeds manually** to test immediately.

### Upgrading from Feed Post Bot 1.0.x

Old releases stored the cron settings under different names. The migration `adopt_legacy_cron_config` copies them into the current settings when these are missing; existing settings are never overwritten.

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
