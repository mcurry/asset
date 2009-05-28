<?php
/*
 * Asset Packer CakePHP Plugin
 * Copyright (c) 2009 Matt Curry
 * www.PseudoCoder.com
 * http://github.com/mcurry/asset
 *
 * @author      Matt Curry <matt@pseudocoder.com>
 * @license     MIT
 *
 */

App::import('Core', array('File', 'Folder', 'Sanitize'));

class AssetHelper extends Helper {
  //Cake debug = 0                          packed js/css returned.  $this->debug doesn't do anything.
  //Cake debug > 0, $this->debug = false    essentially turns the helper off.  js/css not packed.  Good for debugging your js/css files.
  //Cake debug > 0, $this->debug = true     packed js/css returned.  Good for debugging this helper.
  var $debug = false;

  //there is a *minimal* perfomance hit associated with looking up the filemtimes
  //if you clean out your cached dir (as set below) on builds then you don't need this.
  var $checkTs = false;

  //the packed files are named by stringing together all the individual file names
  //this can generate really long names, so by setting this option to true
  //the long name is md5'd, producing a resonable length file name.
  var $md5FileName = false;

  //you can change this if you want to store the files in a different location.
  //this is relative to your webroot
  var $cachePaths = array('css' => 'ccss', 'js' => 'cjs');
  var $paths = array('www_root' => WWW_ROOT,
                     'js' => JS,
                     'css' => CSS);

  var $foundFiles = array();

  //set the css compression level
  //options: default, low_compression, high_compression, highest_compression
  //default is no compression
  //I like high_compression because it still leaves the file readable.
  var $cssCompression = 'high_compression';

  var $helpers = array('Html', 'Javascript');
  var $viewScriptCount = 0;
  var $initialized = false;
  var $js = array();
  var $css = array();
  
  var $View = null;

  function __construct($paths=array()) {
    $this->paths = am($this->paths, $paths);
        
    $this->view =& ClassRegistry::getObject('view');
  }

  //flag so we know the view is done rendering and it's the layouts turn
  function afterRender() {
    if ($this->view) {
      $this->viewScriptCount = count($this->view->__scripts);
    }
  }

  function scripts_for_layout($types=array('js', 'css')) {
    if(!is_array($types)) {
      $types = array($types);  
    }
    
    if (!$this->initialized) {
      $this->__init();
    }

    $scripts_for_layout = '';
    //first the css
    if (in_array('css', $types) && !empty($this->css)) {
      $scripts_for_layout .= $this->Html->css('/' . $this->cachePaths['css'] . '/' . $this->__process('css', $this->css));
      $scripts_for_layout .= "\n\t";
    }

    //then the js
    if (in_array('js', $types) && !empty($this->js)) {
      $scripts_for_layout .= $this->Javascript->link('/' . $this->cachePaths['js'] . '/' . $this->__process('js', $this->js));
    }

    //anything leftover is outputted directly
    if (!empty($this->view->__scripts)) {
      $scripts_for_layout .= join("\n\t", $this->view->__scripts);
    }

    return $scripts_for_layout;
  }

  function __init() {
    $this->initialized = true;
    
    //nothing to do
    if (!$this->view->__scripts) {
      return;
    }

    if (Configure::read('Asset.jsPath')) {
      $this->cachePaths['js'] = Configure::read('Asset.jsPath');
    }

    if (Configure::read('Asset.cssPath')) {
      $this->cachePaths['css'] = Configure::read('Asset.cssPath');
    }

    //compatible with DebugKit
    if (!empty($this->view->viewVars['debugToolbarPanels'])) {
      $this->view->viewScriptCount += 1 + count($this->view->viewVars['debugToolbarJavascript']);
    }

    //move the layout scripts to the front
    $this->view->__scripts = array_merge(
                         array_slice($this->view->__scripts, $this->viewScriptCount),
                         array_slice($this->view->__scripts, 0, $this->viewScriptCount)
                       );

    //split the scripts into js and css
    foreach ($this->view->__scripts as $i => $script) {
      if (preg_match('/src="\/?(.*\/)?(js|css)\/(.*).js"/', $script, $match)) {
        $temp = array();
        $temp['script'] = $match[3];
        $temp['plugin'] = trim($match[1], '/');
        $this->js[] = $temp;

        //remove the script since it will become part of the merged script
        unset($this->view->__scripts[$i]);
      } else if (preg_match('/href="\/?(.*\/)(js|css)\/(.*).css/', $script, $match)) {
        $temp = array();
        $temp['script'] = $match[3];
        $temp['plugin'] = trim($match[1], '/');
        $this->css[] = $temp;

        //remove the script since it will become part of the merged script
        unset($this->view->__scripts[$i]);
      }
    }
  }

