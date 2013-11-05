<?php
/*
Plugin Name: WP-Markdown
Description: Allows you to use MarkDown in posts, BBPress forums and comments
Version: 1.1.2
Author: Stephen Harris
Author URI: http://HarrisWebSolutions.co.uk/blog
*/
/*  Copyright 2011 Stephen Harris (stephen@harriswebsolutions.co.uk)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
*/
class WordPress_Markdown {

	var $domain = 'markdown';

	//Version
	static $version ='1.1.2';

	//Options and defaults
	static $options = array(
		'post_types'=>array(),
		'markdownbar'=>array(),
		'prettify'=>0,
	);
	static $option_types = array(
		'post_types'=>'array',
		'markdownbar'=>'array',
		'prettify'=>'checkbox',
	);

	public function __construct() {
		register_activation_hook(__FILE__,array(__CLASS__, 'install' )); 
		register_uninstall_hook(__FILE__,array( __CLASS__, 'uninstall' )); 
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
	}

	static function install(){
		update_option("markdown_version",self::$version);
		add_option('markdown',self::$options);
	}

	static function uninstall(){
		delete_option("markdown_version");
		delete_option('markdown');
	}


	public function init() {
		//Remove unwanted formatting by WordPress, based on MarkDown Extra
		remove_filter('the_content',     'wpautop');
        remove_filter('the_content_rss', 'wpautop');
		remove_filter('the_excerpt',     'wpautop');

		remove_filter('content_save_pre',  'balanceTags', 50);
		remove_filter('excerpt_save_pre',  'balanceTags', 50);

		//Allow translations
		load_plugin_textdomain( 'markdown', false, basename(dirname(__FILE__)).'/languages');

		//Markdown posts and comments
		add_filter('pre_comment_content',array($this,'pre_comment_content'),5);
		/*
		 * Higher level of the filter,as in http://codex.wordpress.org/Plugin_API/Filter_Reference/wp_insert_post_data
		 * Before Avoid Collisions
		 */
		add_filter( 'wp_insert_post_data', array( $this, 'wp_insert_post_data' ), 99, 2 );

		//Try to parse the content before showing
		add_filter('the_content',  array($this, 'render_content'), 6);
                
		/*
                 * Use save_post filter to add the toc, because only then we know the post ID
		 */
		add_filter( 'save_post', array( $this, 'add_meta_toc' ), 10, 2 );

		//Convert HTML to Markdown (posts, comments, BBPress front-end editing)
		add_filter( 'edit_post_content', array( $this, 'edit_post_content' ), 10, 2 );
		add_filter( 'comment_edit_pre',  array( $this, 'edit_comment_content' ));
		add_filter('bbp_get_form_reply_content',array( $this, 'bbpress_edit_reply' ));
		add_filter('bbp_get_form_topic_content',array( $this, 'bbpress_edit_topic' ));


		//Add button bar and prettyify to BBPress and comment fields
		if($this->is_bar_enabled('comment')){ 
			add_filter('comment_form_field_comment',array($this,'comment_field'));
		}
		if($this->is_bar_enabled('bbpress')){
			add_action('bbp_theme_before_reply_form_content',array( $this,'pre_textarea_prettify_bbpress_reply'));
			add_action('bbp_theme_after_reply_form_content',array( $this,'post_textarea_prettify_bbpress_reply'));
			add_action('bbp_theme_before_topic_form_content',array( $this,'pre_textarea_prettify_bbpress_topic'));
			add_action('bbp_theme_after_topic_form_content',array( $this,'post_textarea_prettify_bbpress_topic'));
		}

		//Register scripts
		add_action('wp_enqueue_scripts', array($this,'register_scripts'));
	}

