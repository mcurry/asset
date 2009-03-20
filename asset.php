<?php
/*
 * Asset Packer CakePHP Component
 * Copyright (c) 2008 Matt Curry
 * www.PseudoCoder.com
 * http://github.com/mcurry/cakephp/tree/master/helpers/asset
 * http://sandbox2.pseudocoder.com/demo/asset
 *
 * @author      mattc <matt@pseudocoder.com>
 * @license     MIT
 *
 */

App::import('Sanitize');

class AssetHelper extends Helper {
  //Cake debug = 0                          packed js/css returned.  $this->debug doesn't do anything.
  //Cake debug > 0, $this->debug = false    essentially turns the helper off.  js/css not packed.  Good for debugging your js/css files.
  //Cake debug > 0, $this->debug = true     packed js/css returned.  Good for debugging this helper.
  var $debug = false;

  //there is a *minimal* perfomance hit associated with looking up the filemtimes
  //if you clean out your cached dir (as set below) on builds then you don't need this.
  var $checkTS = false;

  //the packed files are named by stringing together all the individual file names
  //this can generate really long names, so by setting this option to true
  //the long name is md5'd, producing a resonable length file name.
  var $md5FileName = false;

  //you can change this if you want to store the files in a different location.
  //this is relative to your webroot
  var $cachePaths = array('css' => 'ccss', 'js' => 'cjs');

  //set the css compression level
  //options: default, low_compression, high_compression, highest_compression
  //default is no compression
  //I like high_compression because it still leaves the file readable.
  var $cssCompression = 'high_compression';

  var $helpers = array('Html', 'Javascript');
  var $viewScriptCount = 0;

  //flag so we know the view is done rendering and it's the layouts turn
  function afterRender() {
    $view =& ClassRegistry::getObject('view');
    if ($view) {
      $this->viewScriptCount = count($view->__scripts);
    }
  }

  function scripts_for_layout() {
    $view =& ClassRegistry::getObject('view');

    //nothing to do
    if (!$view->__scripts) {
      return;
    }
    
    if(Configure::read('Asset.jsPath')) {
      $this->cachePaths['js'] = Configure::read('Asset.jsPath');
    }

    if(Configure::read('Asset.cssPath')) {
      $this->cachePaths['css'] = Configure::read('Asset.cssPath');
    }
    
    //compatible with DebugKit
    if(!empty($view->viewVars['debugToolbarPanels'])) {
      $this->viewScriptCount += 1 + count($view->viewVars['debugToolbarJavascript']);
    }
    
    //move the layout scripts to the front
    $view->__scripts = array_merge(
                         array_slice($view->__scripts, $this->viewScriptCount),
                         array_slice($view->__scripts, 0, $this->viewScriptCount)
                       );


    if (Configure::read('debug') && $this->debug == false) {
      return join("\n\t", $view->__scripts);
    }

    //split the scripts into js and css
    foreach ($view->__scripts as $i => $script) {
      if (preg_match('/src="\/?(.*\/)?js\/(.*).js"/', $script, $match)) {
        $temp = array();
        $temp['script'] = $match[2];
        $temp['plugin'] = trim($match[1], '/');
        $js[] = $temp;

        //remove the script since it will become part of the merged script
        unset($view->__scripts[$i]);
      } else if (preg_match('/href="\/?(.*\/)css\/(.*).css/', $script, $match)) {
        $temp = array();
        $temp['script'] = $match[2];
        $temp['plugin'] = trim($match[1], '/');
        $css[] = $temp;

        //remove the script since it will become part of the merged script
        unset($view->__scripts[$i]);
      }
    }

    $scripts_for_layout = '';
    //first the css
    if (!empty($css)) {
      $scripts_for_layout .= $this->Html->css('/' . $this->cachePaths['css'] . '/' . $this->process('css', $css));
      $scripts_for_layout .= "\n\t";
    }

    //then the js
    if (!empty($js)) {
      $scripts_for_layout .= $this->Javascript->link('/' . $this->cachePaths['js'] . '/' . $this->process('js', $js));
    }

    //anything leftover is outputted directly
    if (!empty($view->__scripts)) {
      $scripts_for_layout .= join("\n\t", $view->__scripts);
    }

    return $scripts_for_layout;
  }


  function process($type, $assets) {
    switch ($type) {
      case 'js':
        $path = JS;
        break;
      case 'css':
        $path = CSS;
        break;
    }

    $folder = new Folder(WWW_ROOT . $this->cachePaths[$type], true);

    //check if the cached file exists
    $scripts = Set::extract($assets, '{n}.script');
    $fileName = $folder->find($this->__generateFileName($scripts) . '_([0-9]{10}).' . $type);
    if ($fileName) {
      //take the first file...really should only be one.
      $fileName = $fileName[0];
    }

    //make sure all the pieces that went into the packed script
    //are OLDER then the packed version
    if ($this->checkTS && $fileName) {
      $packed_ts = filemtime($path . $this->cachePaths[$type] . DS . $fileName);

      $latest_ts = 0;
      foreach($scripts as $script) {
        $latest_ts = max($latest_ts, filemtime($path . $script . '.' . $type));
      }

      //an original file is newer.  need to rebuild
      if ($latest_ts > $packed_ts) {
        unlink(WWW_ROOT . $this->cachePaths[$type] . DS . $fileName);
        $fileName = null;
      }
    }

    //file doesn't exist.  create it.
    if (!$fileName) {
      $ts = time();

      //merge the script
      $scriptBuffer = '';
      foreach($assets as $asset) {
        $buffer = $this->__getFileContents($asset, $type);

        switch ($type) {
          case 'js':
            //jsmin only works with PHP5
            if (PHP5) {
              App::import('Vendor', 'jsmin/jsmin');
              $buffer = trim(JSMin::minify($buffer));
            }
            break;

          case 'css':
            App::import('Vendor', 'csstidy', array('file' => 'class.csstidy.php'));
            $tidy = new csstidy();
            $tidy->load_template($this->cssCompression);
            $tidy->parse($buffer);
            $buffer = $tidy->print->plain();
            break;
        }

        $scriptBuffer .= sprintf("/* %s.%s */\n", $asset['script'], $type);
        $scriptBuffer .= $buffer . "\n\n";
      }

      //write the file
      $fileName = $this->__generateFileName($scripts) . '_' . $ts . '.' . $type;
      $file = new File(WWW_ROOT . $this->cachePaths[$type] . DS . $fileName);
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
    $paths = array();

    switch ($type) {
      case 'js':
        $paths[] = JS;
        break;
      case 'css':
        $paths[] = CSS;
        break;
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

    if($assetFile) {
      return trim(file_get_contents($assetFile));
    }
    
    return '';
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
}
?>