<?php

/**
 * Main wrapper class of the plugin.
 */
class Improved_Comment_Moderation {

	static function on_load() {

		add_action( 'admin_init', array( __CLASS__, 'admin_init' ) );
	}

	static function admin_init() {

		add_action( 'load-edit-comments.php', array( __CLASS__, 'load_pagenow' ) );
		add_filter( 'manage_edit-comments_columns', array( __CLASS__, 'manage_columns' ) );
		add_action( 'manage_comments_custom_column', array( __CLASS__, 'manage_comments_custom_column' ), 10, 2 );
		add_filter( 'comment_text', array( __CLASS__, 'comment_text' ) );
	}

	static function load_pagenow() {

		add_action( 'admin_print_styles', array( __CLASS__, 'admin_print_styles' ) );
	}

	static function admin_print_styles() {
		?>
	<style type="text/css">
		.column-gravatar {
			width: 64px;
		}

		.column-author_improved {
			width: 20%;
		}

		.column-comment a {
			text-decoration: underline;
		}

		.column-comment .approved, .column-comment .approved a {
			color: green;
		}

		.column-comment .spam, .column-comment .spam a {
			color: red;
		}

		.column-comment em {
			/*color: grey;*/
		}

		.column-comment ul ul li {
			padding-left: 10px;
		}
	</style>
	<?php
	}

	/**
	 * @param array $columns setup for comments table
	 *
	 * @return array
	 */
	static function manage_columns( $columns ) {

		remove_filter( 'comment_author', 'floated_admin_avatar' );
		$add_columns = array();

		if ( get_option( 'show_avatars' ) )
			$add_columns['gravatar'] = 'Gravatar';

		$columns = array_merge(
			array_slice( $columns, 0, 1 ),
			$add_columns,
			array_slice( $columns, 1 )
		);

		unset( $columns['author'] );

		return $columns;
	}

	/**
	 * @param string $text of comment
	 *
	 * @return string
	 */
	static function comment_text( $text ) {

		return self::author_improved() . $text;
	}

	/**
	 * @param string $column name
	 * @param int    $id     of comment
	 */
	static function manage_comments_custom_column( $column, $id ) {

		global $comment;

		if ( 'gravatar' == $column ) {

			if ( empty( $comment->comment_author_email ) ) {

				$blank = 'blank';
			} else {

				$email_counts = self::get_approved_counts( 'comment_author_email', get_comment_author_email( $id ) );
				$blank        = empty( $email_counts['approved'] ) ? 'blank' : '';
			}

			echo get_avatar( $comment->comment_author_email, 64, $blank );
		}
	}

	/**
	 * @return string improved author details
	 */
	static function author_improved() {

		global $wp_list_table, $comment, $comment_status;

		$output = '';

		$author  = self::get_colored_span( 'comment_author', get_comment_author() );
		$output .= '<strong>' . $author . '</strong>' . self::get_pending_text( 'comment_author', $author ) . '<br />';

		$author_url = get_comment_author_url();

		if ( 'http://' == $author_url )
			$author_url = '';

		if ( ! empty( $author_url ) ) {

			$url = parse_url( str_replace( '&#038;', '&', $author_url ) );

			$output .= "<br /><a href='{$author_url}'>" . self::get_colored_span( 'comment_author_url', "%{$url['host']}%", true, $url['host'] );

			if ( ! empty( $url['path'] ) && '/' != $url['path'] )
				$output .= '<br />' . str_repeat( '&nbsp;', 8 ) . esc_html( $url['path'] );

			if ( ! empty( $url['query'] ) )
				$output .= '<br />' . str_repeat( '&nbsp;', 8 ) . '?' . esc_html( $url['query'] );

			if ( ! empty( $url['fragment'] ) )
				$output .= '<br />' . str_repeat( '&nbsp;', 8 ) . '#' . esc_html( $url['fragment'] );

			$output .= '</a><br />';
		}

		if ( $wp_list_table->user_can ) {

			if ( ! empty( $comment->comment_author_email ) ) {

				$email   = get_comment_author_email();
				$email   = self::get_colored_span( 'comment_author_email', $email );
				$output .= '<br />' . get_comment_author_email_link( $email ) . self::get_pending_text( 'comment_author_email', $email ) . '<br />';
			}

			$ip = get_comment_author_IP();
			$ip = self::get_colored_span( 'comment_author_IP', $ip );
			$output .= '<br /><a href="edit-comments.php?s=' . get_comment_author_IP() . '&amp;mode=detail';

			if ( 'spam' == $comment_status )
				$output .= '&amp;comment_status=spam';

			$output .= "\">{$ip}</a>";
			$output .= self::get_pending_text( 'comment_author_IP', $ip );
		}

		$output .= '<br /><br />';

		return $output;
	}