	/*
	* Settings
	*/
	function admin_init(){
		register_setting('writing',$this->domain, array($this,'validate'));
		add_settings_section( $this->domain.'_section', 'MarkDown', array($this,'settings'), 'writing'); 
		add_settings_field($this->domain.'_posttypes', __('Enable MarkDown for:', 'markdown'), array($this,'settings_posttypes'), 'writing', $this->domain.'_section');
		add_settings_field($this->domain.'_markdownbar', __('Enable MarkDown help bar for:', 'markdown'), array($this,'settings_markdownbar'), 'writing', $this->domain.'_section');
		add_settings_field($this->domain.'_prettify', __('Enable Prettify syntax highlighter:', 'markdown'), array($this,'settings_prettify'), 'writing', $this->domain.'_section');

		//Remove html tab for markdown posts
		add_filter( 'user_can_richedit', array($this,'can_richedit'), 99 );

		//Add admin scripts
		if($this->is_bar_enabled('posteditor')){
			add_action('admin_enqueue_scripts', array($this,'admin_scripts'),10,1);
		}
	}

	public function can_richedit($bool){
		$screen = get_current_screen();
		$post_type = $screen->post_type;
		if($this->is_Markdownable($post_type))
			return false;

		return $bool;
	}

	function settings(){
		//settings_fields('markdown'); 
		echo '<p>'.__("Select the post types or comments that will support Markdown. Comments and bbPress forums can also feature a Markdown 'help bar' and previewer. Automatic syntax highlighting can be provided by <a href='http://code.google.com/p/google-code-prettify/' target='_blank'>Prettify</a>.",$this->domain).'</p>';
	}

	function settings_posttypes(){
		$options = get_option($this->domain);
		$savedtypes = (array) $options['post_types'];
		$types=get_post_types(array('public'   => true),'objects'); 
		unset($types['attachment']);

		$id = "id={$this->domain}_posttypes'";
		foreach ($types as $type){
			echo "<label><input type='checkbox' {$id} ".checked(in_array($type->name,$savedtypes),true,false)."name='{$this->domain}[post_types][]' value='$type->name' />{$type->labels->name}</label></br>";
		}
		echo "<label><input type='checkbox' {$id} ".checked(in_array('comment',$savedtypes),true,false)."name='{$this->domain}[post_types][]' value='comment' />Comments</label></br>";	
	}

	function settings_markdownbar(){
		$options = get_option($this->domain);
		$savedtypes = (array) $options['post_types'];
		$barenabled = isset($options['markdownbar']) ? $options['markdownbar']  : self::$options['markdownbar'];
		$types=get_post_types(array('public'   => true),'objects'); 

		$id = "id={$this->domain}_markdownbar'";
		//If Forum, Topic, and Replies exist assume BBPress is activated:
		$type_names = array_keys($types);
		$bbpress = array_unique(array_merge($type_names,array('reply','forum','topic'))) === $type_names;

		echo "<label><input type='checkbox' {$id} ".checked(in_array('posteditor',$barenabled),true,false)."name='{$this->domain}[markdownbar][]' value='posteditor' />".__('Post editor',$this->domain)."</label></br>";				
		echo "<label><input type='checkbox' {$id} ".checked(in_array('comment',$barenabled)&&in_array('comment',$savedtypes),true,false)."name='{$this->domain}[markdownbar][]' value='comment' />".__('Comments',$this->domain)."</label></br>";
		echo "<label><input type='checkbox' {$id} ".checked(in_array('bbpress',$barenabled),true,false).disabled($bbpress,false,false)."name='{$this->domain}[markdownbar][]' value='bbpress' />".__('bbPress topics and replies',$this->domain)."</label></br>";
	}

	function settings_prettify(){
		$options = get_option($this->domain);
		$checked = (int) $options['prettify'];
		$id = "id={$this->domain}_prettify'";
		echo "<input type='checkbox' {$id} ".checked($checked,true,false)."name='{$this->domain}[prettify]' value='1' />";
	}

	function validate($options){
		$clean = array();
		foreach (self::$options as $option => $default){
			if(self::$option_types[$option]=='array'){
				$clean[$option] = isset($options[$option]) ? array_map('esc_attr',$options[$option]) : $default;
			}elseif(self::$option_types[$option]=='checkbox'){
				$clean[$option] = isset($options[$option]) ? (int) $options[$option] : $default;
			}
		}
		return $clean;
	}

	

