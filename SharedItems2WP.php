<?php

/*
Plugin Name: SharedItems2WP
Plugin URI: http://jardenberg.se/SharedItems2WP
Description: Scheduled automatic posting of Google Reader Shared Items.
Version: 2.0.4
Author: <a href="http://www.googletutor.com/" title="Original author">Craig Fifield (Google Tutor)</a>, <a href="http://satf.se" title="Fork developer">Jonas Skovmand</a>, <a href="http://jardenberg.se" title="Forker and initiative taker">Joakim Jardenberg</a> and <a href="http://fridholm.net" title="Contributor">Marcus Fridholm</a>
*/

require_once(ABSPATH.WPINC.'/class-snoopy.php');
require_once(ABSPATH.WPINC.'/rss.php');
// test to fix simplepie problem
require_once(ABSPATH.WPINC.'/class-simplepie.php');


if (!class_exists('SharedItems2WP')) {
    class SharedItems2WP
    {
		
		var $options_key = 'shared-items-post-options';
		var $google_feed_url = 'http://www.google.com/reader/public/atom/user/%s/state/com.google/broadcast';
		var $cron_event = 'si2wp_post';
		
        var $plugin_url;
        var $plugin_path;
        var $status = "";
	var $max_origin_url_iteration = 4;

        var $o;

        var $default_options = array(
            'revision' => 11,
            'share_url' => '',
            'feed_url' => '',
            'refresh_period' => 'weekly',
            'refresh_time' => '06:00 AM',
            'post_title' => 'Shared Items - %DATE%',
            'post_header_template' => '&lt;ul&gt;',
            'post_footer_template' => '&lt;/ul&gt;',
            'post_item_template' => '&lt;li&gt;&lt;a href=&quot;%BASEURL%&quot; title=&quot;%BASETITLE%&quot;&gt;%BASETITLE%&lt;/a&gt; - &lt;a href=&quot;%LINK%&quot; title=&quot;%TITLE%&quot;&gt;%TITLE%&lt;/a&gt;&lt;br&gt;%DATE% %NOTE%&lt;/li&gt;',
            'post_note_template' => '- %CONTENT%',
            'post_category' => 1,
            'post_tags' => '',
            'post_status' => 'publish',
            'post_author' => 1,
            'post_comments' => 1,
            'last_crawl' => 0,
            'last_refresh' => 0,
	    	'next_refresh' => 0,
            'last_refresh_feed' => 0,
            'last_refresh_date' => 0, // FIX: check/set the current date in the prototype, then exit if already run
	    'currently_running' => 0
        );
		
		var $item_elements = array (
			'%TITLE%'		=>	'feed item title',
			'%LINK%'		=>	'link for the feed item',
			'%DATE%'		=>	'item publish date',
			'%NOTE%'		=>	'feed note',
			'%BASETITLE%'		=>	'site title',
			'%BASEURL%'		=>	'site base URL'
		);
		
		var $annotation_elements = array (
			'%CONTENT%'		=>	'note content',
			'%AUTHOR%'		=>	'note author'
		);
		
		var $title_elements = array (
			'%DATE%'		=>	'post publish date'
		);
		
		var $refresh_periods = array (
			'daily'		=>	'day',
			'weekly'	=>	'week',
			'monthly'	=>	'month'
		);
        
        function SharedItems2WP() {
            $this->plugin_path_url();
            $this->install_plugin();
	    $this->actions_filters();
        }

        function plugin_path_url() {
			$this->plugin_path = dirname(__FILE__).'/';
			$this->plugin_url = WP_PLUGIN_URL.'/' . basename(dirname(__FILE__)) . '/';
        }

		function install_plugin() {
			$this->o = get_option($this->options_key);
						
			if (!is_array($this->o) || empty($this->o) ) {
				update_option($this->options_key, $this->default_options);
				$this->o = get_option($this->options_key);
			}
			else {
				$this->o = $this->o + $this->default_options;
				$this->o["revision"] = $this->default_options["revision"];
				update_option( $this->options_key, $this->o);
			}
		}

		function actions_filters() {
			add_action('init', array(&$this, 'init'));
			add_action('admin_menu', array(&$this, 'admin_menu'));
			add_action('admin_head', array(&$this, 'admin_head'));
			add_action($this->cron_event, array(&$this, 'generate_post'));
			add_filter('cron_schedules', array ( &$this, 'register_schedules' ) );
			register_deactivation_hook(__FILE__, array ( &$this, 'unregister_cron' ) );
		}
		
		/**
		 * Registers additional cron frequencies
		 * @return void
		 */
		
		function register_schedules ( )
		{
			
			return array (
				'weekly'	=>	array (
					'interval'	=>	604800,
					'display'	=>	__( 'Once weekly' )
				),
				'monthly'	=>	array (
					'interval'	=>	108000,
					'display'	=>	__( 'Once monthly' )
				)
			);
			
		}
		
		/**
		 * Schedules a cron event
		 * @param string $occurrence
		 * @param time $offset
		 * @return void
		 */
		
		function register_cron ( $occurence, $offset )
		{
		
			if ( $this->check_cron_registered ( ) )
				$this->unregister_cron ( );
			
			$offset += ( get_option ( 'gmt_offset' ) * 3600 ); // post in the correct timezone!
			wp_schedule_event ( $offset, $occurence, $this->cron_event );
		
		}
		
		/**
		 * Unregisters cron event
		 * @return void
		 */
		
		function unregister_cron ( )
		{
		
			wp_clear_scheduled_hook ( $this->cron_event );
		
		}
		
		/**
		 * Checks if SharedItems2WP is scheduled to post
		 * @return boolean
		 */
		
		function check_cron_registered ( )
		{
		
			return wp_next_scheduled ( $this->cron_event );
		
		}
		
		/**
		 * Parses Google Reader Shared Items page for feed URL
		 * @param string $feed_url
		 * @return string feed url
		 */
		
		function get_feed_url ( $share_url )
		{
			$file = $this->get_file_contents ( $share_url );
			preg_match ( '/xml".href="(.+?)"/', $file, $matches );
			return $matches[1];
		}

		function get_file_contents($url) {
			$client = new Snoopy();
			$client->agent = MAGPIE_USER_AGENT;
			$client->read_timeout = MAGPIE_FETCH_TIME_OUT;
			$client->use_gzip = MAGPIE_USE_GZIP;
			$client->fetch($url);
			$file = $client->results;
			return $file;
		}
		
		/**
		 * Get the origin URL if a HTTP redirect occurred--recursively!
		 * @param string $url
		 * @param int $iteration
		 * @return string origin url
		 */
		
		function get_origin_url ( $start_url, $iteration = 0 )
		{

			if ( $iteration < $this->max_origin_url_iteration ) // prevent infinite loops
			{
			
				$iteration++;
				$url = parse_url( $start_url );

				$host = $url['host'];
				$port = $url['port'];
				$path = $url['path'];
				if(!$port)
					$port = 80;
				if ( !empty ( $url['query'] ) )
					$path .= '?' . $url['query'];

				$request = "HEAD $path HTTP/1.1\r\n"
				."Host: $host\r\n"
				."Connection: close\r\n"
				."\r\n";

				$address = gethostbyname($host);
				$socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
				$connected = @socket_connect($socket, $address, $port);
				socket_set_option( $socket, SOL_SOCKET, SO_RCVTIMEO, array("sec"=>1, "usec"=>500) );

				if ( $connected !== false && $socket !== false )
				{
					socket_write($socket, $request, strlen($request));

					$response = split("\n", socket_read($socket, 1024));
					socket_close($socket);

					if ( ( strpos ( $response[0], 'HTTP/1.1 30' ) === 0 || strpos ( $response[0], 'HTTP/1.0 30' ) === 0 ) )
					{
					
						foreach ( $response as $http_line )
						{
						
							if ( strpos ( $http_line, 'Location:' ) === 0 )
								return $this->get_origin_url ( trim ( str_replace ( 'Location: ', '', $http_line ) ), $iteration );
						
						}
					
					}
				}
				
			}

			return $start_url;

		}
		
		/**
		 * Init function, called at every load
		 * @return void
		 */
		
		function init() {
			if ($_POST['action'] == 'runow') {
			
				check_admin_referer('shared-2', '_wpnonce2');
				$this->generate_post();
				
			}
			else {
				if ($_POST['action'] == 'reset') {
						check_admin_referer('shared-3', '_wpnonce3');
						
					$this->o = $this->default_options;
					update_option( $this->options_key, $this->default_options);
				}
				
				if ($_POST['action'] == 'save') {
				
					check_admin_referer('shared-1');
					$this->save ( );
				}
			}
		}
        
	
	function save ( )
	{
	
		$this->o["post_title"] = $_POST['post_title'];
		$this->o["post_tags"] = $_POST['post_tags'];
		$this->o["post_status"] = $_POST['post_status'];
		$this->o["post_header_template"] = stripslashes(htmlentities($_POST['post_header_template'], ENT_QUOTES, 'UTF-8'));
		$this->o["post_footer_template"] = stripslashes(htmlentities($_POST['post_footer_template'], ENT_QUOTES, 'UTF-8'));
		$this->o["post_item_template"] = stripslashes(htmlentities($_POST['post_item_template'], ENT_QUOTES, 'UTF-8'));
		$this->o["post_note_template"] = stripslashes(htmlentities($_POST['post_note_template'], ENT_QUOTES, 'UTF-8'));
		$this->o["post_author"] = $_POST['post_author'];
		$this->o["post_category"] = $_POST['post_category'];
		$this->o["post_comments"] = isset($_POST['post_comments']) ? 1 : 0;
		$this->o["refresh_period"] = $_POST['refresh_period'];
		$this->o["refresh_time"] = $_POST['refresh_time'];
		
		$time_offset = isset ( $_POST['run_immediately'] ) ? time ( ) - 50: strtotime ( $_POST['refresh_time'] . ' +1 ' . $this->refresh_periods[strtolower($_POST['refresh_period'])] );
		
		if ( $_POST['refresh_time'] != $this->o['refresh_time'] || !$this->check_cron_registered ( ) )
			$this->register_cron ( $this->o['refresh_time'], $time_offset );
		
		update_option($this->options_key, $this->o);

		$share_url = str_replace("https://", "http://", $_POST['share_url']);
		
		if ($share_url != $this->o["share_url"]) {
			$url = $this->get_feed_url($share_url);
			if ($url != "") {
				$this->o["share_url"] = $share_url;
				$this->o["feed_url"] = $url;
				update_option($this->options_key, $this->o);
				$this->status = "ok";
			}
			else
				$this->status = "not ok";
		}
	
	}
	
        function generate_post() {
			if ($this->o["feed_url"] == "") return;
        	 
            $rss = new SimplePie();
            $rss->set_feed_url($this->o["feed_url"]);
            $rss->enable_cache(false);
            $rss->enable_order_by_date(false);
            $rss->init();
            
            $updated = $rss->get_channel_tags("http://www.w3.org/2005/Atom", "updated");
            $last_update = strtotime(str_replace(array("T", "Z"), " ", $updated[0]["data"]));
          
            $new_items = array();
            $post_content = "";
            $newtime=0;
            
            if ($this->o["last_refresh_feed"] < $last_update) {
                foreach ($rss->get_items() as $item) {
                	                	                 
 	
                    $entry_time = strtotime($item->get_local_date());
                    $crawl_time = $item->data["attribs"]["http://www.google.com/schemas/reader/atom/"]["crawl-timestamp-msec"];
 							
					if ($newtime==0)
                	  	$newtime=$crawl_time;
                	  
                	  	
                    if ($this->o["last_crawl"] < $crawl_time) {
                        $new_item["crawl_time"] = $crawl_time;
                        $new_item["entry_time"] = $entry_time;
                        $new_item["title"] = $item->get_title();
                        $new_item["link"] = $this->get_origin_url ( $item->get_link() );
						
						if ( $source = $item->get_source ( ) )
						{
							$new_item['site_url'] = $source->get_link ( 0 );
							$new_item['site_name'] = $source->get_title ( );
						}
						
                        $annotation = $item->get_item_tags("http://www.google.com/schemas/reader/atom/", "annotation");
                        if (isset($annotation)) {
                            $note = html_entity_decode($this->o["post_note_template"]);
                            $note = str_replace( array_keys ( $this->annotation_elements ), array ( 
								$annotation[0]["child"]["http://www.w3.org/2005/Atom"]["content"][0]["data"],
								$annotation[0]["child"]["http://www.w3.org/2005/Atom"]["author"][0]["child"]["http://www.w3.org/2005/Atom"]["name"][0]["data"]
							), $note);
                        }
                        else
							$note = "";
						
                        $new_item["note"] = $note;
                        $new_items[] = $new_item;
                    }
                }
				
				
                if (count($new_items) > 0) {
                    foreach ($new_items as $item) {
                        $item_date = date(get_option("date_format"), $item["entry_time"]);
                        $import = html_entity_decode($this->o["post_item_template"]);
						$import = str_replace ( array_keys ( $this->item_elements ), array (
							$item["title"],
							$item["link"],
							$item_date,
							$item["note"],
							$item["site_name"],
							$item["site_url"]
						), $import );
                        $post_content.= $import;
                    }
                }
            }
            
            if ($post_content != "") {
                $post_title = html_entity_decode($this->o["post_title"]);
                $post_title = str_replace( array_keys ( $this->title_elements ), date(get_option("date_format")), $post_title);
                $post_header = html_entity_decode($this->o["post_header_template"]);
                $post_footer = html_entity_decode($this->o["post_footer_template"]);
                
                $new_post = array();
                
                $new_post['comment_status'] = $this->o["post_comments"] == 1 ? 'open' : 'closed';
                $new_post['post_author'] = $this->o["post_author"];
                $new_post['post_content'] = $post_header.$post_content.$post_footer;
                $new_post['post_status'] = $this->o["post_status"];
                $new_post['post_title'] = $post_title;
                $new_post['post_type'] = 'post';
                $new_post['post_category'] = array($this->o["post_category"]);
                $new_post['tags_input'] = $this->o["post_tags"];

                wp_insert_post($new_post);
                $this->o["last_refresh_feed"] = $last_update;
                $this->o["last_refresh"] = mktime();
                $this->o["last_crawl"] =$newtime;
                
                update_option($this->options_key, $this->o);
            }
        }
        

        function admin_menu() {
            add_submenu_page('options-general.php','SharedItems2WP', 'SharedItems2WP', 9, basename(__FILE__), array($this, 'options_panel'));
        }

        function admin_head() {
       
	    ?>
<script type="text/javascript">
//<![CDATA[

function check_url ( that, $ )
{
	var share_url = $(that).val ( );
	var matches = /(http|https):\/\/(www.)?google.([a-z]{2,7})\/reader\/shared\/(.+)/.exec(share_url);
	
	if (  typeof matches != 'undefined' && matches != null && typeof matches[4] != 'undefined' )
	{
		$("#adv_paypal_url").removeClass('invalid valid').addClass('valid');
	}
	else
	{
		$("#adv_paypal_url").removeClass('invalid valid').addClass('invalid');
	}
}
jQuery(document).ready ( function ( $ )
{
	
	check_url ( $("#adv_paypal_url")
		.change ( function ( ) { check_url ( this, $ ); } )
		.keyup ( function ( ) { check_url ( this, $ ); } ), $ ) /* a bit dirty, maybe... */
	;
});
//]]>
</script>
<style type="text/css">
.gdsr { margin-top: 10px; }
.gdsr .previewtable td {
	padding: 0px; 
	border: 0px;
}
.gdsr-table-split {
	width: 100%; 
	margin-top: 10px; 
	padding-top: 10px;
}
.submit {padding:0;}
.column {margin-left: 150px; padding: 5px 0; width: 570px; clear: both; overflow: hidden;}
.column div {float: left; width: 180px; padding: 5px;}
.column span {float: right;}
.friendlyform {float:left; margin-right: 2em;}
.valid { background-color: lightgreen; }
.invalid { background-color: #CD5C5C; }
</style>
	    <?php
	    
        }
        
        function options_panel() {
            $options = $this->o + array (
				'title_elements'		=>	$this->title_elements,
				'item_elements'			=>	$this->item_elements,
				'annotation_elements'	=>	$this->annotation_elements,
				'refresh_periods'	=>	$this->refresh_periods
			);
            $status = $this->status;
			
            ?>
	    
<?php if ($_POST['action'] == 'save') { ?>
<div id="message" class="updated fade" style="background-color: rgb(255, 251, 204);"><p><strong>Settings saved.</strong></p></div>
<?php } else if ( $_POST['action'] == 'runow' ) { ?>
<div id="message" class="updated fade" style="background-color: rgb(255, 251, 204);"><p><strong>Running!</strong></p></div>
<?php } else if ( $_POST['action'] == 'reset' ) { ?>
<div id="message" class="updated fade" style="background-color: rgb(255, 251, 204);"><p><strong>Settings reset.</strong></p></div>
<?php } ?>

<div class="wrap"><h2>SharedItems2WP</h2>

<div class="gdsr">
<form method="post" action="">
	<?php wp_nonce_field('shared-1' ); ?>
<input type="hidden" name="action" value="save" />
<table class="form-table"><tbody>
    <tr><th scope="row"><label for="adv_paypal_url">Shared items url:</label></th>
        <td>
            <input type="text" name="share_url" id="adv_paypal_url" value="<?php echo $options["share_url"]; ?>" class="<?php if ( $status=='not ok' ): echo 'invalid'; else: echo 'valid'; endif; ?>" style="width: 720px" />
        </td>
    </tr>
    <tr><th scope="row"><label for="refresh_period">Refresh:</label></th>
        <td>
            <table cellpadding="0" cellspacing="0" class="previewtable">
                <tr>
                    <td width="150" height="25"><label for="refresh_period">Period:</label></td>
                    <td align="left">
                    <select style="width: 180px;" name="refresh_period" id="refresh_period">
                        <?php foreach ( $options['refresh_periods'] as $key => $value ): ?>
                            <option value="<?=$key?>"<?php echo $options["refresh_period"] == $key ? ' selected="selected"' : ''; ?>><?=ucfirst($key)?></option>
                        <?php endforeach; ?>
                    </select>
                    </td>
                </tr>
                <tr>
                    <td width="150" height="25"><label for="refresh_time">Time:</label></td>
                    <td align="left">
                        <input maxlength="8" type="text" name="refresh_time" id="refresh_time" value="<?php echo $options["refresh_time"]; ?>" style="width: 170px" /> [format: HH:MM or HH:MM AP (AM/PM)]
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr><th scope="row"><label for="post_title">Post Template:</label></th>
        <td>
            <table cellpadding="0" cellspacing="0" class="previewtable">
                <tr>
                    <td width="150" height="25"><label for="post_title">Title:</label></td>
                    <td><input type="text" name="post_title" id="post_title" value="<?php echo wp_specialchars(stripslashes($options["post_title"])); ?>" style="width: 570px" /><br />
                    List of available template elements:</td>
                </tr>
            </table>
			<div class="column">
				<?php foreach ( $options['title_elements'] as $tag => $description ): ?>
					<div><code><?=$tag?></code> <span><?=$description?></span></div>
				<?php endforeach; ?>
			</div>
            <table cellpadding="0" cellspacing="0" class="previewtable">
                <tr>
                    <td width="150" height="25"><label for="post_header_template">Header:</label></td>
                    <td><input type="text" name="post_header_template" id="post_header_template" value="<?php echo wp_specialchars($options["post_header_template"]); ?>" style="width: 570px" /></td>
                </tr>
                <tr>
                    <td width="150" height="25"><label for="post_footer_template">Footer:</label></td>
                    <td><input type="text" name="post_footer_template" id="post_footer_template" value="<?php echo wp_specialchars($options["post_footer_template"]); ?>" style="width: 570px" /></td>
                </tr>
            </table>
            <div class="gdsr-table-split"></div>
            <table cellpadding="0" cellspacing="0" class="previewtable">
                <tr>
                    <td width="150" height="25"><label for="post_item_template">Item:</label></td>
                    <td><input type="text" name="post_item_template" id="post_item_template" value="<?php echo wp_specialchars($options["post_item_template"]); ?>" style="width: 570px" /><br />
                    List of available template elements:</td>
                </tr>
            </table>
			<div class="column">
				<?php foreach ( $options['item_elements'] as $tag => $description ): ?>
					<div><code><?=$tag?></code> <span><?=$description?></span></div>
				<?php endforeach; ?>
			</div>
            <div class="gdsr-table-split"></div>
            <table cellpadding="0" cellspacing="0" class="previewtable">
                <tr>
                    <td width="150" height="25"><label for="post_note_template">Note:</label></td>
                    <td><input type="text" name="post_note_template" id="post_note_template" value="<?php echo wp_specialchars($options["post_note_template"]); ?>" style="width: 570px" /><br />
                    List of available template elements:</td>
                </tr>
            </table>
            <div class="column">
				<?php foreach ( $options['annotation_elements'] as $tag => $description ): ?>
					<div><code><?=$tag?></code> <span><?=$description?></span></div>
				<?php endforeach; ?>
			</div>
        </td>
    </tr>
    <tr><th scope="row"><label for="post_author">Post Settings:</label></th>
        <td>
            <table cellpadding="0" cellspacing="0" class="previewtable">
                <tr>
                    <td width="150" height="25"><label for="post_author">Author:</label></td>
                    <td>
                    <select name="post_author" id="post_author">
                    <?php
                        $all_users = get_users_of_blog();
                        foreach ($all_users as $u) {
                            $selected = "";
                            if ($u->user_id == $options["post_author"]) $selected = ' selected="selected"';
                            echo '<option value="'.$u->user_id.'"'.$selected.'>'.$u->display_name.'</option>';
                        }
                    ?>
                    </select>
                    </td>
                </tr>
                <tr>
                    <td width="150" height="25"><label for="post_category">Category:</label></td>
                    <td>
                    <?php 
                        $dropdown_options = array('show_option_all' => '', 'hide_empty' => 0, 'hierarchical' => 1,
                            'show_count' => 0, 'depth' => 0, 'orderby' => 'ID', 'selected' => $options["post_category"], 'name' => 'post_category');
                        wp_dropdown_categories($dropdown_options);
                    ?>
                    </td>
                </tr>
                <tr>
                    <td width="150" height="25"><label for="post_tags">Tags:</label></td>
                    <td>
                    <input type="text" name="post_tags" id="post_tags" value="<?php echo $options["post_tags"]; ?>" style="width: 570px" />
                    </td>
                </tr>
                <tr>
                	<td width="150" height="25"><label for="post_status">Status:</label></td>
                	<td>
                		<input type="radio" name="post_status" value="publish" <?php if($options['post_status'] == 'publish') echo " checked=\"checked\""; ?> /> Publish<br />
                		<input type="radio" name="post_status" value="draft"  <?php if($options['post_status'] == 'draft') echo " checked=\"checked\""; ?> /> Create draft
                	</td>
                </tr>
                <tr>
                    <td width="150" height="25"><label for="post_comments">Comments:</label></td>
                    <td>
                    <input type="checkbox" name="post_comments" id="post_comments"<?php if ($options["post_comments"] == 1) echo " checked=\"checked\""; ?> /><label style="margin-left: 5px;" for="post_comments">Allow posting of comments</label>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</tbody></table>
<p class="submit"><input type="submit" value="Save Options" name="saving"/> <label><input type="checkbox" name="run_immediately" /> Run immediately</label></p>
</form>
</div>
<div class="gdsr submit">

	<h2>Misc. operations</h2>
	
		<form method="post" class="friendlyform" action="">
			<?php wp_nonce_field('shared-2', '_wpnonce2' ); ?>
			<input type="hidden" name="action" value="runow" />
			<input type="submit" value="Run Now" name="saving" />
		</form>
		
		<form method="post" action="">
			<?php wp_nonce_field('shared-3', '_wpnonce3' ); ?>
			<input type="hidden" name="action" value="reset" />
			<input type="submit" value="Reset" name="saving" />
		</form>
	
</div>
</div>

	    <?php
        }
    }
    
    $rssShare = new SharedItems2WP();
}

?>