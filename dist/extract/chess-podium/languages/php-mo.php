<?php
/**
 * php.mo - Converts gettext .po files to binary .mo files in PHP.
 * By Joss Crowcroft - https://github.com/josscrowcroft/php.mo
 * License: GPL v3
 */
function phpmo_convert($input, $output = false) {
    if (!$output)
        $output = str_replace('.po', '.mo', $input);
    $hash = phpmo_parse_po_file($input);
    if ($hash === false) {
        return false;
    }
    phpmo_write_mo_file($hash, $output);
    return true;
}

function phpmo_clean_helper($x) {
    if (is_array($x)) {
        foreach ($x as $k => $v) {
            $x[$k] = phpmo_clean_helper($v);
        }
    } else {
        if ($x[0] == '"')
            $x = substr($x, 1, -1);
        $x = str_replace("\"\n\"", '', $x);
        $x = str_replace('$', '\\$', $x);
    }
    return $x;
}

function phpmo_parse_po_file($in) {
    $fh = fopen($in, 'r');
    if ($fh === false) return false;
    $hash = array();
    $temp = array();
    $state = null;
    $fuzzy = false;
    while (($line = fgets($fh, 65536)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = preg_split('/\s/', $line, 2);
        $key = $parts[0] ?? '';
        $data = $parts[1] ?? '';
        switch ($key) {
            case '#,':
                $fuzzy = in_array('fuzzy', preg_split('/,\s*/', $data));
            case '#':
            case '#.':
            case '#:':
            case '#|':
                if (sizeof($temp) && array_key_exists('msgid', $temp) && array_key_exists('msgstr', $temp)) {
                    if (!$fuzzy) $hash[] = $temp;
                    $temp = array();
                    $state = null;
                    $fuzzy = false;
                }
                break;
            case 'msgctxt':
            case 'msgid':
            case 'msgid_plural':
                $state = $key;
                $temp[$state] = $data;
                break;
            case 'msgstr':
                $state = 'msgstr';
                $temp[$state] = array($data);
                break;
            default:
                if (strpos($key, 'msgstr[') !== false) {
                    $state = 'msgstr';
                    $temp[$state][] = $data;
                } else {
                    switch ($state) {
                        case 'msgctxt':
                        case 'msgid':
                        case 'msgid_plural':
                            $temp[$state] .= "\n" . $line;
                            break;
                        case 'msgstr':
                            $temp[$state][sizeof($temp[$state]) - 1] .= "\n" . $line;
                            break;
                        default:
                            fclose($fh);
                            return false;
                    }
                }
                break;
        }
    }
    fclose($fh);
    if ($state == 'msgstr' && !empty($temp)) $hash[] = $temp;
    $out = array();
    foreach ($hash as $entry) {
        if (empty($entry['msgid']) && empty($entry['msgstr'])) continue;
        foreach ($entry as &$v) {
            $v = phpmo_clean_helper($v);
            if ($v === false) return false;
        }
        $out[$entry['msgid']] = $entry;
    }
    return $out;
}

function phpmo_write_mo_file($hash, $out) {
    ksort($hash, SORT_STRING);
    $ids = '';
    $strings = '';
    $offsets = array();
    foreach ($hash as $entry) {
        $id = $entry['msgid'];
        if (isset($entry['msgid_plural'])) $id .= "\x00" . $entry['msgid_plural'];
        if (array_key_exists('msgctxt', $entry)) $id = $entry['msgctxt'] . "\x04" . $id;
        $str = is_array($entry['msgstr']) ? implode("\x00", $entry['msgstr']) : $entry['msgstr'];
        $offsets[] = array(strlen($ids), strlen($id), strlen($strings), strlen($str));
        $ids .= $id . "\x00";
        $strings .= $str . "\x00";
    }
    $key_start = 7 * 4 + sizeof($hash) * 4 * 4;
    $value_start = $key_start + strlen($ids);
    $key_offsets = array();
    $value_offsets = array();
    foreach ($offsets as $v) {
        list($o1, $l1, $o2, $l2) = $v;
        $key_offsets[] = $l1;
        $key_offsets[] = $o1 + $key_start;
        $value_offsets[] = $l2;
        $value_offsets[] = $o2 + $value_start;
    }
    $offsets = array_merge($key_offsets, $value_offsets);
    $mo = pack('Iiiiiii', 0x950412de, 0, sizeof($hash), 7 * 4, 7 * 4 + sizeof($hash) * 8, 0, $key_start);
    foreach ($offsets as $offset) $mo .= pack('i', $offset);
    $mo .= $ids . $strings;
    return file_put_contents($out, $mo) !== false;
}