	/*
	* Function to determine if markdown has been enabled for the current post_type or comment
	* If an integer is passed it assumed to be a post (not comment) ID. Otherwise it assumed to be the
	* the post type or 'comment' to test.
	*
	* @param (int|string) post ID or post type name or 'comment'
	* @return (true|false). True if markdown is enabled for this post type. False otherwise.
	* @since 1.0
	*/
	function is_Markdownable($id_or_type){
		if(is_int($id_or_type))
			$type = get_post_type($id_or_type);
		else
			$type = esc_attr($id_or_type);

		$options = get_option($this->domain);
		$savedtypes = (array) $options['post_types'];

		return in_array($type,$savedtypes);
	}

	function is_bar_enabled($id_or_type){
		if(is_int($id_or_type))
			$type = get_post_type($id_or_type);
		else
			$type = esc_attr($id_or_type);

		$options = get_option($this->domain);
		$barenabled = (array) $options['markdownbar'];

		return in_array($type,$barenabled);
	}
	/*
	* Function to determine if prettify should be loaded
	*/
	function loadPrettify(){
		$options = get_option($this->domain);
		if(empty($options['prettify'])) 
			return false;

		$savedtypes = (array) $options['post_types'];

		return is_singular($savedtypes);

	}


	/*
	* Convert Markdown to HTML prior to insertion to database
	*/
	//For comments
	function pre_comment_content($comment){
		if($this->is_Markdownable('comment')){
			$comment = stripslashes($comment);
			$comment = MarkdownExtended($comment );
		}
		return $comment;
	}
	//For posts
	public function wp_insert_post_data( $data, $postarr ) {		
		if($this->is_Markdownable($data['post_type'])|| ($data['post_type'] =='revision' && $this->is_Markdownable($data['post_parent']))){
                /*
                * Load the class, instead of the simple fnction
                * so we can acces the toc
                */
                $parser = new MarkdownExtraExtended_Parser($default_claases);

                /*
                * Add date to verify post_content_filtered
                * Format <!-- WPMD 2012-01-19 00:52:27 -->
                */
                $PCT = "<!-- WPMD ";
                $PCT .= $data['post_modified'] . ' -->';
                $PCT .= $parser->transform($data['post_content']);

               /*
                * Save meta content, before parsing
                */
                $data['post_content_filtered'] = $PCT;
                
                $this->meta_toc = $parser->toc;
                
               /*
                * The behaviour has changed, the content will be save as is
                * after all, MarkDown is meant to be legible, it may need 
                * a hook if the plugin is desactivated, but there is no problem as I see.
                */
		// $data['post_content'] = MarkdownExtended($data['post_content'] );
                
            }
            return $data;
	}
        
        /*
         * Save the meta toc
         */
        var $meta_toc;
        
        public function add_meta_toc($post_id, $post)
        {
            /*
             * Check if is MarkDownable to save the toc
             */
            if($this->is_Markdownable($post_id))
            {
                /*
                 * Save the TOC as post meta
                 */
                update_post_meta($post_id, '_simple_toc', serialize($this->meta_toc));
            }
            
            return $post;
        }

	public function render_content($content)
	{
		/*
		 *	Try to get the filtered_content before rendering
		 */
		$filtered = $this->_get_content_filtered();


		/*
		 * Based on $filtered value we can return the filtered content or
		 * apply the render.
		 */
		return ($filtered)
				? $filtered
				: MarkdownExtended($content);
	}


	/*
	* Convert HTML to MarkDown for editing
	*/
	//Post content
	public function edit_post_content( $content, $id ) {
		/*
		 * Check if WP-MarkdDown is Active
		 */
		if($this->is_Markdownable((int) $id)){
			/*
			 * We first check if we nhave previously filtered the content
			 */
			if($this->_has_content_filtered($id))
				return $content;

			/*
			 * The content was not written in MarkDown,
			 * so the MarkDownify is the last option
			 */
			$md = new Markdownify;
			$content = $md->parseString($content);
		}
		return $content;
	}

