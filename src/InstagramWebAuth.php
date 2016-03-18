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

use GuzzleHttp\Client;
use GuzzleHttp\Collection;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Subscriber\Cookie;

class InstagramWebAuth implements SubscriberInterface
{
    private $config;

    public function __construct($config)
    {
        $this->config = Collection::fromConfig(
            $config,
            array(
                'login_ajax'    => 'https://www.instagram.com/accounts/login/ajax/',
                'login_url'     => 'https://www.instagram.com/ajax/bz',
                'origin'        => 'https://www.instagram.com',
                'referer'       => 'https://www.instagram.com',
                'enable_cookie' => true,
            ),
            array()
        );
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
        );
    }

    public function onBefore(BeforeEvent $event)
    {
        $client = $event->getClient();
        $request = $event->getRequest();
        $config = $request->getConfig();

        if (!$this->hasCookiesSubscriber($request)) {
            if ($this->config['enable_cookie']) {
                $cookie = new Cookie();
                $request->getEmitter()->attach($cookie);
                $client->getEmitter()->attach($cookie);
            } else {
                throw new \RuntimeException('Client cookies are disabled');
            }
        }

        if ($request->getMethod() == 'POST' && $this->config['login_ajax'] == $request->getUrl()) {
            $fields = $request->getBody()->getFields();
            if (empty($fields['username']) || empty($fields['password'])) {
                throw new \RuntimeException('Username and password are required');
            }

            $payload = sprintf('{"q":[{"page_id":"","posts":[["slipstream:pageview",{"description":"loginPage","event_name":"pageview","platform":"web","extra":"{\"gk\":{\"rhp\":true}}","hostname":"www.instagram.com","path":"/accounts/login/","referer":"","url":"https://www.instagram.com/accounts/login/"},%s,0],["slipstream:action",{"description":"fbLoginFallback","event_name":"action","extra":"{\"gk\":{\"rhp\":true},\"type\":\"login\"}","hostname":"www.instagram.com","path":"/accounts/login/","referer":"","url":"https://www.instagram.com/accounts/login/"},%s,0]],"trigger":"slipstream:pageview"},{"page_id":"p8ysg7","posts":[["slipstream:action",{"description":"fbLoginFallback","event_name":"action","extra":"{\"gk\":{\"rhp\":true},\"type\":\"login\"}","hostname":"www.instagram.com","path":"/accounts/login/","referer":"","url":"https://www.instagram.com/accounts/login/"},%s,1]]}]}', round(microtime(true) * 1000), round(microtime(true) * 1000), round(microtime(true) * 1000));
            $req = $client->createRequest('POST', $this->config['login_url'], array('body' => $payload));

            $req->addHeaders(array(
                'x-requested-with' => 'XMLHttpRequest',
                'Origin'           => $this->config['origin'],
            ));

            $response = $client->send($req);

            $cookie = SetCookie::fromString($response->getHeader('Set-Cookie'));

            if (!$cookie->getName() == 'csrftoken') {
                throw new \RuntimeException('Missing csrftoken from header Set-Cookie response');
            }

            $request->getBody()->setField('intent', '');

            $request->addHeaders(array(
                'X-CSRFToken' => $cookie->getValue(),
                'cookie'      => 'csrftoken='.$cookie->getValue(),
                //'X-Instagram-AJAX' => 1,
                'X-Requested-With' => 'XMLHttpRequest',
                'Origin'           => $this->config['origin'],
                'Referer'          => $this->config['referer'],
            ));

            $config->overwriteWith(array('redirect' => array(
                'max'     => 10,
                'strict'  => true,
                'referer' => true,
            )));
        }
    }

    protected function hasCookiesSubscriber($request)
    {
        foreach ($request->getEmitter()->listeners('before') as $plugin) {
            if ($plugin[0] instanceof Cookie) {
                return true;
            }
        }

        return false;
    }
}
