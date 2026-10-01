<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\classes;

/** Fetch HTTP feeds without delegating DNS or redirects to the parser. Local and private hosts are allowed. */
class http_client
{
	public const MAX_BYTES = 5242880;
	public const MAX_REDIRECTS = 5;
	public const MAX_TIMEOUT = 60;
	protected $final_url;

	public function get_final_url()
	{
		return $this->final_url;
	}

	/** Validate syntax: HTTP(S), no credentials, a canonical IP address or a host name. */
	public static function valid_url($url)
	{
		if (!is_string($url) || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || !filter_var($url, FILTER_VALIDATE_URL))
		{
			return false;
		}
		$parts = parse_url($url);
		if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true) || isset($parts['user']) || isset($parts['pass']))
		{
			return false;
		}
		$host = strtolower(trim($parts['host'], '[]'));
		if (filter_var($host, FILTER_VALIDATE_IP))
		{
			return true;
		}
		// Host names, including single-label ones such as localhost. Abbreviated, integer,
		// hexadecimal and octal IPv4 representations are still rejected: the last label must
		// start with a letter.
		return (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z][a-z0-9-]*\.?$/iD', $host);
	}

	/** Resolve once and pin the address, so every request of a redirect step uses one address. */
	protected function resolve($host)
	{
		if (filter_var($host, FILTER_VALIDATE_IP))
		{
			return array($host);
		}
		$addresses = array();
		$records = @dns_get_record($host, DNS_A | DNS_AAAA);
		foreach ($records ?: array() as $record)
		{
			if (isset($record['ip']))
			{
				$addresses[] = $record['ip'];
			}
			else if (isset($record['ipv6']))
			{
				$addresses[] = $record['ipv6'];
			}
		}
		if (!$addresses)
		{
			// Names such as localhost usually come from the hosts file, not from DNS.
			$addresses = @gethostbynamel($host) ?: array();
		}
		return array_unique($addresses);
	}

	/** @return string Feed bytes. Throws on invalid URLs or failed requests. */
	public function get($url, $timeout)
	{
		$deadline = microtime(true) + max(1, min(self::MAX_TIMEOUT, (int) $timeout));
		for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++)
		{
			if (!self::valid_url($url))
			{
				throw new \RuntimeException('FPB_HTTP_UNSAFE');
			}
			$host = trim(parse_url($url, PHP_URL_HOST), '[]');
			$addresses = $this->resolve($host);
			if (!$addresses)
			{
				throw new \RuntimeException('FPB_HTTP_FAILED');
			}
			$remaining = $deadline - microtime(true);
			if ($remaining <= 0)
			{
				throw new \RuntimeException('FPB_HTTP_TIMEOUT');
			}
			$response = $this->request($url, reset($addresses), $remaining);
			if (in_array($response['status'], array(301, 302, 303, 307, 308), true) && !empty($response['location']))
			{
				$url = \SimplePie\Misc::absolutize_url($response['location'], $url);
				continue;
			}
			if ($response['status'] < 200 || $response['status'] >= 300)
			{
				throw new \RuntimeException('FPB_HTTP_FAILED');
			}
			$this->final_url = $url;
			return $response['body'];
		}
		throw new \RuntimeException('FPB_HTTP_FAILED');
	}

	/** @return array HTTP response from the pinned address, without automatic redirects. */
	protected function request($url, $ip, $timeout)
	{
		$parts = parse_url($url);
		$host = trim($parts['host'], '[]');
		$port = isset($parts['port']) ? $parts['port'] : (strtolower($parts['scheme']) === 'https' ? 443 : 80);
		$response = array('status' => 0, 'location' => '', 'body' => '');
		if (function_exists('curl_init'))
		{
			$curl = curl_init($url);
			$options_set = curl_setopt_array($curl, array(
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_PROXY => '',
				CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
				CURLOPT_TIMEOUT_MS => max(1, (int) ($timeout * 1000)),
				CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ($timeout * 1000)),
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_USERAGENT => 'FeedPostBot/1.1',
				CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$response) {
					if (stripos($line, 'Location:') === 0)
					{
						$response['location'] = trim(substr($line, 9));
					}
					return strlen($line);
				},
				CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$response) {
					if (strlen($response['body']) + strlen($chunk) > self::MAX_BYTES)
					{
						return 0;
					}
					$response['body'] .= $chunk;
					return strlen($chunk);
				},
			));
			try
			{
				$resolve_host = (strpos($ip, ':') !== false) ? '[' . $ip . ']' : $ip;
				if (!$options_set || (!filter_var($host, FILTER_VALIDATE_IP) && !curl_setopt($curl, CURLOPT_RESOLVE, array($host . ':' . $port . ':' . $resolve_host))))
				{
					throw new \RuntimeException('FPB_HTTP_FAILED');
				}
				if (curl_exec($curl) === false)
				{
					throw new \RuntimeException(curl_errno($curl) === CURLE_OPERATION_TIMEDOUT ? 'FPB_HTTP_TIMEOUT' : 'FPB_HTTP_FAILED');
				}
				$response['status'] = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
			}
			finally
			{
				curl_close($curl);
			}
			return $response;
		}

		// The stream wrapper connects to the numeric address; TLS still verifies the original hostname.
		$pinned_url = $parts['scheme'] . '://' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip) . ':' . $port
			. (isset($parts['path']) ? $parts['path'] : '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
		$context = stream_context_create(array(
			'http' => array('method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => $timeout,
				'header' => 'Host: ' . $parts['host'] . ':' . $port . "\r\nConnection: close\r\n", 'user_agent' => 'FeedPostBot/1.1'),
			'ssl' => array('peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true),
		));
		$deadline = microtime(true) + $timeout;
		$stream = @fopen($pinned_url, 'rb', false, $context);
		if (!$stream)
		{
			throw new \RuntimeException('FPB_HTTP_FAILED');
		}
		try
		{
			$metadata = stream_get_meta_data($stream);
			foreach ($metadata['wrapper_data'] as $header)
			{
				if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $match))
				{
					$response['status'] = (int) $match[1];
				}
				else if (stripos($header, 'Location:') === 0)
				{
					$response['location'] = trim(substr($header, 9));
				}
			}
			while (!feof($stream))
			{
				$remaining = $deadline - microtime(true);
				if ($remaining <= 0)
				{
					throw new \RuntimeException('FPB_HTTP_TIMEOUT');
				}
				stream_set_timeout($stream, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
				$chunk = fread($stream, 8192);
				$metadata = stream_get_meta_data($stream);
				if ($metadata['timed_out'])
				{
					throw new \RuntimeException('FPB_HTTP_TIMEOUT');
				}
				if ($chunk === false || strlen($response['body']) + strlen($chunk) > self::MAX_BYTES)
				{
					throw new \RuntimeException('FPB_HTTP_FAILED');
				}
				$response['body'] .= $chunk;
			}
		}
		finally
		{
			fclose($stream);
		}
		return $response;
	}
}
