<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

use ger\feedpostbot\classes\driver;

require_once __DIR__ . '/fixtures/tracking.php';

/**
 * The SimplePie library is a Composer dependency: without it no feed can be read,
 * which must be reported instead of looking like feeds without new items.
 */
class dependency_test extends fixtures\tracking_test_case
{
	public function setUp(): void
	{
		parent::setUp();
		$this->config['feedpostbot_enable_logs'] = 1;
		$this->saved[] = $this->source(array('url' => 'https://example.org/feed'));
		$this->driver->raw_feed = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Feed</title>'
			. '<item><guid>a</guid><title>Title a</title><link>https://example.com/a</link><description>Body</description></item>'
			. '</channel></rss>';
	}

	protected function operations($operation)
	{
		return array_values(array_filter($this->logs, function ($log) use ($operation) {
			return $log['operation'] === $operation;
		}));
	}

	protected function run_cron()
	{
		$this->driver->functions_loaded = false;
		$this->driver->current_state = array();
		return $this->driver->fetch_all();
	}

	public function test_available_simplepie_reads_and_posts_the_feed()
	{
		$this->assertSame(2, $this->run_cron());
		$this->assertSame(2, $this->driver->http_requests);
		$this->assertCount(2, $this->operations(driver::LOG_FEED_FETCHED));
		$this->assertSame(array(), $this->operations(driver::LOG_FEED_ERROR));
		$this->assertFalse($this->driver->get_run_result()['dependency_missing']);
		$this->assertCount(2, $this->topics());
		$this->assertFalse(isset($this->config[driver::CONFIG_DEPENDENCY_ERROR]));
	}

	public function test_missing_simplepie_is_logged_instead_of_a_successful_fetch()
	{
		$before = $this->saved;
		$this->driver->simplepie = false;

		$this->assertSame(0, $this->run_cron());

		$this->assertTrue($this->driver->get_run_result()['dependency_missing']);
		$errors = $this->operations(driver::LOG_FEED_ERROR);
		$this->assertCount(1, $errors, 'One error per run, not one per feed');
		$this->assertSame('FPB_SIMPLEPIE_MISSING', $errors[0]['data'][1]);
		$this->assertSame(array(), $this->operations(driver::LOG_FEED_FETCHED));
		$this->assertSame(0, $this->driver->http_requests, 'No request without a parser');
		$this->assertSame(array(), $this->topics());
		foreach ($before as $id => $source)
		{
			$this->assertSame($source['latest'], $this->saved[$id]['latest']);
			$this->assertSame($source['handled'], $this->saved[$id]['handled']);
		}
	}

	public function test_missing_simplepie_is_logged_at_most_once_per_interval()
	{
		$this->driver->simplepie = false;
		$this->run_cron();
		$this->run_cron();
		$this->assertTrue($this->driver->get_run_result()['dependency_missing'], 'Every run reports it');
		$this->assertCount(1, $this->operations(driver::LOG_FEED_ERROR));

		$this->config[driver::CONFIG_DEPENDENCY_ERROR] = time() - driver::DEPENDENCY_LOG_INTERVAL - 1;
		$this->run_cron();
		$this->assertCount(2, $this->operations(driver::LOG_FEED_ERROR));
	}

	public function test_restored_simplepie_resets_the_log_interval()
	{
		$this->driver->simplepie = false;
		$this->run_cron();
		$this->driver->simplepie = null;
		$this->assertSame(2, $this->run_cron());
		$this->assertFalse(isset($this->config[driver::CONFIG_DEPENDENCY_ERROR]));

		$this->driver->simplepie = false;
		$this->run_cron();
		$this->assertCount(2, $this->operations(driver::LOG_FEED_ERROR), 'A new outage is logged at once');
	}

	/**
	 * The real loader in a fresh PHP process, from an extension directory with and without vendor/
	 */
	public function test_loader_finds_simplepie_only_in_the_extension_vendor_directory()
	{
		$root = sys_get_temp_dir() . '/fpb-dependency-' . bin2hex(random_bytes(6)) . '/';
		$ext = $root . 'ext/ger/feedpostbot/';
		mkdir($ext . 'classes', 0700, true);
		try
		{
			copy(__DIR__ . '/../classes/driver.php', $ext . 'classes/driver.php');
			$this->assertSame('missing', $this->probe($root));

			// The autoloader this suite runs with, as the extension's vendor/autoload.php.
			$library = dirname((new \ReflectionClass('\SimplePie\SimplePie'))->getFileName());
			while (!is_file($library . '/autoload.php') && dirname($library) !== $library)
			{
				$library = dirname($library);
			}
			$this->assertFileExists($library . '/autoload.php');
			mkdir($ext . 'vendor');
			file_put_contents($ext . 'vendor/autoload.php', '<?php require ' . var_export($library . '/autoload.php', true) . ';');
			$this->assertSame('loaded ' . \SimplePie\SimplePie::VERSION, $this->probe($root));
		}
		finally
		{
			foreach (array('vendor/autoload.php', 'classes/driver.php') as $file)
			{
				@unlink($ext . $file);
			}
			foreach (array($ext . 'vendor', $ext . 'classes', $ext, $root . 'ext/ger', $root . 'ext', $root) as $dir)
			{
				@rmdir($dir);
			}
		}
	}

	protected function probe($root)
	{
		$code = 'require ' . var_export($root . 'ext/ger/feedpostbot/classes/driver.php', true) . ';'
			. 'echo \ger\feedpostbot\classes\driver::load_simplepie(' . var_export($root, true) . ') ? "loaded " . \SimplePie\SimplePie::VERSION : "missing";';
		$output = array();
		exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $status);
		$this->assertSame(0, $status, implode("\n", $output));
		return implode("\n", $output);
	}
}
