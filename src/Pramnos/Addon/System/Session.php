<?php
namespace Pramnos\Addon\System;
/**
 * Session manager addon
 * @package     PramnosFramework
 * @copyright   2005 - 2020 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */

class Session extends \Pramnos\Addon\Addon
{
    /**
     * This runs on EVERY application execution
     */
    public function onAppInit()
    {
        $uid = 'NULL';
        $guest = 1;
        $sid = md5(session_id());
        $uname = "Anonymous";
        $database = \Pramnos\Framework\Factory::getDatabase();
        $session = \Pramnos\Framework\Factory::getSession();
        $auth = \Pramnos\Framework\Factory::getAuth();
        $app = \Pramnos\Application\Application::getInstance();
        $request = \Pramnos\Framework\Factory::getRequest();
        $past = time() - 300;
        $sql = $database->prepareQuery(
            "DELETE FROM `#PREFIX#sessions` where `time` < %d", $past
        );
        try {
            $database->query($sql);
        } catch (\Exception $exc) {
            \Pramnos\Logs\Logger::log($exc->getMessage());
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $agent = $_SERVER['HTTP_USER_AGENT'];
        } else {
            $agent = '';
        }
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $remoteip = $_SERVER['REMOTE_ADDR'];
        } else {
            $remoteip = '';
        }
        $cloudflareip = '';
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cloudflareip = $remoteip;
            $remoteip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        $country = '';
        if (isset($_SERVER['HTTP_CF_IPCOUNTRY']) 
            && is_string($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            $country = strip_tags($_SERVER['HTTP_CF_IPCOUNTRY']);
        }
        $language = '';
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $languages = explode(",", $_SERVER['HTTP_ACCEPT_LANGUAGE']);
            $language = $languages[0];
        }
        //For localhost
        if ($remoteip == '192.168.2.1'
            && (!defined('UNITTESTING')
                || (defined('UNITTESTING') && UNITTESTING == false)
                )) {
            try {
                $opts = array(
                    'http' => array(
                        'method'  => 'GET',
                        'timeout' => 5
                    )
                );
                $context  = stream_context_create($opts);
                $remoteip = @file_get_contents(
                    'https://api.ipify.org', false, $context
                );
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::log($ex->getMessage());
            }

        }

        if (isset($_SESSION['visitorid'])) {
            unset($_SESSION['visitorid']);
        }

        //Here we set a unique visitor id to be used for stats etc
        if ($request->cookieget('visitorid') !== null
            && $request->cookieget('visitorid') != '') {
            $visitorid = $request->cookieget('visitorid');
            $_SESSION['visitorid'] = $visitorid;
        } else {
            $visitorid = substr(
                md5($remoteip.$agent.$country.$language), 0, 16
            );
            $request->cookieset('visitorid', $visitorid);
            $_SESSION['visitorid'] = $visitorid;
        }


        //Last seen time -- needed for stats
        if ($request->cookieget('lastseen') !== null
            && (int)$request->cookieget('lastseen') > 0) {
            $lastseen = (int)$request->cookieget('lastseen');
        } else {
            $lastseen = 0;
        }
        $request->cookieset('lastseen', time());



