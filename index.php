<?php

///////////////////////
// Utility functions //
///////////////////////

function serve_500( $reason = 'misconfigured' ) {
	header( 'HTTP/1.1 500 Internal Server Error' );
	header( 'Content-Type: application/json' );
	echo json_encode( [ 'error' => $reason ] );
	die();
}

function serve_403() {
	header( 'HTTP/1.1 403 Forbidden' );
	header( 'Content-Type: application/json' );
	echo json_encode( [ 'error' => 'forbidden' ] );
	die();
}

function serve_400( $reason = 'bad_request' ) {
	header( 'HTTP/1.1 400 Bad Request' );
	header( 'Content-Type: application/json' );
	echo json_encode( [ 'error' => $reason ] );
	die();
}

function serve_302() {
	global $discourse_url;
	header( 'HTTP/1.1 302 Found' );
	header( 'Content-Type: text/html' );
	header( 'Location: ' . $discourse_url );
	echo '<a href="' . htmlspecialchars( $discourse_url ) . '">Redirecting</a>';
	die();
}

function match_any( $patterns, $value ) {
	foreach ( $patterns as $pattern ) {
		$parts = explode( '*', $pattern );
		foreach ( $parts as &$part ) {
			$part = preg_quote( $part, '#' );
		}
		$regex = '#^' . implode( '.*', $parts ) . '$#';
		if ( preg_match( $regex, $value ) ) {
			return true;
		}
	}
	return false;
}

// Not using `parse_str()` and `http_build_query()` because they mangle arrays:
// 'tags[]=' -> {"tags":[""]} -> 'tags[0]='
// This simplistic approach is good enough for finding/adding/removing single parameters.
function custom_parse_str( $str ) {
	return explode( '&', $str );
}

function custom_build_query( $arr ) {
	return implode( '&', $arr );
}

function custom_get_param( $arr, $name ) {
	foreach ( $arr as $entry ) {
		$parts = explode( '=', $entry, 2 );
		if ( $parts[0] === $name ) {
			if ( count( $parts ) === 1 ) {
				return '';
			} else {
				return $parts[1];
			}
		}
	}
	return null;
}

function custom_remove_param( $arr, $name ) {
	return array_values( array_filter( $arr, function( $entry ) use ( $name ) {
		return strtok( $entry, '=' ) !== $name;
	} ) );
}

function custom_add_param( $arr, $name, $value ) {
	return array_merge(
		custom_remove_param( $arr, $name ),
		[ "$name=$value" ]
	);
}


///////////////////////////////////
// Load and verify configuration //
///////////////////////////////////

$discourse_api_key = null;
$discourse_url = null;
$client_keys = null;
require __DIR__ . '/config.php';

if (
	empty( $discourse_api_key ) ||
	empty( $discourse_url ) ||
	empty( $client_keys ) ||
	! is_array( $client_keys )
) {
	serve_500();
}

foreach ( $client_keys as $key => $data ) {
	if (
		empty( $key ) ||
		! is_string( $key ) ||
		! is_array( $data ) ||
		empty( $data['endpoint_whitelist'] ) ||
		! is_array( $data['endpoint_whitelist'] ) ||
		empty( $data['ip_whitelist'] ) ||
		! is_array( $data['ip_whitelist'] )
	) {
		serve_500();
	}
}

if ( ! function_exists( 'curl_init' ) ) {
	serve_500( 'install_curl_extension' );
}


//////////////////////////////
// Parse and verify request //
//////////////////////////////

$url_parts = explode( '?', $_SERVER['REQUEST_URI'], 2 );
$url = $url_parts[0];
if ( empty( $url_parts[1] ) ) {
	$query_params = [];
} else {
	$query_params = custom_parse_str( $url_parts[1] );
}

if ( $url === '/' ) {
	serve_302();
}

$api_key = null;
if (
	$_SERVER['REQUEST_METHOD'] === 'POST' ||
	$_SERVER['REQUEST_METHOD'] === 'PUT'
) {
	if (
		isset( $_SERVER['HTTP_CONTENT_TYPE'] ) &&
		strpos( $_SERVER['HTTP_CONTENT_TYPE'], 'json' ) !== false
	) {
		serve_400( 'json_not_supported' );
	}
	$body_raw = file_get_contents( 'php://input' );
	$body_params = custom_parse_str( $body_raw );
	$api_key = custom_get_param( $body_params, 'api_key' );
	if ( $api_key ) {
		$body_params = custom_remove_param( $body_params, 'api_key' );
	}
} else if ( $_SERVER['REQUEST_METHOD'] === 'GET' ) {
	$body_params = [];
} else {
	serve_403();
}

// Note: if `api_key` is present in both query and body parameters, then the
// body parameter will take precedence
if ( ! $api_key ) {
	$api_key = custom_get_param( $query_params, 'api_key' );
	if ( $api_key ) {
		$query_params = custom_remove_param( $query_params, 'api_key' );
	}
}

$endpoint = $_SERVER['REQUEST_METHOD'] . ' ' . $url;
$ip = $_SERVER['REMOTE_ADDR'];

$ok = false;

if ( ! empty( $api_key ) && isset( $client_keys[ $api_key ] ) ) {
	$config = $client_keys[ $api_key ];
	if (
		match_any( $config['endpoint_whitelist'], $endpoint ) &&
		match_any( $config['ip_whitelist'], $ip )
	) {
		$ok = true;
	}
}

if ( ! $ok ) {
	error_log(
		'discourse-api-proxy DENIED: '
		. json_encode(
			compact( 'endpoint', 'ip', 'api_key' ),
			JSON_UNESCAPED_SLASHES
		)
	);
	serve_403();
}


//////////////////////////////////
// Forward request to Discourse //
//////////////////////////////////

$query_params = custom_add_param( $query_params, 'api_key', $discourse_api_key );

$remote_url = (
	rtrim( $discourse_url, '/' ) . '/' . ltrim( $url, '/' )
	. '?' . custom_build_query( $query_params )
);
// error_log( 'url: ' . $remote_url );
$ch = curl_init( $remote_url );

$headers = [];
if ( isset( $_SERVER['HTTP_CONTENT_TYPE'] ) ) {
	$headers[] = 'Content-Type: ' . $_SERVER['HTTP_CONTENT_TYPE'];
}
if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
	$headers[] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
}
if ( isset( $_SERVER['HTTP_ACCEPT'] ) ) {
	$headers[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
}
if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
	$headers[] = 'Referer: ' . $_SERVER['HTTP_REFERER'];
}

curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD'] );
curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
curl_setopt( $ch, CURLOPT_SAFE_UPLOAD, true );
if ( ! empty( $body_params ) ) {
	curl_setopt( $ch, CURLOPT_POSTFIELDS, custom_build_query( $body_params ) );
	// error_log( 'body: ' . custom_build_query( $body_params ) );
}
curl_setopt( $ch, CURLOPT_HEADER, true );
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
$response = curl_exec( $ch );

$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
error_log(
	'discourse-api-proxy accepted: '
	. json_encode(
		compact( 'endpoint', 'ip', 'api_key', 'status_code' ),
		JSON_UNESCAPED_SLASHES
	)
);

list( $headers, $body ) = explode( "\r\n\r\n", $response, 2 );

foreach ( explode( "\r\n", $headers ) as $header ) {
	$header_name = strtolower( strtok( $header, ':' ) );
	switch ( $header_name ) {
		case 'transfer-encoding':
			// Skip these headers
			break;
		default:
			header( $header );
	}
}

echo $body;
