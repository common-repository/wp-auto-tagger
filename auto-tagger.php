<?php
/*
Plugin Name: WP Auto Tagger
Plugin URI: http://blinger.org/wordpress-plugins/auto-tagger/
Description: Automatically finds tags based on your post content.
Version: 1.3.3
Author: iDope
Author URI: http://efextra.com/
*/

/*  Copyright 2008  Saurabh Gupta  (email : saurabh0@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Add sidebar button
add_action('dbx_post_sidebar', 'tagger_sidebar', 1);
function tagger_sidebar() {
	global $post_ID;
	wp_print_scripts( array( 'sack' ));    
	?>
	<script type="text/javascript">
	//<![CDATA[
	jQuery(document).ready( function() {
		jQuery( '#tagsdiv' ).append( jQuery( '#autotaggerdiv' ) );
	} );
	
	function tagger_gettags( )
	{
		var form = document.getElementById('post');
		if ( (typeof tinyMCE != "undefined") && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden() )
		{
		   tinyMCE.triggerSave();
		}
		if(form.post_title.value.length==0 || form.content.value.length==0) {
          alert("Please enter some content first");
          return;
		}
		var mysack = new sack("<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php" );
		mysack.execute = 1;
		mysack.method = 'POST';
		mysack.setVar( "action", "gettags" );
		mysack.setVar( "postid", "<?php echo $post_ID; ?>" );
		mysack.setVar( "tags", form.tags_input.value );
		mysack.setVar( "title", form.post_title.value);
		mysack.setVar( "content", form.content.value );
		mysack.encVar( "cookie", document.cookie, false );
		mysack.onError = function() { alert('AJAX error in getting tags' )};
		document.getElementById('gettags').disabled=true;
		mysack.runAJAX();
		return true;
	}
	function tagger_showtags( tags )
	{
		//alert(tags);
        jQuery('#tags-input').val(tags);
		document.getElementById('gettags').disabled=false;
		tag_update_quickclicks();
	}
	//]]>
	</script>
		<div id="autotaggerdiv">
			<h3 class="dbx-handle">Auto Tagger</h3>
			<div class="dbx-content">
			<input type="hidden" name="autotagger" value="1" />
			<button id="gettags" class="button" onclick="tagger_gettags(); return false;" style="float: right">Suggest Tags</button>
			<label for="autotag" class="selectit"><input type="checkbox" tabindex="2" id="autotag" name="autotag" value="yes" <?php if(get_option('autotag')=='yes') echo 'checked="checked"'; ?> /> Auto-tag post on save</label><br />
			<small>Auto Tagger will not replace existing tags. For tag suggestions without saving click 'Suggest Tags'.</small>
			</div>
		</div>
	<?php
}

// Register post insert hook
add_action('wp_insert_post', 'auto_gettags', 10, 2);
function auto_gettags($post_id, $post) {
	if(isset($_POST['autotagger'])) update_option('autotag',$_POST['autotag']);
	//print_r($post); exit;
	if(get_option('autotag')=='yes') {
		$tags=$post->tags_input;
		if(is_array($tags)) $tags=implode(',',$tags);
		if(empty($tags) && !empty($_POST['tags_input'])) $tags=$_POST['tags_input'];
		$tags=gettags($post->post_title,$post->post_content,$tags);
		if(!is_array($tags)) return;
		wp_add_post_tags($post_id,$tags);
	}
}

// Register AJAX action
add_action('wp_ajax_gettags', 'ajax_gettags' );
function ajax_gettags() {
	$tags=gettags($_POST['title'],$_POST['content'],$_POST['tags']);
	if(!is_array($tags)) die("alert('".$tags."')");
	// Compose JavaScript for return
	die( "tagger_showtags('" . tagger_ajax_escape(implode(',',$tags)) . "')" );
}
function gettags($title,$content,$tags) {
	//if(!current_user_can('publish_posts')) {
	//	die("alert('You cannot edit posts')");
	//}
	$content=preg_replace('|<[^<>]*>|',' ',"$title\n$content");
	$content=preg_replace('|\s{2,}|',' ',$content);
	if(strlen($tags)) {
		$subject=$tags;
	} else {
		$subject=$title;
    }
	if(!function_exists('curl_init')) return 'cURL not available';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://search.yahooapis.com/ContentAnalysisService/V1/termExtraction');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, array('appid'=>'AutoTagger','context'=>$content,'query'=>$subject,'output'=>'php'));
	$response = curl_exec($ch);
	if(curl_errno($ch)) return curl_error($ch);
	curl_close($ch);
    $results=unserialize($response);
	$tags = explode(',',$tags);	
	if(is_array($results['ResultSet']['Result'])) $tags=array_merge($tags, $results['ResultSet']['Result']);
	array_walk($tags,create_function('&$value','$value = tagger_proper_case(trim($value));'));
	$tags = array_unique($tags);
	if(in_array('',$tags)) unset($tags[array_search('',$tags)]); // remove blanks
	return $tags;
}

register_activation_hook(__FILE__,'tagger_activate');
function tagger_activate() {
	// Set defaults
	update_option('autotag','yes');
}

/**
* Escapes a string so it can be safely echo'ed out as Javascript
*
* @param  string $str String to escape
* @return string      JS Safe string
*/
function tagger_ajax_escape($str)
{
    $str = str_replace(array('\\', "'"), array("\\\\", "\\'"), $str);
    $str = preg_replace('#([\x00-\x1F])#e', '"\x" . sprintf("%02x", ord("\1"))', $str);

    return $str;
}
function tagger_proper_case($input) {
  return preg_replace_callback('|\b[a-z]|',create_function('$matches','return strtoupper($matches[0]);'),$input);
}