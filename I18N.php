<?php

/**
 * I18N – Internationalization with PHP.
 *
 * This class is a merger of two codes:
 * Zend/Translator/Adapter/GetText.php from Zend Framework and 
 * trunk/moeffju/php-msgfmt/msgfmt-functions.php from WordPress Summer of Code 2007
 * make sure that you have one directory like this:
 *  ./locale/<YOUR_LANGUAGE>/LC_MESSAGES/ to put your translations (*.mo, *.po files)
 * 
 * @package I18N
 * @author Ricardo Sismeiro <ricardo@sismeiro.com>
 * @see http://www.gnu.org/software/gettext/manual/gettext.html
 * @license http://www.gnu.org/licenses/gpl-3.0-standalone.html
 * 
 * @example I18N::instance('pt_PT'); I18N::translate('your string');
 */
class I18N
{

  private $_domain;
  private $_bigEndian;
  private $_defaultFunction;
  private $_cache;
  private static $_instance;

  public function __construct($lang='en_EN', $domain='default')
  {

    /* ./locale/en_EN/LC_MESSAGES/default.po */
    $fnMO = dirname(__FILE__) . '/locale/' . $lang . '/LC_MESSAGES/' . $domain . '.mo';
    if (!file_exists($fnMO)) {
      $fnPO = substr($fnMO, 0, -2) . 'po';
      if (file_exists($fnPO) && is_readable($fnPO)) {
        $this->_moConverter($fnPO);
      }
    }

    @putenv('LC_ALL=' . $lang);
    @setlocale(LC_ALL, $lang);

    if (function_exists('bindtextdomain')) {
      bindtextdomain($domain, dirname(__FILE__) . '/locale');
    }

    if (function_exists('bind_textdomain_codeset')) {
      bind_textdomain_codeset($domain, 'UTF-8');
    }

    if (function_exists('textdomain')) {
      textdomain($domain);
    }

    $this->_defaultFunction = false;
    $this->_cache = array();

    if (function_exists('gettext')) {
      $this->_defaultFunction = true;
    } else {
      $_tmp = $this->_moRead($fnMO, $lang);
      if (is_array($_tmp)) {
        $this->_cache = $_tmp[$lang];
      }
      unset($_tmp);
    }
  }

  public static function instance($lang='en_EN', $domain='default')
  {

    if (!isset(self::$_instance)) {
      $c = __CLASS__;
      self::$_instance = new $c($lang, $domain);
    }
    return self::$_instance;
  }

  /**
   * @ignore
   */
  private function _gettext($key)
  {
    $result = $key;
    if (isset($this->_cache[$key])) {
      $result = $this->_cache[$key];
    }
    return $result;
  }

  /**
   * @ignore
   */
  private function _poCleanHelper($x)
  {
    if (is_array($x)) {
      foreach ($x as $k => $v) {
        $x[$k] = $this->_poCleanHelper($v);
      }
    } else {
      if ($x[0] == '"')
        $x = substr($x, 1, -1);
      $x = str_replace("\"\n\"", '', $x);
      $x = str_replace('$', '\\$', $x);
      $x = @ eval("return \"$x\";");
    }
    return $x;
  }

