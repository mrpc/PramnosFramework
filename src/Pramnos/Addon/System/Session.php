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
        if (isset($_SERVER['HTTP_CF_IPCOUNTRY'])) {
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
            } catch (Exception $ex) {
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
                $session->cookieset('logged', true);
                $session->cookieset('uid', $_SESSION['uid']);
                $session->cookieset('username', @$_SESSION['username']);
                $session->cookieset('auth', @$_SESSION['auth']);
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
            if (preg_match("/Yahoo/i", $agent)) {
                $uname = "Slurp (Yahoo Bot)";
            } elseif (preg_match("/googlebot/i", $agent)) {
                $uname = "Googlebot";
            } elseif (preg_match("/facebookexternalhit/i", $agent)) {
                $uname = "Facebook Ext";
            } elseif (preg_match("/Feedburner/i", $agent)) {
                $uname = "Feedburner";
            } elseif (preg_match("/Feedfetcher/i", $agent)) {
                $uname = "Feedfetcher (Google)";
            } elseif (preg_match("/msnbot/i", $agent)) {
                $uname = "MSNBot";
            } elseif (preg_match("/Baiduspider/i", $agent)) {
                $uname = "Baiduspider";
            } elseif (preg_match("/Freecrawl/i", $agent)) {
                $uname = "Freecrawl";
            } elseif (preg_match("/AdsBot-Google/i", $agent)) {
                $uname = "Google AdsBot";
            } elseif (preg_match("/ia_archiver/i", $agent)) {
                $uname = "AlexaBot";
            } elseif (preg_match("/Mediapartners/i", $agent)) {
                $uname = "Mediapartners (Adsense)";
            } elseif (preg_match("/SnapPreviewBot/i", $agent)) {
                $uname = "Snap Preview Bot";
            } elseif (preg_match("/Speedy Spider/i", $agent)) {
                $uname = "Speedy Spider";
            } elseif (preg_match("/pramnos/i", $agent)) {
                $uname = "Pramnos.net Toolbar";
            } elseif (preg_match("/IRLbot/i", $agent)) {
                $uname = "IRLbot";
            } elseif (preg_match("/BuzzSumo/i", $agent)) {
                $uname = "BuzzSumo Bot";
            } elseif (preg_match("/Gigabot/i", $agent)) {
                $uname = "Gigabot";
            } elseif (preg_match("/J12bot/i", $agent)) {
                $uname = "MJ12bot";
            } elseif (preg_match("/InternetSeer/i", $agent)) {
                $uname = "InternetSeer";
            } elseif (preg_match("/Twitterbot/i", $agent)) {
                $uname = "Twitter Bot";
            } elseif (preg_match("/Applebot/i", $agent)) {
                $uname = "Apple Bot (Siri/Spotlight Suggestions)";//
            } elseif ($remoteip == "62.1.217.20" and $agent == "") {
                $uname = "Pramnos Hosting Balrog Server ";
            } elseif ($remoteip == "109.169.27.9"
                || $remoteip == '95.154.242.224') {
                $uname = "Pramnos Hosting Balrog Server ";
            } elseif ($remoteip == "141.101.93.72") {
                $uname = "Nannuka Cron Bot";
            } elseif (preg_match("/ahrefs/i", $agent)) {
                $uname = "ahrefs.com Crawler";
            } elseif (preg_match("/JoobleBot/i", $agent)) {
                $uname = "Jooble Bot";
            } elseif (preg_match("/Mechanize/i", $agent)) {
                $uname = "Mechanize Bot";
            } elseif (preg_match("/Qwantify/i", $agent)) {
                $uname = "Qwantify Bot";
            } elseif (preg_match("/bingbot/i", $agent)) {
                $uname = "Bing Bot";
            } elseif (preg_match("/affiliatecoach/i", $agent)) {
                $uname = "affiliatecoach.net crawler";
            } elseif (preg_match("/Curl/i", $agent)) {
                $uname = "Unknown Curl Bot";
            } elseif (preg_match("/HTTP_Request2/i", $agent)) {
                $uname = "Unknown HTTP_Request2 (PHP) Bot";
            } elseif (preg_match("/YandexBot/i", $agent)) {
                $uname = "Yandex Bot";
            } elseif (preg_match("/spbot/i", $agent)) {
                $uname = "spbot (OpenLinkProfiler.org Bot)";
            } elseif (preg_match("/Go 1.1 package http/i", $agent)) {
                $uname = "Unknown Go bot";
            }




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
            $session->reset();
            $auth->logout();
            $guest = 1;
            $uname = "SecurityLogout";
        }
    }

}
