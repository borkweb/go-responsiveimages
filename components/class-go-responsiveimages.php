<?php

class GO_ResponsiveImages
{
	private $config = NULL;
	private $attachment_id_cache_group = 'go-responsiveimages-attachment-id';

	/**
	 * constructor!
	 */
	public function __construct()
	{
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'init' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ) );
	}//end __construct

	/**
	 * Loads config settings
	 */
	public function config( $key = NULL )
	{
		if ( ! $this->config )
		{
			$defaults = array(
				'sources-by-class' => array(),
			);

			$this->config = apply_filters( 'go_config', $defaults, 'go-responsiveimages' );
		}//end if

		if ( $key )
		{
			return isset( $this->config[ $key ] ) ? $this->config[ $key ] : NULL;
		}//end if

		return $this->config;
	}//end config

	/**
	 * hooked to the init action
	 */
	public function init()
	{
		$this->register_density_image_sizes();
	}//end init

	/**
	 * hooked to the wp_enqueue_scripts action
	 */
	public function wp_enqueue_scripts()
	{
		$script_config = apply_filters( 'go_config', array( 'version' => 1 ), 'go-script-version' );

		wp_register_script(
			'picturefill',
			plugins_url( 'js/min/external/picturefill.js', __FILE__ ),
			array(),
			$script_config['version']
		);

		wp_enqueue_script( 'picturefill' );
	}//end wp_enqueue_scripts

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
	 * Alters content to replace img tags with responsive <picture> tags
	 */
	public function replace_images( $content )
	{
		// Don't load for feeds, previews, and attachment pages
		if ( is_preview() || is_feed() || is_attachment() )
		{
			return $content;
		}//end if

		// disable the ability to load external entities. See: http://wordpress.tv/2013/08/09/mike-adams-three-security-issues-you-thought-youd-fixed/
		libxml_disable_entity_loader( TRUE );

		// we're wrapping the content in an html/body tag with a charset meta tag to ensure proper UTF-8 encoding
		$wrapped_content = '<html><head><meta http-equiv="content-type" content="text/html; charset=UTF-8"></head><body>' . $content . '</body></html>';
		$doc = new DOMDocument( '1.0', 'UTF-8' );
		try
		{
			$doc->loadHTML( $wrapped_content );
		}//end try
		catch( Exception $e )
		{
			// unable to parse the content. Let's not do responsive images then.
			return $content;
		}//end catch

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
				if ( isset( $matches[1] ) && 'full' != $matches[1] )
				{
					$current_size = $matches[1];
				}//end if
			}//end if

			// clone the picture node
			$cloned_picture_node = $picture_node->cloneNode();

			// replace the image in the DOMDocument with the picture node
			$image->parentNode->replaceChild( $cloned_picture_node, $image );

			// start IE 9 fix (IE doesn't like <source> elements outside of the video tag
			$cloned_picture_node->appendChild( $ie_fix_start->cloneNode() );

			$twiddled = $this->twiddle_image( $doc, $image, $image_id, $image_class, $parent_class, $cloned_picture_node );

			// if the image wasn't successfully twiddled, we at least want to twiddle it enough to add image densities
			if ( ! $twiddled && $current_size )
			{
				$default_size = wp_get_attachment_image_src( $image_id, $current_size );
				$image->setAttribute( 'src', $default_size[0] );
				$image->setAttribute( 'srcset', $this->get_image_srcset( $image_id, $current_size ) );
			}//end if

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
		$new_content = $doc->saveHTML( $body );

		if ( ! $new_content )
		{
			return $content;
		}//end if

		// replace the opening and closing body tag from the source
		$new_content = preg_replace( '#((^<body>)|(</body>$))#', '', $new_content );

		return $new_content;
	}//end replace_images

	/**
	 * Injects picture <source> elements with srcset densities AND adjusts the src and srcset of the img tag
	 *
	 * @param DOMDocument $doc Document object created from the snippet of code we're switching images over to picture elements
	 * @param DOMNode $image Image found in the DOMDocument that we're attempting to replace
	 * @param int $image_id WP Post ID for the image
	 * @param string $image_class The contents of the img class attribute
	 * @param string $parent_class The contents of the img's parent (or grandparent if the immediate parent is an anchor tag)
	 * @param DOMNode $picture_node The picture node to add items to
	 */
	public function twiddle_image( $doc, $image, $image_id, $image_class, $parent_class, $picture_node )
	{
		$twiddled = FALSE;

		// if there aren't any sources by class specified or the config is malformed, don't twiddle the image here
		if ( ! $this->config( 'sources-by-class' ) || ! is_array( $this->config( 'sources-by-class' ) ) )
		{
			return;
		}//end if

		// loop over sources-by-class and determine how to twiddle the image
		foreach ( $this->config( 'sources-by-class' ) as $targets => $sources )
		{
			$targets = explode( ',', $targets );

			$match = FALSE;

			foreach ( $targets as $target )
			{
				if ( FALSE !== strpos( $parent_class, $target ) || FALSE !== strpos( $image_class, $target ) )
				{
					$match = TRUE;
					break;
				}//end if
			}//end foreach

			// if the image doesn't match what we're looking for,
			if ( ! $match )
			{
				continue;
			}//end if

			$source = $doc->createElement( 'source' );

			foreach ( $sources as $media => $size )
			{
				if ( 'base' == $media )
				{
					$default_size = wp_get_attachment_image_src( $image_id, $size );
					$image->setAttribute( 'src', $default_size[0] );
					$image->setAttribute( 'srcset', $this->get_image_srcset( $image_id, $size ) );
					continue;
				}//end if

				$source_clone = $source->cloneNode();
				$source_clone->setAttribute( 'srcset', $this->get_image_srcset( $image_id, $size ) );
				$source_clone->setAttribute( 'media', $media );
				$picture_node->appendChild( $source_clone );
			}//end foreach

			$twiddled = TRUE;
		}//end foreach

		return $twiddled;
	}//end twiddle_image

	/**
	 * Build a srcset density string
	 */
	public function get_image_srcset( $image_id, $image_size )
	{
		$size_1x = wp_get_attachment_image_src( $image_id, $image_size );
		$size_2x = wp_get_attachment_image_src( $image_id, "{$image_size}_go_responsiveimages_density_2x" );
		$size_3x = wp_get_attachment_image_src( $image_id, "{$image_size}_go_responsiveimages_density_3x" );

		return "{$size_1x[0]}, {$size_2x[0]} 2x, {$size_3x[0]} 3x";
	}//end get_image_srcset

	public function url_to_attachment_id( $image_url )
	{
		global $wpdb;

		$original_image_url = $image_url;

		$image_url = preg_replace( '/^(.+?)(-\d+x\d+)?\.(jpg|jpeg|png|gif)((?:\?|#).+)?$/i', '$1.$3', $image_url );

		// Filter the image URL to handle cases where the URL may be different due to a CDN
		$image_url = apply_filters( 'go_responsiveimages_image_url', $image_url );

		if ( ! ( $attachment_id = wp_cache_get( $image_url, $this->attachment_id_cache_group ) ) )
		{
			$sql = "SELECT ID FROM {$wpdb->prefix}posts WHERE guid = '%s'";
			$attachment_id = $wpdb->get_col( $wpdb->prepare( $sql, $image_url ) );

			if ( empty( $attachment_id ) )
			{
				$attachment_id = $wpdb->get_col( $wpdb->prepare( $sql, $original_image_url ) );
			}//end if

			$attachment_id = empty( $attachment_id ) ? NULL : $attachment_id[0];

			wp_cache_set( $image_url, $attachment_id, $this->attachment_id_cache_group );
		}//end if

		return $attachment_id;
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
