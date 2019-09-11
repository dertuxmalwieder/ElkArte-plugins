<?php

/**
 * @name      OEmbed Support
 * @copyright Cthulhux
 * @license   WTFPL http://www.wtfpl.net/txt/copying/
 * @version   1.0.6
 *
 */


function get_all_urls($string)
{
    // Returns all URLs in <$string>.
    $regex = '/(?<!(=|\]))https?\:\/\/[^\s\]\["<>]+\b/im';
    preg_match_all($regex, $string, $matches);
    return $matches[0];
}

function download_from_url($url)
{
    // Downloads a website and returns its source code.
    $handle = curl_init();

    curl_setopt($handle, CURLOPT_URL, str_replace("&amp;", "&", $url));
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($handle, CURLOPT_HEADER, 0);

    $ret = curl_exec($handle);

    curl_close($handle);

    return $ret;
}

function oembed_pre_parse(&$message)
{
    // This is the hook. Yay!
    global $user_info, $txt, $scripturl;

    if (!extension_loaded("curl")) {
        // Admins don't read READMEs. :'(
        return;
    }

    $curl = curl_multi_init();
    $arr_curl_hnd = array();

    // 1.0.4:
    // Some OEmbed sources return IFRAMEs. ElkArte purges those when checking
    // for a message's emptiness. We'll need to add a bogus character to the
    // end of a processed message or else ElkArte will break OEmbed. Well...
    $add_dummy_character = false;

    foreach (get_all_urls($message) as $url) {
        if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
            // Yup, this is a URL. Get it:
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25); // 1.0.6: Don't wait eternally.
            curl_setopt($ch, CURLOPT_HEADER, 0);

            // Add this cURL result to the array of handles:
            $arr_curl_hnd[$url] = $ch;
            curl_multi_add_handle($curl, $ch);
        }
    }

    $active = NULL;

    // Execute all handles asynchronously:
    do {
        $mrc = curl_multi_exec($curl, $active);
    }
    while ($active > 0);

    $re_oembed_json = "/<link.*?type=\"application\/json\+oembed\".*?>/i";
    $re_oembed_xml = "/<link.*?type=\"text\/xml\+oembed\".*?>/i";
    $re_url = '/https?\:\/\/[^\s"<>]+/i';

    // Iterate through the results:
    foreach ($arr_curl_hnd as $url=>$ch) {
        $html = curl_multi_getcontent($ch);

        // Now we probably have shiny HTML.
        // Check if there's oEmbed in it and replace where applicable:
        if (preg_match($re_oembed_json, $html, $matches)) {
            // $matches[0] is a JSON link tag now.
            if (preg_match($re_url, $matches[0], $json_urls)) {
                if (filter_var($json_urls[0], FILTER_VALIDATE_URL) == false) {
                    // Invalid OEmbed URL.
                    curl_multi_remove_handle($curl, $ch);
                    continue;
                }

                // Download and parse the OEmbed JSON:
                $returned_json = download_from_url($json_urls[0]);
                $decoded_json = json_decode($returned_json, true);

                if ($decoded_json == NULL) {
                    // Wrong JSON.
                    curl_multi_remove_handle($curl, $ch);
                    continue;
                }

                if (!array_key_exists("html", $decoded_json)) {
                    // Wrong OEmbed JSON.
                    curl_multi_remove_handle($curl, $ch);
                    continue;
                }

                // Do what needs to be done:
                $message = str_replace($url, $decoded_json["html"], $message);
                $add_dummy_character = true;
            }
        }
        if (extension_loaded("xml")) {
            // 1.0.5:
            // If the XML extension is not available, nothing will happen here.
            // JSON is always supported though.
            if (preg_match($re_oembed_xml, $html, $matches)) {
                // $matches[0] is an XML link tag now.
                if (preg_match($re_url, $matches[0], $xml_urls)) {
                    if (filter_var($xml_urls[0], FILTER_VALIDATE_URL) == false) {
                        // Invalid OEmbed URL.
                        curl_multi_remove_handle($curl, $ch);
                        continue;
                    }

                    // Download and parse the OEmbed XML:
                    $returned_xml = download_from_url($xml_urls[0]);

                    $parser = xml_parser_create();
                    $valid_xml = xml_parse_into_struct($parser, $returned_xml, $vals, $index);
                    xml_parser_free($parser);

                    if (!$valid_xml) {
                        // Nah.
                        curl_multi_remove_handle($curl, $ch);
                        continue;
                    }

                    if (!(array_key_exists("oembed", $vals) && array_key_exists("html", $vals["oembed"]))) {
                        // Invalid OEmbed XML
                        curl_multi_remove_handle($curl, $ch);
                        continue;
                    }

                    // Do what needs to be done:
                    $message = str_replace($url, $vals["oembed"]["html"], $message);
                    $add_dummy_character = true;
                }
            }
        }

        curl_multi_remove_handle($curl, $ch);
    }

    // Clean up:
    curl_multi_close($curl);

    // Add a dummy character when required:
    if ($add_dummy_character) {
        $message .= "&#8291;"; // This should work ... for now.
    }
}