  /**
   * Parse gettext .po files
   * @link http://www.gnu.org/software/gettext/manual/gettext.html#PO-Files
   *
   * @param string $filename
   * @return array
   * @access private
   */
  private function _poParser($filename)
  {

    if (!is_readable($filename)) {
      return false;
    }

    $fc = file_get_contents($filename);
    $fc = str_replace(array("\r\n", "\r"), "\n", $fc);
    $fc = explode("\n", $fc);

    $hash = array();
    $temp = array();

    $state = null;
    $fuzzy = false;

    foreach ($fc as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $_aline = explode(' ', $line, 2);

      $key = '';
      $data = '';

      if (isset($_aline[0])) {
        $key = $_aline[0];
      }
      if (isset($_aline[1])) {
        $data = $_aline[1];
      }

      switch ($key) {
        case '#,' : // flag...
          $fuzzy = in_array('fuzzy', preg_split('/,\s*/', $data));
        case '#' : // translator-comments
        case '#.' : // extracted-comments
        case '#:' : // reference...
        case '#|' : // msgid previous-untranslated-string
          // start a new entry
          if (sizeof($temp) && array_key_exists('msgid', $temp) && array_key_exists('msgstr', $temp)) {
            if (!$fuzzy)
              $hash[] = $temp;
            $temp = array();
            $state = null;
            $fuzzy = false;
          }
          break;
        case 'msgctxt' :
        // context
        case 'msgid' :
        // untranslated-string
        case 'msgid_plural' :
          // untranslated-string-plural
          $state = $key;
          $temp[$state] = $data;
          break;
        case 'msgstr' :
          // translated-string
          $state = 'msgstr';
          $temp[$state][] = $data;
          break;
        default :
          if (strpos($key, 'msgstr[') !== FALSE) {
            // translated-string-case-n
            $state = 'msgstr';
            $temp[$state][] = $data;
          } else {
            // continued lines
            switch ($state) {
              case 'msgctxt' :
              case 'msgid' :
              case 'msgid_plural' :
                $temp[$state] .= "\n" . $line;
                break;
              case 'msgstr' :
                $temp[$state][sizeof($temp[$state]) - 1] .= "\n" . $line;
                break;
              default :
                // parse error
                return false;
            }
          }
          break;
      }
    }

    // add final entry
    if ($state == 'msgstr') {
      $hash[] = $temp;
    }

    // Cleanup data, merge multiline entries, reindex hash for ksort
    $temp = $hash;
    $hash = array();
    foreach ($temp as $entry) {
      foreach ($entry as & $v) {
        $v = $this->_poCleanHelper($v);
        if ($v === false) {
          return false;
        }
      }
      $hash[$entry['msgid']] = $entry;
    }

    return $hash;
  }

  /**
   * Write a GNU gettext style machine object.
   * @link http://www.gnu.org/software/gettext/manual/gettext.html#MO-Files
   *
   * @param array $hash
   * @param string $out
   * @return bool
   * @access private
   */
  private function _moWrite($hash, $out)
  {
    // sort by msgid
    ksort($hash, SORT_STRING);

    // our mo file data
    $mo = '';

    // header data
    $offsets = array();

    $ids = '';
    $strings = '';

    foreach ($hash as $entry) {
      $id = $entry['msgid'];

      if (isset($entry['msgid_plural'])) {
        $id .= "\x00" . $entry['msgid_plural'];
      }

      // context is merged into id, separated by EOT (\x04)
      if (array_key_exists('msgctxt', $entry)) {
        $id = $entry['msgctxt'] . "\x04" . $id;
      }

      // plural msgstrs are NUL-separated
      $str = implode("\x00", $entry['msgstr']);

      // keep track of offsets
      $offsets[] = array(
          strlen($ids
          ), strlen($id), strlen($strings), strlen($str));

      // plural msgids are not stored (?)
      $ids .= $id . "\x00";

      $strings .= $str . "\x00";
    }

    // keys start after the header (7 words) + index tables ($#hash * 4 words)
    $key_start = 7 * 4 + sizeof($hash) * 4 * 4;

    // values start right after the keys
    $value_start = $key_start + strlen($ids);

    // first all key offsets, then all value offsets
    $key_offsets = array();
    $value_offsets = array();

    // calculate
    foreach ($offsets as $v) {
      list ($o1, $l1, $o2, $l2) = $v;
      $key_offsets[] = $l1;
      $key_offsets[] = $o1 + $key_start;
      $value_offsets[] = $l2;
      $value_offsets[] = $o2 + $value_start;
    }

    $offsets = array_merge($key_offsets, $value_offsets);

    // write header
    $mo .= pack('Iiiiiii', 0x950412de, // magic number
            0, // version
            sizeof($hash), // number of entries in the catalog
            7                         * 4, // key index offset
            7                         * 4 + sizeof($hash) * 8, // value index offset,
            0, // hashtable size (unused, thus 0)
            $key_start // hashtable offset
    );

    foreach ($offsets as $offset) {
      $mo .= pack('i', $offset);
    }

    $mo .= $ids;
    $mo .= $strings;

    $result = @file_put_contents($out, $mo);
    $result = (false === $result) ? false : true;
    return $result;
  }

