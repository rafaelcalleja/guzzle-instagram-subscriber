<?php

namespace GuzzleHttp\Tests\Instagram;


use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Subscriber\Instagram\InstagramWebAuth;
use GuzzleHttp\Subscriber\Instagram\ImplicitAuth;

use GuzzleHttp\Subscriber\Mock,
    GuzzleHttp\Message\Response,
    GuzzleHttp\Client;
use GuzzleHttp\Transaction;

/**
 * @covers GuzzleHttp\Subscriber\Instagram\ImplicitAuth
 */
class ImplicitAuthTest extends \PHPUnit_Framework_TestCase
{

    private $config = [
        'username' => 'foo',
        'password' => 'bar',
        'client_id'    => 'foo',
        'redirect_uri' => 'bar',
        'response_type'           => 'token',
        'scope' => 'likes+comments',
        'authorize_url' => 'https://www.instagram.com/oauth/authorize',
        'origin' => 'https://www.instagram.com'
    ];

    public function testSubscribesToEvents()
    {

        $events = (new ImplicitAuth($this->config, new InstagramWebAuth([]) ))->getEvents();
        $this->assertArrayHasKey('before', $events);
    }

    public function testAcceptsConfigurationData()
    {
        $p = new ImplicitAuth($this->config, new InstagramWebAuth([]));

        $class = new \ReflectionClass($p);
        $property = $class->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($p);

        $this->assertEquals('foo', $config['username']);
        $this->assertEquals('bar', $config['password']);
        $this->assertEquals('foo', $config['client_id']);
        $this->assertEquals('bar', $config['redirect_uri']);
        $this->assertEquals('token', $config['response_type']);
        $this->assertEquals('likes+comments', $config['scope']);
        $this->assertEquals('https://www.instagram.com/oauth/authorize', $config['authorize_url']);
        $this->assertEquals('https://www.instagram.com', $config['origin']);
    }

    /**
     * @expectedException        \InvalidArgumentException
     * @expectedExceptionMessage Config is missing the following keys: username, password, client_id, redirect_uri
     */
    public function testThrowExceptionWhenNoRequiredParams(){
        new ImplicitAuth([], new InstagramWebAuth([]));
    }

    public function testWebAuthCreatedOrSetAreSame(){

        $actualSet = new InstagramWebAuth([]);
        $set = new ImplicitAuth($this->config, $actualSet);

        $created = new ImplicitAuth($this->config);

        $this->assertSame($actualSet, $set->getWebauth());
        $this->assertNotSame($actualSet, $created->getWebauth());
    }

    public function testMakeRequestToLoginAjaxWhenPostToAuthorizeUrl(){

        $client = new Client([]);

        $instagramWebAuthMock = $this->getMockBuilder('GuzzleHttp\Subscriber\Instagram\InstagramWebAuth')
            ->setConstructorArgs([[]])
            ->setMethods(array('getEvents', 'onBefore', 'onComplete'))
            ->getMock();

        $instagramWebAuthMock->expects($this->once())
            ->method('onBefore');

        $instagramWebAuthMock->expects($this->exactly(2))
            ->method('getEvents')
            ->willReturn([
                'before'   => ['onBefore'],
                'complete' => ['onComplete', RequestEvents::REDIRECT_RESPONSE]
            ]);
        ;

        $client->getEmitter()->attach( new ImplicitAuth($this->config, $instagramWebAuthMock ));
        $client->getEmitter()->attach( new Mock([ new Response(200), new Response(200) ]) );

        $client->post('https://www.instagram.com/oauth/authorize');


    }

    /**
     * @dataProvider ulrProvider
     */
    public function testGetAccessTokenInHashUrlWithAppPreviouslyAuthorization($url){

        $client = new Client([]);

        $mock = $this->getSuccessMock();

        $implicitAuth = new ImplicitAuth($this->config);

        $client->getEmitter()->attach($mock);
        $client->getEmitter()->attach( $implicitAuth );

        $client->post($url);

        $expected = '1558166829.033680f.4ec89a163eba441f875d4a88dd1a048e';
        $actual = $implicitAuth->getAccessToken();

        $this->assertEquals($expected, $actual);

    }

    public function ulrProvider()
    {
        return array(
            array('https://www.instagram.com/oauth/authorize'),
            array('https://instagram.com/oauth/authorize'),
        );
    }

    public function testBehaviorValidateCookiesAndAddToHeaders(){

        $client = new Client([]);

        $implicitAuth = $this->getMockBuilder('GuzzleHttp\Subscriber\Instagram\ImplicitAuth')
            ->setConstructorArgs([$this->config])
            ->setMethods(['extractCookies', 'isValidCookies', 'addCookiesHeaderFromArray'])
            ->getMock();


        $implicitAuth->expects($this->once())
            ->method('extractCookies')
            ->willReturn(['csrftoken' => new SetCookie()])
        ;

        $implicitAuth->expects($this->once())
            ->method('isValidCookies')
            ->willReturn(true)
        ;

        $implicitAuth->expects($this->once())
            ->method('addCookiesHeaderFromArray');

        $client->getEmitter()->attach( $implicitAuth );

        $mock = $this->getSuccessMock();
        $client->getEmitter()->attach($mock);
        $client->post('https://www.instagram.com/oauth/authorize');

    }


