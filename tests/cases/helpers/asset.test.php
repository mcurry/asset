<?php
App::import('Helper', array('Asset.Asset', 'Javascript', 'Html'));
App::import('Core', array('Folder', 'View'));

class AssetTestCase extends CakeTestCase {
  var $Asset = null;
  var $Folder = null;
  var $View = null;
  
  var $www_root = null;
  var $jsCache = null;
  var $cssCache = null;
  
  function startCase() {
    $this->www_root = ROOT . DS . 'app' . DS . 'plugins' . DS . 'asset' . DS . 'tests' . DS . 'test_app' . DS . 'webroot' . DS;
    $this->jsCache = $this->www_root . 'cjs' . DS;
    $this->cssCache = $this->www_root . 'ccss' . DS;

    $controller = null;
    $this->View = new View($controller);
    
    $this->Asset = new AssetHelper(array('www_root' => $this->www_root, 'js' => $this->www_root . 'js' . DS, 'css' => $this->www_root . 'css' . DS));
    $this->Asset->Javascript = new JavascriptHelper();
    $this->Asset->Html = new HtmlHelper();
    
    $this->Folder = new Folder();
  }
  
  function endCase() {
    $this->Folder->delete($this->jsCache);
    $this->Folder->delete($this->cssCache);
  }
  
  function startTest() {
    $this->Asset->js = array();
    $this->Asset->css = array();
  }

  function testInstances() {
    $this->assertTrue(is_a($this->Asset, 'AssetHelper'));
    $this->assertTrue(is_a($this->Asset->Javascript, 'JavascriptHelper'));
    $this->assertTrue(is_a($this->Asset->Html, 'HtmlHelper'));
    $this->assertTrue(is_a($this->View, 'View'));
    $this->assertTrue(is_a(ClassRegistry::getObject('view'), 'View'));
  }
  
  function testVendors() {
    if(PHP5) {
      App::import('Vendor', 'jsmin/jsmin');
      $this->assertTrue(class_exists('JSMin'));
    }
    
    App::import('Vendor', 'csstidy', array('file' => 'class.csstidy.php'));
    $this->assertTrue(class_exists('csstidy'));
  }
  
  function testAfterRender() {
    $this->View->__scripts = array('script1', 'script2', 'script3');
    $this->Asset->afterRender();
    $this->assertEqual(3, $this->Asset->viewScriptCount);
  }
  
  function testGenerateFileName() {
    $files = array('script1', 'script2', 'script3');
    $name = $this->Asset->__generateFileName($files);
    $this->assertEqual('script1_script2_script3', $name);
  }
  
  function testGenerateFileNameMd5() {
    $this->Asset->md5FileName = true;
    $files = array('script1', 'script2', 'script3');
    $name = $this->Asset->__generateFileName($files);
    $this->assertEqual('4991a54c1356544e1188bf6c8b9e7ae9', $name);
    $this->Asset->md5FileName = false;
  }
  
  function testFindFileDupeName() {
    $path1 = $this->Asset->__findFile(array('plugin' => '', 'script' => 'asset1'), 'js');
    $path2 = $this->Asset->__findFile(array('plugin' => '', 'script' => 'asset1'), 'css');
    
    $this->AssertNotEqual($path1, $path2);
  }
  
  function testGetFileContents() {
    $contents = $this->Asset->__getFileContents(array('plugin' => '', 'script' => 'script1'), 'js');
    $expected = <<<END
var str = "I'm a string";
alert(str);
END;
    $this->assertEqual($expected, $contents);
  }
  
  function testGetFileContentsPlugin() {
    $contents = $this->Asset->__getFileContents(array('plugin' => 'asset', 'script' => 'script3'), 'js');
    $expected = <<<END
$(function(){
  $("#nav").show();
});
END;
    $this->assertEqual($expected, $contents);
  }
  
  function testProcessJsNew() {
    $this->assertFalse(is_dir($this->jsCache));
    
    $js = array(array('plugin' => '', 'script' => 'script1'),
                array('plugin' => '', 'script' => 'script2'),
                array('plugin' => 'asset', 'script' => 'script3'));
    
    $fileName = $this->Asset->__process('js', $js);
    $expected = <<<END
/* script1.js (91%) */
var str="I'm a string";alert(str);

/* script2.js (73%) */
var sum=0;for(i=0;i<100;i++){sum+=i;}
alert(i);

/* script3.js (89%) */
\$(function(){\$("#nav").show();});
END;
    $contents = file_get_contents($this->jsCache . $fileName);
    $this->assertEqual($expected, $contents);
  }
  
  function testProcessJsExistingNoChanges() {
    $this->Folder->cd($this->jsCache);
    $files = $this->Folder->find('script1_script2_script3_([0-9]{10}).js');
    
    $this->assertTrue(!empty($files[0]));
    $origFileName = $files[0];

    $js = array(array('plugin' => '', 'script' => 'script1'),
                array('plugin' => '', 'script' => 'script2'),
                array('plugin' => 'asset', 'script' => 'script3'));
    
    $this->Asset->checkTs = true;
    $fileName = $this->Asset->__process('js', $js);
    $this->assertEqual($origFileName, $fileName);
  }
  
