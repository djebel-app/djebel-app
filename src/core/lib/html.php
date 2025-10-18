<?php

class Djebel_App_HTML {
	/**
     * Generates HTML select
	 * Djebel_App_HTML::htmlSelect()
	 * @param string $name
	 * @param string $sel
	 * @param array $options - the dropdown options
	 * @param array $extra_options
	 * @return string
	 */
	public static function htmlSelect($name = '', $sel = null, $options = array(), $extra_options = []) {
		// Track if CSS has been output
		static $icons_css_added = false;

		$attr_str = '';
        $attr = isset($extra_options['attr']) ? $extra_options['attr'] : '';

		if ( is_array($attr) ) {
			$attr_pairs = [];

			foreach ($attr as $key => $value) {
				$attr_pairs[] = sprintf( "$key='%s'", esc_attr($value));
			}

			$attr_str = join( ' ', $attr_pairs );
		} elseif (is_scalar($attr)) {
			$attr_str = $attr;
		} else {
			trigger_error("Invalid param passed as attr", E_USER_NOTICE);
		}

		// if id is not supplied add it.
		if (stripos($attr_str, 'id=') === false) {
			$id = sanitize_title($name); // ID can't
			$attr_str .= " id='$id' ";
		}

        if (stripos($attr_str, 'class=') === false) {
			$id = sanitize_title($name); // ID can't
			$attr_str .= " class='$id' ";
		}

		$name = esc_attr($name); // can contain []
		$html = "\n<select name='$name' $attr_str>\n";

		// Move inactive IDs check outside loop for better performance
		$inactive_ids = !empty($extra_options['inactive_ids']) ? (array) $extra_options['inactive_ids'] : [];

		// Add support for icons in select if icons are provided
		if (!empty($extra_options['icons'])) {
			$local_attr[] = 'class="djebel_app_html_dropdown_with_icons"';
			
			// Add CSS for icon support only once
			if (!$icons_css_added) {
				$html .= "<style>
					.djebel_app_html_dropdown_with_icons option {
						padding-left: 25px;
						background-repeat: no-repeat;
						background-position: 5px center;
						background-size: 16px;
					}
				</style>";
				$icons_css_added = true;
			}
		}

		foreach ($options as $key => $label) {
            $key = esc_attr($key);
            $label = esc_html($label);
            
            // Collect option-specific attributes
            $local_attr = [];

            if ($sel == $key) {
                $local_attr[] = 'selected="selected"';
            }

            if (!empty($inactive_ids) && in_array($key, $inactive_ids)) {
                $local_attr[] = 'disabled="disabled"';
            }

            // Add icon if provided in the options
            if (!empty($extra_options['icons'][$key])) {
                $icon_url = esc_url($extra_options['icons'][$key]);
                $local_attr[] = sprintf('style="background-image: url(\'%s\')"', $icon_url);
            }

            $local_attr_str = !empty($local_attr) ? ' ' . implode(' ', $local_attr) : '';

            if (!empty($label) && !empty($key) && !empty($extra_options['append_id'])) {
                $label .= sprintf(' (id:%s)', esc_html($key));
            }

            $html .= "\t<option value='{$key}'{$local_attr_str}>{$label}</option>\n";
		}

		$html .= '</select>';
		$html .= "\n";

		return $html;
	}
	
	/**
	 * Generates an HTML radio group
	 * Djebel_App_HTML::radio();
	 *
	 * If the msg has a text surrounded with * it will be bolded.
	 * You can append or prepend text by passing the last param '$extra'.
	 */
	public static function radio( $name, $cur_val = null, $extra = array() ) {
		$extra['_type'] = 'radio';
		$options = empty($extra['value']) ? [] : (array) $extra['value'];

		$radio_opts_pairs = [];

		foreach ($options as $val => $label) {
			$single_radio_args = [
				'id' => sanitize_title($name . '_' . $val),
				'msg' => $label,
				'value' => $val,
				'_type' => 'radio',
			];

			$radio_btn = self::checkbox($name, $cur_val, $single_radio_args);
			$radio_opts_pairs[] = $radio_btn;
		}

		$buff = join("<br/>", $radio_opts_pairs);

		return $buff;
	}

