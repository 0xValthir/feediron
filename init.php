<?php

//Load bin components
require_once "bin/fi_logger.php";
require_once "bin/fi_json.php";
require_once "bin/fi_helper.php";

//Load PrefTab components
require_once "preftab/fi_pref_tab.php";
require_once "preftab/fi_recipe_manager.php";

//Load Filter modules
spl_autoload_register(function ($class) {
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'filters' . DIRECTORY_SEPARATOR . $class . DIRECTORY_SEPARATOR . 'init.php';
    if(is_readable($file))
        include $file;
});

class Feediron extends Plugin implements IHandler
{
  private $host;
  protected $charset;
  private $json_error;
  private $cache;
  protected $defaults = array(  'debug' => false,
                                'tidy-source' => true);

  // Required API
  function about()
  {
    return array(
      1.32,   // version
      'Reforge your feeds',   // description
      'm42e',   // author
      false,   // is_system
    );
  }

  // Required API
  function api_version()
  {
    return 2;
  }

  // Required API for adding the hooks
  function init($host)
  {
    $this->host = $host;
    $host->add_hook($host::HOOK_PREFS_TABS, $this);
    $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
  }

  // Required API, Django...
  function csrf_ignore($method)
  {
    $csrf_ignored = array("index", "edit");
    return array_search($method, $csrf_ignored) !== false;
  }

  // Allow only in active sessions
  function before($method)
  {
    if ($_SESSION["uid"])
    {
      return true;
    }
    return false;
  }

  // Required API
  function after()
  {
    return true;
  }

  // The hook to filter the article. Called for each article
  function hook_article_filter($article)
  {
    Feediron_Logger::get()->set_log_level(0);
    $content['link'] = $article["link"];
    if ($content['link'] === null) {  return $article; };
    $config = $this->getConfig($content['link']);
    Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Production config: ", $config);
    if ($config !== false) {
      $content['content'] = $this->getArticle($article['link'], $config);
      if ( is_null( $content['content'] ) ) { return $article; };
      $content = $this->processArticle($content, $config);

      // If xpath tags are to replaced tags completely
      if( !empty( $content['tags'] ) AND !empty( $content['replace-tags'] ) ) {
        Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Replacing Tags");
        // Overwrite Article tags, Also ensure no empty tags are returned
        $article['tags'] = array_filter( $content['tags'] );
        // If xpath tags are to be prepended to existing tags
      } elseif ( !empty( $content['tags'] ) ) {
        // Merge with in front of Article tags to avoid empty array issues
        $taglist = array_merge($content['tags'], $article['tags']);
        Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Merging Tags: ".implode( ", ", $taglist));
        // Ensure no empty tags are returned
        $article['tags'] = array_filter( $taglist );
      }
      $article['content'] = $content['content'];
    }

    Feediron_Logger::get()->log_object(Feediron_Logger::LOG_TTRSS, "Article tags: ", $article['tags']);
    return $article;
  }

  function getConfig($url)
  {
    if ($url === null) { return false; };

    $json_conf = $this->host->get($this, 'json_conf');
    $data = json_decode($json_conf, true);

    if(is_array($data)){

      foreach ($data as $urlpart=>$config) { // Check for multiple URL's
        if (strpos($urlpart, "|") !== false){
          $urlparts = explode("|", $urlpart);
          foreach ($urlparts as $suburl){
            if (strpos($url, $suburl) !== false){
              $urlpart = $suburl;
              break; // exit loop
            }
          }

        }
        if (strpos($url, $urlpart) === false){
          continue;   // skip this config if URL not matching
        }

        foreach ( array_keys( $this->defaults ) as $key ) {
            if( isset( $data[$key] ) && is_bool( $data[$key] ) ) {
                $this->defaults[$key] = $data[$key];
            }
        }

        if(Feediron_Logger::get()->get_log_level() == 0){
          Feediron_Logger::get()->set_log_level( ( $this->defaults['debug'] ) || !is_array( $data ) );
        }

        return $config;
      }
    }
    return false;
  }

  function getArticle($link, $config)
  {
    if(is_array($this->cache) && array_key_exists($link, $this->cache)){
      Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Fetching from cache");
      return $this->cache[$link];
    }

    global $fetch_last_content_type;
    Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, $link);
    $html = fetch_file_contents($link);
    $content_type = $fetch_last_content_type;

