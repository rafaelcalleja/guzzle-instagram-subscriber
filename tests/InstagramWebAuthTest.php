<?php

/*
 * This file is part of the guzzle-instagram-subscriber package.
 *
 * (c) Rafael Calleja <rafaelcalleja@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GuzzleHttp\Tests\Instagram;

use GuzzleHttp\Client;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Subscriber\Cookie;
use GuzzleHttp\Subscriber\History;
use GuzzleHttp\Subscriber\Instagram\InstagramWebAuth;
use GuzzleHttp\Subscriber\Mock;

/**
 * @covers GuzzleHttp\Subscriber\Instagram\InstagramWebAuth
 */
class InstagramWebAuthTest extends \PHPUnit_Framework_TestCase
{
    private $config = array(
        'enable_cookie' => false,
    );

    public function testSubscribesToEvents()
    {
        $events = (new InstagramWebAuth($this->config))->getEvents();
        $this->assertArrayHasKey('before', $events);
    }

    public function testAcceptsConfigurationData()
    {
        $p = new InstagramWebAuth($this->config);

        // Access the config object
        $class = new \ReflectionClass($p);
        $property = $class->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($p);

        $this->assertSame($this->config['enable_cookie'], $config['enable_cookie']);
    }

    /**
     * @expectedException              \RuntimeException
     * @expectedExceptionMessage    Client cookies are disabled
     */
    public function testThrowExceptionNotClientHasCookieSubscriber()
    {
        $mock = new Mock(array(
            new Response(200),
        ));

        $client = new Client();
        $client->getEmitter()->attach($mock);
        $client->getEmitter()->attach(new InstagramWebAuth($this->config));
        $client->get('/');
    }

    public function testNewRequestIsCreatedWhenClientDoPostToUrlLoginAjax()
    {
        $mock = new Mock(array(
            new Response(200, array('Set-Cookie' => 'csrftoken=3d24ddab3a6797fe4fffaf45148532e3; expires=Sun, 22-Nov-2015 13:52:10 GMT; Max-Age=31449600; Path=/, mid=VHHmigAEAAEEAttmLKB26UD9lO7T; expires=Sat, 18-Nov-2034 13:52:10 GMT; Max-Age=630720000; Path=/, ccode=ES; Path=/')),
            new Response(200),
        ));

        $client = new Client(array('base_url' => 'https://www.instagram.com'));
        $history = new History();
        $cookie = new Cookie();

        $client->getEmitter()->attach($mock);
        $client->getEmitter()->attach($cookie);
        $client->getEmitter()->attach($history);

        $client->getEmitter()->attach(new InstagramWebAuth($this->config));

        $request = $client->createRequest('POST', 'accounts/login/ajax/');

        $postBody = $request->getBody();
        $postBody->setField('username', 'test');
        $postBody->setField('password', 'pass');

        $response = $client->send($request);

        $expected = 2;
        $actual = count($history);

        $this->assertSame($expected, $actual);

        $expected = '3d24ddab3a6797fe4fffaf45148532e3';
        $actual = $request->getHeader('X-CSRFToken');

        $this->assertSame($expected, $actual);
    }

    /**
     * @expectedException              \RuntimeException
     * @expectedExceptionMessage    Username and password are required
     */
    public function testThrowExceptionWhenNotUserAndPasswordPostingToLoginAjaxUrl()
    {
        $mock = new Mock(array(
            new Response(200),
        ));

        $client = new Client(array('base_url' => 'https://www.instagram.com'));
        $client->getEmitter()->attach(new InstagramWebAuth(array()));
        $client->getEmitter()->attach($mock);

        $client->post('accounts/login/ajax/');
    }

    public function testResponseIsValidRealWeb()
    {
        $client = new Client(array('base_url' => 'https://www.instagram.com'));

        $client->getEmitter()->attach(new InstagramWebAuth(array()));

        $response = $client->post('accounts/login/ajax/', array('body' => array('username' => 'foo', 'password' => 'bar')));

        json_decode($response->getBody());

        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        $this->assertArrayHasKey('status', $response->json());
    }

    public function testResponseIsValidBuildPostWithCompleteUrl()
    {
        $client = new Client();
        $client->getEmitter()->attach(new InstagramWebAuth(array()));

        $client->getEmitter()->attach(new Mock(array(
            new Response(200, array(
                'Set-Cookie' => 'csrftoken=3d24ddab3a6797fe4fffaf45148532e3; expires=Sun, 22-Nov-2015 13:52:10 GMT; Max-Age=31449600; Path=/, mid=VHHmigAEAAEEAttmLKB26UD9lO7T; expires=Sat, 18-Nov-2034 13:52:10 GMT; Max-Age=630720000; Path=/, ccode=ES; Path=/',
            )),
            new Response(200, array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ), Stream::factory('{"status":"ok","authentication":false}')),
        )));

        $response = $client->post('https://www.instagram.com/accounts/login/ajax/', array('body' => array('username' => 'foo', 'password' => 'bar')));

        json_decode($response->getBody());

        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        $this->assertArrayHasKey('status', $response->json());
    }
}