  function __process($type, $assets) {
    $path = $this->__getPath($type);
    $folder = new Folder($this->paths['www_root'] . $this->cachePaths[$type], true);

    //check if the cached file exists
    $scripts = Set::extract('/script', $assets);
    $fileName = $folder->find($this->__generateFileName($scripts) . '_([0-9]{10}).' . $type);
    if ($fileName) {
      //take the first file...really should only be one.
      $fileName = $fileName[0];
    }

    //make sure all the pieces that went into the packed script
    //are OLDER then the packed version
    if ($this->checkTs && $fileName) {
      $packed_ts = filemtime($this->paths['www_root'] . $this->cachePaths[$type] . DS . $fileName);

      $latest_ts = 0;
      foreach($assets as $asset) {
        $assetFile = $this->__findFile($asset, $type);
        if (!$assetFile) {
          continue;
        }
        $latest_ts = max($latest_ts, filemtime($assetFile));
      }

      //an original file is newer.  need to rebuild
      if ($latest_ts > $packed_ts) {
        unlink($this->paths['www_root'] . $this->cachePaths[$type] . DS . $fileName);
        $fileName = null;
      }
    }

    //file doesn't exist.  create it.
    if (!$fileName) {
      $ts = time();
      switch($type) {
        case 'js':
          if (PHP5) {
            App::import('Vendor', 'jsmin/jsmin');
          }
        case 'css':
          App::import('Vendor', 'csstidy', array('file' => 'class.csstidy.php'));
          $tidy = new csstidy();
          $tidy->load_template($this->cssCompression);
          break;
      }
      
      //merge the script
      $scriptBuffer = '';
      foreach($assets as $asset) {
        $buffer = $this->__getFileContents($asset, $type);
        $origSize = strlen($buffer);

        switch ($type) {
          case 'js':
            //jsmin only works with PHP5
            if (PHP5) {
              $buffer = trim(JSMin::minify($buffer));
            }
            break;

          case 'css':
            $tidy->parse($buffer);
            $buffer = $tidy->print->plain();
            break;
        }

        $delta = 0;
        if ($origSize > 0) {
          $delta = (strlen($buffer) / $origSize) * 100;
        }
        $scriptBuffer .= sprintf("/* %s.%s (%d%%) */\n", $asset['script'], $type, $delta);
        $scriptBuffer .= $buffer . "\n\n";
      }

      //write the file
      $fileName = $this->__generateFileName($scripts) . '_' . $ts . '.' . $type;
      $file = new File($this->paths['www_root'] . $this->cachePaths[$type] . DS . $fileName);
      $file->write(trim($scriptBuffer));
    }

    if ($type == 'css') {
      //$html->css doesn't check if the file already has
      //the .css extension and adds it automatically, so we need to remove it.
      $fileName = str_replace('.css', '', $fileName);
    }

    return $fileName;
  }

  /**
   * Find the source file contents.  Looks in in webroot, vendors and plugins.
   *
   * @param string $filename
   * @return string the full path to the file
   * @access private
  */
  function __getFileContents($asset, $type) {
    $assetFile = $this->__findFile($asset, $type);

    if ($assetFile) {
      return trim(file_get_contents($assetFile));
    }

    return '';
  }

  function __findFile($asset, $type) {
    $key = md5(serialize($asset) . $type);
    if (!empty($this->foundFiles[$key])) {
      return $this->foundFiles[$key];
    }

    $paths = array($this->__getPath($type));
    if (Configure::read('Asset.searchPaths')) {
      $paths = array_merge($paths, Configure::read('Asset.searchPaths'));
    }

    if (!empty($asset['plugin']) > 0) {
      $pluginPaths = Configure::read('pluginPaths');
      $count = count($pluginPaths);
      for ($i = 0; $i < $count; $i++) {
        $paths[] = $pluginPaths[$i] . $asset['plugin'] . DS . 'vendors' . DS;
      }
    }

    $paths = array_merge($paths, Configure::read('vendorPaths'));
    $assetFile = '';
    foreach ($paths as $path) {
      $script = sprintf('%s.%s', $asset['script'], $type);
      if (is_file($path . $script) && file_exists($path . $script)) {
        $assetFile = $path . $script;
        break;
      }

      if (is_file($path . $type . DS . $script) && file_exists($path . $type . DS . $script)) {
        $assetFile = $path . $type . DS . $script;
        break;
      }
    }

    $this->foundFiles[$key] = $assetFile;
    return $assetFile;
  }

  /**
   * Generate the cached filename.
   *
   * @param array $names an array of the original file names
   * @return string
   * @access private
  */
  function __generateFileName($names) {
    $fileName = Sanitize::paranoid(str_replace('/', '-', implode('_', $names)), array('_', '-'));

    if ($this->md5FileName) {
      $fileName = md5($fileName);
    }

    return $fileName;
  }

  function __getPath($type) {
    switch ($type) {
      case 'js':
        return $this->paths['js'];
      case 'css':
        return $this->paths['css'];
    }

    return false;
  }
}
?>