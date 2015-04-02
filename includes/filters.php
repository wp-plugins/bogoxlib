<?php

function bogoxlib_fix_language_switcher_links( $links ) {

	foreach( $links as &$link ) {

		// skip item of current locale
		if ( $link['locale'] == get_locale() ) {
			continue;
		}

		$link['href'] = bogoxlib_localize_current_url_using_locale( $link['locale'] );
	}

	return $links;
}

?>
