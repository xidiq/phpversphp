<?php
/**
* @package    jelix
* @subpackage utils
* @author     Laurent Jouanneau
* @copyright  2008-2013 Laurent Jouanneau
* @link       http://jelix.org
* @licence    http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public Licence, see LICENCE file
*/

/**
* utility class to modify an ini file by preserving comments, whitespace..
* It follows same behaviors of parse_ini_file, except when there are quotes
* inside values. it doesn't support quotes inside values, because parse_ini_file
* is totally bugged, depending cases.
* @package    jelix
* @subpackage utils
* @since 1.1
*/
class jIniFileModifier {

    /**
     * @const integer token type for whitespaces
     */
    const TK_WS = 0;
    /**
     * @const integer token type for a comment
     */
    const TK_COMMENT = 1;
    /**
     * @const integer token type for a section header
     */
    const TK_SECTION = 2;
    /**
     * @const integer token type for a simple value
     */
    const TK_VALUE = 3;
    /**
     * @const integer token type for a value of an array item 
     */
    const TK_ARR_VALUE = 4;

    /**
     * each item of this array contains data for a section. the key of the item
     * is the section name. There is a section with the key "0", and which contains
     * data for options which are not in a section.
     * each value of the items is an array of tokens. A token is an array with
     * some values. first value is the token type (see TK_* constants), and other
     * values depends of the token type:
     * - TK_WS: content of whitespaces
     * - TK_COMMENT: the comment
     * - TK_SECTION: the section name
     * - TK_VALUE: the name, and the value
     * - TK_ARRAY_VALUE: the name, the value, and the key
     * @var array
     */
    protected $content = array();

    /**
     * @var string the filename of the ini file
     */
    protected $filename = '';
    
    /**
     * @var boolean true if the content has been modified
     */
    protected $modified = false;

    /**
     * load the given ini file
     * @param string $filename the file to load
     * @throws Exception
     */
    function __construct($filename) {
        if(!file_exists($filename) || !is_file($filename))
            // because the class is used also by installers, we don't have any
            // modules in this case, so impossible to use jException
            throw new Exception ('(23)The file '.$filename.' doesn\'t exist' );
        $this->filename = $filename;
        $this->parse(preg_split("/(\r\n|\n|\r)/", file_get_contents($filename)));
    }
    
    /**
     * @return string the file name
     * @since 1.2
     */
    function getFileName() {
        return $this->filename;
    }

    /**
     * parsed the lines of the ini file
     */
    protected function parse($lines) {
        $this->content = array(0=>array());
        $currentSection=0;
        $multiline = false;
        $currentValue= null;
        
        $arrayContents = array();
        
        foreach ($lines as $num => $line) {
            if($multiline) {
                if(preg_match('/^(.*)"\s*$/', $line, $m)) {
                    $currentValue[2].=$m[1];
                    $multiline=false;
                    $this->content[$currentSection][]=$currentValue;
                } else {
                    $currentValue[2].=$line."\n";
                }
            } elseif (preg_match('/^\s*([\\w0-9_.\\-]+)(\\[[^\\[\\]]*\\])?\s*=\s*(")?([^"]*)(")?(\s*)/ui', $line, $m)) {
                list($all, $name, $foundkey, $firstquote, $value ,$secondquote, $lastspace) = $m;

                if ($foundkey !='') {
                    $key = substr($foundkey, 1, -1);
                    if ($key == '') {
                        if (isset($arrayContents[$currentSection][$name])) {
                            $key = count(
                                $arrayContents[$currentSection][$name]
                            );
                        } else {
                            $key = 0;
                        }
                    }
                    $currentValue = array(self::TK_ARR_VALUE, $name, $value, $key);
                    $arrayContents[$currentSection][$name][$key] = $value;
                }
                else
                    $currentValue = array(self::TK_VALUE, $name, $value);

                if($firstquote == '"' && $secondquote == '') {
                    $multiline = true;
                    $currentValue[2].="\n";
                } else {
                    if($firstquote == '' && $secondquote == '')
                        $currentValue[2] = trim($value);
                    $this->content[$currentSection][]=$currentValue;
                }

            } elseif (preg_match('/^(\\s*;.*)$/', $line, $m)) {
                $this->content[$currentSection][]=array(self::TK_COMMENT, $m[1]);

            } elseif (preg_match('/^(\\s*\\[([^\\]]+)\\]\\s*)/ui', $line, $m)) {
                if (strpos($m[2], ';')) {
                    // ';' is forbidden in the name as it begins a comment
                    throw new Exception("Invalid syntax for the section name: \"".$m[2].'"');
                }
                $currentSection = $m[2];
                $this->content[$currentSection]=array(
                    array(self::TK_SECTION, $m[1]),
                );

            } else  {
                $this->content[$currentSection][]=array(self::TK_WS, $line);
            }
        }
    }

