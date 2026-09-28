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

        $crawlerDetect = $this->crawlerDetect();

        if ($crawlerDetect !== null) {
            return (bool) $crawlerDetect->isCrawler($userAgent);
        }

        foreach (self::$patterns as $pattern => $_) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }
        return false;
    }

    /**
     * `jaybizzle/crawler-detect`, when the application installed it.
     *
     * ## Why the built-in list is not enough
     *
     * Thirty-five patterns, one of which is a generic `bot` — so the list catches more than
     * that count suggests, and SemrushBot, AhrefsBot, PetalBot and headless Chrome are
     * already among them. Measured rather than assumed, because "it misses the obvious
     * ones" is the easy story and it is not the true one.
     *
     * **What it misses are the crawlers that do not say "bot"**: `python-requests`,
     * `Go-http-client`, `okhttp`, Scrapy, Zabbix, link unfurlers, `Google-InspectionTool`.
     * Those are also the ones most likely to be hammering a site.
     *
     * Which is enough to keep a session table from filling up — what this class was
     * extracted to do — and not enough to count visits: a counter that lets them through
     * reports crawlers as readers, and the number stops being comparable with anything else
     * measuring the same site.
     *
     * The library is one compiled regular expression over about 1,500 crawlers, and it is
     * maintained — which is the part a hand-kept list cannot be. A new crawler appears
     * every week and nobody notices the day this list stops covering them, because the
     * symptom is a number that is slightly too high.
     *
     * ## Why `suggest` rather than `require`
     *
     * The same reasoning as `matomo/device-detector` beside it, and as the push library:
     * a framework that pulled a crawler list into every application's `vendor/` would
     * impose it on every project that never counts a visit. Without it this class answers
     * exactly as it did, so nothing an installation relies on changes by upgrading.
     *
     * Resolved once per instance. The library compiles its regular expression in the
     * constructor, and this is called per request in the session tracker.
     */
    protected function crawlerDetect(): ?object
    {
        if ($this->crawlerDetect !== false) {
            return $this->crawlerDetect;
        }

        $class = $this->crawlerDetectClass();

        if (!class_exists($class)) {
            return $this->crawlerDetect = null;
        }

        return $this->crawlerDetect = new $class();
    }

    /**
     * The class to look for.
     *
     * A seam, and a small one: the library is in `require-dev` here precisely so the suite
     * exercises the path an application gets by installing it — which leaves the **absent**
     * path unreachable, and that is the path every installation that does not install it
     * takes. A test names a class that is not there and gets the real branch.
     *
     * It is also where an application would point at a compatible detector of its own,
     * which is the only reason to make it `protected` rather than a constant.
     *
     * @return class-string|string
     */
    protected function crawlerDetectClass(): string
    {
        return '\\Jaybizzle\\CrawlerDetect\\CrawlerDetect';
    }

    /**
     * The resolved library, `null` when it is absent, `false` before the first look.
     *
     * Three states rather than two, because "absent" is an answer worth remembering: a
     * `null` cache would ask `class_exists()` again on every call.
     *
     * @var object|null|false
     */
    private $crawlerDetect = false;

    /**
     * Return the human-readable bot name for a known bot user-agent, or an
     * empty string if the agent is not recognised.
     *
     * @param string $userAgent Raw HTTP User-Agent header value
     */
    public function botName(string $userAgent): string
    {
        if ($userAgent === '') {
            return '';
        }

        // The framework's own patterns first, because they carry a name somebody wrote —
        // "Googlebot" rather than whichever fragment of the agent string the library's
        // regular expression happened to match. The library is the wider net, not the
        // better label.
        foreach (self::$patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        $crawlerDetect = $this->crawlerDetect();

        if ($crawlerDetect !== null && $crawlerDetect->isCrawler($userAgent)) {
            // What it matched on, which is the closest thing to a name it has.
            return trim((string) $crawlerDetect->getMatches());
        }

        return '';
    }
}
