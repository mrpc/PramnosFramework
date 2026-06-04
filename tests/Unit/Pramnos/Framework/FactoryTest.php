<?php

namespace Tests\Unit\Pramnos\Framework;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Factory;
use Pramnos\Database\Database;
use Pramnos\Http\Session;
use Pramnos\Application\Settings;
use Pramnos\Filesystem\Filesystem;
use Pramnos\Cache\Cache;
use Pramnos\Document\Document;
use Pramnos\Auth\Permissions;
use Pramnos\Auth\Auth;
use Pramnos\Translator\Language;
use Pramnos\Http\Request;
use Pramnos\Email\Email;

class FactoryTest extends TestCase
{
    public function testGetDatabase()
    {
        $db = Factory::getDatabase();
        $this->assertInstanceOf(Database::class, $db);
    }

    public function testGetSession()
    {
        $session = Factory::getSession();
        $this->assertInstanceOf(Session::class, $session);
        $this->assertSame($session, Factory::getSession());
    }

    public function testGetSettings()
    {
        $settings = Factory::getSettings();
        $this->assertInstanceOf(Settings::class, $settings);
        $this->assertSame($settings, Factory::getSettings());
    }

    public function testGetFilesystem()
    {
        $filesystem = Factory::getFilesystem();
        $this->assertInstanceOf(Filesystem::class, $filesystem);
        $this->assertSame($filesystem, Factory::getFilesystem());
    }

    public function testGetCache()
    {
        $cache = Factory::getCache();
        $this->assertInstanceOf(Cache::class, $cache);
    }

    public function testGetDocument()
    {
        $document = Factory::getDocument();
        $this->assertInstanceOf(Document::class, $document);
    }

    public function testGetPermissions()
    {
        $permissions = Factory::getPermissions();
        $this->assertInstanceOf(Permissions::class, $permissions);
        $this->assertSame($permissions, Factory::getPermissions());
    }

    public function testGetAuth()
    {
        $auth = Factory::getAuth();
        $this->assertInstanceOf(Auth::class, $auth);
        $this->assertSame($auth, Factory::getAuth());
    }

    public function testGetLanguage()
    {
        $language = Factory::getLanguage();
        $this->assertInstanceOf(Language::class, $language);
        $this->assertSame($language, Factory::getLanguage());
    }

    public function testGetRequest()
    {
        $request = Factory::getRequest();
        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame($request, Factory::getRequest());
    }

    public function testGetEmail()
    {
        $email = Factory::getEmail();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame($email, Factory::getEmail());
    }
}