	/**
	 * Generates an HTML checkbox
	 * Djebel_App_HTML::checkbox();
	 *
	 * If the msg has a text surrounded with * it will be bolded.
	 * You can append or prepend text by passing the last param '$extra'.
	 */
	public static function checkbox( $name, $cur_val = null, $extra = array() ) {
		$cur_val_esc = esc_attr($cur_val);
		$name    = esc_attr($name);
		$html    = '';
		$id     = empty($extra['id']) ? '' : $extra['id'];
		$msg     = empty($extra['msg']) ? '' : $extra['msg'];
		$attr    = empty($extra['attr']) ? '' : $extra['attr'];
		$value   = isset($extra['value']) ? $extra['value'] : 1;
		$value_esc = esc_attr($value);

		if (!empty($id)) {
			// ok
		} elseif ( stripos( $attr, 'id=' ) === false ) { // ID not in the attrib list so we'll add it
			$id = $name . '_' . $cur_val;
			$id = preg_replace( '#[^\w\-]#si', '_', $id );
			$id = preg_replace( '#\_+#si', '_', $id );
			$id = trim( $id, '_' );
			$attr .= " id='$id' ";
		} elseif ( preg_match( '#id=[\'"]*([\w-]+)[\'"]*#si', $attr, $matches ) ) { // parse for ID
			$id = $matches[1];
		}

		if ( ( stripos( $attr, 'checked' ) === false ) && $value == $cur_val ) { // not working I think?
			$attr .= " checked='checked' ";
		}

		$type = empty($extra['_type']) ? 'checkbox' : esc_attr($extra['_type']);

		// Let's make things bold (if any)
		$msg = preg_replace( '#\*(.*?)\*#si', '<strong>${1}</strong>', $msg );
		$msg_esc = esc_html($msg);
		$html .= "\n<label for='$id'><input id='$id' type='$type' name='$name' value='$value_esc' $attr /> $msg_esc</label>\n";

		// We have a hidden element that corresponds to the checkbox so we always have a value
		//$html .= "<input type='hidden' id='{$id}_hidden' class='{$id}_hidden' name='$name' value='' />\n";

		return $html;
	}

	/**
	 * Generates a hidden input text
	 * Djebel_App_HTML::hidden();
	 */
	public static function hidden( $name, $value = '', $extra = array() ) {
        return self::text($name, $value, ['_type' => 'hidden', ]);
    }

	/**
	 * Generates an html input text box
	 * Djebel_App_HTML::text();
	 */
	public static function text( $name, $value = '', $extra = array() ) {
		$name    = esc_attr($name);
		$html    = '';
		$id     = empty($extra['id']) ? '' : $extra['id'];
		$msg     = empty($extra['msg']) ? '' : $extra['msg'];
		$attr    = empty($extra['attr']) ? '' : $extra['attr'];
		$value_esc = esc_attr($value);

		if (!empty($id)) {
			// ok
		} elseif ( stripos( $attr, 'id=' ) === false ) { // ID not in the attrib list so we'll add it
			$id = $name;
			$id = preg_replace( '#[^\w\-]#si', '_', $id );
			$id = preg_replace( '#\_+#si', '_', $id );
			$id = trim( $id, '_' );
			$attr .= " id='$id' ";
		} elseif ( preg_match( '#id=[\'"]*([\w-]+)[\'"]*#si', $attr, $matches ) ) { // parse for ID
			$id = $matches[1];
		}

		$type = empty($extra['_type']) ? 'text' : esc_attr($extra['_type']);

		// Let's make things bold (if any)
		$msg = preg_replace( '#\*(.*?)\*#si', '<strong>${1}</strong>', $msg );
		$msg_esc = esc_html($msg);
		$html .= "\n<label for='$id'><input type='$type' id='$id' name='$name' value='$value_esc' $attr /> $msg_esc</label>\n";

		// We have a hidden element that corresponds to the checkbox so we always have a value
		//$html .= "<input type='hidden' id='{$id}_hidden' class='{$id}_hidden' name='$name' value='' />\n";

		return $html;
	}