    list($html, $content_type) = array( $html,  $content_type);

    $this->charset = false;

    // Array of valid charsets for tidy functions
    $valid_charsets = array(
      "raw" => array("raw"),
      "ascii" => array("ascii"),
      "latin0" => array("latin0"),
      "latin1" => array("latin1"),
      "utf8" => array("utf8", "UTF-8", "ISO88591", "ISO-8859-1", "ISO8859-1"),
      "iso2022" => array("iso2022"),
      "mac" => array("mac"),
      "win1252" => array("win1252"),
      "ibm858" => array("ibm858"),
      "utf16le" => array("utf16le"),
      "utf16be" => array("utf16be"),
      "utf16" => array("utf16"),
      "big5" => array("big5"),
      "shiftjis" => array("shiftjis")
    );

    if (!isset($config['force_charset']))
    {
      if (!$content_type)
      {
        // Match charset from content_type header
        preg_match('/charset=(\S+)/', $content_type, $matches);
        if (isset($matches[1]) && !empty($matches[1])) {
          $this->charset = str_replace('"', "", html_entity_decode($matches[1]));
          Feediron_Logger::get()->log(Feediron_Logger::LOG_TEST, "Matched charset:", $this->charset);
        } else {
          // Attempt to detect encoding of html directly
          $detected_charset = mb_detect_encoding($html, implode(',', mb_list_encodings()), true);
          if (is_string($detected_charset)) {
            Feediron_Logger::get()->log(Feediron_Logger::LOG_TEST, "Detected charset:", $detected_charset);
            $this->charset = $detected_charset;
          } else {
            Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Failed to detect charset. Consider manually setting the chareset");
          }
        }
      }

    } elseif ( isset( $config['force_charset'] ) ) {
      // use forced charset
      $this->charset = $config['force_charset'];
    }

    Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Current charset:", $this->charset);
    $html = mb_convert_encoding($html, 'HTML-ENTITIES', $this->charset);
    $this->charset = 'utf-8';
    Feediron_Logger::get()->log_html(Feediron_Logger::LOG_VERBOSE, "Changed charset to utf-8:", $html);

    // Map Charset to valid_charsets
    if ( isset($this->charset) ){
      foreach($valid_charsets as $index => $alias) {

        foreach($alias as $key => $value) {

          if ($value == $this->charset) {
            $this->charset = $index;
            Feediron_Logger::get()->log(Feediron_Logger::LOG_TEST, "Valid Charset detected and mapped", $this->charset);
            break 2;
          }
        }
      }
    }

