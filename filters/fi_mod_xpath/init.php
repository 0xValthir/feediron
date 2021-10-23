<?php

class fi_mod_xpath
{

  public function perform_filter( $content, $xpath, $settings )
  {
    $html = $content['content'];
    $debug = false;
    $doc = Feediron_Helper::getDOM( $html, $settings['charset'], $debug );
    $xpathdom = new DOMXPath($doc);
    $htmlout = array();

    Feediron_Logger::get()->log(Feediron_Logger::LOG_TEST, "Perfoming xpath", $xpath);
    $entries = $xpathdom->query($xpath);   // find main DIV according to config

    if (is_null($entries)) {
      Feediron_Logger::get()->log(Feediron_Logger::LOG_TTRSS, "Query returned no results", is_null($entries));
    }

    foreach ($entries as $entry) {
      Feediron_Logger::get()->log_object(Feediron_Logger::LOG_TEST, "Extracted node", $entry->nodeValue);

      //render nested nodes to html
      $inner_html = $this->getInnerHtml($entry);
      if (!$inner_html){
        //if there's no nested entrys, render the entry itself
        $inner_html = $entry->ownerDocument->saveXML($entry);
      }

      Feediron_Logger::get()->log_html(Feediron_Logger::LOG_TEST, "Node content: ", $inner_html);
      array_push($htmlout, $inner_html);
    }

    return $htmlout;

/*    $html_content = join((array_key_exists('join_element', $config)?$config['join_element']:''), $htmlout);
    if(array_key_exists('start_element', $config)){
      Feediron_Logger::get()->log_html(Feediron_Logger::LOG_VERBOSE, "Adding start element", $config['start_element']);
      $html_content = $config['start_element'].$html_content;
    }

    if(array_key_exists('end_element', $config)){
      Feediron_Logger::get()->log_html(Feediron_Logger::LOG_VERBOSE, "Adding end element", $config['end_element']);
      $html_content = $html_content.$config['end_element'];
    }*/

  }

  private function getInnerHtml( $node ) {
    $innerHTML= '';
    $children = $node->childNodes;

    foreach ($children as $child) {
      $innerHTML .= $child->ownerDocument->saveXML( $child );
    }

    return $innerHTML;
  }

}