    /**
     * modify an option in the ini file. If the option doesn't exist,
     * it is created.
     * @param string $name    the name of the option to modify
     * @param string|array $value   the new value
     * @param string $section the section where to set the item. 0 is the global section
     * @param integer $key     for option which is an item of array, the key in the array. '' to just add a value in the array
     */
    public function setValue($name, $value, $section=0, $key=null)
    {
        if (!preg_match('/^[^\\[\\]]*$/', $name)) {
            throw new \Exception("Invalid value name $name");
        }

        if (is_array($value)) {
            if ($key !== null) {
                throw new \Exception("You cannot indicate a key for an array value");
            }
            $this->_setArrayValue($name, $value, $section);
        }
        else {
            $this->_setValue($name, $value, $section, $key);
        }
    }

    protected function _setValue($name, $value, $section = 0, $key = null) {
        if (is_string($key) && !preg_match('/^[^\\[\\]]*$/', $key)) {
            throw new \Exception("Invalid key $key for the value $name");
        }
        $foundValue=false;
        $lastKey = -1; // last key in an array value
        if (isset($this->content[$section])) {
            // boolean to erase array values if the new value is not a new item for the array
            $deleteMode = false;
            foreach ($this->content[$section] as $k =>$item) {
                if ($deleteMode) {
                    if ($item[0] == self::TK_ARR_VALUE && $item[1] == $name) {
                        $this->content[$section][$k] = array(self::TK_WS, '--');
                        $this->modified              = true;
                    }
                    continue;
                }
                
                // if the item is not a value or an array value, or not the same name
                if (($item[0] != self::TK_VALUE && $item[0] != self::TK_ARR_VALUE)
                    || $item[1] != $name)
                    continue;
                // if it is an array value, and if the key doesn't correspond
                if ($item[0] == self::TK_ARR_VALUE && $key !== null) {
                    if ($item[3] !== $key || $key === '') {
                        if (is_numeric($item[3])) {
                            $lastKey = $item[3];
                        }
                        continue;
                    }
                }

                // we are here because we found an item with the same name

                if ($key !== null) {
                    // we add the value as an array value
                    if ($key === '') {
                        $key = 0;
                    }
                    if ($item[0] == self::TK_VALUE || !$this->_compareNewValue($item[2], $value)) {
                        $this->content[$section][$k] = array(self::TK_ARR_VALUE, $name, $value, $key);
                        $this->modified = true;
                    }
                } else {
                    // we store the value
                    if (!$this->_compareNewValue($item[2], $value)) {
                        $this->content[$section][$k] = array(self::TK_VALUE, $name, $value);
                        $this->modified = true;
                    }
                    $this->content[$section][$k] = array(self::TK_VALUE,$name,$value);
                    if ($item[0] == self::TK_ARR_VALUE) {
                        // the previous value was an array value, so we erase other array values
                        $deleteMode = true;
                        $foundValue = true;
                        continue;
                    }
                }
                $foundValue=true;
                break;
            }
        }
        else {
            $this->content[$section] = array(array(self::TK_SECTION, '['.$section.']'));
        }
        if (!$foundValue) {
            if($key === null) {
                $this->content[$section][]= array(self::TK_VALUE, $name, $value);
            } else {
                if ($key === '') {
                    if ($lastKey != -1) {
                        $key = ++$lastKey;
                    } else {
                        $key = 0;
                    }
                 }
                $this->content[$section][]= array(self::TK_ARR_VALUE, $name, $value, $key);
            }
            $this->modified = true;
        }

    }

