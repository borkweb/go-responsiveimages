go-responsiveimages
===================

Adds support for HTML5 responsive images via [picturefill](http://scottjehl.github.io/picturefill/).

## Image density

For all registered image sizes, `go-responsiveimages` registers 2x and 3x versions to support 2x and 3x image densities.

## Usage

Hooking to `the_content` can be expensive, so this plugin _does not_ do that. Instead, it provides a filter that can be referenced to replace all `<img>` tags with responsive image equivalents.

Example usage:

```
ob_start();
the_content();
$content = ob_get_clean();

echo apply_filters( 'go_responsiveimages_replace_images', $content );
```

By default, the `<img>` tag gets replaced with a `<picture>` element and a `srcset` attribute specifying 2x and 3x densities, like so:

```
<picture>
  <img srcset="path/to/image.png, path/to/image-2x.png 2x, path/to/image-3x.png 3x" src="path/to/image.png">
</picture>
```

You can get full use of the `<picture>` element by filtering `go_config`, where you can specify different images to serve at various screen sizes.


### Customizing by filtering go_config
Here's an example config filter that serves different images sizes (that exist in `$_wp_additional_image_sizes`) to different resolutions.

* Large: `( min-width: 960px )`
* Medium: `( min-width: 641px )`
* Small: `default`

```
add_filter( 'go_config', function( $config, $which_config ) {
  // only run the filter if it is a go-responsiveimages config.
  if ( 'go-responsiveimages' != $which_config ) {
    return $config;
  }//end if
  
  if ( ! isset( $config['sources-by-class'] ) ) {
    $config['sources-by-class'] = array();
  }//end if
  
  // make all images with the class of "alignleft" have different sizes
  $config['sources-by-class']['alignleft'] = array(
    // at widths 960px +, use the large image size
    '( min-width: 960px )' => 'large',
    
    // at widths 640px-959px, use the medium image size
    '( min-width: 641px )' => 'medium',
    
    // the default base size is small
    'base' => 'small',
  );
});
```

Result:

```
<picture>
  <!--[if IE 9]><video style="display: none;"><![endif]-->
  <source srcset="http://url/to/large-size-image.png, http://url/to/large-size-image-doublesize.png 2x, http://url/to/large-size-image-triplesize.png 3x" media="(min-width:960px)">
  <source srcset="http://url/to/medium-size-image.png, http://url/to/medium-size-image-doublesize.png 2x, http://url/to/medium-size-image-triplesize.png 3x" media="(min-width:321px)">
  <!--[if IE 9]></video><![endif]-->
  <img src="http://url/to/small-size-image.png, http://url/to/small-size-image-doublesize.png 2x, http://url/to/small-size-image-triplesize.png 3x" alt="A sweet image" width="960" height="526" class="alignleft size-full wp-image-620443">
</picture>
```