	//Comment content
	public function edit_comment_content( $content ) {
		if($this->is_Markdownable('comment')){
			$md = new Markdownify;
			$content = htmlspecialchars_decode($content);
			$content = $md->parseString($content);
			$content = esc_html($content);
		}
		return $content;
	}

	/*
	 * Has Content Filtered
	 * Checks if we can raly in the content filtered column
	 * 
	 */
	function _has_content_filtered($id, $return = false)
	{
		/*
		 * Get the post
		 */
		$post = get_post($id);

		/*
		 * 
		 */
		if(is_string($post->post_content_filtered) AND $post->post_content_filtered)
		{
			/*
			 * The tag contains the date to be more acurate
			 */
			$tag = "<!-- WPMD " . $post->post_modified . ' -->';
			$prevtag = "<-- WPMD " . $post->post_modified . ' -->';
			
			/*
			 * Use the triple equal operator because the position of the substring is int(0)
                         * And since we change the tag syntix we also chck because only this
                         * the function is backwards compatible
			 */
			if(strpos($post->post_content_filtered, $tag) !== false || 
                           strpos($post->post_content_filtered, $prevtag) !== false)
			{
				return ($return)
						/*
						 * Remove the tag
						 */
						? str_replace($tag, '', $post->post_content_filtered)
						: true;
			}
			else
			{
				return false;
			}
		}
	}

	/*
	 * Alias to get contentent filtered wiht one parameter
	 */
	function _get_content_filtered()
	{
		global $post;
		return $this->_has_content_filtered($post->id, true);
	}

	public function bbpress_edit_reply($content='') {
		return $this->bbpress_edit($content,'reply');
	}
	public function bbpress_edit_topic( $content='') {
		return $this->bbpress_edit($content,'topic');
	}
	public function bbpress_edit( $content='', $type='' ) {
		if($this->is_Markdownable($type)){
			$md = new Markdownify;
			$content = htmlspecialchars_decode($content);
			$content = $md->parseString($content);
			$content = esc_attr($content);
		}
		return $content;
	}


	/*
	* Adds html for the textareas to make them PageDown compatible 
       * Adds the PageDown 'button bar'
	*/
	function pre_textarea_prettify_bbpress_reply(){
		if($this->is_Markdownable('reply')){
			echo self::pre_textarea_prettify('bbp_reply_content');
		}
	}
	function post_textarea_prettify_bbpress_reply(){
		if($this->is_Markdownable('reply')){
			echo self::post_textarea_prettify('bbp_reply_content');
		}
	}
	function pre_textarea_prettify_bbpress_topic(){
		if($this->is_Markdownable('topic')){
			echo self::pre_textarea_prettify('bbp_topic_content');
		}
	}
	function post_textarea_prettify_bbpress_topic(){
		if($this->is_Markdownable('topic')){
			echo self::post_textarea_prettify('bbp_topic_content');
		}
	}

	function comment_field($html){
		if($this->is_Markdownable('comment')){
			$html =self::pre_textarea_prettify('comment').$html.self::post_textarea_prettify('comment');
		}
		return $html;
	}