    protected function _compareNewValue($iniValue, $newValue) {
        $iniVal = $this->convertValue($iniValue);
        $newVal = $this->convertValue($newValue);
        return ($iniVal == $newVal);
    }

    protected function _setArrayValue($name, $value, $section = 0) {

        $foundKeys = array_combine(array_keys($value),
                                   array_fill(0, count($value), false));
        if (isset($this->content[$section])) {
            foreach ($this->content[$section] as $k => $item) {
                // if the item is not a value or an array value, or not the same name
                if (($item[0] != self::TK_VALUE && $item[0] != self::TK_ARR_VALUE)
                    || $item[1] != $name) {
                    continue;
                }

                if ($item[0] == self::TK_ARR_VALUE) {
                    if (isset($value[$item[3]])) {
                        $foundKeys[$item[3]] = true;
                        $this->content[$section][$k][2] = $value[$item[3]];
                    }
                    else {
                        $this->content[$section][$k] = array(self::TK_WS, '--');
                    }
                }
                else {
                    $this->content[$section][$k] = array(self::TK_WS, '--');
                }
            }
        } else {
            $this->content[$section] = array(array(self::TK_SECTION, '['.$section.']'));
        }

        foreach($value as $k => $v) {
            if (!$foundKeys[$k]) {
                $this->content[$section][] = array(self::TK_ARR_VALUE, $name, $v, $k);
            }
        }
        $this->modified = true;
    }

    /**
     * modify several options in the ini file.
     * @param array $value   associated array with key=>value
     * @param string $section the section where to set the item. 0 is the global section
     */
    public function setValues($values, $section=0) {
        foreach($values as $name=>$val) {
            $this->setValue($name, $val, $section);
        }
    }

    /**
     * remove an option from the ini file. It can remove an entire section if you give
     * an empty value as $name, and a $section name
     * @param string $name    the name of the option to remove, or null to remove an entire section
     * @param string $section the section where to remove the value, or the section to remove
     * @param integer $key     for option which is an item of array, the key in the array
     * @since 1.2
     */
    public function removeValue($name, $section=0, $key=null, $removePreviousComment = true) {

        if ($section === 0 && $name == '')
            return;

        if ($name == '') {
            $this->removeSection($section, $removePreviousComment);
            return;
        }
        
        if (isset($this->content[$section])) {
            // boolean to erase array values if the option to remove is an array
            $deleteMode = false;
            $previousComment = array();
            foreach ($this->content[$section] as $k =>$item) {
                if ($deleteMode) {
                    if ($item[0] == self::TK_ARR_VALUE && $item[1] == $name)
                        $this->content[$section][$k] = array(self::TK_WS, '--');
                    continue;
                }
                
                if ($item[0] == self::TK_COMMENT) {
                    if ($removePreviousComment)
                        $previousComment[] = $k;
                    continue;
                }

                if ($item[0] == self::TK_WS) {
                    if ($removePreviousComment)
                        $previousComment[] = $k;
                    continue;
                }

                // if the item is not a value or an array value, or not the same name
                if ($item[1] != $name) {
                    $previousComment = array();
                    continue;
                }

                // if it is an array value, and if the key doesn't correspond
                if ($item[0] == self::TK_ARR_VALUE && $key !== null) {
                    if($item[3] != $key) {
                        $previousComment = array();
                        continue;
                    }
                }
                $this->modified = true;
                if (count($previousComment)) {
                    $kc = array_pop($previousComment);
                    while ($kc !== null && $this->content[$section][$kc][0] == self::TK_WS) {
                        $kc = array_pop($previousComment);
                    }

                    while ($kc !== null && $this->content[$section][$kc][0] == self::TK_COMMENT) {
                        if(strpos($this->content[$section][$kc][1], "<?") === false) {
                            $this->content[$section][$kc] = array(self::TK_WS, '--');
                        }
                        $kc = array_pop($previousComment);
                    }

                }
                if ($key !== null) {
                    // we remove the value from the array
                    $this->content[$section][$k] = array(self::TK_WS, '--');
                } else {
                    // we remove the value
                    $this->content[$section][$k] = array(self::TK_WS, '--');
                    if ($item[0] == self::TK_ARR_VALUE) {
                        // the previous value was an array value, so we erase other array values
                        $deleteMode = true;
                        continue;
                    }
                }
                break;
            }
        }
    }