    /**
     * Goes through an array of records and picks id and title from the array
     * @param array $records
     * @return string[]
     * @throws QS_Site_App_Exception
     */
    public static function prepareForDropdown($records)
    {
        $dropdown_arr = [
            '' => '',
        ];

        foreach ($records as $rec) {
            $rec = (array) $rec;
            $id = QS_Site_App_Util::getField('id|post_id', $rec);
            $label = QS_Site_App_Util::getField('title|label|post_title', $rec);

            if (empty($id) || empty($label)) {
                continue;
            }

            $dropdown_arr[$id] = esc_html($label);
        }

        return $dropdown_arr;
    }

    /**
     * Djebel_App_HTML::generateAssetUrl();
     * Enqueues a plugin or css file that is within the plugin.
     * @todo switch to .min version if available and live.
     * @todo allow external urls
     * @param string $file_rel
     * @param string $plugin_file
     * @return string
     */
    public static function generateAssetUrl($file_rel, $plugin_file) {
        if (!function_exists('plugin_dir_path')) {
            return '';
        }

        $file = plugin_dir_path($plugin_file) . $file_rel;

        if (!file_exists($file)) {
            return '';
        }

        $file_last_mod = filemtime($file);
        $src_url = plugins_url($file_rel, $plugin_file);

        if (!empty($file_last_mod)) {
	        $src_url = add_query_arg('v', $file_last_mod, $src_url);
        }

        return $src_url;
    }

    /**
     * Djebel_App_HTML::enqueueAsset();
     * Enqueues a plugin or css file that is within the plugin.
     * @todo switch to .min version if available and live.
     * @todo allow external urls
     * @param string $file_rel
     * @param string $plugin_file
     * @return bool
     */
    public static function enqueueAsset($file_rel, $plugin_file = '') {
        if (!function_exists('wp_enqueue_style')) {
            return false;
        }

        $file = plugin_dir_path($plugin_file) . $file_rel;

        if (!file_exists($file)) {
            trigger_error("Plugin asset not found. File: [$file_rel]", E_USER_ERROR);
        }

        $suffix = QS_SITE_LIVE_ENV ? '.min' : '';
        $load_url = plugins_url( $file_rel, $plugin_file );

        // check for this modify to min if running on live
        if (!empty($suffix)) {
            $ext_now = strpos($file_rel, '.css') !== false ? 'css' : 'js';

            $file_rel_min = QS_Site_App_File_Util::replaceExt($file_rel, $suffix . '.' . $ext_now);
            $local_file_full = plugin_dir_path( $plugin_file ) . $file_rel_min;

            if (is_file($local_file_full)) {
                $file = $local_file_full;
                $load_url = plugins_url( $file_rel_min, $plugin_file );
            }
        }

        $plugin_file = empty($plugin_file) ? QS_SITE_CORE_BASE_PLUGIN : $plugin_file;

        $file_last_mod = filemtime($file);

        $handle = basename($plugin_file) . '-' . basename($file_rel);
        $handle_fmt = $handle;
        $handle_fmt = str_ireplace([ '.php', '.js', '.css', ], '', $handle_fmt);
        $handle_fmt = sanitize_title($handle_fmt);
        $handle_fmt = str_replace([ '--', '_', ], '-', $handle_fmt);

        if (stripos($file_rel, '.css') !== false) {
            wp_register_style(
                $handle_fmt,
                $load_url,
                array(),
                $file_last_mod,
                'all'
            );

            wp_enqueue_style( $handle_fmt );
            return true;
        } elseif (stripos($file_rel, '.js') !== false) {
            wp_enqueue_script(
                $handle_fmt,
                $load_url,
                array('jquery'),
                $file_last_mod,
                true
            );

            return true;
        }

        return false;
    }

