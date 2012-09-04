<?php
/*
Plugin Name: Data Viz
Description: Fun data visualization projects
*/

/**
 * I play with stuff in this plugin. It's not meant to do anything except data visualizations and mappings or the occasional API integration
 *
 * @package default
 * @author Aaron Brazell
 */
class AB_Data_Viz {
	
	/**
	 * Property that holds a Google Maps API Key. Define this key as a constant AB_GMAPS_APIKEY in wp-config.
	 * https://code.google.com/apis/console/b/0/
	 *
	 * @var string
	 */
	private $gmaps_api_key;
	
	/**
	 * Property that holds a Brewery DB API Key. If you want to use this, pay for premium. My friend would appreciate the support.
	 * Define this in wp-config.php as a constant AB_BREWERYDB_APIKEY
	 * http://developer.pintlabs.com/brewerydb/
	 *
	 * @var string
	 */
	private $brewerydb_api_key;
	
	/**
	 * Property that holds the BreweryDB API endpoint.
	 *
	 * @var string
	 */
	public $brewerydb_api_url;
	
	/**
	 * Constructor sets up Gmaps API and BreweryDB API
	 *
	 * @author Aaron Brazell
	 */
	public function __construct()
	{
		$this->gmaps_api_key = AB_GMAPS_APIKEY;
		$this->brewerydb_api_key = AB_BREWERYDB_APIKEY;
		$this->brewerydb_api_url = 'http://api.brewerydb.com/v2/';
		$this->shortcodes();
		$this->hooks();
	}
	
	/**
	 * Registers WordPress shortcodes for various projects in this plugin
	 *
	 * @return void
	 * @author Aaron Brazell
	 */
	public function shortcodes()
	{
		add_shortcode( 'beer_map', array( $this, 'beer_map' ) );
		add_shortcode( 'beer_selector', array($this, 'beer_selector' ) );
	}
	
	/**
	 * Integrates with WordPress hooks and plugin architecture
	 *
	 * @return void
	 * @author Aaron Brazell
	 */
	public function hooks()
	{
		add_action( 'wp_enqueue_scripts', array( $this, 'js' ) );
		add_action( 'wp_ajax_beer_selector', array( $this, 'beer_selector_ajax' ) );
		add_action( 'wp_ajax_nopriv_beer_selector', array( $this, 'beer_selector_ajax' ) );
	}
	
	/**
	 * Registers necessary external Javascript libraries
	 *
	 * @return void
	 * @author Aaron Brazell
	 */
	public function js()
	{
		wp_register_script( 'gmaps', 'http://maps.googleapis.com/maps/api/js?&sensor=false&key=' . $this->gmaps_api_key, array() );
		wp_register_script( 'abdata', WP_PLUGIN_URL . '/data-viz/js/ab.js', array( 'jquery' ) );
		wp_enqueue_script( 'gmaps' );
		wp_enqueue_script( 'abdata' );
		wp_enqueue_script( 'jquery' );
	}
	
	/**
	 * Generates a Google Map based on an array of objects containing nested objects with latitude and longitude properties.
	 *
	 * @param array $markers 
	 * @return void
	 * @author Aaron Brazell
	 */
	public function do_map( $markers )
	{
		$markers = array_slice( $markers, 0 , 25 );
		$lat_sum = 0;
		$lon_sum = 0;
		foreach( $markers as $marker )
		{
			$lat_sum += $marker->latitude;
			$lon_sum += $marker->longitude;
		}
		$lat_avg = $lat_sum / count($markers);
		$lon_avg = $lon_sum / count($markers);
		?>
		<script>
			jQuery(document).ready(function(){
				jQuery('body').attr( 'onload', 'initialize()' );
			});
			var map;
			
			function initialize() {
				var mapOptions = {
					zoom: 11,
					center: new google.maps.LatLng(<?php echo $lat_avg ?>, <?php echo $lon_avg ?>),
					mapTypeId: google.maps.MapTypeId.ROADMAP,
				}
				map = new google.maps.Map(document.getElementById("map_canvas"), mapOptions);
				add_brewery();
			}
			
			function add_brewery() {
				<?php
				foreach( $markers as $marker )
				{
					$marker = (object) $marker;
					?>
					marker = new google.maps.Marker(
					{ 
						position: new google.maps.LatLng(<?php echo esc_js( $marker->latitude ); ?>, <?php echo esc_js( $marker->longitude ); ?>), 
						map: map 
					} );
					
					var infowindow = new google.maps.InfoWindow();
	
					google.maps.event.addListener(marker, 'click', (function(marker) {
						return function() {
							infowindow.setContent('<?php echo esc_js( $marker->brewery->name ) ?>');
							infowindow.open(map, marker);
						}
					})(marker));
					<?php
				}
				?>
			}
			
			
		</script>
		<?php
	}
	
