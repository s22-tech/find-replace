#!/usr/local/bin/php
<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

  /**
  * Requires PHP 8.0+
  *
  * PHP command line script that recursively finds and replaces a string within the files of a directory and it's sub-directories.
  *
  * Useage: php script.php <DIRECTORY> <SEARCH-STRING> <REPLACE-STRING> (PARTIAL-FILENAME-MATCH)
  *
  * The script will replace all strings matching <SEARCH-STRING> within all files inside of <DIRECTORY> and its sub-directories with <REPLACE-STRING>.
  *
  * If you provide the option (PARTIAL-FILENAME-MATCH) it will only replace occurences in files with filenames that contain the partial search.
  *
  * Change $file_ext_to_search, $limit_to_dirs, and $dirs_to_skip to suit your particular needs.
  *
  * It's best to place this script outside of your web root so that it can't be accessed by others.
  */

  // Full or partial directory names to skip...
	$dirs_to_skip = [ '/backup', '/cache', '/cgi', '/conf/', '/css', '/data', '/default.', '/entities', '/framework', '/fonts',
							'/graphics', '/images', '/img', '/install', '/java', '/js', '/language', '/library', '/lists', '/localization',
							'/logs', '/media', '/passwords', '/payloads', '/routes', '/sdk', '/src', '/translation', '/trumbowyg', '/tmp',
							'/upload', '/vendor', '/--', '/_', '.svg',
						 ];

  // Limit the search to paths with the following paths...
	$user = get_current_user();
	$limit_to_dirs = ['/Users/'.$user, '/usr/local', ];

  // OPTIONS LEGEND | : = parameter requires value | :: = optional value | (no colon) = does not accept values.
	$options = getopt('d:f:r:e:n::', ['test', 'help', 'no_recurse']);
	$search_string      = $options['f'];        // Find string - mandatory.
	$replacement_string = $options['r'] ?? '';  // Replacement string - leave blank to delete search string.
	$file_ext_to_search = $options['e'];        // Only search files of these types.
	$name_contains      = $options['n'] ?? '';  // Name contains string - partial matching - optional.
	$top_level_dir = trim($options['d']) . DIRECTORY_SEPARATOR;  // Top level directory - mandatory.
	$top_level_dir = preg_replace('#//#',  DIRECTORY_SEPARATOR, $top_level_dir);
	$top_level_dir = preg_replace('#\\\\{2,}#', DIRECTORY_SEPARATOR, $top_level_dir);

	$file_ext_to_search = explode('|', $file_ext_to_search);

	$files_changed_list = '';
	$count = $dir_count = $total_files_changed = 0;

	if (is_cli()) {
		if (isset($options['help']) || empty($search_string)) {
		  // Display instructions.
			echo PHP_EOL;
			echo "Search & Replace in Multiple Files, Version 3" . PHP_EOL;
			echo "Finds (recursively) files and does a search & replace inside them." . PHP_EOL . PHP_EOL;
			echo "Usage: find-replace.php <options?> -f='search_string' -r='replacement' -n='name_contains?'" . PHP_EOL;
			echo "  --help       = Option: print these instructions." . PHP_EOL;
			echo "  --no_recurse = Option: do not recurse into subdirectories." . PHP_EOL;
			echo "  --test       = Option: test mode - perform a dry run (will not make any changes)." . PHP_EOL;
			echo "  -d <dir>     = Search within <dir> directory (use an absolute path)." . PHP_EOL;
			echo "  -f <string>  = <string> to search for." . PHP_EOL;
			echo "  -r <string>  = <string> to replace search_string with." . PHP_EOL;
			echo "  -n <text>    = Limit to files with names containing <text>." . PHP_EOL;
			echo "<search_string>, <replacement> & <name_contains> are user entered strings." . PHP_EOL . PHP_EOL;
			echo 'To run the replacement, remove "--help" from the command line and run it again.' . PHP_EOL . PHP_EOL;
			exit;
		}

		if (empty($top_level_dir)) {
			echo '*** You need to specify a directory!' . PHP_EOL;
		}
		elseif ($top_level_dir === '/') {
			colorize_text(string:'*** WARNING! DO NOT RUN THIS ON YOUR ENTIRE SERVER! ***', foreground_color:'bold_red', background_color:'', newlines:1);
		}
		elseif (empty($search_string)) {
			echo '*** You need to specify a search string!' . PHP_EOL;
		}
		elseif (empty($replacement_string)) {
			'*** You need to specify a replacement string!' . PHP_EOL;
		}
		elseif (!empty($name_contains)) {
			colorize_text(string:"Starting a recursive search for all files in << $top_level_dir >> whose filename contains '{$name_contains}'...", foreground_color:'green', background_color:'', newlines:1);
			find_replace($top_level_dir, $search_string, $replacement_string, $name_contains);
		}
		else {
			find_replace($top_level_dir, $search_string, $replacement_string);
		}
		if (isset($options['test']))   { $tense = 'will be';           } else { $tense = 'were';            }
		if ($dir_count != 1)           { $ies = 'ies';                 } else { $ies = 'y';                 }
		if ($total_files_changed != 1) { $s = 's'; $were_was = 'were'; } else { $s = ''; $were_was = 'was'; }
		if (isset($options['test']))   { $were_was = 'will be';        }
		if ($total_files_changed > 0 || $dir_count > 0) {
			echo PHP_EOL;
			colorize_text(string:"$total_files_changed file{$s} $were_was updated in $dir_count director{$ies}.", foreground_color:'black', background_color:'', newlines:2);
		}
		if ($total_files_changed > 0) {
			colorize_text(string:"These files $tense updated:", foreground_color:'black', background_color:'', newlines:1);
			echo $files_changed_list;
			echo PHP_EOL;
		}

		if (isset($options['test'])) {
			echo '*** DRY RUN -- Nothing was replaced.'. PHP_EOL;
			echo '*** To do the replacement, remove "--test" from the command line and run it again.'. PHP_EOL . PHP_EOL;
		}
	}
	else {
		echo 'This script must be run from the command line.' . PHP_EOL;
	}