    // Use PHP tidy to fix source page if option tidy-source called
    if ( !isset($config['tidy-source']) ){
        $config['tidy-source'] = $this->defaults['tidy-source'];
    }
    if (function_exists('tidy_parse_string') && $config['tidy-source'] !== false && $this->charset !== false){
        try {
          Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "attempting tidy of source");
          // Use forced or discovered charset of page
          $tidy = tidy_parse_string($html, array('indent'=>true, 'show-body-only' => true), str_replace(["-", "–"], '', $this->charset));
          $tidy->cleanRepair();
          $tidy_html = $tidy->value;
          if( strlen($tidy_html) <= ( strlen($html)/2 )) {
                Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "tidy removed too much content, reverting");
          } else {
                Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "tidy of source completed successfully");
                $html = $tidy_html;
          }
        } catch (Exception $e) {
          Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Error running tidy", $e);
        } catch (Throwable $t) {
          Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Error running tidy", $t);
        }
    }

    Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Writing into cache");
    $this->cache[$link] = $html;

    return $html;
  }

  function processArticle($content, $config)
  {
    $link = $content['link'];
    //$tags = $content['tags'];

    // Build settings array
    $settings = array( "charset" => $this->charset, "link" => $link );

    foreach ( $config as $key=>$mod ) {
      Feediron_Logger::get()->log_object(Feediron_Logger::LOG_TTRSS, "Config value: ", $mod);
      $class = 'fi_mod_' . $key;

      if (class_exists($class)) {
        $content = ( new $class() )->perform_filter($content, $mod, $settings);
      } else {
        Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Unrecognized option: ".$key." ".$class);
      }

    }

/*    if($this->array_check($config, 'modify'))
    {
      $filter_return = reformat($filter_return, $config['modify']);
    }
    // if we've got Tidy, let's clean it up for output
    if (function_exists('tidy_parse_string') && $this->array_check($config, 'tidy') && $this->charset !== false) {
      try {
        $tidy = tidy_parse_string($filter_return, array('indent'=>true, 'show-body-only' => true), str_replace(["-", "–"], '', $this->charset));
        $tidy->cleanRepair();
        $filter_return = $tidy->value;
      } catch (Exception $e) {
        Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Error running tidy", $e);
      } catch (Throwable $t) {
        Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Error running tidy", $t);
      }
    }*/
    return $content;
  }

  function hook_prefs_tabs(...$args)
  {
    print '<div id="feedironConfigTab" dojoType="dijit.layout.ContentPane"
    href="backend.php?op=feediron"
    title="' . __('FeedIron') . '"></div>';
  }

  function array_check($array, $key){
    if( array_key_exists($key, $array) && is_array($array[$key]) ){
      return true;
    } else {
      return false;
    }
  }

  function index()
  {
    $pluginhost = PluginHost::getInstance();
    $json_conf = $pluginhost->get($this, 'json_conf');
    $test_conf = $pluginhost->get($this, 'test_conf');
    print Feediron_PrefTab::get_pref_tab($json_conf, $test_conf);
  }

  /*
  * Storing the json reformat data
  */
  function save()
  {
    $json_conf = $_POST['json_conf'];

    $json_reply = array();
    Feediron_Json::format($json_conf);
    header('Content-Type: application/json');
    if (is_null(json_decode($json_conf)))
    {
      $json_reply['success'] = false;
      $json_reply['errormessage'] = __('Invalid JSON! ').json_last_error_msg();
      $json_reply['json_error'] = Feediron_Json::get_error();
      echo json_encode($json_reply);
      return false;
    }

    $this->host->set($this, 'json_conf', Feediron_Json::format($json_conf));
    $json_reply['success'] = true;
    $json_reply['message'] = __('Configuration saved.');
    $json_reply['json_conf'] = Feediron_Json::format($json_conf);
    echo json_encode($json_reply);
  }

  function export()
  {
    $conf = $this->getConfig();
    $recipe2export = $_POST['recipe'];
    Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "export recipe: ".$recipe2export);
    header('Content-Type: application/json');
    if(!isset ($conf[$recipe2export])){
      $json_reply['success'] = false;
      $json_reply['errormessage'] = __('Not found');
      echo json_encode($json_reply);
      return false;
    }
    $json_reply['success'] = true;
    $json_reply['message'] = __('Exported');

    $sth = $this->pdo->prepare("SELECT full_name FROM ttrss_users WHERE id = ?");
    $sth->execute([$_SESSION['uid']]);
    $author = $sth->fetch();

    $recipe = json_encode($conf[$recipe2export]);

    $recipe = preg_replace('/&/', '&amp;', $recipe);
    $recipe = preg_replace('/</', '&lt;', $recipe);
    $recipe = preg_replace('/</', '&gt;', $recipe);

    $recipe = json_decode($recipe);

    $data = array(
      "name"=> (isset($conf[$recipe2export]['name'])?$conf[$recipe2export]['name']:$recipe2export),
      "url" => (isset($conf[$recipe2export]['url'])?$conf[$recipe2export]['url']:$recipe2export),
      "stamp" => time(),
      "author" =>  $author['full_name'],
      "match" => $recipe2export,
      "config" => $recipe
    );
    $json_reply['json_export'] = Feediron_Json::format(json_encode($data));
    echo json_encode($json_reply);
  }

  function add()
  {
    $conf = $this->getConfig();
    $recipe2add = $_POST['addrecipe'];
    Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "recipe: ".$recipe2add);
    $rm = new RecipeManager();
    $recipe = $rm->getRecipe($recipe2add);
    header('Content-Type: application/json');
    if(!isset ($recipe['match'])){
      $json_reply['success'] = false;
      $json_reply['errormessage'] = __('Github API message: ').$recipe['message'];
      $json_reply['data'] = Feediron_Json::format(json_encode($recipe));
      echo json_encode($json_reply);
      return false;
    }
    if(isset ($conf[$recipe['match']])){
      $conf[$recipe['match'].'_orig'] = $conf[$recipe['match']];
    }
    $conf[$recipe['match']] = $recipe['config'];

    $json_reply['success'] = true;
    $json_reply['message'] = __('Configuration updated.');
    $json_reply['json_conf'] = Feediron_Json::format(json_encode($conf, JSON_UNESCAPED_SLASHES));
    echo json_encode($json_reply);
  }

  function arrayRecursiveDiff($aArray1, $aArray2) {
    $aReturn = array();

    // Ensure we are dealing with arrays
    $aArray1 = Feediron_Helper::check_array( $aArray1 );
    $aArray2 = Feediron_Helper::check_array( $aArray2 );

    foreach ($aArray1 as $mKey => $mValue) {
      if (array_key_exists($mKey, $aArray2)) {
        if (is_array($mValue)) {
          $aRecursiveDiff = $this->arrayRecursiveDiff($mValue, $aArray2[$mKey]);
          if (count($aRecursiveDiff)) { $aReturn[$mKey] = $aRecursiveDiff; }
        } else {
          if ($mValue != $aArray2[$mKey]) {
            $aReturn[$mKey] = $mValue;
          }
        }
      } else {
        $aReturn[$mKey] = $mValue;
      }
    }
    return $aReturn;
  }



  /*
  *  this function tests the rules using a given url
  */
  function test()
  {
    Feediron_Logger::get()->set_log_level(array_key_exists('verbose', $_POST)?Feediron_Logger::LOG_VERBOSE:Feediron_Logger::LOG_TEST);
    $test_url = $_POST['test_url'];
    //Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Test url: $test_url");

    if(isset($_POST['test_conf']) && trim($_POST['test_conf']) != ''){

      $json_conf = $_POST['test_conf'];
      $json_reply = array();
      Feediron_Json::format($json_conf);
      header('Content-Type: application/json');
      if (is_null(json_decode($json_conf)))
      {
        $json_reply['success'] = false;
        $json_reply['errormessage'] = __('Invalid JSON! ').json_last_error_msg();
        $json_reply['json_error'] = Feediron_Json::get_error();
        echo json_encode($json_reply);
        return false;
      }

      $config = $this->getConfig($test_url);
      $newconfig = json_decode($_POST['test_conf'], true);
      Feediron_Logger::get()->log_object(Feediron_Logger::LOG_TEST, "config posted: ", $newconfig);
      if($config != false){
        Feediron_Logger::get()->log_object(Feediron_Logger::LOG_TEST, "config found: ", $config);
        Feediron_Logger::get()->log_object(Feediron_Logger::LOG_TEST, "config diff", $this->arrayRecursiveDiff($config, $newconfig));
        if(count($this->arrayRecursiveDiff($newconfig, $config))!= 0){
          $this->host->set($this, 'test_conf', Feediron_Json::format(json_encode($config)));
        }
      }
      $config = json_decode($_POST['test_conf'], true);
    }else{
      $config = $this->getConfig($test_url);
    }
    Feediron_Logger::get()->log_object(Feediron_Logger::LOG_TTRSS, "Using config", $config);
    //$test_url = $this->reformatUrl($test_url, $config);
    //Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Url after reformat: $test_url");
    header('Content-Type: application/json');
    $reply = array();
    if($config === false) {

      $reply['success'] = false;
      $reply['errormessage'] = "URL did not match";
      $reply['log'] = Feediron_Logger::get()->get_testlog();
      echo json_encode($reply);
      return false;

    } else {

      $reply['success'] = true;
      $reply['url'] = $test_url;
      $content['link'] = $test_url;
      $content['content'] = $this->getArticle($test_url, $config);
      $content = $this->processArticle($content, $config);
      $reply['content'] = $content['content'];
      $reply['tags'] = $content['tags'];
      $reply['config'] = Feediron_Json::format(json_encode($config));
      if($reply['config'] == null){
        $reply['config'] = $_POST['test_conf'];
      }
      $reply['log'] = Feediron_Logger::get()->get_testlog();
      echo json_encode($reply);

    }
  }
}
