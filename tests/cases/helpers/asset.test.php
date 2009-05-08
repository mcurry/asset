<?php
App::import('Helper', array('Asset.Asset', 'Javascript', 'Html'));
App::import('Core', array('Folder'));

class AssetTestCase extends CakeTestCase {
  var $Asset = null;
  var $Folder = null;
  var $www_root = null;
  
  function start() {
    parent::start();
    
    $this->www_root = ROOT . DS . 'app' . DS . 'plugins' . DS . 'asset' . DS . 'tests' . DS . 'test_app' . DS . 'webroot' . DS;
    
    $this->Asset = new AssetHelper(array('www_root' => $this->www_root, 'js' => $this->www_root . 'js' . DS, 'css' => $this->www_root . 'css' . DS));
    $this->Asset->Javascript = new JavascriptHelper();
    $this->Asset->Html = new HtmlHelper();
    
    $this->Folder = new Folder();
  }

  function testInstances() {
    $this->assertTrue(is_a($this->Asset, 'AssetHelper'));
    $this->assertTrue(is_a($this->Asset->Javascript, 'JavascriptHelper'));
    $this->assertTrue(is_a($this->Asset->Html, 'HtmlHelper'));
  }
  
  function testGenerateFileName() {
    $files = array('test1', 'test2', 'test3');
    $name = $this->Asset->__generateFileName($files);
    $this->assertEqual('test1_test2_test3', $name);
  }
  
  function testGenerateFileNameMd5() {
    $this->Asset->md5FileName = true;
    $files = array('test1', 'test2', 'test3');
    $name = $this->Asset->__generateFileName($files);
    $this->assertEqual('658f623f5f77d24124bb35c576304bf3', $name);
    $this->Asset->md5FileName = false;
  }
  
  function testGetFileContents() {
    $contents = $this->Asset->__getFileContents(array('plugin' => '', 'script' => 'test1'), 'js');
    $expected = <<<END
var str = "I'm a string";
alert(str);
END;
    $this->assertEqual($expected, $contents);
  }
  
  function testGetFileContentsPlugin() {
    $contents = $this->Asset->__getFileContents(array('plugin' => 'asset', 'script' => 'test3'), 'js');
    $expected = <<<END
$(function(){
  $("#nav").show();
});
END;
    $this->assertEqual($expected, $contents);
  }
  
  function testProcessJsNew() {
    $outputDir = $this->www_root . 'cjs' . DS;
    if(is_dir($outputDir)) {
      $this->Folder->delete($outputDir);
    }
    $this->assertFalse(is_dir($outputDir));
    
    $js = array(array('plugin' => '', 'script' => 'test1'),
                array('plugin' => '', 'script' => 'test2'),
                array('plugin' => 'asset', 'script' => 'test3'));
    
    $fileName = $this->Asset->__process('js', $js);
    $expected = <<<END
/* test1.js (91%) */
var str="I'm a string";alert(str);

/* test2.js (69%) */
var sum=0;for(i=0;i<100;i++){sum+=i;}
alert(i);

/* test3.js (89%) */
\$(function(){\$("#nav").show();});
END;
    $contents = file_get_contents($outputDir . $fileName);
    $this->assertEqual($expected, $contents);
  }
  
  function testProcessJsExistingNoChanges() {
    $outputDir = $this->www_root . 'cjs' . DS;
    $this->Folder->cd($outputDir);
    $files = $this->Folder->find('test1_test2_test3_([0-9]{10}).js');
    
    $this->assertTrue(!empty($files[0]));
    $origFileName = $files[0];

    $js = array(array('plugin' => '', 'script' => 'test1'),
                array('plugin' => '', 'script' => 'test2'),
                array('plugin' => 'asset', 'script' => 'test3'));
    
    $this->Asset->checkTs = true;
    $fileName = $this->Asset->__process('js', $js);
    $this->assertEqual($origFileName, $fileName);
  }
  
  function testProcessJsExistingWithChanges() {
    $outputDir = $this->www_root . 'cjs' . DS;
    $this->Folder->cd($outputDir);
    $files = $this->Folder->find('test1_test2_test3_([0-9]{10}).js');
    
    $this->assertTrue(!empty($files[0]));
    $origFileName = $files[0];

    sleep(1);
    $touched = touch($this->www_root . 'js' . DS . 'test1.js');
    $this->assertTrue($touched);
    
    $js = array(array('plugin' => '', 'script' => 'test1'),
                array('plugin' => '', 'script' => 'test2'),
                array('plugin' => 'asset', 'script' => 'test3'));
    
    $this->Asset->checkTs = true;
    $fileName = $this->Asset->__process('js', $js);
    $this->assertNotEqual($origFileName, $fileName);
  }
}