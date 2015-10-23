<?php
/**!
 * Copyright (c) 2014, Facebook, Inc.
 * All rights reserved.
 *
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the root directory of this source tree. An additional grant
 * of patent rights can be found in the PATENTS file in the same directory.
 */

/**
 * A PHP class to render React components on the server
 * and then hook the components on the client side.
 * Requires V8JS PHP extension: http://php.net/v8js
 */
class ReactJS {
  
  private
    /**
     * Name of the component to render
     * @var string
     */
    $component,
    
    /**
     * Properties that go along with the component
     * @var mixed
     */
    $data = null,
    
    /**
     * Instance of V8Js class
     * @var object
     */
    $v8,
    
    /**
     * Custom error handler
     * @var callable
     */
    $errorHandler,
    $hash,
    $reactPath;
  
  function __construct($requireJs, $reactPath) {

    $react = array(); //TODO: this is a poorly named variable

    // Unsure if necessary...
    $react[] = "var console = {warn: function(){}, error: print}";
    $react[] = "var global = global || this, self = self || this, window = window || this";

    //Load up requireJS
    $react[] = $requireJs; 
    // $react[] = "var React = require('" . $reactPath . "');";
    $react[] = "var React = require('" . $reactPath . "');";

    $concatenated = implode(";\n", $react);

    $this->reactPath = $reactPath;

    $this->v8 = new V8Js();

    //NOTE: This seems to be the crux of the big problem getting serverside rendering to work. 
    //      require('') is not loading up requireJS's env and therefore not able to load up the es6! module. 
    //      I think it has something to do with using AMD and if we switch to a CommonJS like model like browserfy 
    //      I think we could load more directly.

    $this->setRequires(["react" => $reactPath]);
    $this->executeJS($concatenated);

  }

  function setRequires($hash = []) {
    $this->hash = $hash; //TODO: is there a better way to pass $hash in in phP
    $this->v8->setModuleLoader(function($path) {
        $hash = $this->hash;
        $path = array_key_exists($path, $hash) ? $hash[$path] : ("/" . $path);
        return file_get_contents($path);
    });
  }
  
  /**
   * Which components is to be rendered along with it's data
   * E.g.
   *   $rjs->setComponent('MyTable', array('content' => $q4_results));
   *
   * @param string $component Component name
   * @param mixed $data Any type of data to be passed as params
   *              when initializing the component. Optional.
   * @return object $this instance
   */
  function setComponent($component, $path, $data = null) {
    // $path must be set to babelified Component and export the $component
    $this->component = $component;
    $this->executeJS( sprintf("%s = require('%s')",$component, $path) );
    $this->data = json_encode($data);
    return $this;
  }
  
  /**
   * Custom error handler. The default one var_dumps the exception
   * and die()s.
   *
   * @param callable $err Callback passed to call_user_func()
   * @return object $this instance
   */
  function setErrorHandler($err) {
    $this->errorHandler = $err;
    return $this;
  }
  
  /**
   * Returns the markup to print to the page
   *
   * @return string HTML string
   */
  function getMarkup() {
    $js = sprintf(
      "print(React.renderToString(React.createElement(%s, %s)))",
      $this->component,
      $this->data);

    return $this->executeJS($js);
  }
  
  /**
   * Returns JS to be inlined in the page (without <script> tags)
   * It instantiates the client side, once the JS arrives
   * 
   * NOTE: This class makes no attempt to load files JS so you can load it
   * however is appropriate - from a CDN, asynchronously, etc.
   *
   * e.g. getJS('document.body'); 
   *     renders in body and doesn't retain a var
   * e.g. getJS('#page', "GLOB"); 
   *      renders in element id="page" and assigns the component to 
   *      a JavaScript variable named GLOB for later use if needed
   * @param string $where A reference to a DOM object, or an ID
   *               for convenience if prefixed by a #. E.g. "#page"
   *               It will be passed to document.getElementById('page')
   * @param string $return_var Optional name of JS variable to be assigned to
   *               the rendered component
   * @return string JS code
   */
  function getJS($where, $return_var = null) {
    // special case for IDs
    if (substr($where, 0, 1) === '#') {
      $where = sprintf(
        'document.getElementById("%s")',
        substr($where, 1)
      );
    }
    return 
      ($return_var ? "var $return_var = " : "") .
      sprintf(
        "React.render(React.createElement(%s, %s), %s);",
        $this->component,
        $this->data,
        $where
      );
  }

  /**
   * Executes Javascript using V8JS, with primitive exception handling
   *
   * @param string $js JS code to be executed
   * @return string The execution response
   */
  private function executeJS($js) {
    try {
      ob_start();
      $this->v8->executeString($js);
      return ob_get_clean();
    } catch (V8JsException $e) {
      if ($this->errorHandler) {
        call_user_func($this->errorHandler, $e);
      } else {
        // default error handler blows up bad
        echo "<pre>";
        echo $e->getMessage();
        echo "</pre>";
        die(); 
      }
    }
  }

}