    public function testBehaviorRequestAreUpdated(){

        $client = new Client([]);

        $mock = $this->getSuccessMock();
        $client->getEmitter()->attach($mock);


        $request = $client->createRequest('POST', 'https://www.instagram.com/oauth/authorize');

        $transaction = new Transaction($client, $request);

        $beforeMock = $this->getMockBuilder('GuzzleHttp\Event\BeforeEvent')
            ->setConstructorArgs([$transaction])
            ->getMock()
        ;

        $beforeMock->expects($this->once())
            ->method('getRequest')
            ->willReturn($request)
        ;

        $beforeMock->expects($this->once())
            ->method('getClient')
            ->willReturn($client)
        ;


        $implicitAuth = new ImplicitAuth($this->config);

        $client->getEmitter()->attach( $implicitAuth );

        $implicitAuth->onBefore($beforeMock);


        $this->assertEquals($this->config['response_type'], $request->getQuery()->get('response_type'));
        $this->assertEquals($this->config['client_id'], $request->getQuery()->get('client_id'));
        $this->assertEquals($this->config['redirect_uri'], $request->getQuery()->get('redirect_uri'));

        $this->assertEquals('49c3703546874785992eed3c234f5855', $request->getBody()->getField('csrfmiddlewaretoken'));
        $this->assertEquals('Authorize', $request->getBody()->getField('allow'));

        $this->assertEquals($this->config['origin'], $request->getHeader('Origin'));
        $this->assertEquals($this->config['origin'], $request->getHeader('Referer'));

    }

    protected function getSuccessMock(){

        $token = 'csrftoken=49c3703546874785992eed3c234f5855; expires=Fri, 27-Nov-2015 17:08:40 GMT; Max-Age=31449600; Path=/, ';
        $sessionId = 'sessionid=IGSC8438aa9dfad304bb5b5036f225187a5e4b41b9f27dcce20e39a295e714aa9a7e%3AUjFue4Wlprjg8e6M4CmfdUyImYuDgQfZ%3A%7B%22_auth_user_id%22%3A1558166829%2C%22_token%22%3A%221558166829%3AaT0Q7aquLwAqLm1Lzn1tJ1f9KMAXvUkv%3A5ae62410483467f569c69c9afe6be6a95f1bc557c9df4b54fbc78ee1c41e065b%22%2C%22_auth_user_backend%22%3A%22accounts.backends.CaseInsensitiveModelBackend%22%2C%22last_refreshed%22%3A1417194520.312225%2C%22_tl%22%3A1%2C%22_platform%22%3A4%7D; expires=Thu, 26-Feb-2015 17:08:40 GMT; Max-Age=7776000; Path=/;HttpOnly';
        $dsUserId = 'ds_user_id=1558166829; expires=Thu, 26-Feb-2015 17:08:40 GMT; Max-Age=7776000; Path=/';

        return new Mock([
            new Response(200, [
                'Set-Cookie' => 'csrftoken=3d24ddab3a6797fe4fffaf45148532e3; expires=Sun, 22-Nov-2015 13:52:10 GMT; Max-Age=31449600; Path=/, mid=VHHmigAEAAEEAttmLKB26UD9lO7T; expires=Sat, 18-Nov-2034 13:52:10 GMT; Max-Age=630720000; Path=/, ccode=ES; Path=/'
            ]),
            new Response(200, [
                'Set-Cookie' => [$token, $sessionId, $dsUserId]

            ]),
            new Response(302, ['Location' => 'http://localhost/callback#access_token=1558166829.033680f.4ec89a163eba441f875d4a88dd1a048e']),
        ]);

    }

    public function testInstagramAuthIntegration()
    {

        if (empty($_SERVER['CLIENT_ID'])) {
            $this->markTestSkipped('No CLIENT_ID provided in phpunit.xml');
            return;
        }
        $client = new Client(['debug' => true]);

        $config = [
            'username' => $_SERVER['USERNAME'],
            'password' => $_SERVER['PASSWORD'],
            'client_id'    => $_SERVER['CLIENT_ID'],
            'redirect_uri' => $_SERVER['REDIRECT_URI'],
        ];

        $implicitAuth = new ImplicitAuth($config);
        $client->getEmitter()->attach($implicitAuth);

        $client->post('https://www.instagram.com/oauth/authorize');

        $this->assertNotFalse($implicitAuth->getAccessToken());

    }



}

