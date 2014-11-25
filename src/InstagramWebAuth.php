<?php

namespace GuzzleHttp\Subscriber\Instagram;

use GuzzleHttp\Client;
use GuzzleHttp\Collection;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Subscriber\Cookie;


class InstagramWebAuth implements SubscriberInterface {

    private $config;

    public function __construct($config){
        $this->config = Collection::fromConfig(
            $config,
            [
                'login_ajax' => 'https://instagram.com/accounts/login/ajax',
                'login_url' => 'https://instagram.com/accounts/login',
                'origin' => 'https://instagram.com',
                'referer' => 'https://instagram.com/accounts/login/ajax/?targetOrigin=https%3A%2F%2Finstagram.com',
                'enable_cookie' => true,
            ],
            []
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
        return [
            'before'   => ['onBefore']
        ];
    }

    public function onBefore(BeforeEvent $event)
    {

        $client = $event->getClient();
        $request = $event->getRequest();
        $config = $request->getConfig();

        if(!$this->hasCookiesSubscriber($request)){
            if( $this->config['enable_cookie'] ) {
                $cookie = new Cookie();
                $request->getEmitter()->attach($cookie);
                $client->getEmitter()->attach($cookie);
            }
            else throw new \RuntimeException('Client cookies are disabled');
        }


        if( $request->getMethod() == 'POST' && $this->config['login_ajax'] == $request->getUrl() ){

            if(empty($request->getBody()->getFields()['username']) || empty($request->getBody()->getFields()['password']))
                throw new \RuntimeException('Username and password are required');


            $response = $client->send(
                $client->createRequest('GET', $this->config['login_url'])
            );

            $cookie = SetCookie::fromString($response->getHeader('Set-Cookie'));

            if(!$cookie->getName() == 'csrftoken')
                throw new \RuntimeException('Missing csrftoken from header Set-Cookie response');

            $request->getBody()->setField('intent', '');

            $request->addHeaders([
                'X-CSRFToken' => $cookie->getValue(),
                'X-Instagram-AJAX' => 1,
                'X-Requested-With' => 'XMLHttpRequest',
                'Origin' => $this->config['origin'],
                'Referer' => $this->config['referer'],
            ]);

            $config->overwriteWith([ 'redirect' => [
                'max'     => 10,
                'strict'  => true,
                'referer' => true
            ]]);


        }



    }

    protected function hasCookiesSubscriber($request){

        foreach( $request->getEmitter()->listeners('before') as $plugin ) {
            if($plugin[0] instanceof Cookie )
                return true;
        }

        return false;

    }
}
