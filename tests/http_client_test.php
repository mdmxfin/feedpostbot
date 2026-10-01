<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

class http_client_test extends \phpbb_test_case
{
	public function test_accepts_local_and_private_destinations()
	{
		foreach (array('http://127.0.0.1/feed', 'http://127.0.0.1:8080/rss?x=1', 'http://localhost/feed', 'https://localhost:8443/feed',
			'http://localhost./feed', 'http://[::1]/feed', 'http://[fd00::1]/feed', 'http://[::ffff:127.0.0.1]/feed',
			'http://10.0.0.5/feed', 'http://172.16.0.1/feed', 'http://192.168.1.10/rss', 'http://100.64.0.1/feed',
			'http://my-proxy/feed', 'http://feeds.lan/rss', 'https://example.com/feed?a=1&b=2') as $url)
		{
			$this->assertTrue(\ger\feedpostbot\classes\http_client::valid_url($url), $url);
		}
	}

	public function test_rejects_invalid_urls()
	{
		foreach (array('http://127.1/feed', 'http://2130706433/feed', 'http://0x7f000001/feed', 'http://0177.0.0.1/feed',
			'http://user:password@example.com/feed', 'http://user@localhost/feed', 'file:///etc/passwd', 'ftp://localhost/feed',
			'gopher://127.0.0.1:70/', 'http:///feed', 'localhost/feed', "https://example.com/\r\nHost: localhost",
			'http://local host/feed', 'http://-bad.example/feed') as $url)
		{
			$this->assertFalse(\ger\feedpostbot\classes\http_client::valid_url($url), $url);
		}
	}

	public function test_local_and_private_answers_are_fetched_from_the_pinned_address()
	{
		foreach (array(array('http://localhost/feed', '127.0.0.1'), array('http://127.0.0.1/feed', '127.0.0.1'),
			array('http://feeds.lan/rss', '192.168.1.5'), array('http://[::1]/feed', '::1')) as $case)
		{
			$client = new http_client_fixture();
			$client->addresses = array($case[1]);
			$client->responses = array(array('status' => 200, 'location' => '', 'body' => '<rss/>'));
			$this->assertSame('<rss/>', $client->get($case[0], 3), $case[0]);
			$this->assertSame($case[1], $client->requests[0][1]);
			$this->assertSame($case[0], $client->get_final_url());
		}
	}

	public function test_redirect_to_local_address_is_followed()
	{
		$client = new http_client_fixture();
		$client->responses = array(array('status' => 302, 'location' => 'http://127.0.0.1:8080/local', 'body' => ''),
			array('status' => 200, 'location' => '', 'body' => '<rss/>'));
		$this->assertSame('<rss/>', $client->get('https://example.com/feed', 3));
		$this->assertSame('http://127.0.0.1:8080/local', $client->get_final_url());
		$this->assertCount(2, $client->requests);
	}

	public function test_redirect_to_unsupported_url_is_rejected()
	{
		$client = new http_client_fixture();
		$client->responses = array(array('status' => 302, 'location' => 'file:///etc/passwd', 'body' => ''));
		try
		{
			$client->get('https://example.com/feed', 3);
			$this->fail('Unsupported redirect was accepted');
		}
		catch (\RuntimeException $error)
		{
			$this->assertSame('FPB_HTTP_UNSAFE', $error->getMessage());
			$this->assertCount(1, $client->requests);
		}
	}

	public function test_localhost_resolves_through_the_hosts_file()
	{
		if (!@gethostbynamel('localhost'))
		{
			$this->markTestSkipped('localhost does not resolve on this system');
		}
		$method = new \ReflectionMethod(\ger\feedpostbot\classes\http_client::class, 'resolve');
		$method->setAccessible(true);
		$addresses = $method->invoke(new \ger\feedpostbot\classes\http_client(), 'localhost');
		$this->assertNotEmpty($addresses);
		foreach ($addresses as $address)
		{
			$this->assertNotFalse(filter_var($address, FILTER_VALIDATE_IP), $address);
		}
	}

	public function test_acp_accepts_local_feed_urls_and_still_detects_duplicates()
	{
		$module = new \ger\feedpostbot\acp\main_module();
		$method = new \ReflectionMethod($module, 'validate_feed');
		$method->setAccessible(true);
		$state = array(array('url' => 'http://127.0.0.1/feed'));
		$this->assertTrue($method->invoke($module, $state, 'http://localhost/feed'));
		$this->assertTrue($method->invoke($module, $state, 'http://192.168.1.10/rss'));
		$this->assertFalse($method->invoke($module, $state, 'http://127.0.0.1/feed'));
		$this->assertFalse($method->invoke($module, $state, 'ftp://127.0.0.1/feed'));
	}

