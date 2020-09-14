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

function serve_302( $path = '/' ) {
	global $discourse_url;
	$url = rtrim( $discourse_url, '/' ) . '/' . ltrim( $path, '/' );
	header( 'HTTP/1.1 302 Found' );
	header( 'Content-Type: text/html' );
	header( 'Location: ' . $url );
	echo '<a href="' . htmlspecialchars( $url ) . '">Redirecting</a>';
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

function condense_values( $list ) {
	$list = array_filter( $list, function( $item ) {
		return ! empty( $item );
	} );
	$list = array_unique( array_values( $list ) );
	switch ( count( $list ) ) {
		case 0:
			return null;
		case 1:
			return $list[0];
		default:
			serve_400();
	}
}


///////////////////////////////////
// Load and verify configuration //
///////////////////////////////////

$discourse_api_key = null;
$discourse_url = null;
$discourse_proxy_debug = false;
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

$req_url_parts = explode( '?', $_SERVER['REQUEST_URI'], 2 );
$req_url_path = $req_url_parts[0];
if ( empty( $req_url_parts[1] ) ) {
	$query_params = [];
} else {
	$query_params = custom_parse_str( $req_url_parts[1] );
}

if ( $req_url_path === '/' ) {
	serve_302();
}

$api_key_body = null;
$api_username_body = null;
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
	$req_body_raw = file_get_contents( 'php://input' );
	$req_body_params = custom_parse_str( $req_body_raw );
	$api_key_body = custom_get_param( $req_body_params, 'api_key' );
	if ( $api_key_body ) {
		$req_body_params = custom_remove_param( $req_body_params, 'api_key' );
	}
	$api_username_body = custom_get_param( $req_body_params, 'api_username' );
	if ( $api_username_body ) {
		$req_body_params = custom_remove_param( $req_body_params, 'api_username' );
	}
} else if ( $_SERVER['REQUEST_METHOD'] === 'GET' ) {
	$req_body_params = [];
} else {
	serve_403();
}

// `api_key` and `api_username` may be set via headers (preferred), query
// parameters, or body parameters. This code will look in all three locations
// and then send any values found as HTTP headers. If these values are present
// in more than one location, and they are different, then the request is
// invalid.
$api_key_query = custom_get_param( $query_params, 'api_key' );
if ( $api_key_query ) {
	$query_params = custom_remove_param( $query_params, 'api_key' );
}
$api_username_query = custom_get_param( $query_params, 'api_username' );
if ( $api_username_query ) {
	$query_params = custom_remove_param( $query_params, 'api_username' );
}
$api_key_header = $_SERVER['HTTP_API_KEY'] ?? null;
$api_username_header = $_SERVER['HTTP_API_USERNAME'] ?? null;
$api_key = condense_values( [ $api_key_header, $api_key_query, $api_key_body ] );
$api_username = condense_values( [ $api_username_header, $api_username_query, $api_username_body ] );

$endpoint = $_SERVER['REQUEST_METHOD'] . ' ' . $req_url_path;
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
	if ( ! $api_key && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
		// Redirect to Discourse
		$redirect_url = $req_url_path;
		if ( ! empty( $query_params ) ) {
			$redirect_url .= '?' . custom_build_query( $query_params );
		}
		serve_302( $redirect_url );
	}

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

$remote_url = (
	rtrim( $discourse_url, '/' ) . '/' . ltrim( $req_url_path, '/' )
	. '?' . custom_build_query( $query_params )
);
$ch = curl_init( $remote_url );

$req_headers = [];
if ( $api_key ) {
	$req_headers[] = 'Api-Key: ' . $discourse_api_key;
}
if ( $api_username ) {
	$req_headers[] = 'Api-Username: ' . $api_username;
}
if ( isset( $_SERVER['HTTP_CONTENT_TYPE'] ) ) {
	$req_headers[] = 'Content-Type: ' . $_SERVER['HTTP_CONTENT_TYPE'];
}
if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
	$req_headers[] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
}
if ( isset( $_SERVER['HTTP_ACCEPT'] ) ) {
	$req_headers[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
}
if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
	$req_headers[] = 'Referer: ' . $_SERVER['HTTP_REFERER'];
}

$req_body = '';
if ( ! empty( $req_body_params ) ) {
	$req_body = custom_build_query( $req_body_params );
}

curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD'] );
curl_setopt( $ch, CURLOPT_HTTPHEADER, $req_headers );
curl_setopt( $ch, CURLOPT_SAFE_UPLOAD, true );
if ( ! empty( $req_body ) ) {
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $req_body );
}
curl_setopt( $ch, CURLOPT_HEADER, true );
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
$response = curl_exec( $ch );

$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
list( $res_headers, $res_body ) = explode( "\r\n\r\n", $response, 2 );

error_log(
	'discourse-api-proxy accepted: '
	. json_encode(
		compact( 'endpoint', 'ip', 'api_key', 'status_code' ),
		JSON_UNESCAPED_SLASHES
	)
);
if ( $discourse_proxy_debug ) {
	error_log(
		'discourse-api-proxy debug: '
		. json_encode(
			compact( 'req_headers', 'req_body', 'remote_url', 'res_headers', 'res_body' ),
			JSON_UNESCAPED_SLASHES
		)
	);
}

foreach ( explode( "\r\n", $res_headers ) as $res_header ) {
	$res_header_name = strtolower( strtok( $res_header, ':' ) );
	switch ( $res_header_name ) {
		case 'transfer-encoding':
			// Skip these headers
			break;
		default:
			header( $res_header );
	}
}

echo $res_body;