    /**
     * remove a section from the ini file.
     *
     * @param string $section the section where to remove the value, or the section to remove
     *
     * @since 2.4.3
     */
    public function removeSection($section = 0, $removePreviousComment = true)
    {
        if ($section === 0 || !isset($this->content[$section])) {
            return;
        }

        if ($removePreviousComment) {
            // retrieve the previous section
            $previousSection = -1;
            foreach ($this->content as $s => $c) {
                if ($s === $section) {
                    break;
                } else {
                    $previousSection = $s;
                }
            }

            if ($previousSection != -1) {
                //retrieve the last comment
                $s = $this->content[$previousSection];
                end($s);
                $tok = current($s);
                while ($tok !== false) {
                    if ($tok[0] != self::TK_WS && $tok[0] != self::TK_COMMENT) {
                        break;
                    }
                    if ($tok[0] == self::TK_COMMENT && strpos($tok[1], '<?') === false) {
                        $this->content[$previousSection][key($s)] = array(self::TK_WS, '--');
                    }
                    $tok = prev($s);
                }
            }
        }

        unset($this->content[$section]);
        $this->modified = true;
    }

    /**
     * return the value of an option in the ini file. If the option doesn't exist,
     * it returns null.
     * @param string $name    the name of the option to retrieve
     * @param string $section the section where the option is. 0 is the global section
     * @param integer $key     for option which is an item of array, the key in the array
     * @return mixed the value
     */
    public function getValue($name, $section=0, $key=null) {
        if(!isset($this->content[$section])) {
            return null;
        }
        $arrayValue = array();
        $isArray = false;
        foreach ($this->content[$section] as $k =>$item) {
            if (($item[0] != self::TK_VALUE && $item[0] != self::TK_ARR_VALUE)
                || $item[1] != $name)
                continue;
            if ($item[0] == self::TK_ARR_VALUE) {
                if ($key !== null) {
                    if($item[3] != $key)
                        continue;
                }
                else {
                    $isArray = true;
                    $arrayValue[$item[3]] = $this->convertValue($item[2]);
                    continue;
                }
            }

            return $this->convertValue($item[2]);
        }
        if ($isArray)
            return $arrayValue;
        return null;
    }

    /**
     * return all values of a section in the ini file. 
     * @param string $section the section from wich we want values. 0 is the global section
     * @return array the list of values, $key=>$value
     */
    public function getValues($section=0) {
        if(!isset($this->content[$section])) {
            return array();
        }
        $values = array();
        foreach ($this->content[$section] as $k =>$item) {
            if ($item[0] != self::TK_VALUE && $item[0] != self::TK_ARR_VALUE)
                continue;

            $val = $this->convertValue($item[2]);
            
            if ($item[0] == self::TK_VALUE) {
                $values[$item[1]] = $val;
            }
            else {
                $values[$item[1]][$item[3]] = $val;
            }
        }
        return $values;
    }

    protected function convertValue($value)
    {
        if (!is_string($value)) {
            // values that are set after the parsing, may be PHP raw values...
            return $value;
        }
        if (preg_match('/^-?[0-9]$/', $value)) {
            return intval($value);
        } elseif (preg_match('/^-?[0-9\.]$/', $value)) {
            return floatval($value);
        } elseif (strtolower($value) === 'true' || strtolower($value) === 'on' || strtolower($value) === 'yes') {
            return true;
        } elseif (strtolower($value) === 'false' || strtolower($value) === 'off' || strtolower($value) === 'no' || strtolower($value) === 'none') {
            return false;
        }
        return $value;
    }

