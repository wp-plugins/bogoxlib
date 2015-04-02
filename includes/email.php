<?php

/* localize any and all emails sent by wordpress or plugins (that use wp_mail())
 * (c) 2015 Markus Echterhoff, Licence: GPLv3 or later, donate at http://www.markusechterhoff.com/donation
 *
 * usage: 	-> 	call bogoxlib_localize_emails_for( $domain, $url_localization_enabled_paths, $registered_strings )
 *				parameters:
 *					$domain: the text domain you wish to monitor
 *					$url_localization_enabled_paths: a path or an array of paths that url localization should
 *						be enabled for. that means that links inside emails that point to certain paths on your site
 *						will be localized before the email is sent.
 *						example: say BBPress has its forums at http://example.com/forums/ then passing '/forums/'
 *						will localize all BBPress specific urls inside any emails sent use full paths. if your wordpress
 *						is installed in 'example.com/blog' pass the relative path '/forums/'.
 *					$registered_strings: if you notice that in one of your translated mails there is only a single word
 *						or other short passage translated, then add the original text of the message here. can be a single
 *						string or array of strings. e.g. BBPress will match 'topic' and translate only that word in your
 *						mail and leave the rest untranslated. by adding the whole email string here, it is given precendence
 *						and will correctly be translated. in short: try adding the original strings here if some emails
 *						are not properly translated.
 *				
 *			->	call above function from 'plugins_loaded' hook with very low priority, i.e. ~PHP_INT_MAX (note the ~)
 *
 *			-> 	the default behavior is to look for translations in all domains registered per the above call
 *			  	and retranslate using existing .mo files of the email sending components (e.g. plugins)
 *
 *			-> 	you can override the default by hooking the filter 'bogoxlib_translate_email'
 *				parameters:
 *					$email: key/value array with keys: 'to',  'subject', 'message', 'headers' and 'attachments' )
 *					$locales_by_email: an associative array with email addresses as keys and locales as values
 *					$email_locale: the locale the email is being sent in
 *				returns: translated $email or array of translated $emails, e.g. to split CCs by recipient locales )
 *					IMPORTANT: you'll receive all emails sent by wordpress so make sure to return unmodified those
 *							which do not belong to the plugin you are translating
 *				note 1: inside the filter you can call:
 *					bogoxlib_retranslate_this_email_field( $text, $domain, $target_locale )
 *					on any email fields you wish translated using mo files ( usually 'subject' and 'message' )
 *					in case the same email is send many times: don't worry, retranslations are cached
 *
 * how it works: so here is the magic explained: for any domain registered as explained above, we collect the strings
 *				 that are being translated by calling __( 'string', 'domain' ). we also sack all emails sent
 *				 via wp_mail(). at the end of it all, via the 'shutdown' hook, we look at all the emails and reverse-
 *				 engineer the original translated strings and any variables used. we look up the user's email in the
 *				 users table and get their locale meta value. then we re-translate to the user's language setting and
 *				 send the mail.
 */
 
function bogoxlib_localize_emails_for( $domain, $url_localization_enabled_paths='', $registered_strings='' ) {
	BogoXLibEmailLocalizer::get_instance()->localize_emails( $domain, $url_localization_enabled_paths, $registered_strings );
}

function bogoxlib_retranslate_this_email_field( $text, $domain, $target_locale ) {
	return BogoXLibEmailLocalizer::get_instance()->retranslate_this( $text, $domain, $target_locale );
}

require_once ABSPATH . WPINC . '/class-phpmailer.php';
require_once ABSPATH . WPINC . '/class-smtp.php';
class BogoXLibHappyMailman extends PHPMailer {
	public function Send() {
		return true;
	}	
}

class BogoXLibTranslationEntry {
	public $original;
	public $pattern;
}

class BogoXLibEmailLocalizer {

	private static $instance;
	
	private $initialized = false;
	
	private $domains = array();
	private $translations = array();
	private $dictionaries = array();
	private $mofiles = array();
	private $emails = array();
	private $pattern_tables = array();
	private $locales_by_email = array();
	private $fragment_cache = array();
	private $url_localization_enabled_paths = array();
	private $registered_strings = array();
	
	private $saved_locale;
	private $saved_wp_filter_locale;
	private $saved_wp_merged_filters_locale;
	
	private $home_host;
		
	private function __construct() {
		$this->home_host = parse_url( home_url() )['host'];
	}
	
