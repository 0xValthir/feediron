<?php

class fi_mod_tags
{
  public function perform_filter($content, $config, $settings )
  {
    foreach ( $config as $key=>$mod ) {
      Feediron_Logger::get()->log_object(Feediron_Logger::LOG_VERBOSE, "Config key: ", $key);
      Feediron_Logger::get()->log_object(Feediron_Logger::LOG_VERBOSE, "Config value: ", $mod);

      switch ( $key ) {
        case 'xpath': $tags = ( new fi_mod_xpath() )->perform_filter( $content, $mod, $settings ); break;
        #case 'replace-tags': 
        default: Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Unrecognized option: ".$key);
      }
    }

    if(!$tags){
      Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "No tags saved");
      return;
    }

    // Split tags
    if( isset( $config['split'] ) )
    {
      $split_tags = array();
      foreach( $tags as $key=>$tag )
      {
        $split_tags = array_merge($split_tags, explode( $config['split'], $tag ) );
      }
      $tags = $split_tags;
    }

    // Loop through tags indivdually
    foreach( $tags as $key=>$tag )
    {
      // If set perform modify
      if($this->array_check($config, 'modify'))
      {
        $tag = Feediron_Helper::reformat($tag, $config['modify']);
      }
      // Strip tags of html and ensure plain text
      $tags[$key] = trim( preg_replace('/\s+/', ' ', strip_tags( $tag ) ) );
      Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Tag saved: ".$tags[$key]);
    }

    $content['tags'] = array_filter($tags);

    return $content;

  }

  public function get_tags_regex($html, $config, $settings )
  {
    if(!( array_key_exists('pattern', $config) && is_array($config['pattern']) )){
      $patterns = array($config['pattern']);
    }else{
      $patterns = $config['pattern'];
    }

    if( !isset( $config['index'] ) ){
      $index = 0;
    } else {
      $index = $config['index'];
    }

    // loop through regex pattern array
    foreach( $patterns as $key=>$pattern ){
      preg_match($pattern, $html, $match);
      $tags[$key] = $match[$index];
    }
    return $tags;
  }

  private function array_check($array, $key){
    if( array_key_exists($key, $array) && is_array($array[$key]) ){
      return true;
    } else {
      return false;
    }
  }

  public function get_tags_search($html, $config, $settings )
  {
    if(!$this->array_check($config,'pattern')){
      $patterns = array($config['pattern']);
    }else{
      $patterns = $config['pattern'];
    }

    if(!$this->array_check($config,'match')){
      $matches = array($config['match']);
    }else{
      $matches = $config['match'];
    }

    if( count($patterns) != count($matches) ){
      Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Number of Patterns ".count($patterns)." doesn't equal number of Matches ".count($matches));
      return;
    }

    $matches = array_combine ( $patterns, $matches );

    // loop through regex pattern array
    foreach( $matches as $pattern=>$match ){
      if( preg_match($pattern, $html) && substr( $match, 0, 1 ) != "!" ){
        Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Tag search match", $pattern);
        $tags[$pattern] .= $match;
      } else if( !preg_match($pattern, $html) && substr( $match, 0, 1 ) == "!" ) {
        Feediron_Logger::get()->log(Feediron_Logger::LOG_VERBOSE, "Tag inverted search match", $pattern);
        $tags[$pattern] .= substr( $match, 1 );
      }
    }
    return array_values( $tags );
  }
}
