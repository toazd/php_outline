<?php
if (!extension_loaded("mbstring")) {
    echo ExitWithException("This script requires the PHP extension 'mbstring'");
}

# this script requires the php mbstring extension
# not only for this option but also for the function mb_strlen
# eg. sudo apt install php-mbstring
mb_internal_encoding("UTF-8");

# Bible text used to search.
# this script expects the following format (a "|" delimited CSV):
# book chapter#:verse#|verse{strongs} text {strongs} {strongs} text {strongs} {strongs} {strongs}
# and supports upto 3 consecutive strongs numbers
$bible_text = "kjvs.csv";

# set to true to get the hash(s)
if (false) {
    echo "<P>$bible_text CRC32: " . hash_file("crc32", $bible_text) . "</P>";
    #echo hash_file("crc32", "style.css") . "<BR>";
    echo "<P>Running on PHP v" . phpversion() . "</P>";
    echo "<P>" . var_dump(hash_algos()) . "</P>";
}

# ensure the integrity of the data file by checking its hash
if (hash_file("crc32", $bible_text) != "359b6817") {
    ExitWithException("Integrity check for data file \"$bible_text\" failed.<BR>You may need to aquire an original \"$bible_text\" or reinstall this application.<BR>If you intentionally changed \$bible_text in the source code, remove this warning or update the hash value for the data file.");
}

# ensure after each run all variables are cleared
register_shutdown_function('UnsetVarsOnShutdown');

# Enable PHP error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('xdebug.mode', 'off');

# Older versions of php are significantly slower (eg. 5.6.40)
if (phpversion() < "7.4.33") {
    ini_set('max_execution_time', '300');
    ini_set('memory_limit', '512M');
} elseif (phpversion() >= "7.4.33") {
    ini_set('max_execution_time', '60');
    ini_set('memory_limit', '256M');
}
?>

<!DOCTYPE html>
<HTML lang="en">

<HEAD>
    <TITLE>Outline</TITLE>
    <LINK rel="icon" href="site-icon.png">
    <LINK rel="stylesheet" href="style.css">
    <META name="author" content="wmcdannell at gmail dot com">
    <META name="description" content="Outline of Biblical usage for words">
    <META charset="UTF-8">
    <META name="viewport" content="width=device-width, initial-scale=1.0">
</HEAD>