  /**
   * MO file reader
   * @param string $filename
   * @param string $locale
   * @return array
   */
  private function _moRead($filename, $locale)
  {

    if (!is_readable($filename)) {
      return false;
    }

    $fs = stat($filename);

    if ($fs['size'] < 10) {
      return false;
    }

    $this->_bigEndian = false;
    $fp = @fopen($filename, 'rb');

    $input = $this->_moReadData($fp, 1);

    if (strtolower(substr(dechex($input[1]), -8)) == "950412de") {
      $this->_bigEndian = false;
    } elseif (strtolower(substr(dechex($input[1]), -8)) == "de120495") {
      $this->_bigEndian = true;
    } else {
      @fclose($fp);
      return false;
    }

    // read revision - not supported for now
    $input = $this->_moReadData($fp, 1);

    // number of bytes
    $input = $this->_moReadData($fp, 1);
    $total = $input[1];

    // number of original strings
    $input = $this->_moReadData($fp, 1);
    $OOffset = $input[1];

    // number of translation strings
    $input = $this->_moReadData($fp, 1);
    $TOffset = $input[1];

    // fill the original table
    fseek($fp, $OOffset);
    $origtemp = $this->_moReadData($fp, (2 * $total));
    fseek($fp, $TOffset);
    $transtemp = $this->_moReadData($fp, (2 * $total));

    for ($count = 0; $count < $total; ++$count) {
      if ($origtemp[$count * 2 + 1] != 0) {
        fseek($fp, $origtemp[$count * 2 + 2]);
        $original = @fread($fp, $origtemp[$count * 2 + 1]);
        $original = explode("\0", $original);
      } else {
        $original[0] = '';
      }

      if ($transtemp[$count * 2 + 1] != 0) {
        fseek($fp, $transtemp[$count * 2 + 2]);
        $translate = fread($fp, $transtemp[$count * 2 + 1]);
        $translate = explode("\0", $translate);
        if ((count($original) > 1) && (count($translate) > 1)) {
          $result[$locale][$original[0]] = $translate;
          array_shift($original);
          foreach ($original as $orig) {
            $result[$locale][$orig] = '';
          }
        } else {
          $result[$locale][$original[0]] = $translate[0];
        }
      }
    }

    fclose($fp);
    if (isset($result[$locale][''])) {
      unset($result[$locale]['']);
    }
    return $result;
  }

  /**
   * @ignore
   */
  private function _moReadData($fp, $bytes)
  {
    if ($this->_bigEndian === false) {
      return unpack('V' . $bytes, fread($fp, 4 * $bytes));
    } else {
      return unpack('N' . $bytes, fread($fp, 4 * $bytes));
    }
  }

  /**
   * Convert .po into .mo file
   *
   * @param string $pofilename
   * @param string $mofilename
   * @return bool
   *
   * @access private
   */
  private function _moConverter($pofilename, $mofilename = false)
  {
    $result = false;
    if (false === $mofilename) {
      $mofilename = preg_replace('/(.+?)(\.po)$/', '\1.mo', trim($pofilename));
    }
    $hash = $this->_poParser($pofilename);
    if (false !== $hash) {
      $result = $this->_moWrite($hash, $mofilename);
    }
    return $result;
  }

  public function _($key)
  {
    $result = '';

    if ($this->_defaultFunction) {
      $result = gettext($key);
    } else {
      $result = $this->_gettext($key);
    }

    return $result;
  }

  public static function translate($key)
  {
    $c = __CLASS__;
    return $c::instance()->_($key);
  }

}