///////////////////
// Functions //////
///////////////////

function is_cli() {
    return php_sapi_name() === 'cli';
}


function find_replace($dir, $find, $replace, $name_contains='') {
	global $count, $dir_count, $dirs_to_skip, $file_ext_to_search, $options, $limit_to_dirs;

  // Skip these directories...
	if (str_contains_array(haystack:$dir, needles:$dirs_to_skip)) return;

  // Narrow down the search scope...
	if (!str_contains_array(haystack:$dir, needles:$limit_to_dirs)) return;

	colorize_text(string:PHP_EOL ."Searching for files in directory '$dir'", foreground_color:'green', background_color:'', newlines:1);
	$file_cnt = 0;
	if (is_dir($dir)) {
		if ($dh = opendir($dir)) {
			$dir_count++;
			$dir_contents = make_sorted_dir_contents_array($dir);

			foreach ($dir_contents as $file) {
				$cnt = 0;
			  // Prevent runaway recursion.
				if ($file_cnt > 1_500) die('Too many recursions.' . PHP_EOL);
				$dir_file = str_replace('//', DIRECTORY_SEPARATOR, $dir . DIRECTORY_SEPARATOR . $file);
				$temp1 = $temp2 = '';  // Start with a blank slate.
				if (is_dir($dir_file) && !isset($options['no_recurse'])) {
				  // Recurse into subdirectory.
					find_replace($dir_file, $find, $replace);
				}
				else {
					if (!str_ends_with_array($file, $file_ext_to_search)) continue;
					$file_cnt++;
					echo "File searched: '$file' ";
					if (empty($name_contains)) {
						if (str_ends_with_array($file, $file_ext_to_search)) {
							perform_replacement($file, $dir_file, $find, $replace);
						}
					}
					else {
						if (str_contains($file, $str)) {
							if (str_ends_with_array($file, $file_ext_to_search)) {
								perform_replacement($file, $dir_file, $find, $replace);
							}
						}
					}
					echo PHP_EOL;
				}
			}
			closedir($dh);
		}
		else {
			echo "There was a problem opening the directory $dir (permissions maybe?)" . PHP_EOL;
		}
	}
	else {
		colorize_text(string:'ERROR: You entered a non-existent directory path.', foreground_color:'bold_red', background_color:'', newlines:2);
	}
// 	if ($file_cnt > 0) {
// 		echo 'Completed search through ' . $dir . "\n\n";
// 		if (!empty(isset($options['test']))) echo "••• To do the replacement, remove '--test' from the command line and run it again.". PHP_EOL . PHP_EOL;
// 	}
// 	else {
// 		echo 'No files processed in '. $dir.DIRECTORY_SEPARATOR.$file . PHP_EOL;
// 	}
}


function replace_string($dir_file, $count, $temp) {
	if ( !file_put_contents($dir_file, $temp) ) {
		echo 'There was a problem (permissions?) replacing the file ' . $dir_file . PHP_EOL;
	}
	else {
		echo '  -- ';
		colorize_text(string:'contents were replaced', foreground_color:'black', background_color:'', newlines:2);
		$count++;
	}
}