	/**
	 * Handles presentation of beer styles with Ajax loading of drilldown values
	 *
	 * @return void
	 * @author Aaron Brazell
	 */
	public function beer_selector_ajax()
	{
		if( !wp_verify_nonce( $_POST['_wpnonce'], 'beer_selector' ) )
			return false;
		$biers = $this->get_beer_by_style( $_POST['style_id'] );
		echo '<dl>';
		foreach( $biers->data as $bier )
		{
			$html = ( $bier->labels->medium ) ? '<img src="' . $bier->labels->medium . '" class="alignleft" />' : '';
			$html .= '<p>' . $bier->description . '</p>';
			$html .= '<ul>';
				$html .= '<li><strong>ABV:</strong> ' . $bier->abv . '%</li>';
				$html .= '<li><strong>IBUs:</strong> ' . $bier->ibu . '</li>'; 
				$html .= '<li><strong>Organic?</strong>';
					$html .= ( $bier->isOrganic == 'N' ) ? 'No' : 'Yes';
				$html .= '</li>';
				$html .= '<li><strong>Availability:</strong>' . $bier->available->description . '</li>';
			$html .= '</ul>';
			echo '<dt>' . $bier->name . '</dt>';
			echo '<dd>' . $html  . '</dd>';
		}
		echo '</dl>';
		exit;
	}
	
	/**
	 * Shortcode handler to generate a google map of breweries
	 *
	 * @param array $atts 
	 * @return string
	 * @author Aaron Brazell
	 */
	public function beer_map( $atts )
	{
		$defaults = array( 'height' => 300, 'width' => 500, 'location' => 'Austin' );
		extract( shortcode_atts( $defaults, $atts ) );
		$url = esc_url_raw( $this->brewerydb_api_url . '/locations/?key=' . $this->brewerydb_api_key . '&locality=' . $location );
		$breweries = wp_remote_get( $url);
		$json = json_decode( wp_remote_retrieve_body( $breweries ) );
		$this->do_map( $json->data );
		return '<div style="height:' . $height . 'px; width:' . $width . 'px;"><div id="map_canvas" style="width: 100%; height: 100%"></div></div>';
	}
	
	/**
	 * Shortcode handler generating lost of beer styles with Javascript to load Ajax content.
	 *
	 * @param array $atts 
	 * @return string
	 * @author Aaron Brazell
	 */
	public function beer_selector( $atts )
	{
		$defaults = array();
		extract( shortcode_atts( $defaults, $atts ) );
		
		$url = esc_url_raw( $this->brewerydb_api_url . '/styles/?key=' . $this->brewerydb_api_key );
		$styles = wp_remote_get( $url );
		$styles = json_decode( wp_remote_retrieve_body( $styles ) );
		
		$ajax_url = get_option( 'siteurl' ) . '/wp-admin/admin-ajax.php';
		$nonce = wp_create_nonce( 'beer_selector' );
		
		$html .= '<div id="beer-selector-wrap">';
		
		$html .= '<dl>';
		foreach( $styles->data as $style )
		{
			$html .= '<dt data-beer-style-id="' . esc_attr( $style->id ) . '" data-beer-style="' . esc_attr( $style->name ) . '">' . esc_html( $style->name ) . ' <a style="display:none; cursor:pointer;">Find beers!</a></dt>';
			$html .='<dd>' . esc_html( $style->description ) . '</dd>';
		}
		$html .= '</dl>';
		$html .= '</div>';
		?>
		<script>
		jQuery(document).ready(function(){
			jQuery('dt').hover(function(domobj){
				jQuery("a", this).show();
			}, function(domobj) {
				jQuery("a", this).hide();
			});
			
			jQuery('dt a').click(function(){
				var style_id = jQuery(this).parent().data('beer-style-id'); 
				
				jQuery.post( '<?php echo $ajax_url ?>', 
				{ 
					action: 'beer_selector', 
					style_id: style_id, 
					_wpnonce: '<?php echo $nonce ?>' 
				}, function(response){
					jQuery('#beer-selector-wrap').html( response );
				})
			});
		});
		</script>
		<?php
		return $html;
	}
	
	/**
	 * API wrapper to retrieve beers matching a given style ID
	 *
	 * @param int $style_id 
	 * @return object
	 * @author Aaron Brazell
	 */
	public function get_beer_by_style( $style_id )
	{
		$url = esc_url_raw( $this->brewerydb_api_url . '/beers/?styleId=' . (int) $style_id . '&key=' . $this->brewerydb_api_key );
		$biers = wp_remote_get( $url );
		$biers = json_decode( wp_remote_retrieve_body( $biers ) );
		return $biers;
	}
}
new AB_Data_Viz;