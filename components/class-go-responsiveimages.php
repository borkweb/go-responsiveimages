<?php

class GO_ResponsiveImages
{
	/**
	 * constructor!
	 */
	public function __construct()
	{
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'init' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ) );
		add_filter( 'the_content', array( $this, 'the_content' ), 99 );
	}//end __construct

	/**
	 * hooked to the init action
	 */
	public function init()
	{
		$this->register_image_sizes();
		$this->register_density_image_sizes();
	}//end init

	public function wp_enqueue_scripts()
	{
		$script_config = apply_filters( 'go_config', array( 'version' => 1 ), 'go-script-version' );

		wp_register_script(
			'picturefill',
			plugins_url( 'js/lib/external/picturefill.js', __FILE__ ),
			array(),
			$script_config['version']
		);

		wp_enqueue_script( 'picturefill' );
	}//end wp_enqueue_scripts

	public function register_image_sizes()
	{
		// @TODO: declare these in a config
		add_image_size( 'story-cantilevered', 300 );
		add_image_size( 'story-small', 320 );
		add_image_size( 'story-small-plus', 640 );
		add_image_size( 'story-breakout', 804 );
	}//end register_image_sizes

	/**
	 * registers higher density image sizes (2x and 3x) for all already registered image sizes
	 */
	public function register_density_image_sizes()
	{
		global $_wp_additional_image_sizes;
		$sizes = array();

		$image_sizes = get_intermediate_image_sizes();

		foreach ( $image_sizes as $size )
		{
			$sizes[ $size ] = array();

			if ( in_array( $size, array( 'thumbnail', 'medium', 'large' ) ) )
			{
				$sizes[ $size ]['width'] = get_option( "{$size}_size_w" );
				$sizes[ $size ]['height'] = get_option( "{$size}_size_h" );
				$sizes[ $size ]['crop'] = (bool) get_option( "{$size}_crop" );
			}//end if

			if ( ! empty( $_wp_additional_image_sizes[ $size ] ) )
			{
				$sizes[ $size ] = array(
					'width' => $_wp_additional_image_sizes[ $size ]['width'],
					'height' => $_wp_additional_image_sizes[ $size ]['height'],
					'crop' => $_wp_additional_image_sizes[ $size ]['crop'],
				);
			}//end if
		}//end foreach

		$size_keys = array_keys( $sizes );
		$densities = array( 2, 3 );

		foreach ( $sizes as $size => $size_data )
		{
			if ( FALSE !== strpos( $size, '_go_responsiveimages_density' ) )
			{
				continue;
			}//end if

			foreach ( $densities as $density )
			{
				$key = "{$size}_go_responsiveimages_density_{$density}x";

				if ( ! in_array( $key, $size_keys ) )
				{
					add_image_size( $key, $size_data['width'] * $density, $size_data['height'] * $density, $size_data['crop'] );
				}//end if
			}//end foreach
		}//end foreach
	}//end register_density_image_sizes

	/**
	 * hooked to the the_content filter to swap out images for responsive ones
	 */
	public function the_content( $content )
	{
		// Don't load for feeds, previews, and attachment pages
		if ( is_preview() || is_feed() || is_attachment() )
		{
			return $content;
		}//end if

		$doc = new DOMDocument();
		try
		{
			$doc->loadHTML( $content );
		}//end try
		catch( Exception $e )
		{
			// unable to parse the content. Let's not do responsive images then.
			return $content;
		}//end catch

		$xpath = new DOMXpath( $doc );

		$images = $doc->getElementsByTagName( 'img' );

		// if there aren't any images, let's bail
		if ( empty( $images ) || ! $images->length )
		{
			return $content;
		}//end if

		// create a picture node once so we don't need to create it for every image
		$picture_node = $doc->createElement( 'picture' );
		$ie_fix_start = new DOMComment( '[if IE 9]><video style="display: none;"><![endif]' );
		$ie_fix_end = new DOMComment( '[if IE 9]></video><![endif]' );

		$image_sizes = get_intermediate_image_sizes();
		$image_settings = array(
			'base' => 'small',
		);

		// loop over the images, wrap them in a picture element and add responsive element siblings
		foreach ( $images as $image )
		{
			$image_url = $image->getAttribute( 'src' );
			$image_id = $this->url_to_attachment_id( $image_url );

			if ( ! $image_id )
			{
				continue;
			}//end if

			$image_class = $image->getAttribute( 'class' );
			$parent_class = '';

			if ( $image_parent = $image->parentNode )
			{
				if ( 'a' == $image_parent->tagName )
				{
					$image_parent = $image_parent->parentNode;
				}//end if

				$parent_class = $image_parent->getAttribute( 'class' );
			}//end if

			// find the current size of the image if it is set
			$current_size = NULL;

			if ( preg_match( '/size-([^\s]+)/', $image_class, $matches ) )
			{
				$current_size = $matches[1];
			}//end if

			// clone the picture node
			$cloned_picture_node = $picture_node->cloneNode();

			// replace the image in the DOMDocument with the picture node
			$image->parentNode->replaceChild( $cloned_picture_node, $image );

			// start IE 9 fix (IE doesn't like <source> elements outside of the video tag
			$cloned_picture_node->appendChild( $ie_fix_start->cloneNode() );

			$large_source = $doc->createElement( 'source' );

			// @TODO: drive this if/elseif from a config
			if (
				FALSE !== strpos( $parent_class, 'aligncenter' )
				|| FALSE !== strpos( $image_class, 'aligncenter' )
			)
			{
				$large_source->setAttribute( 'srcset', $this->get_image_srcset( $image_id, 'story-breakout' ) );
				$large_source->setAttribute( 'media', '(min-width: 641px)' );
				$cloned_picture_node->appendChild( $large_source );
			}//end if
			elseif (
				FALSE !== strpos( $image_class, 'alignleft' )
				|| FALSE !== strpos( $image_class, 'alignright' )
				|| FALSE !== strpos( $parent_class, 'alignleft' )
				|| FALSE !== strpos( $parent_class, 'alignright' )
			)
			{
				$large_source->setAttribute( 'srcset', $this->get_image_srcset( $image_id, 'story-cantilevered' ) );
				$large_source->setAttribute( 'media', '(min-width: 970px)' );
			}//end elseif

			$cloned_picture_node->appendChild( $large_source );

			$small_plus_source = $doc->createElement( 'source' );
			$small_plus_source->setAttribute( 'srcset', $this->get_image_srcset( $image_id, 'story-small-plus' ) );
			$small_plus_source->setAttribute( 'media', '(min-width: 321px)' );
			$cloned_picture_node->appendChild( $small_plus_source );

			$size = $current_size ?: 'story-small';
			$default_size = wp_get_attachment_image_src( $image_id, $size );
			do_action( 'debug_robot', $size . ' :: ' . print_r( $default_size, TRUE ) );

			$image->setAttribute( 'src', $default_size[0] );
			$image->setAttribute( 'srcset', $this->get_image_srcset( $image_id, $size ) );

			// end IE 9 fix (IE doesn't like <source> elements outside of the video tag
			$cloned_picture_node->appendChild( $ie_fix_end->cloneNode() );

			// add the image as a child of the picture node
			$cloned_picture_node->appendChild( $image );
		}//end foreach

		// find the body tag
		$body = $doc->getElementsByTagName( 'body' );

		// if it errors out, bail
		if ( ! $body || $body->length <= 0 )
		{
			return $content;
		}//end if

		// get the one and only body element
		$body = $body->item( 0 );

		// replace the opening and closing body tag from the source
		$content = preg_replace( '#((^<body>)|(</body>$))#', '', $doc->saveHTML( $body ) );

		return $content;
	}//end the_content

	/**
	 * Build a srcset density string
	 */
	public function get_image_srcset( $image_id, $image_size )
	{
		$size_1x = wp_get_attachment_image_src( $image_id, $image_size );
		$size_2x = wp_get_attachment_image_src( $image_id, "{$image_size}_go_responsiveimages_density_2x" );
		$size_3x = wp_get_attachment_image_src( $image_id, "{$image_size}_go_responsiveimages_density_3x" );
		do_action( 'debug_robot', print_r( $size_1x, TRUE ) );

		return "{$size_1x[0]}, {$size_2x[0]} 2x, {$size_3x[0]} 3x";
	}//end get_image_srcset

	public function url_to_attachment_id( $image_url )
	{
		global $wpdb;

		$original_image_url = $image_url;

		$image_url = preg_replace( '/^(.+?)(-\d+x\d+)?\.(jpg|jpeg|png|gif)((?:\?|#).+)?$/i', '$1.$3', $image_url );
		$sql = "SELECT ID FROM {$wpdb->prefix}posts WHERE guid = '%s'";
		$attachment_id = $wpdb->get_col( $wpdb->prepare( $sql, $image_url ) );

		if ( empty( $attachment_id ) )
		{
			$attachment_id = $wpdb->get_col( $wpdb->prepare( $sql, $original_image_url ) );
		}//end if

		return empty( $attachment_id ) ? NULL : $attachment_id[0];
	}//end url_to_attachment_id
}//end class

function go_responsiveimages()
{
	global $go_responsiveimages;

	if ( ! $go_responsiveimages )
	{
		$go_responsiveimages = new GO_ResponsiveImages;
	}//end if

	return $go_responsiveimages;
}//end go_responsiveimages