function perform_replacement($file, $dir_file, $find, $replace) {
	global $options, $total_files_changed, $temp1, $temp2, $cnt, $files_changed_list, $count;
	$temp1 = file_get_contents($dir_file);

  // Only replace file contents if string was found.
	if (str_contains($temp1, $find)) {
		$temp2    = str_replace($find, $replace, $temp1, $cnt);
		$do_done  = (isset($options['test'])) ? 'found' : 'updated';
		$was_were = ($cnt === 1) ? 'was' : 'were';
		$s        = ($cnt === 1) ? '' : 's';
		colorize_text(string:" $cnt occurence{$s} of search_string $was_were $do_done in $file", foreground_color:'red', background_color:'', newlines:2);

		$files_changed_list .= '• '. $dir_file . PHP_EOL;
		$total_files_changed++;
		if (!isset($options['test'])) {  // Not a test run.
			replace_string($dir_file, $count, $temp2);
		}
	}
}


function make_sorted_dir_contents_array($dir) {
	$directories = $files_list = [];
	$files = scandir($dir);
	foreach ($files as $file) {
		clearstatcache();  // is_dir is cached.
		if (str_starts_with($file, '.')) continue;
		if (is_dir($dir . DIRECTORY_SEPARATOR . $file)) {
			if (str_starts_with($file, '_')) $file = str_replace('_', '!!', $file);  // #1
			$directories[] = $file;
		}
		else {
			if (str_starts_with($file, '_')) $file = str_replace('_', '!!', $file);
			$files_list[] = $file;
		}
	}
	natcasesort($files_list);
	$files_list = str_replace('!!', '_', $files_list);
	natcasesort($directories);
	$directories = str_replace('!!', '_', $directories);
	// The above replaces the leading _ with !! to sort those files/dirs first.
	// Then they're replaced with _ again.

	$all_files = array_merge($files_list, $directories);
	return $all_files;
}


function str_ends_with_array($haystack, array $needles, $case='') {
	foreach ($needles as $needle) {
		if ($case === 'i') {
			$haystack = strtolower($haystack);
			$needle   = strtolower($needle);
		}
		if (str_ends_with($haystack, $needle)) return true;
	}
	return false;
}


function str_contains_array($haystack, array $needles, $case='') {
	foreach ($needles as $needle) {
		if ($case === 'i') {
			$haystack = strtolower($haystack);
			$needle   = strtolower($needle);
		}
		if (str_contains($haystack, $needle)) return true;
	}
	return false;
}


////////////////////////////////////////////////////
// Print colored output in Terminal.
////////////////////////////////////////////////////
function colorize_text($string, $foreground_color=null, $background_color=null, $newlines=0) {
	$foreground_color  = str_replace('bold_', '', $foreground_color, $bold_cnt);  // Remove string 'bold_', if present.
	$foreground_colors = [
		'black'  => '30',
		'blue'   => '34',
		'green'  => '32',
		'cyan'   => '36',
		'red'    => '31',
		'purple' => '35',
		'brown'  => '33',
		'gray'   => '37',
	];
	$background_colors = [
		'on_black'   => '40',
		'on_red'     => '41',
		'on_green'   => '42',
		'on_yellow'  => '43',
		'on_blue'    => '44',
		'on_magenta' => '45',
		'on_cyan'    => '46',
		'on_gray'    => '47',
	];

	$colored_string = '';

	if (isset($foreground_colors[$foreground_color])) {
	  // Change foreground color.  Double quotes must be used around color codes.
		$colored_string .= "\e[" . $bold_cnt.';'.$foreground_colors[$foreground_color] . 'm';
	}
	if (isset($background_colors[$background_color])) {
	  // Change background color.  Double quotes must be used around color codes.
		$colored_string .= "\e[" . $background_colors[$background_color] . 'm';
	}

	$spacer = '';
	if ($background_color) $spacer = ' ';  // Adds padding to text when highlighting with background color.

  // Add string and ending color code.  Double quotes must be used around color codes.
	$colored_string .= $spacer . $string . $spacer . "\e[0m";

	$newline = ($newlines > 0) ? str_repeat(PHP_EOL, $newlines) : '';
	echo $colored_string . $newline;
}


__halt_compiler();

////////////////////////////////////////////////////////////
/// NOTES //////////////////////////////////////////////////
////////////////////////////////////////////////////////////

Examples:
find-replace -d '~/Desktop' -f 'UPS Return' -r 'UPS Return NOW'

php ~/path/to/find-replace.php --test --no_recurse -d='~/Sites/user/affiliate/' -f='find this text' -r='replace with this'

#1 -- The directory name can't be changed before calling is_dir, so we need to use the str_replace() function twice.  See inline comments after 2nd one.

#2 -- DO NOT use empty() on $options that don't take a parameter.  It won't work.  Use !isset() instead.