        $url = $request->getURL(false);
        if (isset($_SESSION['logged']) && $_SESSION['logged'] == true) {
            $guest = 0;
            if (isset($_SESSION['uid'])) {
                $uid = $_SESSION['uid'];
            } else {
                $uid = 'NULL';
            }
            if (isset($_SESSION['username'])) {
                $uname = $_SESSION['username'];
            } else {
                $uname = 'annonymous';
            }
            if ($request->cookieget('auth') !== null
                && $request->cookieget('username') !== null
                && isset($_SESSION['uid']) && (int) $_SESSION['uid'] > 1) {
                $request->cookieset('logged', true);
                $request->cookieset('uid', $_SESSION['uid']);
                $request->cookieset('username', @$_SESSION['username']);
                $request->cookieset('auth', @$_SESSION['auth']);
            }
        } elseif ($request->cookieget('auth') !== null
            && $request->cookieget('username') !== null) {
            $auth->authCheck();
            // ΕΔΩ ΜΠΟΡΕΙ ΝΑ ΜΠΕΙ ΤΟ FACEBOOK LOGIN
        } else {
            $guest = 1;
            $uid = 'NULL';
            $session->reset();
            $uname = "Anonymous";
            
            // Bot detection optimization - using array of patterns
            $botPatterns = [
                '/Yahoo/i' => 'Slurp (Yahoo Bot)',
                '/googlebot/i' => 'Googlebot',
                '/facebookexternalhit/i' => 'Facebook Ext',
                '/Feedburner/i' => 'Feedburner',
                '/Feedfetcher/i' => 'Feedfetcher (Google)',
                '/msnbot/i' => 'MSNBot',
                '/Baiduspider/i' => 'Baiduspider',
                '/Freecrawl/i' => 'Freecrawl',
                '/AdsBot-Google/i' => 'Google AdsBot',
                '/ia_archiver/i' => 'AlexaBot',
                '/Mediapartners/i' => 'Mediapartners (Adsense)',
                '/SnapPreviewBot/i' => 'Snap Preview Bot',
                '/Speedy Spider/i' => 'Speedy Spider',
                '/IRLbot/i' => 'IRLbot',
                '/BuzzSumo/i' => 'BuzzSumo Bot',
                '/Gigabot/i' => 'Gigabot',
                '/MJ12bot/i' => 'Majestic-12 Bot',
                '/InternetSeer/i' => 'InternetSeer',
                '/Twitterbot/i' => 'Twitter Bot',
                '/Applebot/i' => 'Apple Bot (Siri/Spotlight Suggestions)',
                '/AhrefsBot/i' => 'Ahrefs Bot',
                '/JoobleBot/i' => 'Jooble Bot',
                '/Mechanize/i' => 'Mechanize Bot',
                '/Qwantify/i' => 'Qwantify Bot',
                '/bingbot/i' => 'Bing Bot',
                '/affiliatecoach/i' => 'affiliatecoach.net crawler',
                '/Curl/i' => 'Unknown Curl Bot',
                '/HTTP_Request2/i' => 'Unknown HTTP_Request2 (PHP) Bot',
                '/YandexBot/i' => 'Yandex Bot',
                '/spbot/i' => 'spbot (OpenLinkProfiler.org Bot)',
                '/Go 1.1 package http/i' => 'Unknown Go bot',
                '/DuckDuckGo-Favicons-Bot/i' => 'DuckDuckGo Favicons Bot',
                '/SemrushBot/i' => 'SEMrush Bot',
                '/PetalBot/i' => 'Petal Bot (Huawei)',
                '/DotBot/i' => 'DotBot Crawler',
                '/Discordbot/i' => 'Discord Bot',
                '/LinkedInBot/i' => 'LinkedIn Bot',
                '/WhatsApp.*Bot/i' => 'WhatsApp Bot',
                '/CCBot(?!.*anthropic)/i' => 'Common Crawl Bot',
                '/CCBot.*anthropic/i' => 'Anthropic Claude Crawler',
                '/Sogou/i' => 'Sogou Spider',
                '/seznambot/i' => 'Seznam Bot',
                '/rogerbot/i' => 'Moz Crawler',
                '/BLEXBot/i' => 'BLEXBot Crawler',
                '/pingdom/i' => 'Pingdom Bot',
                '/Googlebot-Image/i' => 'Google Image Bot',
                '/Pinterestbot/i' => 'Pinterest Bot',
                '/Mail\.RU_Bot/i' => 'Mail.ru Bot',
                '/HeadlessChrome/i' => 'Headless Chrome',
                '/PhantomJS/i' => 'PhantomJS Bot',
                '/Lighthouse/i' => 'Google Lighthouse',
                '/TelegramBot/i' => 'Telegram Bot',
                '/GTmetrix/i' => 'GTmetrix Performance Bot',
                '/Dataprovider\.com/i' => 'Dataprovider Bot',
                '/Uptimebot/i' => 'Uptime Monitoring Bot',
                '/StatusCake/i' => 'StatusCake Bot',
                '/UptimeRobot/i' => 'UptimeRobot Bot',
                '/CloudFront/i' => 'AWS/Amazon CloudFront Bot',
                '/ApacheBench/i' => 'ApacheBench (ab) Bot',
                '/colly/i' => 'Colly Crawler',
                '/Screaming Frog SEO Spider/i' => 'Screaming Frog SEO Spider',
                '/NetcraftSurveyAgent/i' => 'Netcraft Survey Agent',
                '/ZoominfoBot/i' => 'ZoomInfo Bot',
                '/bytespider/i' => 'ByteSpider (Bytedance/TikTok Bot)',
                '/GPTBot/i' => 'OpenAI GPTBot',
                '/ChatGPT-User/i' => 'ChatGPT User Agent',
                '/Claude-Web/i' => 'Claude Web Bot',
                '/Anthropic-AI/i' => 'Anthropic AI Bot',
                '/Google-Extended/i' => 'Google Extended (Bard/Gemini)',
                '/Cohere-AI/i' => 'Cohere AI Bot',
                '/MetaAI/i' => 'Meta AI Crawler',
                '/perplexitybot/i' => 'Perplexity AI Bot',
                '/AppleNewsBot/i' => 'Apple News Bot',
                '/Siri/i' => 'Siri Bot',
                '/AppleMail/i' => 'Apple Mail Bot',
                '/amazonbot/i' => 'Amazon Bot',
                '/AmazonUIPageSpeed/i' => 'Amazon UI PageSpeed Bot',
                '/Kindle/i' => 'Kindle Bot',
                '/Alexa/i' => 'Amazon Alexa Bot',
                '/Nutch/i' => 'Apache Nutch Crawler',
                '/BingPreview/i' => 'Bing Preview Bot',
                '/WBSearchBot/i' => 'Warebay Search Bot',
                '/Archive\.org_bot/i' => 'Internet Archive Bot',
                '/XiaoMi/i' => 'Xiaomi Bot',
                '/Barkrowler/i' => 'Barkrowler Search Bot',
                '/360Spider/i' => '360Spider (Qihoo)',
                '/newspaperbot/i' => 'Newspaper Bot',
                '/wp-fastest-cache-preload/i' => 'WordPress Cache Preload Bot',
                '/W3C_Validator/i' => 'W3C Validator',
                '/SEOkicks/i' => 'SEOkicks Bot',
                '/Neevabot/i' => 'Neeva Search Bot',
                '/YisouSpider/i' => 'Yisou Spider',
                '/Storebot/i' => 'Store Bot',
                '/PagesInventory/i' => 'Pages Inventory Bot',
                '/SiteAuditBot/i' => 'Site Audit Bot',
                '/VelenPublicWebCrawler/i' => 'Velen Web Crawler',
                '/BraveBot/i' => 'Brave Search Bot',
                '/meta-externalagent/i' => 'Meta External Agent (Facebook Link Preview)',
                '/facebot/i' => 'Facebook Bot',
                '/WhatsApp Link Preview/i' => 'WhatsApp Link Preview',
                '/Slackbot-LinkExpanding/i' => 'Slack Link Expander',
                '/TelegramBot\/[0-9]/i' => 'Telegram Link Preview',
                '/Twitterbot/i' => 'Twitter Bot',
                '/Snapchat/i' => 'Snapchat Bot',
                '/vkShare/i' => 'VKontakte Share Bot',
                '/redditbot/i' => 'Reddit Bot',
                '/Skype\/[0-9]+/i' => 'Skype Link Preview',
                '/Teams\/[0-9]+/i' => 'Microsoft Teams Bot',
                '/XING-contenttabreceiver/i' => 'Xing Bot',
                '/OutlookUCBot/i' => 'Outlook Bot',
                '/Line\/[0-9]+/i' => 'LINE App Bot',
                '/viber/i' => 'Viber Bot',
                '/Mastodon\/[0-9]+/i' => 'Mastodon Link Preview',
                '/TeamsBot/i' => 'Microsoft Teams Bot',
                '/MS-TeamsBot/i' => 'Microsoft Teams Bot',
                '/Microsoft-Teams/i' => 'Microsoft Teams Link Preview'
            ];

            // Check user agent against bot patterns
            foreach ($botPatterns as $pattern => $botName) {
                if (preg_match($pattern, $agent)) {
                    $uname = $botName;
                    break;
                }
            }
        
        }

