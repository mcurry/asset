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
  //this is relative to your webroot/js and webroot/css paths
  var $cachePath = 'packed/';

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
    if($view) {
      $this->viewScriptCount = count($view->__scripts);
    }
  }

  function scripts_for_layout() {
    $view =& ClassRegistry::getObject('view');

    //nothing to do
    if (!$view->__scripts) {
      return;
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
      if (preg_match('/js\/(.*).js/', $script, $match)) {
        $temp = array();
        $temp['script'] = $match[1];
        $temp['name'] = basename($match[1]);
        $js[] = $temp;

        //remove the script since it will become part of the merged script
        unset($view->__scripts[$i]);
      } else if (preg_match('/css\/(.*).css/', $script, $match)) {
        $temp = array();
        $temp['script'] = $match[1];
        $temp['name'] = basename($match[1]);
        $css[] = $temp;

        //remove the script since it will become part of the merged script
        unset($view->__scripts[$i]);
      }
    }
    
    $scripts_for_layout = '';
    //first the css
    if (!empty($css)) {
      $scripts_for_layout .= $this->Html->css($this->cachePath . $this->process('css', $css));
      $scripts_for_layout .= "\n\t";
    }

    //then the js
    if (!empty($js)) {
      $scripts_for_layout .= $this->Javascript->link($this->cachePath . $this->process('js', $js));
    }
    
    //anything leftover is outputted directly
    if(!empty($view->__scripts)) {
      $scripts_for_layout .= join("\n\t", $view->__scripts);
    }

    return $scripts_for_layout;
  }


  function process($type, $data) {
    switch ($type) {
      case 'js':
        $path = JS;
        break;
      case 'css':
        $path = CSS;
        break;
    }

    $folder = new Folder($path . $this->cachePath, true);

    //check if the cached file exists
    $names = Set::extract($data, '{n}.name');
    $fileName = $folder->find($this->__generateFileName($names) . '_([0-9]{10}).' . $type);
    if ($fileName) {
      //take the first file...really should only be one.
      $fileName = $fileName[0];
    }

    //make sure all the pieces that went into the packed script
    //are OLDER then the packed version
    if ($this->checkTS && $fileName) {
      $packed_ts = filemtime($path . $this->cachePath . $fileName);

      $latest_ts = 0;
      $scripts = Set::extract($data, '{n}.script');
      foreach($scripts as $script) {
        $latest_ts = max($latest_ts, filemtime($path . $script . '.' . $type));
      }

      //an original file is newer.  need to rebuild
      if ($latest_ts > $packed_ts) {
        unlink($path . $this->cachePath . $fileName);
        $fileName = null;
      }
    }

    //file doesn't exist.  create it.
    if (!$fileName) {
      $ts = time();

      //merge the script
      $scriptBuffer = '';
      $scripts = Set::extract($data, '{n}.script');
      foreach($scripts as $script) {
        $buffer = trim(file_get_contents($path . $script . '.' . $type));

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
        
        $scriptBuffer .= "\n/* $script.$type */\n" . $buffer;
      }


      //write the file
      $fileName = $this->__generateFileName($names) . '_' . $ts . '.' . $type;
      $file = new File($path . $this->cachePath . $fileName);
      $file->write(trim($scriptBuffer));
    }

    if ($type == 'css') {
      //$html->css doesn't check if the file already has
      //the .css extension and adds it automatically, so we need to remove it.
      $fileName = str_replace('.css', '', $fileName);
    }

    return $fileName;
  }
  
  function __generateFileName($names) {
    $fileName = str_replace('.', '-', implode('_', $names));
    
    if($this->md5FileName) {
      $fileName = md5($fileName);
    }
    
    return $fileName;
  }
}
?>