	function pre_textarea_prettify($id=""){
			wp_enqueue_script('markdown');
			wp_enqueue_style('md_style');
		$id = esc_attr($id);
       	 return "<div class='wmd-panel'><div id='wmd-button-bar{$id}'></div>
				<div id='wmd-button-bar-help'>
        <p>
            To create code blocks or other preformatted text, indent by four spaces:
        </p>
        <pre class='wmd-help'><span class='wmd-help-spaces'>&nbsp;&nbsp;&nbsp;&nbsp;</span>This will be displayed in a monospaced font. The first four 
<span class='wmd-help-spaces'>&nbsp;&nbsp;&nbsp;&nbsp;</span>spaces will be stripped off, but all other whitespace
<span class='wmd-help-spaces'>&nbsp;&nbsp;&nbsp;&nbsp;</span>will be preserved.
<span class='wmd-help-spaces'>&nbsp;&nbsp;&nbsp;&nbsp;</span>
<span class='wmd-help-spaces'>&nbsp;&nbsp;&nbsp;&nbsp;</span>Markdown is turned off in code blocks:
<span class='wmd-help-spaces'>&nbsp;&nbsp;&nbsp;&nbsp;</span> [This is not a link](http://example.com)
</pre>
        <p>
            To create not a block, but an inline code span, use backticks:
        </p>
        <pre class='wmd-help'>Here is some inline `code`.</pre>
	For more help see <a href='http://daringfireball.net/projects/markdown/syntax' rel='no-follow'> http://daringfireball.net/projects/markdown/syntax</a>
				</div>";
	}
	function post_textarea_prettify($id=""){
		$id = esc_attr($id);
       	 return "<div id='wmd-preview{$id}' class='wmd-panel wmd-preview prettyprint'></div></div>";
	}

	/*
	* Register the scripts for the PageDown editor
	*/
	function register_scripts() {
		 //Markdown Preview and Prettify scripts
		$plugin_dir = plugin_dir_url(__FILE__);
		wp_register_script('md_convert', $plugin_dir. 'js/pagedown/Markdown.Converter.js',array(),'1.1' );
		wp_register_script('md_sanit', $plugin_dir.'js/pagedown/Markdown.Sanitizer.js',array(),'1.1' );
		wp_register_script('md_edit',$plugin_dir. 'js/pagedown/Markdown.Editor.js',array('md_convert','md_sanit'),'1.1' );
		wp_register_script('markdown_pretty',$plugin_dir. 'js/prettify.js',array('jquery'),'1.1' );

		wp_register_style('md_style',$plugin_dir.'css/demo.css');
		$markdown_dependancy = array('jquery','md_edit');
		$options = get_option($this->domain);

		 //Load prettify if enabled and viewing an appropriate post.
		if(!empty($options['prettify'])){
			$markdown_dependancy[]='markdown_pretty';

			if(!is_admin() && $this->loadPrettify()){	
				wp_enqueue_script('markdown_pretty');
				wp_enqueue_style('md_style');
			}
		}
   		wp_register_script('markdown',$plugin_dir. 'js/markdown.js',$markdown_dependancy ,'1.0' );
	}


	function admin_scripts($hook){
		$screen = get_current_screen();
		$post_type = $screen->post_type;
    		if ( ('post-new.php' == $hook || 'post.php' == $hook) && $this->is_Markdownable($post_type) ){
			$this->register_scripts();
			wp_enqueue_script('markdown_pretty');
			wp_enqueue_script('md_edit');
			wp_enqueue_script('jquery');
			wp_enqueue_style('md_style');
			add_action( 'admin_print_footer_scripts', array($this,'admin_footers_script'),100 );
		}
	}

	function admin_footers_script(){
	?> <script>
		jQuery(document).ready(function($) {                
			$('#wp-content-editor-container').after("<div id='wmd-previewcontent' class='wmd-panel wmd-preview prettyprint'></div>");
			$('#ed_toolbar').html("<div id='wmd-button-barcontent'></div>");
			var converter = new Markdown.getSanitizingConverter();
			var editor = new Markdown.Editor(converter, 'content');
			editor.run();
			$('.wmd-preview pre').addClass('prettyprint');
			prettyPrint();
			if (typeof prettyPrint == 'function') {
				prettyPrint();
				editor.hooks.chain("onPreviewRefresh", function () {
				        $('.wmd-preview pre').addClass('prettyprint');
					prettyPrint();
   				 });
			}
		});
		</script><?php
	}
}

require_once( dirname( __FILE__) . '/markdown/markdown_extra_extended.php' );
require_once( dirname( __FILE__) . '/markdownify/markdownify.php' );
$markdown = new WordPress_Markdown();
