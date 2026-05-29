<?php

declare(strict_types=1);

namespace Pramnos\Http\Middleware;

/**
 * Standalone bot-detection service.
 *
 * Extracted from Addon\System\Session::onAppInit() so it can be used
 * independently (analytics, rate limiting, session tracking) without pulling in
 * the full addon infrastructure.
 *
 * Usage:
 *   $detector = new BotDetector();
 *   if ($detector->isBot($_SERVER['HTTP_USER_AGENT'] ?? '')) {
 *       $name = $detector->botName($_SERVER['HTTP_USER_AGENT'] ?? '');
 *   }
 *
 */
class BotDetector
{
    /**
     * Pattern → human-readable bot name.
     *
     * Patterns are tried in order; the first match wins.
     *
     * @var array<string, string>
     */
    private static array $patterns = [
        '/Yahoo/i'                    => 'Slurp (Yahoo Bot)',
        '/googlebot/i'                => 'Googlebot',
        '/Googlebot-Image/i'          => 'Google Image Bot',
        '/facebookexternalhit/i'      => 'Facebook Ext',
        '/Feedburner/i'               => 'Feedburner',
        '/Feedfetcher/i'              => 'Feedfetcher (Google)',
        '/msnbot/i'                   => 'MSNBot',
        '/Baiduspider/i'              => 'Baiduspider',
        '/Freecrawl/i'                => 'Freecrawl',
        '/AdsBot-Google/i'            => 'Google AdsBot',
        '/ia_archiver/i'              => 'AlexaBot',
        '/Mediapartners/i'            => 'Mediapartners (Adsense)',
        '/SnapPreviewBot/i'           => 'Snap Preview Bot',
        '/Speedy Spider/i'            => 'Speedy Spider',
        '/IRLbot/i'                   => 'IRLbot',
        '/BuzzSumo/i'                 => 'BuzzSumo Bot',
        '/Gigabot/i'                  => 'Gigabot',
        '/MJ12bot/i'                  => 'Majestic-12 Bot',
        '/InternetSeer/i'             => 'InternetSeer',
        '/Twitterbot/i'               => 'Twitter Bot',
        '/Applebot/i'                 => 'Apple Bot (Siri/Spotlight Suggestions)',
        '/AhrefsBot/i'                => 'Ahrefs Bot',
        '/JoobleBot/i'                => 'Jooble Bot',
        '/Mechanize/i'                => 'Mechanize Bot',
        '/Qwantify/i'                 => 'Qwantify Bot',
        '/bingbot/i'                  => 'Bing Bot',
        '/affiliatecoach/i'           => 'affiliatecoach.net crawler',
        '/Curl/i'                     => 'Unknown Curl Bot',
        '/HTTP_Request2/i'            => 'Unknown HTTP_Request2 (PHP) Bot',
        '/YandexBot/i'                => 'Yandex Bot',
        '/spbot/i'                    => 'spbot (OpenLinkProfiler.org Bot)',
        '/Go 1.1 package http/i'      => 'Unknown Go bot',
        '/DuckDuckGo-Favicons-Bot/i'  => 'DuckDuckGo Favicons Bot',
        '/SemrushBot/i'               => 'SEMrush Bot',
        '/PetalBot/i'                 => 'Petal Bot (Huawei)',
        '/DotBot/i'                   => 'DotBot Crawler',
        '/Discordbot/i'               => 'Discord Bot',
        '/LinkedInBot/i'              => 'LinkedIn Bot',
        '/WhatsApp.*Bot/i'            => 'WhatsApp Bot',
        '/CCBot.*anthropic/i'         => 'Anthropic Claude Crawler',
        '/CCBot(?!.*anthropic)/i'     => 'Common Crawl Bot',
        '/Sogou/i'                    => 'Sogou Spider',
        '/seznambot/i'                => 'Seznam Bot',
        '/rogerbot/i'                 => 'Moz Crawler',
        '/BLEXBot/i'                  => 'BLEXBot Crawler',
        '/pingdom/i'                  => 'Pingdom Bot',
        '/Pinterestbot/i'             => 'Pinterest Bot',
        '/Mail\.RU_Bot/i'             => 'Mail.ru Bot',
        '/HeadlessChrome/i'           => 'Headless Chrome',
        '/PhantomJS/i'                => 'PhantomJS Bot',
        '/Lighthouse/i'               => 'Google Lighthouse',
        '/TelegramBot/i'              => 'Telegram Bot',
        '/GTmetrix/i'                 => 'GTmetrix Performance Bot',
        '/Dataprovider\.com/i'        => 'Dataprovider Bot',
        '/Uptimebot/i'                => 'Uptime Monitoring Bot',
        '/StatusCake/i'               => 'StatusCake Bot',
        '/UptimeRobot/i'              => 'UptimeRobot Bot',
        '/CloudFront/i'               => 'AWS/Amazon CloudFront Bot',
        '/ApacheBench/i'              => 'ApacheBench (ab) Bot',
        '/colly/i'                    => 'Colly Crawler',
        '/Screaming Frog SEO Spider/i' => 'Screaming Frog SEO Spider',
        '/NetcraftSurveyAgent/i'      => 'Netcraft Survey Agent',
        '/ZoominfoBot/i'              => 'ZoomInfo Bot',
        '/bytespider/i'               => 'ByteSpider (Bytedance/TikTok Bot)',
        '/GPTBot/i'                   => 'OpenAI GPTBot',
        '/ChatGPT-User/i'             => 'ChatGPT User Agent',
        '/Claude-Web/i'               => 'Claude Web Bot',
        '/Anthropic-AI/i'             => 'Anthropic AI Bot',
        '/Google-Extended/i'          => 'Google Extended (Bard/Gemini)',
        '/Cohere-AI/i'                => 'Cohere AI Bot',
        '/MetaAI/i'                   => 'Meta AI Crawler',
        '/perplexitybot/i'            => 'Perplexity AI Bot',
        '/AppleNewsBot/i'             => 'Apple News Bot',
        '/Siri/i'                     => 'Siri Bot',
        '/AppleMail/i'                => 'Apple Mail Bot',
        '/amazonbot/i'                => 'Amazon Bot',
        '/AmazonUIPageSpeed/i'        => 'Amazon UI PageSpeed Bot',
        '/Kindle/i'                   => 'Kindle Bot',
        '/Alexa/i'                    => 'Amazon Alexa Bot',
        '/Nutch/i'                    => 'Apache Nutch Crawler',
        '/BingPreview/i'              => 'Bing Preview Bot',
        '/WBSearchBot/i'              => 'Warebay Search Bot',
        '/Archive\.org_bot/i'         => 'Internet Archive Bot',
        '/XiaoMi/i'                   => 'Xiaomi Bot',
        '/Barkrowler/i'               => 'Barkrowler Search Bot',
        '/360Spider/i'                => '360Spider (Qihoo)',
        '/newspaperbot/i'             => 'Newspaper Bot',
        '/wp-fastest-cache-preload/i' => 'WordPress Cache Preload Bot',
        '/W3C_Validator/i'            => 'W3C Validator',
        '/SEOkicks/i'                 => 'SEOkicks Bot',
        '/Neevabot/i'                 => 'Neeva Search Bot',
        '/YisouSpider/i'              => 'Yisou Spider',
        '/Storebot/i'                 => 'Store Bot',
        '/PagesInventory/i'           => 'Pages Inventory Bot',
        '/SiteAuditBot/i'             => 'Site Audit Bot',
        '/VelenPublicWebCrawler/i'    => 'Velen Web Crawler',
        '/BraveBot/i'                 => 'Brave Search Bot',
        '/meta-externalagent/i'       => 'Meta External Agent (Facebook Link Preview)',
        '/facebot/i'                  => 'Facebook Bot',
        '/WhatsApp Link Preview/i'    => 'WhatsApp Link Preview',
        '/Slackbot-LinkExpanding/i'   => 'Slack Link Expander',
        '/TelegramBot\/[0-9]/i'       => 'Telegram Link Preview',
        '/Snapchat/i'                 => 'Snapchat Bot',
        '/vkShare/i'                  => 'VKontakte Share Bot',
        '/redditbot/i'                => 'Reddit Bot',
        '/Skype\/[0-9]+/i'            => 'Skype Link Preview',
        '/Teams\/[0-9]+/i'            => 'Microsoft Teams Bot',
        '/XING-contenttabreceiver/i'  => 'Xing Bot',
        '/OutlookUCBot/i'             => 'Outlook Bot',
        '/Line\/[0-9]+/i'             => 'LINE App Bot',
        '/viber/i'                    => 'Viber Bot',
        '/Mastodon\/[0-9]+/i'         => 'Mastodon Link Preview',
        '/TeamsBot/i'                 => 'Microsoft Teams Bot',
        '/MS-TeamsBot/i'              => 'Microsoft Teams Bot',
        '/Microsoft-Teams/i'          => 'Microsoft Teams Link Preview',
    ];

    /**
     * Return true if the user-agent string belongs to a known bot or crawler.
     *
     * An empty agent string is treated as human (not a bot) to avoid
     * false-positives from monitoring tools that omit the header.
     *
     * @param string $userAgent Raw HTTP User-Agent header value
     */
    public function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }
        foreach (self::$patterns as $pattern => $_) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the human-readable bot name for a known bot user-agent, or an
     * empty string if the agent is not recognised.
     *
     * @param string $userAgent Raw HTTP User-Agent header value
     */
    public function botName(string $userAgent): string
    {
        foreach (self::$patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }
        return '';
    }
}