  function testProcessJsExistingWithChanges() {
    $this->Folder->cd($this->jsCache);
    $files = $this->Folder->find('script1_script2_script3_([0-9]{10}).js');
    
    $this->assertTrue(!empty($files[0]));
    $origFileName = $files[0];

    sleep(1);
    $touched = touch($this->www_root . 'js' . DS . 'script1.js');
    $this->assertTrue($touched);
    
    $js = array(array('plugin' => '', 'script' => 'script1'),
                array('plugin' => '', 'script' => 'script2'),
                array('plugin' => 'asset', 'script' => 'script3'));
    
    $this->Asset->checkTs = true;
    $fileName = $this->Asset->__process('js', $js);
    $this->assertNotEqual($origFileName, $fileName);
  }
  
  function testProcessCssNew() {
    $this->assertFalse(is_dir($this->cssCache));
    
    $css = array(array('plugin' => '', 'script' => 'style1'),
                array('plugin' => '', 'script' => 'style2'),
                array('plugin' => 'asset', 'script' => 'style3'));
    
    $fileName = $this->Asset->__process('css', $css);
    $expected = <<<END
/* style1.css (70%) */
*{margin:0;padding:0;}

/* style2.css (85%) */
body{background:#003d4c;color:#fff;font-family:'lucida grande',verdana,helvetica,arial,sans-serif;font-size:90%;margin:0;}

/* style3.css (69%) */
h1,h2,h3,h4{font-weight:400;}
END;
    $contents = file_get_contents($this->cssCache . $fileName  . '.css');
    $this->assertEqual($expected, $contents);
  }
  
  function testInit() {
    $this->View->__scripts = array ('<link rel="stylesheet" type="text/css" href="/css/style1.css" />',
                      '<link rel="stylesheet" type="text/css" href="/css/style2.css" />',
                      '<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.3.2/jquery.min.js"></script>',
                      '<script type="text/javascript" src="/js/script1.js"></script>',
                      '<script type="text/javascript" src="/js/script2.js"></script>',
                      '<script type="text/javascript" src="/asset/js/script3.js"></script>'
    );
    
    
    $this->Asset->__init();
    
    $this->assertEqual($this->Asset->js, array(array('plugin' => '', 'script' => 'script1'),
                                             array('plugin' => '', 'script' => 'script2'),
                                             array('plugin' => 'asset', 'script' => 'script3')));
    $this->assertEqual($this->Asset->css, array(array('plugin' => '', 'script' => 'style1'),
                                              array('plugin' => '', 'script' => 'style2')));
  }
  
  function testScriptsForLayout() {
    $this->View->__scripts = array ('<link rel="stylesheet" type="text/css" href="/css/style1.css" />',
                      '<link rel="stylesheet" type="text/css" href="/css/style2.css" />',
                      '<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.3.2/jquery.min.js"></script>',
                      '<script type="text/javascript" src="/js/script1.js"></script>',
                      '<script type="text/javascript" src="/js/script2.js"></script>',
                      '<script type="text/javascript" src="/asset/js/script3.js"></script>'
    );
    
    $scripts = $this->Asset->scripts_for_layout();
    $expected = '/<link rel="stylesheet" type="text\/css" href="\/ccss\/style1_style2_[0-9]{10}.css" \/>' . "\n\t" .
                '<script type="text\/javascript" src="\/cjs\/script1_script2_script3_[0-9]{10}.js"><\/script>/';
                
    $this->assertPattern($expected, $scripts);
  }
  
  function testWithCodeBlock() {
    $this->View->__scripts = array ('<script type="text/javascript" src="/js/script1.js"></script>',
                                    '<script type="text/javascript">//<![CDATA[alert("test");//]]></script>',
                                    '<script type="text/javascript" src="/js/script2.js"></script>'
    );
    $scripts = $this->Asset->scripts_for_layout();
    $expected = '/<script type="text\/javascript" src="\/cjs\/script1_script2_[0-9]{10}.js"><\/script><script type="text\/javascript">\/\/<!\[CDATA\[alert\("test"\);\/\/]]><\/script>/';
    $this->assertPattern($expected, $scripts);
  }
  
  function testWithScriptsInLayout() {
    $this->View->__scripts = array ('<script type="text/javascript" src="/js/script1.js"></script>',
                                    '<script type="text/javascript" src="/js/layout.js"></script>');
    $this->Asset->viewScriptCount = 1;
    $scripts = $this->Asset->scripts_for_layout();
    $expected = '/<script type="text\/javascript" src="\/cjs\/layout_script1_[0-9]{10}.js"><\/script>/';
    $this->assertPattern($expected, $scripts);
  }
}