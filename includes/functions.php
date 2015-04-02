<?php

function bogoxlib_get_site_path() {
	$parts = parse_url( site_url() );
	if ( !isset( $parts['path'] ) ) {
		return '/';
	}
	return $parts['path'];
}

function bogoxlib_localize_current_url_using_locale( $locale ) {
	return bogoxlib_localize_url_using_locale( bogoxlib_get_current_url(), $locale );
}

function bogoxlib_localize_current_url_using_lang_slug( $lang_slug ) {
	return bogoxlib_localize_url_using_lang_slug( bogoxlib_get_current_url(), $lang_slug );
}

function bogoxlib_localize_url_using_locale( $url, $locale ) {
	return bogoxlib_localize_url_using_lang_slug( $url, bogo_lang_slug( $locale ) );
}

function bogoxlib_localize_url_using_lang_slug( $url, $lang_slug ) {
	return bogoxlib_replace_url_lang_path( $url, $lang_slug );
}

function bogoxlib_delocalize_url( $url ) {
	return bogoxlib_replace_url_lang_path( $url, '' );
}

function bogoxlib_replace_url_lang_path( $url, $replacement = '' ) {

	$parts = parse_url( $url );
	$site_path = bogoxlib_get_site_path();

	// canonicalize to trailing slash
	if ( !isset( $parts['path'] ) ) {
		$parts['path'] = '/';
	}

	// save path of wp install
	if ( $site_path != '/' ) {
		$parts['path'] = str_replace( $site_path, '', $parts['path'] );
	}

	// do not use lang in url for default locale (or when replacement is empty, i.e. removal)
	if ( !$replacement || $replacement == bogo_lang_slug( bogo_get_default_locale() ) ) {
		$lang_path = '';
	} else {
		$lang_path = '/' . $replacement;
	}

	// add lang to path, possibly replacing existing lang path fragment
	if ( !preg_match( '@^/'.bogo_get_lang_regex().'/@', $parts['path'] ) ) {
		$parts['path'] = $lang_path . $parts['path'];
	} else {
		$parts['path'] = preg_replace( '@^/'.bogo_get_lang_regex().'(/.*)@', $lang_path.'$2', $parts['path'] );
	}

	// restore path of wp install
	if ( $site_path != '/' ) {
		$parts['path'] = $site_path . $parts['path'];
	}

	return bogoxlib_unparse_url( $parts );
}

function bogoxlib_unparse_url($parsed_url) {
  $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
  $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
  $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
  $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
  $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
  $pass     = ($user || $pass) ? "$pass@" : '';
  $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
  $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
  $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
  return "$scheme$user$pass$host$port$path$query$fragment";
}

function bogoxlib_move_lang_slug_from_path_to_query_string( $request_uri ) {
	return preg_replace( '@^('.trailingslashit(bogoxlib_get_site_path()).')'.bogo_get_lang_regex().'/(.*)$@', '$1$3?lang=$2', $request_uri );
}

function bogoxlib_update_rewrite_rule_query_string( $qs ) {
	$ret = preg_replace_callback(
		'@\$matches\[(\d+)\]@',
		function( $matches ) {
			return '$matches[' . ( $matches[1] + 1 ) . ']';
		},
		$qs
	);
	$ret .= '&lang=$matches[1]';
	return $ret;
}

function bogo_xlib_get_current_lang_slug() {

	// lang slug may be present in path or query vars
	// precedence given to path because query var may contain bogo locale rather than slug
	$parts = parse_url( bogoxlib_get_current_url() );
	if ( isset( $parts['path'] ) ) {
		$matches = preg_match( '@^/'.bogo_get_lang_regex().'/.*@', $parts['path'] );
		if ( $matches ) {
			return $matches[1];
		}
	}
	
	return bogo_lang_slug( get_query_var( 'lang' ) );
}

function bogoxlib_get_current_url() {
	return 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
}

function bogoxlib_redirect_user_to_localized_url() {
	$user_id = get_current_user_id();
	if ( !$user_id ) {
		return;
	}
	$user_locale = get_user_meta( $user_id, 'locale', true );
	if ( !$user_locale || $user_locale == get_locale() ) {
		return;
	}
	$current_url = bogoxlib_get_current_url();
	$localized_url = bogoxlib_localize_url_using_locale( $current_url, $user_locale );
	wp_redirect( $localized_url );
	exit();
}

function bogoxlib_get_component_path( $url ) {
	$unlocalized_url = bogoxlib_delocalize_url( $url );
	$unlocalized_parts = parse_url( $unlocalized_url );
	$site_path = bogoxlib_get_site_path();
	if ( $site_path != '/' ) {
		$unlocalized_parts['path'] = str_replace( $site_path, '', $unlocalized_parts['path'] );
	}
	return $unlocalized_parts['path'];
}

function bogoxlib_get_current_component_path() {
	return bogoxlib_get_component_path( bogoxlib_get_current_url() );
}

function bogoxlib_starts_with( $haystack, $needle ) {
	return strlen( $needle ) <= strlen( $haystack ) && 0 == strncmp( $needle, $haystack, strlen( $needle ) );
}

function bogoxlib_log( $msg ) {
	if ( WP_DEBUG === true ) {
		if ( is_array( $msg ) || is_object( $msg ) ) {
			error_log( 'bogoxlib: ' . print_r( $msg, true ) );
		} else {
			error_log( 'bogoxlib: ' . $msg );
		}
	}
}

?>