	/*
	 * public methods
	 */
	
	public static function get_instance() {
		if ( !isset( self::$instance ) ) {
			self::$instance = new BogoXLibEmailLocalizer;
		}
		return self::$instance;
	}
	
	public function localize_emails( $domain, $url_localization_enabled_paths='', $registered_strings='' ) {

		if ( !$this->initialized ) {
			add_filter( 'load_textdomain', array( $this, 'load_textdomain_set_mofile_action' ), 10, 2 );
			add_filter( 'gettext', array( $this, 'gettext_recording_filter' ), 10, 3 );
			add_filter( 'wp_mail', array( $this, 'wp_mail_email_collection_filter' ) );
			add_action( 'shutdown', array( $this, 'shutdown_translate_and_send_action' ) );
			$this->initialized = true;
		}

		if ( !in_array( $domain, $this->domains ) ) {
			$this->domains[]= $domain;
			$this->translations[$domain] = array();
			$this->dictionaries[$domain] = array();
			$this->mofiles[$domain] = array();
		}
		
		if ( $url_localization_enabled_paths ) {
			if ( is_array( $url_localization_enabled_paths ) ) {
				$url_localization_enabled_paths = array_map( 'trailingslashit', $url_localization_enabled_paths );		
				$this->url_localization_enabled_paths =
						array_unique(array_merge($this->url_localization_enabled_paths, $url_localization_enabled_paths));
			} else {
				$this->url_localization_enabled_paths []= trailingslashit( $url_localization_enabled_paths );
			}
		}
		
		if ( $registered_strings ) {
			if ( is_array( $registered_strings ) ) {
				$this->registered_strings = array_unique(array_merge($this->registered_strings, $registered_strings));
			} else {
				$this->registered_strings []= $registered_strings;
			}
		}
	}
	
	public function retranslate_this( $fragment, $domain, $target_locale ) {

		if ( !isset($this->translations[$domain] ) ) {
			return "Retranslation error: Text domain '$domain' is not registered. Fix spelling or register by calling: bogoxlib_localize_emails_for( '$domain' );";
		}
	
		// return early if there is no need to translate
		if ( $target_locale == $this->saved_locale ) {
			return $fragment;
		}
		
		// check cache
		if ( isset( $this->fragment_cache[$fragment] ) ) {
			return $this->fragment_cache[$fragment];
		}

		$retranslated_fragment = $this->retranslate_this_recursive( $fragment, $domain, $target_locale );
		
		if ( $retranslated_fragment != $fragment ) {
			$this->fragment_cache[$fragment] = $retranslated_fragment;
		}
		
		return $retranslated_fragment;
	}
	
	/*
	 * filters and actions
	 */
	 
	public function load_textdomain_set_mofile_action( $domain, $mofile ) {
		if ( in_array( $domain, $this->domains ) ) {
			$this->mofiles[$domain] = $mofile;
		}
	}
	 
	public function gettext_recording_filter( $translation, $original, $domain ) {
		if ( isset( $this->translations[$domain] ) ) {
			$entry = new BogoXLibTranslationEntry;
			$entry->translation = $translation;
			$entry->original = $original;
			$entry->pattern = '@' . str_replace( array( '@', '%s' ), array( '\@', '(.*)' ), $translation ) . '@';
			$entry->pattern = preg_replace( '@%\d+\$s@', '(.*)', $entry->pattern );
			if ( in_array( $original, $this->registered_strings ) ) {
				array_unshift( $this->translations[$domain], $entry ); // registered strings go in front
			} else {
				$this->translations[$domain] []= $entry;
			}
		}
		
		return $translation;
	}

	public function wp_mail_email_collection_filter( $args ) {

		global $phpmailer;
		if ( !is_object( $phpmailer ) || !is_a( $phpmailer, 'BogoXLibHappyMailman' ) ) {
			$phpmailer = new BogoXLibHappyMailman;
		}

		$this->emails[] = $args;
	
		return $args;
	}

