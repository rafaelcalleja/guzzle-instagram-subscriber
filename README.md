[![Latest Stable Version](https://poser.pugx.org/guzzlehttp/guzzle-instagram-subscriber/v/stable)](https://packagist.org/packages/guzzlehttp/guzzle-instagram-subscriber)
[![Build Status](https://travis-ci.org/rafaelcalleja/guzzle-instagram-subscriber.svg?branch=master)](https://travis-ci.org/rafaelcalleja/guzzle-instagram-subscriber)

guzzle-instagram-subscriber
===========================

Guzzle Subscriber Instagram: Authorizes the instagram app and generates the access token using login / password

Installing
==========

This project can be installed using Composer. Add the following to your
composer.json:

```javascript

    {
        "require": {
            "guzzlehttp/guzzle-instagram-subscriber": "dev-master"
        }
    }
```
Retrieve the access token using the Implicit Authorization Subscriber
====================

Here's an example showing how to authorize an instagram app and generate access token just one step:

```php

    use GuzzleHttp\Client;
    use GuzzleHttp\Subscriber\Instagram\ImplicitAuth;

    $client = new Client();

    $config = [
        'username' => 'instagram_username',
        'password' => 'instagram_password',
        'client_id'    => 'instagram_client_id',
        'redirect_uri' => 'instagram_redirect_uri',
    ];

    $implicitAuth = new ImplicitAuth($config);
    $client->getEmitter()->attach($implicitAuth);

    $client->post('https://instagram.com/oauth/authorize');

    $access_token = $implicitAuth->getAccessToken();
```

Once you've registered your client it's easy to start requesting data from Instagram,
Using this access token to request the Instagram API endpoints.
More information: http://instagram.com/developer/endpoints/


