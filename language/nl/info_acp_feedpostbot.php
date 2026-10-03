<?php
/**
 *
 * Feed post bot. An extension for the phpBB Forum Software package.
 * [Dutch]
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
	'FPB_ACP_FORUM_ID_EXPLAIN'			=> 'Forum waar nieuwe feed berichten in geplaatst worden.',
	'FPB_ACP_SETTINGS_EXPLAIN'			=> 'Je kunt RSS, ATOM en RDF feeds toevoegen met onderstaand formulier. Begin met het toevoegen van een feed URL. Als je feeds toegevoegd hebt, wordt een tabel met deze parameters getoond:',
	'FPB_ACP_FEEDPOSTBOT_SETTING_SAVED'	=> 'Feed post bot instellingen opgeslagen',
	'FPB_ACP_FEEDPOSTBOT_TITLE'			=> 'Feed post bot',
	'FPB_ACP_FETCHED_ITEMS'             => array(
		1	=> 'Alle feeds verwerkt; %d nieuw bericht geplaatst.',
		2	=> 'Alle feeds verwerkt; %d nieuwe berichten geplaatst.',
	),
	'FPB_ACP_NO_FETCHED_ITEMS'          => 'Geen (nieuwe) items om te verwerken',
	'FPB_ADD_FEED'						=> 'Feed toevoegen',
	'FPB_CONFIGURED_FEEDS'				=> 'Geconfigureerde feeds',
	'FPB_APPEND_LINK'					=> 'Link toevoegen',
	'FPB_APPEND_LINK_EXPLAIN'			=> 'Voeg een link naar de bron van het feedbericht toe.',
	'FPB_CRON_FREQUENCY'				=> 'Interval voor automatisch verwerken van feeds',
	'FPB_CRON_FREQUENCY_EXPLAIN'		=> 'Stel het aantal seconden in tussen automatische verwerkingen van feeds. Stel in op 0 om automatisch ophalen uit te schakelen.<br />Let op: Automatische uitvoering is afhankelijk van phpBB’s cron-systeem. Als "Periodieke taken via het besturingssysteem-cron uitvoeren" is ingeschakeld in de forumconfiguratie, zorg er dan voor dat uw systeem-cron regelmatig bin/phpbbcli.php cron:run aanroept. <a href="https://www.phpbb.com/customise/db/extension/feedpostbot/faq/2446" target="_blank" rel="noopener">Lees meer in de FAQ</a>.',
	'FPB_ENABLE_LOGS'					=> 'Ophalen van feeds loggen',
	'FPB_ENABLE_LOGS_EXPLAIN'			=> 'Log een vermelding in het beheerderslogboek telkens wanneer een feed wordt opgehaald. Laat uitgeschakeld om het logboek niet te overbelasten.',
	'FPB_CURDATE'						=> 'Lokale datum/tijd',
	'FPB_CURDATE_EXPLAIN'				=> 'Vink aan om moment van verwerken als berichttijd op te slaan. Laat uit om de publicatiedatum van de feed als berichttijd op te slaan.',
	'FPB_FETCH_ALL_FEEDS'				=> 'Verwerk alle feeds handmatig',
	'FPB_FEED_TYPE'						=> 'Feed type',
	'FPB_FEED_TYPE_EXPLAIN'				=> 'Het gedetecteerde feedformaat (ATOM, RDF of RSS), automatisch bepaald door de feed engine.',
	'FPB_FEED_URL'						=> 'Feed-URL',
	'FPB_FEED_URL_EXPLAIN'				=> 'De URL naar de daadwerkelijke feed, bijv. <code>https://www.phpbb.com/feeds/rss/</code>. Elke feed-URL moet uniek zijn',
	'FPB_FEED_URL_INVALID'				=> 'Ongeldige feed-URL. Dit kan het gevolg zijn van een dubbele URL in uw feedlijst of gewoon een URL die niet voldoet aan de specificaties',
	'FPB_FEED_URL_PLACEHOLDER'			=> 'https://www.phpbb.com/feeds/rss/',
	'FPB_FEEDS'                         => 'Feeds',
	'FPB_LOCKED_EXPLAIN'                => 'Het verwerken van de feeds is gestart maar nog niet voltooid en kan daarom niet opnieuw gestart worden. Als dit aanhoudt kunt u het proces vrijgeven door op deze knop te klikken',
	'FPB_LOG_FEED_ERROR'				=> 'XML error in feed bron<br />» %1$s<br />» %2$s',
	'FPB_LOG_FEED_FETCHED'				=> 'Feed opgehaald<br />» %s',
	'FPB_PREFIX'						=> 'Onderwerp voorvoegsel',
	'FPB_PREFIX_EXPLAIN'				=> 'U kunt er voor kiezen een voorvoegsel voor uw onderwerpen te plaatsen, bijv. "[phpBB RSS]". Laat leeg voor geen voorvoegsel.',
	'FPB_NO_FEEDS'						=> 'Er zijn nog geen feeds.',
	'FPB_READ_MORE'						=> 'Lees meer',
	'FPB_REQUIRE_URL_FOPEN'				=> 'Voor het downloaden van feeds is cURL of de PHP-instelling allow_url_fopen vereist. Geen van beide is beschikbaar op deze server.',
	'FPB_SOURCE'						=> 'Bron:',
	'FPB_TEXTLIMIT'						=> 'Tekstlimiet',
	'FPB_TEXTLIMIT_EXPLAIN'				=> 'De ruwe tekst van de feed zal gelimiteerd worden tot het opgegeven aantal tekens. Woorden worden niet afgekapt en eventuele HTML-fouten die hierdoor ontstaan worden gerepareerd alvorens de BBcode conversie plaatsvind. Er wordt een “Lees meer” link toegevoegd. <br> Stel op 0 in om deze functie uit te schakelen.',
	'FPB_TIMEOUT'						=> 'Time-out',
	'FPB_TIMEOUT_EXPLAIN'				=> 'De tijd die gewacht wordt op antwoord van de feed URL. Indien deze tijd verstreken is zonder antwoord wordt het verzoek afgebroken.',
	'FPB_MAX_NEW_ITEMS'					=> 'Max. nieuwe onderwerpen per run',
	'FPB_MAX_NEW_ITEMS_EXPLAIN'			=> 'Bovengrens voor het aantal nog niet eerder geziene feeditems dat in één run een nieuw onderwerp mag aanmaken vanuit deze feed. Bevat de feed meer nieuwe items, dan worden de items die het hoogst in de feed staan (meestal de nieuwste) geplaatst en worden de overige, oudere items als verwerkt gemarkeerd; die worden ook later niet geplaatst. Stel op 0 in voor geen limiet.',
	'FPB_USER_ID'						=> 'Feed gebruiker id',
	'FPB_USER_ID_EXPLAIN'				=> 'De id van de gebruiker op wiens naam de nieuwe berichten geplaatst worden.',
	'FPB_SETTINGS_INVALID'				=> 'Kies een bestaande gebruiker en een forum voor berichten (of schakel de feed uit). De time-out moet 1–60 seconden zijn, de tekstlimiet 0–1000000 en het automatische interval 0–31536000 seconden.',
	'FPB_PROCESSING_FAILED'				=> 'Deze feed kon niet worden verwerkt. Controleer de bestemming en probeer opnieuw.',
	'FPB_HTTP_UNSAFE'					=> 'De feed-URL verwijst naar een onveilige of niet-ondersteunde bestemming.',
	'FPB_HTTP_FAILED'					=> 'De feed kon niet worden gedownload binnen de aanvraag- en groottelimieten.',
	'FPB_HTTP_TIMEOUT'					=> 'De aanvraag voor de feed heeft de tijdslimiet overschreden.',
	'FPB_SAVE_CONFLICT'					=> 'De feeds zijn gewijzigd of worden verwerkt. Vernieuw deze pagina en probeer opnieuw op te slaan.',
	'FPB_LOCK_ACTIVE'					=> 'De verwerking van feeds kan nog bezig zijn. Achtergebleven vergrendelingen worden na een uur automatisch hersteld.',
));