	public function shutdown_translate_and_send_action() {
		global $locale, $l10n, $phpmailer, $wpdb;

		remove_filter( 'load_textdomain', array( $this, 'load_textdomain_set_mofile_action' ) );
		remove_filter( 'wp_mail', array( $this, 'wp_mail_email_collection_filter' ) );
		remove_filter( 'gettext', array( $this, 'gettext_recording_filter' ) );

		if ( empty( $this->emails ) ) {
			return;
		}

		$this->saved_locale = get_locale();
		$this->remove_locale_filters();
		
		// buffer currently loaded text domain, so we have to load one less later on
		foreach ( $this->domains as $domain ) {
			$this->dictionaries[$domain][$this->saved_locale] = $l10n[$domain];
		}

		$addresses = array();
		foreach ( $this->emails as $email ) {
			$addresses[] = $email['to'];
			if ( isset( $email['headers'] ) && is_array( $email['headers'] ) ) {
				foreach ( $email['headers'] as $header ) {
					if ( 0 == strncasecmp( $header, 'cc', 2 ) ) {
						$addresses[] = substr( $header, 4, strlen( $header ) - 4 );
					} else if ( 0 == strncasecmp( $header, 'bcc', 3 ) ) {
						$addresses[] = substr( $header, 5, strlen( $header ) - 5 );
					}
				}
			}
		}
		$addresses = array_unique( $addresses );

		// get locales of all email recipients from db
		$in_addresses = implode(', ', array_map( function( $a ){return '\''.esc_sql($a).'\'';}, $addresses) );
		$results = $wpdb->get_results( "
				SELECT u.email, m.locale
				FROM (
					SELECT ID as id, user_email AS email
					FROM {$wpdb->users}
					WHERE user_email IN ($in_addresses)
				) u
				LEFT OUTER JOIN (
					SELECT user_id, meta_value AS locale
					FROM {$wpdb->usermeta}
					WHERE meta_key='locale'
				) m
				ON u.id=m.user_id;", OBJECT );
		$default_locale = bogo_get_default_locale();
		foreach ( $results as $user ) {
			$this->locales_by_email[$user->email] = $user->locale ? $user->locale : $default_locale;
		}

		// restore real php mailer
		$phpmailer = new PHPMailer( true );

		// retranslate and send all remaining collected emails
		foreach ( $this->emails as $email ) {

			if ( has_filter( 'bogoxlib_translate_email' ) ) {

				// apply user defined email translation algorithms
				// filter can return email or array of emails in case we'd like to split up CCs or something
				$filtered = apply_filters( 'bogoxlib_translate_email', $email, $this->locales_by_email, $this->saved_locale );

				if ( $filtered != $email ) {
					if ( is_array( $filtered ) ) {
						foreach ( $filtered as $filtered_single ) {
							$this->send( $filtered_single );
						}
					} else {
						$this->send( $filtered );
					}
					continue;
				}
			}

			// send untranslatable emails and remove them from email list
			// 	- if we don't know the recipient
			// 	- if we don't know their user locale
			// 	- if email has already been translated to user's language
			if ( !isset( $this->locales_by_email[$email['to']] ) ||
					!$this->locales_by_email[$email['to']] ||
					$this->locales_by_email[$email['to']] == $this->saved_locale ) {
				$this->send( $email );
				continue;
			}

			// attempt gettext retranslation using all registered domains
			foreach ( $this->domains as $domain ) {
				$subject = $this->retranslate_this( $email['subject'], $domain, $this->locales_by_email[$email['to']] );
				if ( $subject != $email['subject'] ) {
					$message = $this->retranslate_this( $email['message'], $domain, $this->locales_by_email[$email['to']] );
					if ( $message != $email['message'] ) {
						$email['subject'] = $subject;
						$email['message'] = $message;
						$this->send( $email );
						break;
					}
				}
			}
		}			

		$this->restore_locale_filters();
		$locale = $this->saved_locale;
	}
	
	/*
	 * private methods
	 */
	
	private function retranslate_this_recursive( $fragment, $domain, $target_locale ) {

		foreach ( $this->translations[$domain] as $translation => $entry ) {

			$matches = preg_match( $entry->pattern, $fragment, $vars );

			// on match, we create a new translation from the original using the current (modified) global $locale
			if ( $matches ) {

				list( $prefix, $postfix ) = preg_split( $entry->pattern, $fragment );

				array_shift( $vars );

				$this->set_domain_dictionary( $domain, $target_locale );
				
				$retranslated = __( $entry->original, $domain );

				$retranslated_fragment = $prefix . vsprintf( $retranslated, $vars ) . $postfix;

				$retranslated_fragment = $this->localize_urls( $retranslated_fragment, $target_locale );

				// recursion so that we get strung together localizations working
				// e.g. $msg = _('my message'); if ( condition ) { $msg = $msg . __('what I forgot to add...'); }
				if ( $retranslated_fragment != $fragment ) {
					return $this->retranslate_this_recursive( $retranslated_fragment, $domain, $target_locale ) ;
				}
			}
		}
		
		return $fragment;
	}
	
	private function localize_urls( $text, $target_locale ) {
		return preg_replace_callback(
					
			// https://gist.github.com/dperini/729294 adapted to match localhost
			'_(?:(?:https?|ftp)://)(?:\S+(?::\S*)?@)?(?:localhost|(?:(?:(?!(?:10|127)(?:\.\d{1,3}){3})(?!(?:169\.254|192\.168)(?:\.\d{1,3}){2})(?!172\.(?:1[6-9]|2\d|3[0-1])(?:\.\d{1,3}){2})(?:[1-9]\d?|1\d\d|2[01]\d|22[0-3])(?:\.(?:1?\d{1,2}|2[0-4]\d|25[0-5])){2}(?:\.(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-4]))|(?:(?:[a-z\x{00a1}-\x{ffff}0-9]-*)*[a-z\x{00a1}-\x{ffff}0-9]+)(?:\.(?:[a-z\x{00a1}-\x{ffff}0-9]-*)*[a-z\x{00a1}-\x{ffff}0-9]+)*(?:\.(?:[a-z\x{00a1}-\x{ffff}]{2,})))))(?::\d{2,5})?(?:/\S*)?_iu',
		
			function( $matches ) use ( $target_locale ) {
				$url = $matches[0];
				$parts = parse_url( $url );
				if ( $parts['host'] != $this->home_host ) {
					return $url;
				}
				if ( !isset( $parts['path'] ) ) {
					return $url;
				}
				$component_path = trailingslashit( bogoxlib_get_component_path( $url ) );
				foreach ( $this->url_localization_enabled_paths as $path ) {
					if ( bogoxlib_starts_with( $component_path, $path ) ) {
						return bogoxlib_localize_url_using_locale( $url, $target_locale );
				 	}
				}
		 		return $url;
			},
		
			$text
		);
	}
	
	private function set_domain_dictionary( $domain, $target_locale ) {
		global $locale, $l10n;

		if ( $target_locale == 'en_US' ) {
			$locale = $target_locale;
			$l10n[$domain] = new NOOP_Translations;
			return;
		}

		// load text domain from .mo file if we don't yet have it buffered
		if ( !isset( $this->dictionaries[$domain][$target_locale] ) ) {
			$oldmo = $this->mofiles[$domain];
			$newmo = dirname( $oldmo ) . '/' . $domain . '-' . $target_locale . '.mo'; // try e.g. /path/domain-de_DE.mo
			unload_textdomain( $domain );
			$success = load_textdomain( $domain, $newmo );
			if ( !$success ) {
				$newmo = dirname( $oldmo ) . '/' . $target_locale . '.mo'; // try e.g. /path/de_DE.mo
				load_textdomain( $domain, $newmo );
			}
			if ( isset( $l10n[$domain] ) ) {
				$this->dictionaries[$domain][$target_locale] = $l10n[$domain];
			}
		}
		
		$locale = $target_locale;
		$l10n[$domain] = $this->dictionaries[$domain][$target_locale];
	}
	
	private function remove_locale_filters() {
		global $wp_filter, $merged_filters;
		
		if ( isset( $wp_filter['locale'] ) ) {
			$this->saved_wp_filter_locale = $wp_filter['locale'];
		}
		
		if ( isset( $merged_filters['locale'] ) ) {
			$this->saved_wp_merged_filters_locale = $merged_filters['locale'];
		}
		
		remove_all_filters( 'locale' );
	}
	
	private function restore_locale_filters() {
	
		global $wp_filter, $merged_filters;
	
		if ( isset( $this->saved_wp_filter_locale ) ) {
			$wp_filter['locale'] = $this->saved_wp_filter_locale;
		}
	
		if ( isset( $this->saved_wp_merged_filters_locale ) ) {
			$merged_filters['locale'] = $this->saved_wp_merged_filters_locale;
		}
	}
	
	private function send( $email ) {
		//bogoxlib_log( $email );
		wp_mail( $email['to'],  $email['subject'],  $email['message'],  $email['headers'],  $email['attachments'] );
	}
}

?>
