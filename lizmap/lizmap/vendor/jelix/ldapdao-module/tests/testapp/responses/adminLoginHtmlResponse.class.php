<?php
/**
* @package   testapp
* @subpackage 
* @author    your name
* @copyright 2021 Laurent Jouanneau
* @link      http://jelix.org
* @license    All rights reserved
*/


require_once (JELIX_LIB_CORE_PATH.'response/jResponseHtml.class.php');

class adminLoginHtmlResponse extends jResponseHtml {

    function __construct() {
        parent::__construct();
        // Include your common CSS and JS files here
        $this->addCSSLink(jApp::config()->urlengine['jelixWWWPath'].'design/master_admin.css');
    }

    protected function doAfterActions() {
        $this->bodyTpl = 'master_admin~index_login';
        // Include all process in common for all actions, like the settings of the
        // main template, the settings of the response etc..
       $this->title .= ($this->title !=''?' - ':'').'Administration';
       $this->body->assignIfNone('MAIN','');
    }
}