	/**
	 * @param string  $field name of database field
	 * @param string  $value value to count for
	 * @param bool    $like  fuzzy or strict comparison
	 * @param string  $text  anchor text
	 *
	 * @return string $text wrapped in span with class if known good/bad $value
	 */
	static function get_colored_span( $field, $value, $like = false, $text = '' ) {

		if ( empty( $text ) )
			$text = $value;

		if ( ! empty( $_REQUEST['comment_status'] ) && in_array( $_REQUEST['comment_status'], array( 'spam', 'approved' ) ) )
			return $text;

		$counts = wp_parse_args( self::get_approved_counts( $field, $value, $like ), array(
			'approved' => 0,
			'spam'     => 0,
		) );

		$approved = $counts['approved'];
		$spam     = $counts['spam'];

		if ( $approved > $spam )
			return "<span class='approved'>{$text}</span>";

		if ( $spam > $approved )
			return "<span class='spam'>{$text}</span>";

		return $text;
	}

	/**
	 * @param string  $field name of database field
	 * @param string  $value value to count for
	 * @param bool    $like  fuzzy or strict comparison
	 *
	 * @return array of counts for approved and spam statuses
	 */
	static function get_approved_counts( $field, $value, $like = false ) {

		global $wpdb;

		$key = md5( 'approved_' . $field . $value );

		$counts = wp_cache_get( $key, 'comment-moderation' );

		if ( ! is_array( $counts ) ) {

			if ( $like )
				$where = $wpdb->prepare( "{$field} LIKE %s", $value );
			else
				$where = $wpdb->prepare( "{$field} = %s", $value );

			$results = $wpdb->get_results( "SELECT comment_approved AS status, COUNT(*) AS count FROM {$wpdb->comments} WHERE {$where} GROUP BY comment_approved;" );

			$counts = array();

			foreach ( $results as $result ) {

				if ( 1 == $result->status )
					$counts['approved'] = $result->count;
				elseif ( 'spam' == $result->status )
					$counts['spam'] = $result->count;
			}

			wp_cache_set( $key, $counts, 'comment-moderation', 30 );
		}

		return $counts;
	}

	/**
	 * @param string  $field name of database field
	 * @param string  $value value to count for
	 * @param bool    $like  fuzzy or strict comparison
	 *
	 * @return string linkified number of matching pending comments or empty
	 */
	static function get_pending_text( $field, $value, $like = false ) {

		$text    = '';
		$pending = self::get_pending_count( $field, $value, $like );

		if ( 1 < $pending ) {

			$text = ' (<a href="edit-comments.php?s=' . $value . '&amp;mode=detail">' . (int) $pending . ' pending</a>)';
		}

		return $text;
	}

	/**
	 * @param string  $field name of database field
	 * @param string  $value value to count for
	 * @param bool    $like  fuzzy or strict comparison
	 *
	 * @return int number of matching pending comments
	 */
	static function get_pending_count( $field, $value, $like = false ) {

		global $wpdb;

		$key   = md5( 'pending_' . $field . $value );
		$count = wp_cache_get( $key, 'comment-moderation' );

		if ( ! is_numeric( $count ) ) {

			if ( $like )
				$where = $wpdb->prepare( "{$field} LIKE %s AND comment_approved = '0'", $value );
			else
				$where = $wpdb->prepare( "{$field} = %s AND comment_approved = '0'", $value );

			$count = $wpdb->get_var( "SELECT COUNT(*) AS count FROM {$wpdb->comments} WHERE {$where};" );

			wp_cache_set( $key, $count, 'comment-moderation', 30 );
		}

		return (int)$count;
	}
}