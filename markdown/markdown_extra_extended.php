<?php
require_once('markdown-extra.php');
include('markdown_helper.php');
define( 'MARKDOWNEXTRAEXTENDED_VERSION',  "0.3" );

function MarkdownExtended($text, $default_claases = array()){
  $parser = new MarkdownExtraExtended_Parser($default_claases);
  return $parser->transform($text);
}

class MarkdownExtraExtended_Parser extends MarkdownExtra_Parser {
	# Tags that are always treated as block tags:
	var $block_tags_re = 'figure|figcaption|p|div|h[1-6]|blockquote|pre|table|dl|ol|ul|address|form|fieldset|iframe|hr|legend|section|figure|header|footer|aside';
	var $default_classes;
        
	var $autosections_level = 3;
		
	function MarkdownExtraExtended_Parser($default_classes = array()) {
            $default_classes = $default_classes;

            $this->block_gamut += array(
           
            // "doFencedFigures" => 7,
            
            /*
                * Before list because of compatibility
                */
                "doMiniBlocks" => 35,
            );

            /*
                * Autosections go from lower to higher level
                * We can adjust the level in order to make faster the parsing 
                */
            $autosections = array(
                "doAutoSectionsH6" => -18,
                "doAutoSectionsH5" => -16,
                "doAutoSectionsH4" => -14,
                "doAutoSectionsH3" => -12,
                "doAutoSectionsH2" => -10,
            );

            /*
            * Apply autosections depending on the level
            */
            if($this->autosections_level > 0)
            {
                /*
                * Get the level that will be contrasted with the parse level
                */
                $level = ($this->autosections_level * -2) - 7;

                /*
                * Filter the level
                */
                foreach($autosections AS $key => $l)
                {
                    if($level >= $l)
                        unset($autosections[$key]);
                }
            }

            if(count($autosections))
            {
                $this->block_gamut += $autosections;
            }

            parent::MarkdownExtra_Parser();
	}
	
	function doBlockQuotes($text) {
		$text = preg_replace_callback('/
			(?>^[ ]*>[ ]?
				(?:\((.+?)\))?
				[ ]*(.+\n(?:.+\n)*)
			)+	
			/xm',
			array(&$this, '_doBlockQuotes_callback'), $text);

		return $text;
	}
	
	function _doBlockQuotes_callback($matches) {
		$cite = $matches[1];
		$bq = '> ' . $matches[2];
		# trim one level of quoting - trim whitespace-only lines
		$bq = preg_replace('/^[ ]*>[ ]?|^[ ]+$/m', '', $bq);
		$bq = $this->runBlockGamut($bq);		# recurse

		$bq = preg_replace('/^/m', "  ", $bq);
		# These leading spaces cause problem with <pre> content, 
		# so we need to fix that:
		$bq = preg_replace_callback('{(\s*<pre>.+?</pre>)}sx', 
			array(&$this, '_doBlockQuotes_callback2'), $bq);
		
		$res = "<blockquote";
		$res .= empty($cite) ? ">" : " cite=\"$cite\">";
		$res .= "\n$bq\n</blockquote>";
		return "\n". $this->hashBlock($res)."\n\n";
	}

