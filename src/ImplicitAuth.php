<?php

/*
 * This file is part of the guzzle-instagram-subscriber package.
 *
 * (c) Rafael Calleja <rafaelcalleja@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GuzzleHttp\Subscriber\Instagram;

use GuzzleHttp\Collection;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

class ImplicitAuth implements SubscriberInterface
{
    private $config;

    /** @var InstagramWebAuth */
    protected $webauth;

    public function __construct($config, InstagramWebAuth $webauth = null)
    {
        $this->config = Collection::fromConfig(
            $config,
            array(
                'response_type' => 'token',
                'scope'         => 'likes+comments',
                'authorize_url' => 'https://www.instagram.com/oauth/authorize',
                'origin'        => 'https://www.instagram.com',
                'access_token'  => false,
            ),
            array('username', 'password', 'client_id', 'redirect_uri')
        );

        $this->webauth = $webauth ?: new InstagramWebAuth(array());
    }

    public function getAccessToken()
    {
        return $this->config['access_token'];
    }

    public function setAccessToken($access_token)
    {
        $this->config['access_token'] = $access_token;
    }

    /**
     * Called when a request receives a redirect response.
     *
     * @param CompleteEvent $event Event emitted
     *
     * @throws TooManyRedirectsException
     */
    public function onComplete(CompleteEvent $event)
    {
        $response = $event->getResponse();
        if (substr($response->getStatusCode(), 0, 1) != '3'
            || !$response->hasHeader('Location')
        ) {
            return;
        }

        $hash = parse_url($response->getHeader('Location'), PHP_URL_FRAGMENT);

        if (strpos($hash, 'access_token') === 0) {
            $this->setAccessToken(substr(strstr($hash, '='), 1));
            $response->removeHeader('Location');
        }
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The returned array keys MUST map to an event name. Each array value
     * MUST be an array in which the first element is the name of a function
     * on the EventSubscriber OR an array of arrays in the aforementioned
     * format. The second element in the array is optional, and if specified,
     * designates the event priority.
     *
     * For example, the following are all valid:
     *
     *  - ['eventName' => ['methodName']]
     *  - ['eventName' => ['methodName', $priority]]
     *  - ['eventName' => [['methodName'], ['otherMethod']]
     *  - ['eventName' => [['methodName'], ['otherMethod', $priority]]
     *  - ['eventName' => [['methodName', $priority], ['otherMethod', $priority]]
     *
     * @return array
     */
    public function getEvents()
    {
        return array(
            'before'   => array('onBefore'),
            'complete' => array('onComplete', RequestEvents::REDIRECT_RESPONSE),
        );
    }

    public function onBefore(BeforeEvent $event)
    {
        $client = $event->getClient();
        $request = $event->getRequest();

        if (!$this->hasInstagramWebAuthSubscriber($request)) {
            $request->getEmitter()->attach($this->webauth);
            $client->getEmitter()->attach($this->webauth);
        }

        if (!preg_match('/(?:http[s]*\:\/\/)*(.*?)\.(?=[^\/]*\..{2,5})/i', $request->getUrl(), $match)) {
            $request->setUrl(preg_replace('/^(http[s]?)(\:\/\/)/', '$1://www.', $request->getUrl()));
        }

        if ($request->getMethod() == 'POST' && $request->getUrl() == $this->config['authorize_url']) {
            $response = $client->post('https://www.instagram.com/accounts/login/ajax/', array('body' => array('username' => $this->config['username'], 'password' => $this->config['password'])));

            $cookies = $this->extractCookies($response);

            if ($this->isValidCookies($cookies)) {
                $this->addCookiesHeaderFromArray($request, $cookies);

                $request->getQuery()->set('response_type', $this->config['response_type']);
                $request->getQuery()->set('client_id', $this->config['client_id']);
                $request->getQuery()->set('redirect_uri', $this->config['redirect_uri']);

                $postBody = $request->getBody();
                $postBody->setField('csrfmiddlewaretoken', $cookies['csrftoken']->getValue());
                $postBody->setField('allow', 'Authorize');

                $request->setBody($postBody);

                $request->addHeaders(array(
                    'Origin'  => $this->config['origin'],
                    'Referer' => $this->config['origin'],
                ));
            }
        }
    }

    protected function addCookiesHeaderFromArray(RequestInterface $request, array $cookies  = array())
    {
        $request->setHeader('Cookie', implode('; ',
                array_map(
                    function (SetCookie $cookie) {
                        return $cookie->getName().'='.CookieJar::getCookieValue($cookie->getValue());
                    }, $cookies)
            )
        );
    }

    protected function extractCookies(ResponseInterface $response)
    {
        return array_reduce($response->getHeaderAsArray('Set-Cookie'), function ($result, $cookie) {

            $cookie = SetCookie::fromString($cookie);

            $result[strtolower($cookie->getName())] = $cookie;

            return $result;

        }, array());
    }

    protected function isValidCookies($cookies)
    {
        return count(
            array_intersect(array('csrftoken', 'sessionid', 'ds_user_id'), array_keys($cookies))
        ) === 3;
    }

    protected function hasInstagramWebAuthSubscriber($request)
    {
        foreach ($request->getEmitter()->listeners('before') as $plugin) {
            if ($plugin[0] instanceof InstagramWebAuth) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \GuzzleHttp\Subscriber\Instagram\InstagramWebAuth
     */
    public function getWebauth()
    {
        return $this->webauth;
    }
}
