<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

class template_test extends \phpbb_test_case
{
	public function test_template_compiles_and_escapes_attribute_values()
	{
		$loader = new \Twig\Loader\ArrayLoader(array(
			'body' => file_get_contents(__DIR__ . '/../adm/style/acp_feedpostbot_body.html'),
			'overall_header.html' => '', 'overall_footer.html' => '',
		));
		$twig = new \Twig\Environment($loader, array('autoescape' => false));
		$twig->setLexer(new \phpbb\template\twig\lexer($twig));
		$twig->addTokenParser(new \phpbb\template\twig\tokenparser\includeparser());
		$twig->addFunction(new \Twig\TwigFunction('lang', function ($key) {
			return $key === 'SUBMIT' ? 'Save "changes"' : $key;
		}));
		$html = $twig->render('body', array('loops' => array('feeds' => array(array(
			'ID' => 0, 'URL' => 'https://example.com/feed?a=1&b="two"', 'TYPE' => 'rss', 'S_UPDATE_EXISTING' => true, 'S_UPDATE_REPLY' => false,
		)))));
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try
		{
			$document->loadHTML($html);
			$xpath = new \DOMXPath($document);
			$buttons = $xpath->query('//input[@type="submit"]');
			$this->assertSame(4, $buttons->length);
			foreach ($buttons as $button)
			{
				$this->assertSame('Save "changes"', $button->getAttribute('value'));
			}
			$this->assertSame('https://example.com/feed?a=1&b="two"', $xpath->query('//input[@name="0_url"]')->item(0)->getAttribute('value'));
			$this->assertSame('checked', $xpath->query('//input[@name="0_update_existing"]')->item(0)->getAttribute('checked'));
			$this->assertFalse($xpath->query('//input[@name="0_update_reply"]')->item(0)->hasAttribute('checked'));
			$this->assertSame(1, $xpath->query('//input[@name="0_recreate_deleted"]')->length);
		}
		finally
		{
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}
}