	function doFencedCodeBlocks($text) {
		$less_than_tab = $this->tab_width;
		
		$text = preg_replace_callback('{
				(?:\n|\A)
				# 1: Opening marker
				(
					~{3,}|`{3,} # Marker: three tilde or more.
				)
				
				[ ]?(\w+)?(?:,[ ]?(\d+))?[ ]* \n # Whitespace and newline following marker.
				
				# 3: Content
				(
					(?>
						(?!\1 [ ]* \n)	# Not a closing marker.
						.*\n+
					)+
				)
				
				# Closing marker.
				\1 [ ]* \n
			}xm',
			array(&$this, '_doFencedCodeBlocks_callback'), $text);

		return $text;
	}
	
	function _doFencedCodeBlocks_callback($matches) {
		$codeblock = $matches[4];
		$codeblock = htmlspecialchars($codeblock, ENT_NOQUOTES);
		$codeblock = preg_replace_callback('/^\n+/',
			array(&$this, '_doFencedCodeBlocks_newlines'), $codeblock);
		//$codeblock = "<pre><code>$codeblock</code></pre>";
		//$cb = "<pre><code";
		$cb = empty($matches[3]) ? "<pre><code" : "<pre class=\"linenums:$matches[3]\"><code"; 
		$cb .= empty($matches[2]) ? ">" : " class=\"language-$matches[2]\">";
		$cb .= "$codeblock</code></pre>";
		return "\n\n".$this->hashBlock($cb)."\n\n";
	}

	function doFencedFigures($text){
		$text = preg_replace_callback('{
			(?:\n|\A)
			# 1: Opening marker
			(
				={3,} # Marker: equal sign.
			)
			
			[ ]?(?:\[([^\]]+)\])?[ ]* \n # Whitespace and newline following marker.
			
			# 3: Content
			(
				(?>
					(?!\1 [ ]?(?:\[([^\]]+)\])?[ ]* \n)	# Not a closing marker.
					.*\n+
				)+
			)
			
			# Closing marker.
			\1 [ ]?(?:\[([^\]]+)\])?[ ]* \n
		}xm', array(&$this, '_doFencedFigures_callback'), $text);		
		
		return $text;	
	}	

	function _doFencedFigures_callback($matches) {
		# get figcaption
		$topcaption = empty($matches[2]) ? null : $this->runBlockGamut($matches[2]);
		$bottomcaption = empty($matches[4]) ? null : $this->runBlockGamut($matches[4]);
		$figure = $matches[3];
		$figure = $this->runBlockGamut($figure); # recurse

		$figure = preg_replace('/^/m', "  ", $figure);
		# These leading spaces cause problem with <pre> content, 
		# so we need to fix that - reuse blockqoute code to handle this:
		$figure = preg_replace_callback('{(\s*<pre>.+?</pre>)}sx', 
			array(&$this, '_doBlockQuotes_callback2'), $figure);
		
		$res = "<figure>";
		if(!empty($topcaption)){
			$res .= "\n<figcaption>$topcaption</figcaption>";
		}
		$res .= "\n$figure\n";
		if(!empty($bottomcaption) && empty($topcaption)){
			$res .= "<figcaption>$bottomcaption</figcaption>";
		}
		$res .= "</figure>";		
		return "\n". $this->hashBlock($res)."\n\n";
	}

	/*
	 * Do Variables
	 * 
	 * Syntax
	 * 	VARIABLE_UPPERCASE: Data \n
	 */ 
	function doMiniBlocks($text)
	{ 
		$text = preg_replace_callback('{
				^					
				[ ]*
				([_A-Z0-9]+?)			# $1 = Variable Name
				[ ]*
				(?:(\*))?				# Optional Asterisk
				:						# colon closing
				[ \t]*(\n)?				# Spaces or tabs are allowed, and new line
				(.+?)				    # $2 data
				\n+
			}xm',
			array(&$this, '_doMiniblocks_callback'), $text);

		return $text;
	}

	/*
	 * Callback Variables
	 *
	 * Output:
	 * <div class="{$var}"><p>{$data}</p></div>
	 */ 
	function _doMiniblocks_callback($matches)
	{
		/*
		 * Class = matches[1]
		 * Var = matches[1]
		 * Data = matches[4]
		 * Show Title = matches[2]
		 */ 
		$data = $this->runSpanGamut($matches[4]);
		$var 	= $matches[1];
		$class 	= strtolower($var);

		/*
		 * If no title
		 */ 
		$out = "<div class=\"{$class}\"><p>";
		$out .= $data;
		$out .= "</p></div>";

		/*
		 * @TODO Make If Title
		 */ 
		// $out = $this->_test_matches($matches);
		return "\n\n". $this->hashBlock($out) ."\n\n";
	}

	/*
	 * Autosections based on Heading level
	 */ 
	function doAutoSectionsH2($text){ return $this->_doAutoSections($text, 'h2'); }
	function doAutoSectionsH3($text){ return $this->_doAutoSections($text, 'h3'); }
	function doAutoSectionsH4($text){ return $this->_doAutoSections($text, 'h4'); }
	function doAutoSectionsH5($text){ return $this->_doAutoSections($text, 'h5'); }
	function doAutoSectionsH6($text){ return $this->_doAutoSections($text, 'h6'); }
	

	/*
	 * AutoSections
	 *
	 * Following existing rules, look for h2 and h3 in order to make them
	 * sections automatically. this way designers will have more control
	 * over the document.
	 */
	function _doAutoSections($text, $heading)
	{
		/*
		 * Attributes
		 */ 
		$attr = '
			(?:[ ]+\{
				(
					(div|aside)? 			#Get tag, default is section
					(\#[-_:a-zA-Z0-9]+)? 	#Get id
					((\.[-_:a-zA-Z0-9]+)+)? #Get Classes
			)\})? 
		';
		
		/*
		 * Define Open And close tags
		 */
		$h2 = array(
			'open' => '
				(^(.+?)'.$attr.'[ ]*\n(-+)[ ]*\n)	# h2 dashed, 
				|									# or
				(^\#{2}[ ]*(.+?)[ ]*\#*'.$attr.'\n)	# h2 whith number sign
			',

			'close' => '
				(^(.+?)[ ]*\n(-+)[ ]*\n+)			# h2 dashed, only start to avoid been swallowed
				|									# nor
				(^\#{2}[ ]*(.+?))					# h2 whith number sign, only starting
			'
		);

		$h3 = array(
			'open' => '(^\#{3}[ ]*(.+?)[ ]*\#*'.$attr.'\n)',

			'close' => '(^\#{3}[ ]*(.+?)[ ]*\#*\n)'
					. 	'|' . $h2['close']			//Add h2 because is the parent
		);

		$h4 = array(
			'open' => '(^\#{4}[ ]*(.+?)[ ]*\#*'.$attr.'\n)',

			'close' => '(^\#{4}[ ]*(.+?)[ ]*\#*\n)'
					. '|' . $h2['close']
					. '|' . $h3['close']
		);

		$h5 = array(
			'open' => '(^\#{5}[ ]*(.+?)[ ]*\#*'.$attr.'\n)',

			'close' => '(^\#{5}[ ]*(.+?)[ ]*\#*\n)'
					. '|' . $h2['close']
					. '|' . $h3['close']
					. '|' . $h4['close']
		);
		
		$h6 = array(
			'open' => '(^\#{6}[ ]*(.+?)[ ]*\#*'.$attr.'\n)',

			'close' => '(^\#{6}[ ]*(.+?)[ ]*\#*\n)'
					. '|' . $h2['close']
					. '|' . $h3['close']
					. '|' . $h4['close']
					. '|' . $h5['close']
		);


		/*
		 * Get heading open and close using variable variable of level
		 */ 
		
		$h = $$heading;
		$callback = '_doAutoSections_Callback' . $heading;

		/*
		 * Since we have two ways of declaring an h2
		 * we need to perform doble check
		 */ 
		$text = preg_replace_callback('{
			(?:\n|\A)
			# 1: Opening marker
			(
				'. $h['open'] . '
			)
		
			# 7: Content
			(
				(?>
					[ ]*
					(.*\n+)?									# Content
					(?!
						(
							(' . $h['close'] . ' ) | \.{4,} 
						)
					)
				)+
		 	)
		 }mx', array(&$this, $callback), $text);

		return $text;
	}

	/*
	 * Auto Sections Callback
	 * It will be used for Table of Contents in the future
	 * And also (ehm) styling :)
	 * Strcuture:
	 * 	<section id="{id}" class="{class}">
	 *		<h1>{title}</h1>
	 *  	{content}
	 *  </section>
	 *
	 *	@TODO Support hgroups
	 *
	 *  see:http://dev.w3.org/html5/spec/single-page.html#headings-and-sections
	 * 		http://coding.smashingmagazine.com/2011/08/16/html5-and-the-document-outlining-algorithm/
	 */ 
	function _doAutoSections_Callbackh2($matches)
	{	
		/*
		 * Matches:
		 *	0 Whole Section 
		 *	1 Whole Title
		 *	2 Whole Dashed Title
		 *	3 Dashed Title
		 *	4 Dashed attr
		 *	5 Dashed tag
		 *	6 Dashed id
		 *	7 Dashed Classes
		 *	11 Numb Title
		 *	12 Numb Attr
		 *	13 Numb Tag
		 *	14 Numb Id
		 *	15 Numb Class
		 *	17 Content
		 *	18 Last Paragraph
		 */
		$section = array(
			'title'		=> empty($matches[3]) ? $matches[11] : $matches[3],
			'id' 		=> empty($matches[6]) ? $matches[14] : $matches[6],
			'class'		=> empty($matches[7]) ? $matches[15] : $matches[7],
			'tag'		=> empty($matches[5]) ? $matches[13] : $matches[5],
		);

		return $this->_buildSection_callback($section, $matches[17], 2);
	}

	/*
	 * Do AutoSections Callback H3
	 */ 
	function _doAutoSections_Callbackh3($matches)
	{
		/*
		 * Matches:
		 *	0 Whole Section 
		 *	1 Whole Title
		 *	2 Whole Title
		 *	3 Title
		 *	4 Whole Attr
		 *	5 Tag
		 *	6 Id
		 *  7 Classes
		 * 	8 Last Class
		 *	9 Content
		 *	10 Las Line
		 */
		// $out = $this->_test_matches($matches);
		// return $this->hashBlock($out);
		$section = array('title' => $matches[3], 'tag' => $matches[5], 'id' => $matches[6], 'class' => $matches[7]);
		return $this->_buildSection_callback($section, $matches[9], 3);
	}	

	/*
	 * Do AutoSections Callback H4
	 */ 
	function _doAutoSections_Callbackh4($matches)
	{
		$section = array('title' => $matches[3], 'tag' => $matches[5], 'id' => $matches[6], 'class' => $matches[7]);
		return $this->_buildSection_callback($section, $matches[9], 4);
	}

	/*
	 * Do AutoSections Callback H4
	 */ 
	function _doAutoSections_Callbackh5($matches)
	{
		$section = array('title' => $matches[3], 'tag' => $matches[5], 'id' => $matches[6], 'class' => $matches[7]);
		return $this->_buildSection_callback($section, $matches[9], 5);
	}

	/*
	 * Do AutoSections Callback H4
	 */ 
	function _doAutoSections_Callbackh6($matches)
	{
		$section = array('title' => $matches[3], 'tag' => $matches[5], 'id' => $matches[6], 'class' => $matches[7]);
		return $this->_buildSection_callback($section, $matches[9], 6);
	}
	/*
	 * Build Section Callback
	 * @param Array $matches
	 *	@arg string $title
	 *	@arg string $id
	 *	@arg string $class
	 *	@arg string $tag
	 * @param string $content
	 * @param string $level
	 */ 
	function _buildSection_callback($section, $content, $level)
	{
		/*
		 * Get Attributes and title
		 */
		$tag 	= empty($section['tag']) ? 'section' : $section['tag'];
		$class 	= empty($section['class']) ? '' : ' class="' . trim (str_replace('.', ' ', $section['class'])) . '"';
		
		/*
		 * If no Id, the we do it automatically
		 */ 
		$id 	= empty($section['id'])  ? '' : trim($section['id'], '#');

		if(empty($id))
		{
			$id = (function_exists(remove_accents))
				? remove_accents($section['title'])
				: $section['title'];

			/*
			 * Sanitize and build id
			 */ 
			$id = sanitize_html_attr($id, 'dash', true);
		}


		$htag = 'h'.$level;
		$heading = $this->runSpanGamut($section['title']);
		$content = $this->runBlockGamut($content);
		
                /*
                 * Add the toc contents, only level 2
                 * Since the autosectioning goes lower to higher level
                 * there is no way to know which h3 belong to
                 * this section
                 */
                if($level == 2)
                {
                    $link = array('title' => $section['title'], 'id' => $id);
                    $this->toc[] = $link;
                }
                
		/*
		 * Create section
		 */
		$section= "<!-- {$section['title']} -->\n" //Debugging purposes
				. "\n<{$tag} id=\"{$id}\"{$class}>"
				. "\t<{$htag}>{$heading}</{$htag}>"
				. "\n\t{$content}\n</{$tag}>\n";
                                
		return "\n".$this->hashBlock($section)."\n";
	}

	/*
	 * Test Regex Matches
	 */ 
	function _test_matches($matches)
	{
		$out = '<hr />';

		foreach ($matches as $k => $v) {
			$out .= "\n\n<h2>{$k}</h2>\n<pre>{$v}</pre>";
		}

		$out .= '<hr />';

		return $out;
	}

	function _processListItems_callback($matches) {

		$item = parent::_processListItems_callback($matches);

		/*
		 * The regex get only the classes at the beggining
		 */ 
		$item = preg_replace_callback('{
		#(?:\A)
			# Open tag
		   <(.*)\>			# Openin tag (@TODO Get li attributes if have been given before)
		 	 
		 	 [ ]*\t*			#spaces and tags are allowed

		 	 (.*)			#Content if its one line only

			# Attributes
			(\{
				(
					(\#[-_:a-zA-Z0-9]+)? 	#Get id
					((\.[-_:a-zA-Z0-9]+)+)? #Get Classes
				)
			\})

			[ ]*\t*\n*		# Trailing Spaces will be trimmed

		}mx', array(&$this, '_getTagAttr_callback'), $item);

		/*
		 * The Item has been hashed, so we don't need to hash it twice
		 */ 
		return $item;
	}

	function _getTagAttr_callback($matches)
	{ 
		// $attr = $this->_test_matches($matches);
		// return $attr . '<li>'; exit;
		/*
		 * Classes = 6
		 * Id = 5
		 * Tag = 1
		 * One Line Content = 2
		 */
		//Remove trailing hash
		$id 	= empty($matches[5]) ? '' : ' id="'.substr($matches[5], 1).'"';

		//Transform period in spaces and remove the firstone
		$class 	= empty($matches[6]) ? '' : ' class="'.trim (str_replace('.', ' ', $matches[6])).'"';


		/*
		 * It's safe to return $matches[2], because is the content
		 * while it's given in the end
		 */ 
		return "<{$matches[1]}{$id}{$class}>{$matches[2]}";
	}
}
?>