        // Truncate long strings
        if (strlen($agent) > 255) {
            $agent = substr($agent, 0, 255);
        }
        if (strlen($uname) > 128) {
            $uname = substr($uname, 0, 128);
        }




        $sessionsql = $database->prepareQuery(
            "select * from `#PREFIX#sessions` "
            . "WHERE `visitorid` = %s",
            base64_encode(hex2bin($visitorid))
        );

        $result = $database->query($sessionsql);

        if ($result->numRows != 0) {
            if ($result->fields['logout'] == "1") {

                $session->reset();
                $auth->logout();
                $guest = 1;
                $uname = "Kicked Out";
            }

        }

        if ((int) $uid == 1) {
            $uid = 'NULL';
        }

        try {
            if ($database->type == 'postgresql') {
                $sql = $database->prepareQuery(
                    "insert into `#PREFIX#sessions`
                    (`visitorid`, `uname`, `time`, `host_addr`, `guest`, `agent`,
                    `userid`, `url`,  `logout`, `sid`, `history`)
                    values
                    (%s, %s, %d, %s, %d, %s, $uid, %s,  %d, %s, '')
                    ON CONFLICT (visitorid) DO UPDATE SET
                    `uname` = %s, `time`=%d, `guest` = %d,
                    `userid` = $uid, `url` = %s,  `logout`=%d",
                    base64_encode(hex2bin($visitorid)),
                    $uname, time(), $remoteip, $guest, $agent, $url,
                    0, $sid, $uname, time(), $guest, $url, 0
                );
            } else {
                $sql = $database->prepareQuery(
                    "insert into `#PREFIX#sessions`
                    (`visitorid`, `uname`, `time`, `host_addr`, `guest`, `agent`,
                    `userid`, `url`,  `logout`, `sid`)
                    values
                    (%s, %s, %d, %s, %d, %s, $uid, %s,  %d, %s)
                    ON DUPLICATE KEY UPDATE
                    `uname` = %s, `time`=%d, `guest` = %d,
                    `userid` = $uid, `url` = %s,  `logout`=%d",
                    base64_encode(hex2bin($visitorid)),
                    $uname, time(), $remoteip, $guest, $agent, $url,
                    0, $sid, $uname, time(), $guest, $url, 0
                );
            }
            
            $database->query($sql);
        }
        catch (\Exception $e) {
            \Pramnos\Logs\Logger::log($e->getMessage());
            $session->reset();
            $auth->logout();
            $guest = 1;
            $uname = "SecurityLogout";
        }
    }

}