	public function test_relative_redirect_keeps_query_and_pins_each_connection()
	{
		$client = new http_client_fixture();
		$client->responses = array(array('status' => 302, 'location' => '/news?a=1&b=2', 'body' => ''),
			array('status' => 200, 'location' => '', 'body' => '<rss/>'));
		$this->assertSame('<rss/>', $client->get('https://example.com/feed', 3));
		$this->assertSame('https://example.com/news?a=1&b=2', $client->get_final_url());
		$this->assertSame('8.8.8.8', $client->requests[0][1]);
		$this->assertSame('8.8.8.8', $client->requests[1][1]);
		$this->assertSame(2, $client->resolutions);
		$this->assertLessThanOrEqual($client->requests[0][2], $client->requests[1][2]);
	}

	public function test_redirect_chain_is_bounded()
	{
		$client = new http_client_fixture();
		$client->responses = array_fill(0, 6, array('status' => 302, 'location' => '/again', 'body' => ''));
		try
		{
			$client->get('https://example.com/feed', 3);
			$this->fail('Redirect loop was accepted');
		}
		catch (\RuntimeException $error)
		{
			$this->assertCount(6, $client->requests);
		}
	}

	public function test_acp_duplicate_zero_and_final_batch()
	{
		$module = new \ger\feedpostbot\acp\main_module();
		$method = new \ReflectionMethod($module, 'validate_feed');
		$method->setAccessible(true);
		$state = array(array('url' => 'https://example.com/feed'));
		$this->assertFalse($method->invoke($module, $state, 'https://example.com/feed'));
		$this->assertTrue($method->invoke($module, $state, 'https://example.com/feed', 0));
		$this->assertFalse($method->invoke($module, $state, 'https://example.com/feed', 1));
	}

	public function test_numeric_boundaries()
	{
		foreach (array('0', '-1', '61', 'abc', '1.5') as $value)
		{
			$this->assertFalse(\ger\feedpostbot\classes\driver::valid_number($value, 1, 60));
		}
		$this->assertTrue(\ger\feedpostbot\classes\driver::valid_number('1', 1, 60));
		$this->assertTrue(\ger\feedpostbot\classes\driver::valid_number('60', 1, 60));
	}

	public function test_encoded_feed_title_stays_safe_after_entity_decoding()
	{
		$driver = (new \ReflectionClass(\ger\feedpostbot\classes\driver::class))->newInstanceWithoutConstructor();
		foreach (array('&lt;img src=x onerror=alert(1)&gt;News', '&amp;lt;img src=x onerror=alert(1)&amp;gt;News',
			'&lt;script&gt;alert(1)&lt;/script&gt;News', 'Tom &amp; Jerry') as $title)
		{
			$clean = $driver->clean_title($driver->prop_to_string($title));
			$this->assertStringNotContainsString('<', $clean);
			$this->assertStringNotContainsString('>', $clean);
		}
	}

	public function test_request_entities_and_relative_feed_links()
	{
		$driver = (new \ReflectionClass(http_driver_fixture::class))->newInstanceWithoutConstructor();
		$driver->client = new http_client_fixture();
		$driver->client->responses = array(array('status' => 200, 'location' => '',
			'body' => '<rss version="2.0"><channel><title>Test</title><item><title>Entry</title><link>/entry</link></item></channel></rss>'));
		$feed = $driver->get_simplepie_instance('https://example.com/feed?a=1&amp;b=2');
		$this->assertSame('https://example.com/feed?a=1&b=2', $driver->client->requests[0][0]);
		$this->assertSame('https://example.com/entry', $feed->get_items()[0]->get_permalink());
		$this->assertCount(1, $driver->client->requests);
	}
}

class http_driver_fixture extends \ger\feedpostbot\classes\driver
{
	public $client;
	protected function create_http_client()
	{
		return $this->client;
	}
}

class http_client_fixture extends \ger\feedpostbot\classes\http_client
{
	public $addresses = array('8.8.8.8');
	public $responses = array();
	public $requests = array();
	public $resolutions = 0;

	protected function resolve($host)
	{
		$this->resolutions++;
		return $this->addresses;
	}

	protected function request($url, $ip, $timeout)
	{
		$this->requests[] = array($url, $ip, $timeout);
		return array_shift($this->responses);
	}
}