    /**
     * save the ini file
     */
    public function save($chmod = null) {
        if ($this->modified) {
            if (false === @file_put_contents($this->filename, $this->generateIni())) {
                throw new Exception("Impossible to write into ".$this->filename);
            }
            else if($chmod) {
                chmod($this->filename, $chmod);
            }
            $this->modified = false;
        }
    }

    /**
     * save the content in an new ini file
     * @param string $filename the name of the file
     */
    public function saveAs($filename) {
        file_put_contents($filename, $this->generateIni());
    }

    /**
     * says if the ini content has been modified
     * @return boolean
     * @since 1.2
     */
    public function isModified() {
        return $this->modified;
    }

    /**
     * says if there is a section with the given name
     * @since 1.2
     */
    public function isSection($name) {
        return isset($this->content[$name]);
    }

    /**
     * return the list of section names
     * @return array
     * @since 1.2
     */
    public function getSectionList() {
        $list = array_keys($this->content);
        array_shift($list); // remove the global section
        return $list;
    }

    protected function generateIni()
    {
        $content = '';
        $lastToken = null;
        foreach($this->content as $sectionname=>$section) {
            foreach($section as $item) {
                $lastToken = $item[0];
                switch($item[0]) {
                  case self::TK_SECTION:
                    if($item[1] != '0')
                        $content.=$item[1]."\n";
                    break;
                  case self::TK_WS:
                    if ($item[1]=='--')
                        break;
                  case self::TK_COMMENT:
                    $content.=$item[1]."\n";
                    break;
                  case self::TK_VALUE:
                        $content.=$item[1].'='.$this->getIniValue($item[2])."\n";
                    break;
                  case self::TK_ARR_VALUE:
                      if (is_numeric($item[3])) {
                          $content .= $item[1].'[]='.$this->getIniValue($item[2])."\n";
                      }
                      else {
                          $content .= $item[1].'['.$item[3].']='.$this->getIniValue($item[2])."\n";
                      }
                    break;
                }
            }
        }
        if ($lastToken === self::TK_WS) {
            // remove the last \n
            $content = substr($content, 0, -1);
        }
        return $content;
    }

    protected function getIniValue($value) {
        if (is_bool($value)) {
            if ($value === false) {
                return "off";
            } else {
                return "on";
            }
        }
        if ($value === '' ||
            is_numeric(trim($value)) ||
            (is_string($value) && preg_match('/^[\\w\\-\\.]*$/u', $value) &&
                strpos("\n", $value) === false)
        ) {
            return $value;
        }else {
            $value='"'.$value.'"';
        }
        return $value;
    }

    /**
     * import values of an ini file into the current ini content.
     * If a section prefix is given, all section of the given ini file will be
     * renamed with the prefix plus "_". The global (unamed) section will be the section
     * named with the value of prefix. If the section prefix is not given, the existing
     * sections and given section with the same name will be merged.
     * @param jIniFileModifier $ini  an ini file modifier to merge with the current
     * @param string $sectionPrefix the prefix to add to the section prefix
     * @param string $separator the separator to add between the prefix and the old name
     *                         of the section
     * @since 1.2
     */
    public function import(jIniFileModifier $ini, $sectionPrefix = '', $separator = '_') {
        foreach($ini->content as $section=>$values) {
            if ($sectionPrefix) {
                if ($section == "0") {
                    $realSection = $sectionPrefix;
                }
                else {
                    $realSection = $sectionPrefix.$separator.$section;
                }
            }
            else $realSection = $section;

            if (isset($this->content[$realSection])) {
                // let's merge the current and the given section
                $this->mergeValues($values, $realSection);
            }
            else {
                if ($values[0][0] == self::TK_SECTION)
                    $values[0][1] = '['.$realSection.']';
                else {
                    array_unshift($values, array(self::TK_SECTION, '['.$realSection.']'));
                }
                $this->content[$realSection] = $values;
                $this->modified = true;
            }
        }
    }

