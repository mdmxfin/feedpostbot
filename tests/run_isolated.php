<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

// Optional CLI runner for unit tests when phpBB's repository bootstrap is unavailable.
if (PHP_SAPI !== 'cli')
{
	exit;
}

$phpbb_root_path = dirname(__DIR__, 4) . '/';
require $phpbb_root_path . 'vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';
define('IN_PHPBB', true);
require $phpbb_root_path . 'includes/constants.php';
require $phpbb_root_path . 'includes/functions.php';
require $phpbb_root_path . 'includes/utf/utf_tools.php';

spl_autoload_register(function ($class) use ($phpbb_root_path) {
	if (strpos($class, 'phpbb\\') === 0)
	{
		$file = $phpbb_root_path . str_replace('\\', '/', $class) . '.php';
		if (is_file($file))
		{
			require $file;
		}
	}
	else if (strpos($class, 'ger\\feedpostbot\\') === 0)
	{
		$file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen('ger\\feedpostbot\\'))) . '.php';
		if (is_file($file))
		{
			require $file;
		}
	}
});

// These tests use mocks, not phpBB's database or functional test fixtures.
class phpbb_test_case extends \PHPUnit\Framework\TestCase
{
}

require dirname(__DIR__) . '/classes/http_client.php';
require dirname(__DIR__) . '/classes/driver.php';
require dirname(__DIR__) . '/acp/main_module.php';
$suite = new \PHPUnit\Framework\TestSuite();
foreach (glob(__DIR__ . '/*_test.php') as $test_file)
{
	require_once $test_file;
	$suite->addTestSuite('ger\\feedpostbot\\tests\\' . basename($test_file, '.php'));
}
$result = \PHPUnit\TextUI\TestRunner::run($suite);
exit($result->wasSuccessful() ? 0 : 1);
