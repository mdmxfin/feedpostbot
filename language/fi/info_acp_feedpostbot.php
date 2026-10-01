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
	'FPB_ACP_FORUM_ID'					=> 'Syötteen keskustelualue',
	'FPB_ACP_FORUM_ID_EXPLAIN'			=> 'Keskustelualue, jolle uudet syötemerkinnät julkaistaan.',
	'FPB_ACP_SETTINGS_EXPLAIN'			=> 'Voit lisätä RSS-, ATOM- tai RDF-syötteitä alla olevalla lomakkeella. Aloita lisäämällä syötteen URL-osoite. Kun olet lisännyt syötteitä, näet taulukon, jossa on seuraavat asetukset:',
	'FPB_ACP_FEEDPOSTBOT_SETTING_SAVED'	=> 'Feed post botin asetukset tallennettu',
	'FPB_ACP_FEEDPOSTBOT_TITLE'			=> 'Feed post bot',
	'FPB_ACP_FETCHED_ITEMS'             => array(
		1	=> 'Kaikki syötteet käsitelty: %d uusi aihe luotu.',
		2	=> 'Kaikki syötteet käsitelty: %d uutta aihetta luotu.',
	),
	'FPB_ACP_NO_FETCHED_ITEMS'          => 'Kaikki syötteet käsitelty: uusia aiheita ei luotu.',
	'FPB_ADD_FEED'						=> 'Lisää syöte',
	'FPB_CONFIGURED_FEEDS'				=> 'Määritetyt syötteet',
	'FPB_APPEND_LINK'					=> 'Lisää linkki',
	'FPB_APPEND_LINK_EXPLAIN'			=> 'Lisää linkki syötemerkinnän lähteeseen',
	'FPB_CRON_FREQUENCY'				=> 'Automaattisen syötekäsittelyn aikaväli',
	'FPB_CRON_FREQUENCY_EXPLAIN'		=> 'Aseta automaattisten syötekäsittelyjen välinen aika sekunteina. Arvo 0 poistaa automaattisen haun käytöstä.<br />Huom: Automaattinen suoritus perustuu phpBB:n cron-järjestelmään. Jos keskustelupalstan asetuksissa on käytössä ”Suorita ajoitetut tehtävät käyttöjärjestelmän cronin kautta”, varmista, että järjestelmän cron suorittaa komennon bin/phpbbcli.php cron:run säännöllisesti. <a href="https://www.phpbb.com/customise/db/extension/feedpostbot/faq/2446" target="_blank" rel="noopener">Lue lisää usein kysytyistä kysymyksistä</a>.',
	'FPB_ENABLE_LOGS'					=> 'Ota hakujen lokitus käyttöön',
	'FPB_ENABLE_LOGS_EXPLAIN'			=> 'Kirjaa ylläpitäjän lokiin merkintä aina, kun syöte haetaan. Pidä tämä pois käytöstä, jotta tietokannan lokit eivät kasva liikaa.',
	'FPB_CURDATE'						=> 'Paikallinen päivämäärä/aika',
	'FPB_CURDATE_EXPLAIN'				=> 'Valitse, jos viestin ajaksi merkitään syötteen hakuaika. Poista valinta, jos viestin ajaksi merkitään syötteen PubDate-aika.',
	'FPB_FETCH_ALL_FEEDS'				=> 'Hae kaikki syötteet käsin',
	'FPB_FEED_TYPE'						=> 'Syötteen tyyppi',
	'FPB_FEED_TYPE_EXPLAIN'				=> 'Tunnistettu syötemuoto (ATOM, RDF tai RSS), jonka syötemoottori määrittää automaattisesti.',
	'FPB_FEED_URL'						=> 'Syötteen URL-osoite',
	'FPB_FEED_URL_EXPLAIN'				=> 'Varsinaisen syötteen URL-osoite, esim. <code>https://www.phpbb.com/feeds/rss/</code>. Kunkin syötteen URL-osoitteen on oltava yksilöllinen',
	'FPB_FEED_URL_INVALID'				=> 'Virheellinen syötteen URL-osoite. Syynä voi olla, että syöte on jo luettelossa, tai osoite ei täytä vaatimuksia',
	'FPB_FEED_URL_PLACEHOLDER'			=> 'https://www.phpbb.com/feeds/rss/',
	'FPB_FEEDS'                         => 'Syötteet',
	'FPB_LOCKED_EXPLAIN'                => 'Syötteiden käsittely on alkanut mutta ei ole päättynyt, joten sitä ei voi käynnistää uudelleen. Jos tilanne jatkuu, voit vapauttaa käsittelyn tällä painikkeella',
	'FPB_LOG_FEED_ERROR'				=> 'XML-virhe syötteen lähteessä<br />» %1$s<br />» %2$s',
	'FPB_LOG_FEED_FETCHED'				=> 'Syöte haettu<br />» %s',
	'FPB_PREFIX'						=> 'Aiheen etuliite',
	'FPB_PREFIX_EXPLAIN'				=> 'Voit lisätä aiheisiin etuliitteen, esim. ”[phpBB RSS]”. Jätä tyhjäksi, jos et halua etuliitettä.',
	'FPB_NO_FEEDS'						=> 'Syötteitä ei ole vielä lisätty.',
	'FPB_READ_MORE'						=> 'Lue lisää',
	'FPB_REQUIRE_URL_FOPEN'				=> 'Syötteiden lataaminen vaatii joko cURLin tai PHP-asetuksen allow_url_fopen. Kumpikaan ei ole käytettävissä tällä palvelimella.',
	'FPB_SOURCE'						=> 'Lähde:',
	'FPB_TEXTLIMIT'						=> 'Tekstin enimmäispituus',
	'FPB_TEXTLIMIT_EXPLAIN'				=> 'Syötteen teksti rajataan annettuun merkkimäärään. Huomaa, että rajaus tehdään syötteen raakatekstiin ja sanat säilyvät kokonaisina. Sen jälkeen syötteen rikkinäinen HTML korjataan ja muunnetaan BBCodeksi, ja loppuun lisätään ”Lue lisää” -linkki. Raja on siksi vain suuntaa antava valmiin viestin tekstille. <br> Arvo 0 poistaa tekstin rajauksen käytöstä.',
	'FPB_TIMEOUT'						=> 'Aikakatkaisu',
	'FPB_TIMEOUT_EXPLAIN'				=> 'Syötteen URL-osoitteeseen tehtävän pyynnön aikakatkaisu. Jos syötteen sisältöä ei saada tämän ajan kuluessa, pyyntö perutaan.',
	'FPB_USER_ID'						=> 'Syötteen käyttäjän ID',
	'FPB_USER_ID_EXPLAIN'				=> 'Sen käyttäjän ID, jonka nimissä uudet merkinnät julkaistaan.',
	'FPB_SETTINGS_INVALID'				=> 'Valitse olemassa oleva käyttäjä ja keskustelualue, jolle voi julkaista viestejä (tai poista syöte käytöstä). Aikakatkaisun on oltava 1–60 sekuntia, tekstin enimmäispituuden 0–1000000 ja automaattisen aikavälin 0–31536000 sekuntia.',
	'FPB_PROCESSING_FAILED'				=> 'Tätä syötettä ei voitu käsitellä. Tarkista kohde ja yritä uudelleen.',
	'FPB_HTTP_UNSAFE'					=> 'Syötteen URL-osoite johtaa vaaralliseen tai ei-tuettuun kohteeseen.',
	'FPB_HTTP_FAILED'					=> 'Syötettä ei voitu ladata pyynnön aika- ja kokorajoissa.',
	'FPB_HTTP_TIMEOUT'					=> 'Syötteen pyyntö aikakatkaistiin.',
	'FPB_SAVE_CONFLICT'					=> 'Syötteet muuttuivat tai käsittely on käynnissä. Lataa sivu uudelleen ja yritä tallentaa uudelleen.',
	'FPB_LOCK_ACTIVE'					=> 'Syötteiden käsittely voi olla yhä käynnissä. Hylätyt lukot vapautetaan automaattisesti tunnin kuluttua.',
));