    /**
     * move values of a section into an other section and remove the section
     * @return boolean  true if the merge is a success
     */
    public function mergeSection($sectionSource, $sectionTarget) {
        if (!isset($this->content[$sectionTarget]))
            return $this->renameSection($sectionSource, $sectionTarget);

        if (!isset($this->content[$sectionSource]))
            return false;
        $this->mergeValues($this->content[$sectionSource], $sectionTarget);
        if ($sectionSource == "0")
            $this->content[$sectionSource] = array();
        else
            unset($this->content[$sectionSource]);
        $this->modified = true;
        return true;
    }

    protected function mergeValues($values, $sectionTarget)
    {
        $previousItems = array();
        $arrayValuesToReplace = array();
        // if options already exists, just change their values.
        // if options don't exist, add them to the section, with
        // comments and whitespace
        foreach ($values as $k=>$item) {
            switch($item[0]) {
                case self::TK_SECTION:
                  break;
                case self::TK_WS:
                  if ($item[1]=='--')
                      break;
                case self::TK_COMMENT:
                  $previousItems [] = $item;
                  break;
                case self::TK_VALUE:
                case self::TK_ARR_VALUE:
                    $found = false;
                    $lastNonValues = -1;
                    foreach ($this->content[$sectionTarget] as $j =>$item2) {
                        if ($item2[0] != self::TK_VALUE && $item2[0] != self::TK_ARR_VALUE) {
                            if ($lastNonValues == -1 && $item2[0] != self::TK_SECTION)
                                $lastNonValues = $j;
                            continue;
                        }
                        if ($item2[1] != $item[1]) {
                            $lastNonValues = -1;
                            continue;
                        }
                        if ($item[0] == self::TK_ARR_VALUE && $item2[0] == $item[0]) {
                            if ($item[3] !== $item2[3]) {
                                $lastNonValues = -1;
                                continue;
                            }
                        }

                        $found = true;
                        $this->modified = true;
                        if ($item2[0] != $item[0]) {
                            // same name, but not the same type
                            if ($item2[0] == self::TK_VALUE) {
                                $this->content[$sectionTarget][$j] = $item;
                            }
                            else {
                                $arrayValuesToReplace[$item[1]] = $item[2];
                            }
                            continue;
                        }
                        $this->content[$sectionTarget][$j][2] = $item[2];
                        break;
                    }
                    if (!$found) {
                        $previousItems[] = $item;
                        if ($lastNonValues > 0) {
                            $previousItems = array_splice($this->content[$sectionTarget], $lastNonValues, $j, $previousItems);

                        }
                        $this->content[$sectionTarget] = array_merge($this->content[$sectionTarget], $previousItems);
                        $this->modified = true;
                    }
                    $previousItems = array();
                    break;
            }
        }
        foreach ($arrayValuesToReplace as $name => $value) {
            $this->setValue($name, $value, $sectionTarget);
        }
    }


    /**
     * rename a value
     * 
     */
    public function renameValue($name, $newName, $section=0) {
        if (!isset($this->content[$section]))
            return false;
        foreach ($this->content[$section] as $k =>$item) {
            if ($item[0] != self::TK_VALUE && $item[0] != self::TK_ARR_VALUE) {
                continue;
            }
            if ($item[1] != $name) {
                continue;
            }
            $this->content[$section][$k][1] = $newName;
            $this->modified = true;
            if ($item[0] == self::TK_VALUE) {
                break;
            }
        }
        return true;
    }

    /**
     * rename a section
     */
    public function renameSection($oldName, $newName) {
        if (!isset($this->content[$oldName]))
            return false;

        if (isset($this->content[$newName])) {
            return $this->mergeSection($oldName, $newName);
        }

        $newcontent = array();
        foreach($this->content as $section=>$values) {
            if ((string)$oldName == (string)$section) {
                if ($section == "0") {
                    $newcontent[0] = array();
                }
                if ($values[0][0] == self::TK_SECTION)
                    $values[0][1] = '['.$newName.']';
                else {
                    array_unshift($values, array(self::TK_SECTION, '['.$newName.']'));
                }
                $newcontent[$newName] = $values;
            }
            else
                $newcontent [$section] = $values;
        }
        $this->content = $newcontent;
        $this->modified = true;
        return true;
    }
}