<BODY id="body">
    <DIV id="search_form">
        <FORM action="" method="post" class="search_form" enctype="text/plain accept-charset=UTF-8">
            <TABLE class="center">
                <TR>
                    <TD colspan=3>
                        <A href="<?php echo $_SERVER["REQUEST_URI"]; ?>" class="largefont">Outline of Biblical usage</a>
                    </TD>
                </TR>
                <TR>
                    <TD colspan=3>
                        <!-- autofocus has to be disabled for mobile, otherwise the slide-out keyboard is always visible -->
                        <INPUT type="text" inputmode="search" name="criteria" class="search_criteria" value=""
                            placeholder="type here" maxlength=255 autocomplete="off" autofocus>
                    </TD>
                </TR>
                <TR>
                    <TD>
                        <INPUT type="checkbox" name="case_sensitivity">case-sensitive
                    </TD>
                    <TD>
                        <INPUT type="checkbox" name="show_exact_matches" checked>show <SPAN
                            class="exact_match">exact</SPAN> matches
                    </TD>
                    <TD>
                        <INPUT type="checkbox" name="show_all_strongs">show all strongs
                    </TD>
                </TR>
                <TR>
                    <TD>
                        <INPUT type="checkbox" name="search_ot" checked>OT
                    </TD>
                    <TD>
                        <INPUT type="checkbox" name="search_nt" checked>NT
                    </TD>
                </TR>
                <TR>
                    <TD colspan=3>
                        <INPUT type="submit" name="submit_button" class="submit_button" value="Search">
                    </TD>
                </TR>
            </TABLE>
            <?php
            # for measuring script execution time
            $start_time = microtime(true);

            # warning for php versions < 7.4.33 (runs significantly slower)
            #if (phpversion() < "7.4.33") {
            #    echo "<P class=\"debug center\">This script runs significantly slower on PHP versions less than v7.4.33. Please consider upgrading.<BR>You are using PHP v" . phpversion() . "</P>";
            #}
            
            if (phpversion() < "7.4.33") {
                echo "<P class=\"debug center\">WARNING: This script runs significantly slower on older versions of PHP<BR>Please upgrade to v7 or higher.</P>";
            }

            # Get option values from the checkboxes
            
            # this variable is used in the pattern for preg_match, etc.
            # i = true, case-insensitive
            # "" = false, case-sensitive
            if (isset($_POST['case_sensitivity'])) {
                $case_sensitive = "";
            } else {
                $case_sensitive = "i";
            }

            # whether to show exact matches or not
            if (isset($_POST['show_exact_matches'])) {
                $show_exact_matches = true;
            } else {
                $show_exact_matches = false;
            }

            # whether to show all strongs in each result, or only for the words that were matched
            if (isset($_POST['show_all_strongs'])) {
                $show_all_strongs = true;
            } else {
                $show_all_strongs = false;
            }

            # whether to search the OT
            if (isset($_POST['search_ot'])) {
                $search_ot = true;
            } else {
                $search_ot = false;
            }

            # whether to search the NT
            if (isset($_POST['search_nt'])) {
                $search_nt = true;
            } else {
                $search_nt = false;
            }

            # executed when submit button is pressed (or enter)
            if (isset($_POST['submit_button'])) {

                # Get the text to search for from the criteria input field
                $search_for = $_POST['criteria'];

                # remove special characters that don't belong, except the colon
                # . \ + * ? [ ^ ] $ ( ) { } = ! < > | - #
                $search_for = preg_replace("#[^\w:\d ]+#", "", $search_for);

                # if the search criteria is too short, display an error and don't do anything
                if (mb_strlen(trim($search_for)) <= 2) {
                    echo "<P class=\"debug center\">Search criteria \"$search_for\" is too short.<P>";
                    exit;
                }

                # ignore very common words (that are by themselves) to avoid unncessarily high processing times
                if (preg_match("#^the$|^and$|^that$|^shall$|^unto$|^for$|^his$|^her$|^they$|^him$|^not$|^them$|^with$|^all$|^thou$|^thy$|^was$|^man$|^men$|^which$|^where$|^when$|^old$#", trim($search_for))) {
                    #ExitWithException("Ignoring common word \"$search_for\" to avoid high processing times.<BR>Try entering a specific \"book chapter#:verse# $search_for\"");
                    echo "<P class=\"debug center\">Short or common words may take a long time to process.</P>";
                }

                # global vars
                $hits = 0;
                $strongs_array = [];
                $strongs_num = "";
                $h_found = false;
                $g_found = false;
                $exact_matches = 0;
                $related_matches = 0;
                $lines_checked = 0;
                $book_verse_mode = false;
                $term_arr = [];
                $results_table_begin = false;
                $total_replacement_count = 0;
                $verses_matched = 0;
                $multi_line = "";

                # if searching for only a strongs number
                if (preg_match("#[GHgh][\d]{1,4}#", $search_for)) {
                    $search_for_strongs = true;
                    $search_for = strtoupper($search_for);
                } else {
                    $search_for_strongs = false;
                }

                if ($search_for_strongs) {
                    $strongs_array = ["$search_for" => "supplied"];
                }

                $matches = [];
                # detect if a book chapter:verse is supplied to get the strongs num from
                if (preg_match("#^(\d?[ ]?\w+)[ ]?(\d{1,3}):(\d{1,3}) (\w+)(.*)#", $search_for, $matches) && $search_for_strongs === false) {
                    #VarDump($matches);
                    # matches(6) {
                    #    [0]=> whole match
                    #       string(20) "1 john 2:5 perfected"
                    #    [1]=> book
                    #       string(6) "1 john"
                    #    [2]=> chapter
                    #       string(1) "2"
                    #    [3]=> verse
                    #       string(1) "5"
                    #    [4]=> word to find strongs for (others are ignored)
                    #       string(9) "perfected"
                    #    [5]=> extra words that are ignored
                    #  }
                    $book_verse_mode = true;
                    $bookname = TransformBookNames($matches[1]);
                    $chapter = $matches[2];
                    $verse_number = $matches[3];
                    $word_to_find_strongs_for = $matches[4];
                    $book_chapter_verse = "$bookname $chapter:$verse_number";
                    $specific_verse_found = false;

                    # show a warning if extra words were supplied beyond the first
                    if (array_key_exists(5, $matches) && $matches[5] != "") {
                        echo "<P class=\"center debug\">Ignoring extra words:<BR>\"$matches[5]\"</P>";
                    }

                } elseif (preg_match("#^(\d?[ ]?\w+?)[ ]?(\d{1,3}):(\d{1,3})$#", $search_for)) {
                    ExitWithException("When providing a book chapter:verse, you must also supply a word to find the Strong's number for.");
                }

                if ($search_for_strongs && $book_verse_mode === false) {
                    echo "<P class=\"center bold underline\">Search results for \"$search_for\"</P>";
                } elseif ($search_for_strongs === false && $show_exact_matches && $book_verse_mode === false) {
                    echo "<P class=\"center\"><SPAN class=\"bold underline\">Search results for \"$search_for\"</SPAN><BR><SPAN class=\"normal\">(exact matches included)</SPAN></P>";
                } elseif ($book_verse_mode) {
                    echo "<P class=\"center\"><SPAN class=\"bold underline\">Search results for \"$book_chapter_verse $word_to_find_strongs_for\"</SPAN><BR><SPAN class=\"normal\">(exact matches included)</SPAN></P>";
                } elseif ($show_exact_matches === false) {
                    echo "<P class=\"center\"><SPAN class=\"bold underline\">Search results for \"$search_for\"</SPAN><BR><SPAN class=\"normal\">(exact matches excluded)</SPAN></P>";
                }

                ###########################################################################################
                # get strongs nums for the word/phrase searched for
                if ($search_for_strongs === false) {
                    # open the file of the text to search in read only mode
                    $handle = @fopen($bible_text, "r");

                    if ($handle) {
                        $lines_checked = 0;

                        # get one line at a time from the file
                        while (($line = fgets($handle)) !== false) {
                            $lines_checked += 1;

                            # don't process lines that don't contain at least 9 characters
                            if (mb_strlen($line) < 9) {
                                continue;
                            }

                            if ($search_ot === false || $search_nt === false) {
                                # get the bookname
                                $line_bookname = substr($line, 0, 3);

                                # if not searching the OT, skip those books
                                if ($search_ot === false && (preg_match('/(gen|exo|lev|num|deu|jos|Jdg|rth|1sa|2sa|1ki|2ki|1ch|2ch|ezr|neh|est|job|psa|pro|ecc|son|isa|jer|lam|eze|dan|hos|joe|amo|oba|jon|mic|nah|hab|zep|hag|zec|mal)/i', $line_bookname) === 1)) {
                                    continue;
                                }

                                # if not searching the NT, skip those books
                                if ($search_nt === false && (preg_match('/(mat|mar|luk|joh|act|rom|1co|2co|gal|eph|phi|col|1th|2th|1ti|2ti|tit|phm|heb|jam|1pe|2pe|1jo|2jo|3jo|jud|rev)/i', $line_bookname) === 1)) {
                                    continue;
                                }
                            }

                            # newline characters aren't required for processing or display via HTML so remove them
                            $line = str_replace(["\r", "\n", "\r\n"], "", $line);

                            # only check the specific verse provided in book_verse mode
                            if ($book_verse_mode && $specific_verse_found === false) {
                                # NOTE case sensitivity is handled below but it could be handled here first
                                if (stripos($line, "$bookname $chapter:$verse_number", 0) !== false) {
                                    # found the verse, process it
                                    $specific_verse_found = true;
                                } else {
                                    # not the verse we're looking for, skip to the next line
                                    continue;
                                }
                            }

                            # split the CSV by the delimiter so we can access each column independantly
                            $dataline_arr = explode("|", $line);
                            $book_chapter_verse = $dataline_arr[0];
                            $verse_text = $dataline_arr[1];

                            # in bookversemode, we only want to search for the word supplied
                            # and not the entire string provided
                            if ($book_verse_mode) {
                                $search_for = $word_to_find_strongs_for;
                            }

                            # DEBUG ONLY
                            # only process specific verses
                            #if (preg_match("#Zec 8:4#", $dataline_arr[0])) {
                            #echo "<P>Found $dataline_arr[0]</P>";
                            #} else {
                            #    continue;
                            #}
            
                            # reset the match array for each line
                            $match_array = [];

                            # in book_verse mode the supplied word may not be next to a strongs num
                            # when not in book_verse mode we want to grab all the strongs nums that appear
                            # next to the supplied word/phrase provided
                            if ($book_verse_mode) {
                                $pattern = "#\b$search_for#$case_sensitive";
                            } else {
                                $pattern = "#\b$search_for\{[GH]\d{1,4}\}#$case_sensitive";
                            }

                            # check if search_for is in the verse text
                            # ExtractStrongsNumber handles getting the right number for the word/phrase
                            if (preg_match_all($pattern, $verse_text, $match_array) !== false) {

                                # hits is the number of matches per line
                                # we need to keep track of them because ExtractStrongsNumber will
                                # only extract one strongs num per string provided
                                $hits = count($match_array[0]);

                                # if exactly one hit per line add the strongs num for it to the array
                                # we will use to search for all occurances of afterward
                                if ($hits == 1) {
                                    $strongs_num = ExtractStrongsNumber($line, $search_for, $case_sensitive);
                                    $strongs_array += ["$strongs_num" => "$book_chapter_verse"];
                                } elseif ($hits > 1) {

                                    # if more than one hit per line, add the first one,
                                    # remove that portion of the line, add the next, and so on
                                    $multi_line = explode("|", $line);
                                    $multi_line = $multi_line[1];

                                    # loop until no more matches remain
                                    while (preg_match("#\b$search_for\{[GH]\d{1,4}\}#$case_sensitive", $multi_line)) {

                                        $strongs_num = ExtractStrongsNumber($multi_line, $search_for, $case_sensitive);
                                        $strongs_array += ["$strongs_num" => "$book_chapter_verse"];

                                        # remove portion of the line that a strongs num was already added for
                                        # add 2 to the length to include the curly brackets
                                        $multi_line = substr($multi_line, stripos($line, $strongs_num) + mb_strlen($strongs_num) + 2);
                                    }
                                }
                            }

                            # if we found the specified verse and processed it for strongs nums
                            # no need to check any more lines
                            if ($book_verse_mode && $specific_verse_found) {
                                break;
                            }
                        }
                    }
                    # close the $bible_text file
                    fclose($handle);

                    #
                    if ($book_verse_mode === false && $lines_checked != 31102) {
                        echo "<P class=\"debug center\">WARNING: \$lines_checked not equal to 31,102.<BR>If you intentionally changed \$bible_text remove this warning.</P>";
                    }
                }

                # NOTE: DO NOT USE array_unique because it only works on values not keys!!
                # and the strongs_array is arranged as such:
                # key(s)=strongs_num  value(s)=Book chap:verse
            
                # don't print the report if a strongs number was searched for
                if ($search_for_strongs === false && count($strongs_array) > 0) {
                    # print a report of the strongs nums
                    if ($case_sensitive == "") {
                        echo "<P class=\"underline center\">Strongs numbers associated with \"$search_for\"</P><BR>(case-sensitive)";
                    } elseif ($case_sensitive == "i") {
                        echo "<P class=\"underline center\">Strongs numbers associated with \"$search_for\"</P>";
                    }
                    echo "<TABLE class=\"strongs center\">";
                    foreach ((array) $strongs_array as $strongs_num => $book_verse_source) {
                        echo "<TR><TD class=\"strongs alignright\"><SPAN class=\"book\">$book_verse_source</SPAN></TD><TD class=\"strongs alignleft\">$strongs_num</TD></TR>";
                    }
                    echo "</TABLE><HR>";
                    #echo "</TABLE><BR>";
                }

                #########################################################################################
                # if any strongs nums were found
                if (count($strongs_array) > 0) {

                    # open the file of the text to search in read only mode
                    $handle = @fopen($bible_text, "r");

                    if ($handle) {
                        $lines_checked = 0;
                        # get one line at a time from the file
                        while (($line = fgets($handle)) !== false) {
                            $lines_checked += 1;
                            # don't process lines that don't contain at least a book ch#:v#|\w
                            if (mb_strlen($line) < 9) {
                                continue;
                            }

                            $line = str_replace(["\r", "\n", "\r\n"], "", $line);
                            $line_already_printed = false;
                            $exact_match_found = false;
                            $related_match_found = false;

                            $dataline_arr = explode("|", $line);
                            $book_chapter_verse = $dataline_arr[0];
                            $verse_text = $dataline_arr[1];

                            # DEBUG ONLY
                            # only process specific verses
                            #if (preg_match("#Zec 8:4#", $dataline_arr[0])) {
                            #    echo "<P>Found $dataline_arr[0]</P>";
                            #} else {
                            #    continue;
                            #}
            
                            if ($search_for_strongs) {
                                if (strpos($verse_text, "{{$search_for}}") !== false) {
                                    $exact_matches += preg_match_all("#\{$search_for\}#", $verse_text);
                                    #substr_count($verse_text, $search_for);
                                    $verses_matched += 1;
                                    PrintMatchedVerse($line, $strongs_array, false, $case_sensitive);
                                }
                            } elseif ($search_for_strongs === false) {
                                # loop through the strongs nums found and find verses that match
                                foreach ($strongs_array as $strongs_num => $book_verse_source) {

                                    $exact_found = 0;
                                    $related_found = 0;

                                    # find exact matches
                                    if (preg_match("#\b$search_for\{$strongs_num\}|\b$search_for\{[GH]\d{1,4}\} \{$strongs_num\}|\b$search_for\{[GH]\d{1,4}\} \{[GH]\d{1,4}\} \{$strongs_num\}#$case_sensitive", $verse_text)) {
                                        $exact_match_found = true;

                                        # count the exact and related matches for this verse
                                        $exact_found = preg_match_all("#\b$search_for\{$strongs_num\}|\b$search_for\{[GH]\d{1,4}\} \{$strongs_num\}|\b$search_for\{[GH]\d{1,4}\} \{[GH]\d{1,4}\} \{$strongs_num\}#$case_sensitive", $verse_text);
                                        $exact_matches += $exact_found;

                                        $related_found = preg_match_all("#(?<!\b$search_for|} )\{$strongs_num\}|(?<!\b$search_for|} )\{[GH]\d{1,4}\} \{$strongs_num\}|(?<!\b$search_for|} )\{[GH]\d{1,4}\} \{[GH]\d{1,4}\} \{$strongs_num\}#$case_sensitive", $verse_text);
                                        $related_matches += $related_found;

                                        # related matches
                                    } else {
                                        if (preg_match("#(?<!\b$search_for|} )\{$strongs_num\}|(?<!\b$search_for|} )\{[GH]\d{1,4}\} \{$strongs_num\}|(?<!\b$search_for|} )\{[GH]\d{1,4}\} \{[GH]\d{1,4}\} \{$strongs_num\}#$case_sensitive", $verse_text)) {
                                            $related_match_found = true;
                                            # count the related matches only because if there were
                                            # any exact matches they would have been counted above
                                            # and this else block is skipped
                                            $related_found = preg_match_all("#(?<!\b$search_for|} )\{$strongs_num\}|(?<!\b$search_for|} )\{[GH]\d{1,4}\} \{$strongs_num\}|(?<!\b$search_for|} )\{[GH]\d{1,4}\} \{[GH]\d{1,4}\} \{$strongs_num\}#$case_sensitive", $verse_text);
                                            $related_matches += $related_found;
                                        }
                                    }
                                }

                                # if an exact match is found, print it as that even if
                                # it has related matches in it as well
                                # DO NOT PUT "&& $related_match_found === false" here silly
                                if ($exact_match_found && $show_exact_matches) {
                                    $verses_matched += 1;
                                    PrintMatchedVerse($line, $strongs_array, true, $case_sensitive);
                                } elseif ($exact_match_found === false && $related_match_found) {
                                    $verses_matched += 1;
                                    PrintMatchedVerse($line, $strongs_array, false, $case_sensitive);
                                }
                            }
                        }
                    }
                    fclose($handle);

                    if ($lines_checked != 31102) {
                        echo "<P class=\"debug center\">WARNING: \$lines_checked not equal to 31,102.<BR>If you intentionally changed \$bible_text remove this warning.</P>";
                    }
                }

                # results table end
                if ($exact_matches > 0 || $related_matches > 0) {
                    echo "</TABLE>";
                }

                # summary of words/phrases for matched strongs nums, sorted alphabetically,
                # case-insensitive with duplicates removed and the occurance counts included
                # with each unique word/phrase matched
                # NOTE: array sorting is based on the values so we cannot simply sort() and array_flip()
                $swap_count_arr = [];
                $sorted_arr = [];
                $terms_matched_count = 0;
                if (count($term_arr) > 0) {

                    # get a count for how many of each word/phrase occurs
                    $count_arr = array_count_values($term_arr);

                    # create an array of just the values since we can't
                    # have duplicate keys (otherwise array_flip would suffice)
                    foreach ($count_arr as $key => $value) {
                        #echo "<LI>$key ($value)</LI>";
                        $swap_count_arr[] = $key;
                        $terms_matched_count += $value;
                    }

                    # don't print the summary if it would be very short
                    #if ($terms_matched_count > 5) {
                    # don't print the summary if the number of variations is too low
                    if (count(array_keys($count_arr)) > 4) {
                        echo "<HR>";
                        #echo "<BR>";
            
                        # sort the new array alphabetically, case-insensitive
                        sort($swap_count_arr, SORT_NATURAL | SORT_FLAG_CASE);

                        # loop through the array of just values and create a new
                        # associative array using the values as keys and matching
                        # the counts up with each key from the count_arr
                        foreach ($swap_count_arr as $value) {
                            $sorted_arr[$value] = $count_arr[$value];
                        }

                        # If the counts gotten from above don't match the total number
                        # of replacements in HighlightBeforeStrongsNums
                        #if ($terms_matched_count != $total_replacement_count) {
                        #    echo "<P class=\"debug\">ERROR<BR>Terms matched count not equal to total replacement count: T($terms_matched_count) R($total_replacement_count)</P>";
                        #}
            
                        # print the now sorted summary out along with their counts
                        echo "<TABLE class=\"center\">";
                        echo "<tr class=\"border_bottom\"><td colspan=2 class=\"results_data aligncenter\">Summary</TD></TR>";
                        foreach ($sorted_arr as $key => $value) {
                            # change color for exact matches
                            if (preg_match("#{$search_for}$#$case_sensitive", $key)) {
                                echo "<tr class=\"border_bottom\"><td class=\"results_data alignleft exact_match\">$key</TD><td class=\"results_data alignright\">$value</TD></TR>";
                            } else {
                                echo "<tr class=\"border_bottom\"><td class=\"results_data alignleft\">$key</TD><td class=\"results_data alignright\">$value</TD></TR>";
                            }
                        }
                        echo "<tr class=\"border_bottom\"><td class=\"results_data alignleft\">Total</TD><td class=\"results_data alignright\">$terms_matched_count</TD></TR>";
                        echo "</TABLE>";
                    }
                }

                # hr before summary of counts and time taken
                echo "<HR>";

                # singular or plural results
                if ($exact_matches == 1) {
                    $exact_match_text = "match";
                    $related_match_text = "match";
                } elseif ($exact_matches > 1) {
                    $exact_match_text = "matches";
                    $related_match_text = "matches";
                } else {
                    $exact_match_text = "matches";
                    $related_match_text = "matches";
                }

                if (($exact_matches + $related_matches) == 1) {
                    $total_match_text = "match";
                } elseif (($exact_matches + $related_matches) > 1) {
                    $total_match_text = "matches";
                } else {
                    $total_match_text = "matches";
                }

                if ($verses_matched == 1) {
                    $verses_matched_text = "verse";
                } elseif ($verses_matched > 1) {
                    $verses_matched_text = "verses";
                } else {
                    $verses_matched_text = "verses";
                }

                if ($show_exact_matches === true) {
                    echo "<P class=\"center\">$exact_matches $exact_match_text</P>";
                } elseif ($show_exact_matches === false) {
                    echo "<P class=\"center\">$exact_matches exact $exact_match_text excluded</P>";
                }

                if ($search_for_strongs === false) {
                    echo "<P class=\"center\">$related_matches related $related_match_text found</P>";
                    echo "<P class=\"center\">" . ($exact_matches + $related_matches) . " total $total_match_text in $verses_matched $verses_matched_text</P>";
                }

                $end_time = microtime(true);
                $total_time = round($end_time - $start_time, 2);
                echo "<P class=\"center\"> Finished in $total_time seconds</P>";
            }

            ###########################################################################################
            #############################    Functions    #############################################
            ###########################################################################################
            
            # Format the data line for printing
            function PrintMatchedVerse($line, array $strongs_array, $exact_match, $case_sensitive)
            {
                global $show_all_strongs;
                global $results_table_begin;
                global $exact_matches, $related_matches, $total_replacement_count, $terms_matched_count;

                $line_arr = explode("|", $line);

                $line_arr[1] = HighlightBeforeStrongsNums($line_arr[1], $strongs_array, $case_sensitive);

                # NOTE: DO NOT CHECK ($exact_matches + $related_matches) vs $total_replacement_count here
                # because the number of matches for the data line can exceed the number of replacements
                # because the highlighter will ignore matches that are already highlighted
            
                if ($show_all_strongs) {
                    #$line_arr[1] = StripStrongsNumsButExclude($line_arr[1], $strongs_array);
                    # strongs nums shown in superscript
                    $line_arr[1] = preg_replace("#\{([GH]\d{1,4})\}#", "<sup class=\"strongs\">" . "$1" . "</sup>", $line_arr[1]);
                } elseif ($show_all_strongs === false) {
                    $line_arr[1] = StripStrongsNums($line_arr[1]);
                    # strongs nums shown in superscript
                    $line_arr[1] = preg_replace("#\{([GH]\d{1,4})\}#", "<sup class=\"strongs\">" . "$1" . "</sup>", $line_arr[1]);
                }

                # Before the first of any matched line begin the table
                if ($results_table_begin === false && ($exact_matches >= 1 || $related_matches >= 1)) {
                    echo "<TABLE class=\"results\">";
                    $results_table_begin = true;
                }

                # Fix for highlights right against eachother
                $line_arr[1] = str_ireplace("<SPAN class=\"highlight\"> ", " <SPAN class=\"highlight\">", $line_arr[1]);

                # DEBUG ONLY
                #if (preg_match("#Gen 1:4#", $line_arr[0])) {
                #    echo PrintHTMLtags($line_arr[1]);
                #}
            
                # exact match lines are shown in a different color to easily differentiate them
                if ($exact_match) {
                    echo "<tr class=\"results border_top\"><td class=\"nowrap align_center results_data\"><SPAN class=\"book\">$line_arr[0]</SPAN></TD><td class=\"increase_line_height align_center results_data\"><SPAN class=\"exact_match\">$line_arr[1]</SPAN></TD></TR>";
                } elseif ($exact_match === false) {
                    echo "<tr class=\"results border_top\"><td class=\"nowrap align_center results_data\"><SPAN class=\"book\">$line_arr[0]</SPAN></TD><td class=\"increase_line_height align_center results_data\">$line_arr[1]</TD></TR>";
                }
            }

            # replace html tags so they display
            function PrintHTMLtags($str)
            {
                $str = str_replace("<", "&lt;", $str);
                $str = str_replace(">", "&gt;", $str);
                return $str;
            }

            # Strip all the strongs nums from a data line
            function StripStrongsNums($str)
            {
                #$debugstr = str_replace("<", "&lt;", $str);
                #$debugstr = str_replace(">", "&gt;", $debugstr);
                #echo "<P>StripStrongsNums_STR: \"$debugstr\"</P>";
            
                # remove the extra space between strongs nums that are next to eachother and separated by
                # a space, that are not inside of the first set of <SPAN class="highlight"> </SPAN> tags
                # otherwise, when the strongs nums are removed an extra space will appear in the result
                # that normally separates the strongs nums word{strongs} {strongs}
                # the space is left alone between the <SPAN class="highlight"> </SPAN> tags because it will
                # show in the result
                $str = preg_replace("#<SPAN class=\"highlight\">.+?<\/SPAN>(*SKIP)(*F)|\} \{#", "}{", $str);

                # Replace strongs nums that are not inside of the first set of <SPAN class="highlight"> </SPAN> tags
                # this removes all strongs nums including their brackets and it will leave the extra space that
                # normally is the delimiter between each strongs num
                $str = preg_replace("#<SPAN class=\"highlight\">.+?<\/SPAN>(*SKIP)(*F)|\{[GH]\d{1,4}\}#", "", $str);
                return $str;
            }

            # highlight the text preceding a matched strongs num
            function HighlightBeforeStrongsNums($str, $arr, $case_sensitive)
            {
                $matches = [];
                global $term_arr, $total_replacement_count, $search_for_strongs, $book_verse_mode;
                $hl_beg = "<SPAN class=\"highlight\">";
                $hl_end = "</SPAN>";
                $i = 0;
                $replacements = 0;
                # whether to show full phrase in the summary or only the word preceding the matched
                # strongs num
                #if ($search_for_strongs || $book_verse_mode) {
                #    $full_summary = true;
                #} else {
                #    $full_summary = false;
                #}
                $full_summary = true;

                foreach ($arr as $strongs_num => $bookversesource) {
                    # capture the text before the strongs num we're looking for but stop before the first
                    # of any of the following characters }>?:,;.
                    $match_count = preg_match_all("#([^}>]*?)\{$strongs_num\} \{[GH]\d{1,4}\} \{[GH]\d{1,4}\}|([^}>]*?)\{[GH]\d{1,4}\} \{$strongs_num\} \{[GH]\d{1,4}\}|([^}]*?)\{[GH]\d{1,4}\} \{[GH]\d{1,4}\} \{$strongs_num\}|([^}>]*?)\{$strongs_num\} \{[GH]\d{1,4}\}|([^}>]*?)\{[GH]\d{1,4}\} \{$strongs_num\}|([^}>]*?)\{$strongs_num\}#$case_sensitive", $str, $matches);

                    # get the total match count per verse matched
                    #$match_count = preg_match_all("#([^}>]*?)\{$strongs_num\} \{[GH]\d{1,4}\} \{[GH]\d{1,4}\}|([^}>]*?)\{[GH]\d{1,4}\} \{$strongs_num\} \{[GH]\d{1,4}\}|([^}]*?)\{[GH]\d{1,4}\} \{[GH]\d{1,4}\} \{$strongs_num\}|([^}]*?)\{$strongs_num\} \{[GH]\d{1,4}\}|([^}>]*?)\{[GH]\d{1,4}\} \{$strongs_num\}|([^}>]*?)\{$strongs_num\}#$case_sensitive", $str);
                    if ($match_count > 0) {
                        #$total_replacement_count += $match_count;
                        # remove the first element of $term_matches because it always contains
                        # the matched text (which would count it twice each round and we
                        # don't want that). subsequent elements in $term_matches are
                        # patterned as follows:
                        # key=> the group# matched in the regex, value=>the matched text
                        unset($matches[0]);

                        # DEBUG ONLY
                        #echo "<pre>";
                        #var_dump($match_count);
                        #var_dump($matches);
                        #echo "</pre>";
            
                        # loop through the matched groups and add each one that isn't empty
                        # to the $term_array which is used to make a summary and occurance
                        # count for each match. An empty element indicates that that group
                        # did not match when not using the PREG_UNMATCHED_AS_NULL flag
                        foreach ($matches as $match_array) {
                            foreach ($match_array as $term_match) {
                                if ($term_match != "") {
                                    #echo "<P>Checking \"$term_match\"</P>";
                                    # add the match to term_arr as many times as it appears
                                    # so it can be counted
                                    #for ($i = 1; $i <= $match_count; $i++) {
                                    if ($full_summary) {
                                        $term_match = trim($term_match);
                                        # remove any leading non-word character upto the first
                                        # word character (removes leading punctuation, parenthesis, etc.)
                                        $term_match = preg_replace("#^[^\w]+#", "", $term_match);
                                        # full phrase
                                        $term_arr[] = $term_match;

                                        # DEBUG ONLY
                                        #echo "<pre>";
                                        #var_dump($term_arr);
                                        #echo "</pre>";
                                    } elseif ($full_summary === false) {
                                        # remove any leading non-word character upto the first
                                        # word character (removes leading punctuation, parenthesis, etc.)
                                        $term_match = preg_replace("#^[^\w]+#", "", $term_match);

                                        # only the first word preceding the strongs num
                                        $posbeg = strrpos($term_match, " ");
                                        $term_match = substr($term_match, $posbeg);
                                        $term_arr[] = trim($term_match);
                                    }
                                    #}
                                }
                            }
                        }
                    }

                    #echo "<P>Highlighting: \"$term_match\" and \"$strongs_num\"</P>";
                    # surround the terms captured + the strongs nums with the html tags to highlight them
                    # do not highlight matches that already are highlighted
                    $str = preg_replace("#([^}>]*?\{$strongs_num\} \{[GH]\d{1,4}\} \{[GH]\d{1,4}\}(?!<))|([^}>]*?\{[GH]\d{1,4}\} \{$strongs_num\} \{[GH]\d{1,4}\}(?!<))|([^}>]*?\{[GH]\d{1,4}\} \{[GH]\d{1,4}\} \{$strongs_num\}(?!<))|([^}>]*?\{$strongs_num\} \{[GH]\d{1,4}\}(?!<))|([^}>]*?\{[GH]\d{1,4}\} \{$strongs_num\}(?!<))|([^}>]*?\{$strongs_num\}(?!<))#$case_sensitive", "{$hl_beg}$1$2$3$4$5$6{$hl_end}", $str, -1, $replacements);
                    #echo PrintHTMLtags($str);
                    $total_replacement_count += $replacements;
                    #if ($replacements > 0) {
                    #    echo "<P>AFTER:<BR>" . PrintHTMLtags($str) . "</P>";
                    #}
                }
                return $str;
            }

            # Return the strongs num for a supplied word, or the closest one to the right
            # if the word supplied is part of a phrase
            function ExtractStrongsNumber($line, $search_for, $case_sensitive)
            {
                $break_next = false;

                # If a full data line was given, remove the book ch:ver portion
                if (strpos($line, "|") !== false) {
                    $line_arr = explode("|", $line);
                    $line = $line_arr[1];
                }

                $matches = [];

                if (preg_match_all("#\b$search_for\{[GH]\d{1,4}\}#$case_sensitive", $line, $matches, PREG_OFFSET_CAPTURE) > 0) {

                    foreach ($matches as $match) {
                        foreach ($match as $key) {
                            foreach ($key as $value) {
                                if ($break_next) {
                                    $skipterm = $value;
                                    break 3;
                                }
                                # break at the first strongs num encountered
                                # otherwise if there's more than one the last one
                                # overwrites the rest. chopping the line and checking
                                # for another strongs number is handled elsewhere
                                if (preg_match("#\{[GH]\d{1,4}\}#", $line)) {
                                    # break on the next go round to put the offset in skipterm
                                    $break_next = true;
                                    #echo "<P>SValue: \"$value\"</P>";
                                }
                            }
                        }
                    }

                    $startpos = strpos($line, "{", $skipterm);
                    $endpos = strpos($line, "}", $startpos);
                    $strongs = substr($line, $startpos + 1, ($endpos - $startpos) - 1);

                    if ($strongs == "") {
                        ExitWithException("ExtractStrongsNumber NULL ERROR 1");
                    }

                    return $strongs;
                } elseif (preg_match("#\b$search_for#$case_sensitive", $line)) {
                    # grab the nearest strongs num to the right of search_for
                    # if search_for is within a phrase and not next to it
                    $trimmed = substr($line, stripos($line, $search_for) + mb_strlen($search_for));
                    #echo "<P>Trimmed: \"$trimmed\"</P>";
                    $startpos = strpos($trimmed, "{");
                    $endpos = strpos($trimmed, "}", $startpos);
                    $strongs = substr($trimmed, $startpos + 1, ($endpos - $startpos) - 1);

                    if ($strongs == "") {
                        ExitWithException("ExtractStrongsNumber NULL ERROR 2");
                    }

                    return $strongs;
                } else {
                    ExitWithException("ExtractStrongsNumber NULL ERROR 3");
                }
            }

            # exit with a supplied error and provide the line that called it from the script
            function ExitWithException($message)
            {
                if (mb_strlen(trim($message)) > 3) {
                    echo "<P class=\"debug center\">$message</P>";
                    PrintCallingLine();
                    exit(1);
                } else {
                    echo "<P class=\"debug center\">PROCESS ABORTED</P>";
                    PrintCallingLine();
                    exit(1);
                }
            }

            # print the line of the script that called
            function PrintCallingLine()
            {
                $backtrace = debug_backtrace();
                print "<P class=\"debug center\">Called from line " . $backtrace[1]['line'] . "</P>";
            }

            # transform booknames to match those of the data file
            function TransformBookNames($string_to_check)
            {
                #echo "<P>BEFORE: $string_to_check</P>";
                $string_to_check = trim($string_to_check);
                $abbrev_booknames = array(
                    "genesis" => "Gen",
                    "exodus" => "Exo",
                    "exod" => "Exo",
                    "ex" => "Exo",
                    "leviticus" => "Lev",
                    "levit" => "Lev",
                    "levi" => "Lev",
                    "Numbers" => "num",
                    "Numb" => "num",
                    "deuteronomy" => "Deu",
                    "deuter" => "Deu",
                    "deut" => "Deu",
                    "joshua" => "Jos",
                    "josh" => "Jos",
                    "judges" => "Jdg",
                    "judg" => "Jdg",
                    "jgs" => "Jdg",
                    "ruth" => "Rth",
                    "rut" => "Rth",
                    "rt" => "Rth",
                    "1 samuel" => "1Sa",
                    "2 samuel" => "2Sa",
                    "1 sam" => "1Sa",
                    "2 sam" => "2Sa",
                    "1sam" => "1Sa",
                    "2sam" => "2Sa",
                    "1 kings" => "1Ki",
                    "2 kings" => "2Ki",
                    "1kings" => "1Ki",
                    "2kings" => "2Ki",
                    "1 kgs" => "1Ki",
                    "2 kgs" => "2Ki",
                    "1kgs" => "1Ki",
                    "2kgs" => "2Ki",
                    "1kg" => "1Ki",
                    "2kg" => "2Ki",
                    "1 chronicles" => "1Ch",
                    "2 chronicles" => "2Ch",
                    "1chronicles" => "1Ch",
                    "2chronicles" => "2Ch",
                    "1 chron" => "1Ch",
                    "2 chron" => "2Ch",
                    "1chron" => "1Ch",
                    "2chron" => "2Ch",
                    "1 chr" => "1Ch",
                    "2 chr" => "2Ch",
                    "1chr" => "1Ch",
                    "2chr" => "2Ch",
                    "ezra" => "Ezr",
                    "nehemiah" => "Neh",
                    "nehe" => "Neh",
                    "ne" => "Neh",
                    "esther" => "Est",
                    "esth" => "Est",
                    "es" => "Est",
                    "psalms" => "Psa",
                    "psalm" => "Psa",
                    "pss" => "Psa",
                    "ps" => "Psa",
                    "proverbs" => "Pro",
                    "proverb" => "Pro",
                    "prov" => "Pro",
                    "qoheleth" => "Ecc",
                    "Ecclesiastes" => "Ecc",
                    "Eccles" => "Ecc",
                    "qoh" => "Ecc",
                    "song of solomon" => "Son",
                    "canticles" => "Son",
                    "canticle" => "Son",
                    "song" => "Son",
                    "isaiah" => "Isa",
                    "esaias" => "Isa",
                    "jeremiah" => "Jer",
                    "jere" => "Jer",
                    "lamentations" => "Lam",
                    "lamen" => "Lam",
                    "la" => "Lam",
                    "ezekiel" => "Eze",
                    "ezek" => "Eze",
                    "ezk" => "Eze",
                    "daniel" => "Dan",
                    "da" => "Dan",
                    "hosea" => "Hos",
                    "joel" => "Joe",
                    "amos" => "Amo",
                    "am" => "Amo",
                    "obadiah" => "Oba",
                    "obad" => "Oba",
                    "jonah" => "Jon",
                    "jona" => "Jon",
                    "micah" => "Mic",
                    "mi" => "Mic",
                    "nahum" => "Nah",
                    "na" => "Nah",
                    "Habbakkuk" => "Hab",
                    "habak" => "Hab",
                    "habb" => "Hab",
                    "haba" => "Hab",
                    "hab" => "Hab",
                    "ha" => "Hab",
                    "Zephaniah" => "Zep",
                    "Zeph" => "Zep",
                    "ze" => "Zep",
                    "Haggai" => "Hag",
                    "Hagg" => "Hag",
                    "Zechariah" => "Zec",
                    "Zech" => "Zec",
                    "zec" => "Zec",
                    "Malachi" => "Mal",
                    "Mala" => "Mal",
                    "Matthew" => "Mat",
                    "matt" => "Mat",
                    "mt" => "Mat",
                    "mark" => "Mar",
                    "mk" => "Mar",
                    "luke" => "Luk",
                    "lu" => "Luk",
                    "lk" => "Luk",
                    "john" => "Joh",
                    "jhn" => "Joh",
                    "jno" => "Joh",
                    "jn" => "Joh",
                    "acts" => "Act",
                    "ac" => "Act",
                    "romans" => "Rom",
                    "roman" => "Rom",
                    "ro" => "Rom",
                    "1Corinthians" => "1Co",
                    "2corinthians" => "2Co",
                    "1 corinthians" => "1Co",
                    "2 corinthians" => "2Co",
                    "1 cor" => "1Co",
                    "2 cor" => "2Co",
                    "1cor" => "1Co",
                    "2cor" => "2Co",
                    "Galations" => "Gal",
                    "Gala" => "Gal",
                    "ga" => "Gal",
                    "ephesians" => "Eph",
                    "ep" => "Eph",
                    "Phil" => "Phi",
                    "Philippians" => "Phi",
                    "Colossians" => "Col",
                    "Colo" => "Col",
                    "Co" => "Col",
                    "1 Thessalonians" => "1Th",
                    "2 thessalonians" => "2Th",
                    "1Thessalonians" => "1Th",
                    "2thessalonians" => "2Th",
                    "1 Thess" => "1Th",
                    "2 thess" => "2Th",
                    "1Thess" => "1Th",
                    "2thess" => "2Th",
                    "1 thes" => "1Th",
                    "2 thes" => "2Th",
                    "1thes" => "1Th",
                    "2thes" => "2Th",
                    "1 ths" => "1Th",
                    "2 ths" => "2Th",
                    "1 th" => "1Th",
                    "2 th" => "2Th",
                    "1 timothy" => "1Ti",
                    "2 timothy" => "2Ti",
                    "1timothy" => "1Ti",
                    "2timothy" => "2Ti",
                    "1 tim" => "1Ti",
                    "2 tim" => "2Ti",
                    "1tim" => "1Ti",
                    "2tim" => "2Ti",
                    "1 ti" => "1Ti",
                    "2 ti" => "2Ti",
                    "titus" => "Tit",
                    "ti" => "Tit",
                    "Philemon" => "Phm",
                    "hebrews" => "Heb",
                    "hebrew" => "Heb",
                    "hebr" => "Heb",
                    "he" => "Heb",
                    "james" => "Jam",
                    "jms" => "Jam",
                    "jas" => "Jam",
                    "1 peter" => "1Pe",
                    "2 peter" => "2Pe",
                    "1peter" => "1Pe",
                    "2peter" => "2Pe",
                    "1 Pet" => "1Pe",
                    "2 Pet" => "2Pe",
                    "1Pet" => "1Pe",
                    "2Pet" => "2Pe",
                    "1 john" => "1Jo",
                    "2 john" => "2Jo",
                    "3 john" => "3Jo",
                    "1john" => "1Jo",
                    "2john" => "2Jo",
                    "3John" => "3Jo",
                    "1 joh" => "1Jo",
                    "2 joh" => "2Jo",
                    "3 joh" => "3Jo",
                    "1joh" => "1Jo",
                    "2joh" => "2Jo",
                    "3joh" => "3Jo",
                    "1jno" => "1Jo",
                    "2jno" => "2Jo",
                    "3jno" => "3Jo",
                    "1jn" => "1Jo",
                    "2jn" => "2Jo",
                    "3jn" => "3Jo",
                    "jude" => "Jud",
                    "jd" => "Jud",
                    "Revelation" => "Rev",
                    "Revel" => "Rev",
                    "re" => "Rev",
                );

                # Check if the search string begins with any of the supported abbreviated
                # book names and replace it if found
                foreach ($abbrev_booknames as $key => $value) {
                    #echo "<P>Checking \"$string_to_check\" against \"$key\"</P>";
                    if (substr_compare($string_to_check, $key, 0, strlen($key), true) == 0) {
                        #echo "<P>Transform_string_to_check: \"$string_to_check\"</P>";
                        $string_to_check = preg_replace("/\b$key\b/i", $value, $string_to_check, 1);
                        break;
                    }
                }

                # uniform capitalization
                $string_to_check = strtolower($string_to_check);
                $string_to_check = ucfirst($string_to_check);
                #echo "<P>AFTER: $string_to_check</P>";
                return $string_to_check;
            }

            # unset all set variables on script exit. requires registration via register_shutdown_function
            function UnsetVarsOnShutdown()
            {
                foreach (array_keys(get_defined_vars()) as $defined_var) {
                    unset(${$defined_var});
                }
                unset($defined_var);
            }
            ?>
        </FORM>
    </DIV>

    <!-- Return to top button anchor -->
    <A href="#" class="scrollbutton" id="scrollbuttonid">↑</a>

    <!-- Javacript enable the return-to-top button to appear only after scrolling down a little
    -->
    <SCRIPT>
        let upBtn = document.getElementById("scrollbuttonid");

        window.addEventListener("scroll", function () {
            if (document.body.scrollTop > 25 || document.documentElement.scrollTop > 25) {
                upBtn.style.display = "block";
            } else {
                upBtn.style.display = "none";
            }
        });
    </SCRIPT>

    <!-- The help button -->
    <BUTTON id="open-button" class="open-button" onclick="openHelp()">Help</BUTTON>
    <BUTTON id="close-button" class="close-button" onclick="closeHelp()" style="color: white;">
        <UL>
            <LI>Enter a word into the search and press enter or click the search button</LI>
            <UL>
                <LI>There is limited support for phrases so if you get no results try fewer words</LI>
            </UL>
            <LI>Every Strong's # associated with that word will be found</LI>
            <UL>
                <LI>Each occurance of those Strong's #'s will be displayed along with a summary</LI>
                <LI>Every word that precedes the matched Strong's # will be highlighted</LI>
            </UL>
            <LI>Supports searching for a single Strong's #</LI>
            <LI>You may also enter a specific "book chapter#:verse# word" to find all occurances of the Strong's #
                associated with the "word" entered (eg. luk 14:32 ambassage)</LI>
        </UL>
    </BUTTON>

    <!-- Javascript to "open" the help button when the button is clicked
    -->
    <SCRIPT>
        function openHelp() {
            document.getElementById("close-button").style.display = "block";
        }

        function closeHelp() {
            document.getElementById("close-button").style.display = "none";
        }
    </SCRIPT>
</BODY>

</HTML>