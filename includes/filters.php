<?php

function bogoxlib_fix_language_switcher_links( $output ) {

	$dom = new DOMDocument;
	$dom->loadHTML( $output );
	foreach( $dom->getElementsByTagName( 'li' ) as $li) { // $li is of class DOMNode

		list( $item_language_tag, $item_language_slug ) = explode( ' ', $li->attributes->getNamedItem( 'class' )->value);

		// skip item of current locale
		if ( $item_language_tag == bogo_language_tag( get_locale() ) ) {
			continue;
		}

		$url = bogoxlib_localize_current_url_using_lang_slug( $item_language_slug );

		$a = $dom->createDocumentFragment();
		$a->appendXML( '<a href="' . esc_url( $url ) . '" hreflang="' . $item_language_tag . '" rel="alternate">' . $li->nodeValue . '</a>');
		$li->nodeValue = '';
		$li->appendChild( $a );
	}

	return $dom->saveHTML();
}

?>