	/**
	 * Renders a page with content - ONE method for all cases
	 * Djebel_App_HTML::renderPage($content, $title, $options);
	 */
	public static function renderPage($content, $title = 'Djebel CMS', $options = []) {
		// Auto-detect error pages and set appropriate styling
		$is_error_page = !empty($options['status_code']) && $options['status_code'] >= 400;
		
		if ($is_error_page) {
			$bg_gradient = empty($options['bg_gradient']) ? 'linear-gradient(135deg, #008080 0%, #20b2aa 100%)' : $options['bg_gradient'];
			$container_class = empty($options['container_class']) ? 'djebel-app-error-container' : $options['container_class'];
			$content_class = empty($options['content_class']) ? 'djebel-app-error-content' : $options['content_class'];
		} else {
			$bg_gradient = empty($options['bg_gradient']) ? 'linear-gradient(135deg, #008080 0%, #20b2aa 100%)' : $options['bg_gradient'];
			$container_class = empty($options['container_class']) ? 'djebel-app-page-container' : $options['container_class'];
			$content_class = empty($options['content_class']) ? 'djebel-app-page-content' : $options['content_class'];
		}

		$head_content = empty($options['head_content']) ? '' : $options['head_content'];
		$footer_content = empty($options['footer_content']) ? '' : $options['footer_content'];
		$status_code = empty($options['status_code']) ? null : $options['status_code'];

		// Apply filter to content with context
		$filter_context = [
			'title' => $title,
			'options' => $options,
			'container_class' => $container_class,
			'content_class' => $content_class,
		];

        $content = Dj_App_Hooks::applyFilter('app.page.render.content', $content, $filter_context);
        
        // Set status code if provided
        if (!empty($status_code)) {
            $req_obj = Dj_App_Request::getInstance();
            $req_obj->setResponseCode($status_code);
        }
        
        // Output HTTP headers via system hook
        if (!Dj_App_Hooks::hasRun('app.page.output_http_headers')) {
            Dj_App_Hooks::doAction('app.page.output_http_headers');
        }
		
		ob_start();
		?>
		<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php echo htmlspecialchars($title); ?></title>
			<style>
				* { margin: 0; padding: 0; box-sizing: border-box; }
				body { 
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
					background: <?php echo $bg_gradient; ?>;
					min-height: 100vh;
					display: flex;
					align-items: center;
					justify-content: center;
					padding: 20px;
				}
				        .<?php echo $container_class; ?> {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            max-width: 800px;
            width: 100%;
            padding: 40px;
        }
        .<?php echo $content_class; ?> {
            line-height: 1.6;
            color: #333;
        }
        .djebel-app-error-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f1f3f4;
        }
        .djebel-app-error-brand {
            color: #2c3e50;
            font-size: 2.5em;
            font-weight: 700;
            margin: 0 0 5px 0;
            letter-spacing: -1px;
        }
        .djebel-app-error-subtitle {
            color: #7f8c8d;
            font-size: 1em;
            font-weight: 400;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .djebel-app-error-title {
            color: #e74c3c;
            font-size: 2em;
            margin-bottom: 20px;
            text-align: center;
        }
        .djebel-app-error-message {
            background: #f8f9fa;
            border-left: 4px solid #ff6b6b;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 0 8px 8px 0;
            font-family: "Monaco", "Menlo", monospace;
            font-size: 14px;
            line-height: 1.6;
        }
        .djebel-app-error-details {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .djebel-app-detail-item {
            display: flex;
            margin-bottom: 10px;
        }
        .djebel-app-detail-label {
            font-weight: 600;
            color: #6c757d;
            min-width: 120px;
            margin-right: 15px;
        }
        .djebel-app-detail-value {
            color: #495057;
            font-family: "Monaco", "Menlo", monospace;
            font-size: 13px;
        }
        .djebel-app-trace {
            background: #2d3748;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 8px;
            font-family: "Monaco", "Menlo", monospace;
            font-size: 12px;
            line-height: 1.5;
            overflow-x: auto;
            white-space: pre-wrap;
        }
        .djebel-app-back-link {
            text-align: center;
        }
        .djebel-app-back-link a {
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.3s;
        }
        .djebel-app-back-link a:hover {
            background: #2980b9;
        }
        @media (max-width: 768px) {
            .<?php echo $container_class; ?> { padding: 20px; margin: 10px; }
            .djebel-app-error-brand { font-size: 2em; }
            .djebel-app-error-subtitle { font-size: 0.9em; }
            .djebel-app-error-title { font-size: 1.5em; }
        }
			</style>
			<?php echo $head_content; ?>
		</head>
		<body>
			<div class="<?php echo $container_class; ?>">
				<?php if ($is_error_page): ?>
					<div class="djebel-app-error-header">
						<h1 class="djebel-app-error-brand">Djebel</h1>
					</div>
				<?php endif; ?>
				<div class="<?php echo $content_class; ?>"><?php echo $content; ?></div>
			</div>
			<?php echo $footer_content; ?>
		</body>
		</html>
		<?php
		$buff = ob_get_clean();
		$buff = trim($buff);
		echo $buff;
		exit;
	}



    /**
     * Djebel_App_HTML::encodeEntities();
     * @param string $str
     * @return string
     */
    static public function encodeEntities($str) {
        if (empty($str) || !is_scalar($str)) {
            return '';
        }

        $str = htmlentities($str, ENT_QUOTES, 'UTF-8');

        return $str;
    }

    /**
     *
     * Djebel_App_HTML::decodeEntities();
     * @param string $str
     * @return string
     */
    static public function decodeEntities($str) {
        $str = html_entity_decode( $str, ENT_COMPAT, 'UTF-8' );
        return $str;
    }

    /**
     * Removes HTML tags and their content from a string using case-insensitive string matching
     * 
     * This method efficiently removes all instances of a specified HTML tag and its content
     * from a string using stripos() for case-insensitive searching. It properly handles:
     * - Nested tags of the same type
     * - Tags with attributes
     * - Case-insensitive matching (div, DIV, Div all match)
     * - Malformed HTML (returns original content if no closing tag found)
     * 
     * @param string $tag The tag name to remove (without angle brackets, e.g., 'div', 'script')
     * @param string $content The HTML content to process
     * @return string The content with all specified tags and their contents removed
     * 
     * @example
     * $html = 'Before <div>Content</div> After';
     * $result = Djebel_App_HTML::removeTag('div', $html);
     * // Returns: 'Before  After'
     * 
     * @example  
     * $html = 'Text <SCRIPT>alert("bad")</SCRIPT> More';
     * $result = Djebel_App_HTML::removeTag('script', $html);
     * // Returns: 'Text  More'
     */
    static public function removeTag($tag, $content) {
        // Validate input parameters
        if (empty($tag) || empty($content) || !is_string($content)) {
            return $content;
        }

        // Sanitize tag name to contain only valid HTML tag characters (alphanumeric and hyphens)
        // We use character-by-character checking instead of regex for consistency
        $tag_clean = '';
        $tag_length = strlen($tag);
        
        for ($i = 0; $i < $tag_length; $i++) {
            $char = $tag[$i];
            
            if (ctype_alnum($char) || $char === '-') {
                $tag_clean .= $char;
            }
        }
        
        $tag = $tag_clean;
        
        // If sanitization removed all characters, return original content
        if (empty($tag)) {
            return $content;
        }

        // Initialize processing variables
        $result = $content;
        $iteration = 0;
        $max_iterations = 100; // Prevent infinite loops in malformed HTML
        
        // Create search patterns for case-insensitive stripos() calls
        $opening_tag_start_pattern = '<' . $tag;     // e.g., '<div'
        $closing_tag_pattern = '</' . $tag . '>';    // e.g., '</div>'
        
        // Track search position to handle false positives correctly
        $search_from_pos = 0;
        
        // Main processing loop - remove tags until none are found
        while ($iteration < $max_iterations) {
            // Find the next opening tag using case-insensitive search
            $opening_pos = stripos($result, $opening_tag_start_pattern, $search_from_pos);
            
            if ($opening_pos === false) {
                // No more opening tags found, we're done
                break;
            }
            
            // Find the end of the opening tag by locating the closing '>'
            $opening_tag_end = strpos($result, '>', $opening_pos);
            
            if ($opening_tag_end === false) {
                // Malformed HTML - opening tag without closing '>'
                // Return original content to avoid corruption
                return $content;
            }
            
            // Include the '>' character in our tag end position
            $opening_tag_end++;
            
            // Extract the complete opening tag for validation
            $tag_content = substr($result, $opening_pos, $opening_tag_end - $opening_pos);
            
            // Verify this is a valid tag match (not a partial match within another tag)
            if (!self::isValidTagMatch($tag, $tag_content)) {
                // Not a proper tag match, continue searching after this false positive
                $search_from_pos = $opening_pos + strlen($opening_tag_start_pattern);
                continue;
            }
            
            // Handle nested tags by finding the matching closing tag
            // We directly search for opening/closing tags instead of scanning character by character
            // This is much more efficient than the previous approach
            $nesting_level = 1; // We found one opening tag
            $search_from = $opening_tag_end; // Start searching after the opening tag
            $matching_closing_pos = false;
            
            // Search for matching closing tag, accounting for nested tags
            while ($nesting_level > 0) {
                // Look for both opening and closing tags from current position
                $next_opening_pos = stripos($result, $opening_tag_start_pattern, $search_from);
                $next_closing_pos = stripos($result, $closing_tag_pattern, $search_from);
                
                // Convert false to PHP_INT_MAX for easier comparison
                if ($next_opening_pos === false) {
                    $next_opening_pos = PHP_INT_MAX;
                }
                
                if ($next_closing_pos === false) {
                    $next_closing_pos = PHP_INT_MAX;
                }
                
                // Check if we found any more tags
                if ($next_opening_pos == PHP_INT_MAX && $next_closing_pos == PHP_INT_MAX) {
                    // No more tags found, but nesting level > 0 means no matching closing tag
                    // Return original content to avoid corruption
                    return $content;
                }
                
                // Determine which tag comes first
                if ($next_opening_pos < $next_closing_pos) {
                    // Found another opening tag first - this increases nesting level
                    // But we need to verify it's a valid tag, not a partial match
                    $check_end = strpos($result, '>', $next_opening_pos);
                    
                    if ($check_end !== false) {
                        // Extract the potential tag for validation
                        $check_tag = substr($result, $next_opening_pos, $check_end - $next_opening_pos + 1);
                        
                        if (self::isValidTagMatch($tag, $check_tag)) {
                            // Valid nested opening tag found
                            $nesting_level++;
                            $search_from = $next_opening_pos + strlen($opening_tag_start_pattern);
                        } else {
                            // Not a valid tag, skip this position
                            $search_from = $next_opening_pos + 1;
                        }
                    } else {
                        // Malformed tag, skip this position
                        $search_from = $next_opening_pos + 1;
                    }
                } else {
                    // Found a closing tag first - this decreases nesting level
                    $nesting_level--;
                    
                    if ($nesting_level == 0) {
                        // Found the matching closing tag for our original opening tag
                        $matching_closing_pos = $next_closing_pos;
                    }
                    
                    // Move past this closing tag
                    $search_from = $next_closing_pos + strlen($closing_tag_pattern);
                }
            }
            
            // Verify we found a matching closing tag
            if ($matching_closing_pos === false) {
                // No matching closing tag found - return original content
                return $content;
            }
            
            // Calculate positions for removal
            $closing_tag_end = $matching_closing_pos + strlen($closing_tag_pattern);
            
            // Remove everything from opening tag to closing tag (inclusive)
            $result = substr($result, 0, $opening_pos) . substr($result, $closing_tag_end);
            
            // Reset search position since we modified the string
            $search_from_pos = 0;
            
            // Increment iteration counter to prevent infinite loops
            $iteration++;
        }
        
        return $result;
    }

    /**
     * Validates if a found tag content represents a valid HTML tag match
     * 
     * This helper method uses only string functions (no regex) to verify that
     * a found tag content is actually a proper HTML tag and not a partial match
     * within another tag or text content.
     * 
     * @param string $tag The tag name we're looking for (e.g., 'div')
     * @param string $tag_content The actual tag content found (e.g., '<div class="test">')
     * @return bool True if this is a valid tag match, false otherwise
     * 
     * @example
     * isValidTagMatch('div', '<div>') returns true
     * isValidTagMatch('div', '<div class="test">') returns true  
     * isValidTagMatch('div', '<divider>') returns false
     * isValidTagMatch('div', '<div') returns false (missing >)
     */
    private static function isValidTagMatch($tag, $tag_content) {
        // Validate that tag starts with our expected tag name (case-insensitive)
        $expected_start = '<' . $tag;
        
        if (stripos($tag_content, $expected_start) !== 0) {
            return false;
        }
        
        // Validate that tag ends with '>'
        if (substr($tag_content, -1) !== '>') {
            return false;
        }
        
        // Extract content after the tag name and before the closing '>'
        $after_tag = substr($tag_content, strlen($expected_start));
        $after_tag = substr($after_tag, 0, -1); // Remove the trailing '>'
        
        // If there's content after the tag name, it must start with whitespace
        // This ensures we match '<div>' and '<div class="test">' but not '<divider>'
        if (!empty($after_tag) && !ctype_space($after_tag[0])) {
            return false;
        }
        
        return true;
    }
}