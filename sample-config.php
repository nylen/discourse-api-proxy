<?php

// The master API key from /admin/api/keys in Discourse.
$discourse_api_key = '123def789';

// The base URL of your Discourse instance.  Valid API requests will be
// forwarded here, authenticated with the master API key.
$discourse_url = 'https://forums.yoursite.com/';

// An array of permitted client configurations.  Array keys are what the client
// sends to authenticate (keep these secret), and array values describe the IP
// addresses that are permitted to use each key and the Discourse API endpoints
// that they can call.
//
// `endpoint_whitelist` entries should match values like `REQUEST_METHOD /url`.
// `ip_whitelist` entries should match values like `1.2.3.4`.
//
// Both kinds of whitelist entries may use * as a wildcard character.
$client_keys = [
	'client_secret_key_1' => [
		'endpoint_whitelist' => [
			'GET /categories.json',
		],
		'ip_whitelist' => [
			'127.0.0.1',
		],
	],

	'client_secret_key_2' => [
		'endpoint_whitelist' => [
			'*', // You probably don't want to do this!
		],
		'ip_whitelist' => [
			'*', // You probably don't want to do this!
		],
	],
];
