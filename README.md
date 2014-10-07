go-responsiveimages
===================

Adds support for HTML5 responsive images via [picturefill](http://scottjehl.github.io/picturefill/).

### Customizing with a config file

```php
add_filter( 'go_config', function( $config, $which_config ) {
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

```html
<picture>
  <!--[if IE 9]><video style="display: none;"><![endif]-->
  <source srcset="http://url/to/large-size-image.png, http://url/to/large-size-image-doublesize.png 2x, http://url/to/large-size-image-triplesize.png 3x" media="(min-width:960px)">
  <source srcset="http://url/to/medium-size-image.png, http://url/to/medium-size-image-doublesize.png 2x, http://url/to/medium-size-image-triplesize.png 3x" media="(min-width:321px)">
  <!--[if IE 9]></video><![endif]-->
  <img src="http://url/to/small-size-image.png, http://url/to/small-size-image-doublesize.png 2x, http://url/to/small-size-image-triplesize.png 3x" alt="A sweet image" width="960" height="526" class="alignleft size-full wp-image-620443">
</picture>
```
