# Modified FeedIron TT-RSS Plugin
Original: [FeedIron](https://github.com/feediron/ttrss_plugin-feediron)

About |Table Of Contents
 :---- | --------
This is a plugin for [Tiny Tiny RSS (tt-rss)](https://tt-rss.org/).<br>It allows you to replace an article's contents by the contents of an element on the linked URL's page<br><br>i.e. create a "full feed".<br><br>|<ul><li>[Installation](#installation)</li><li>[Configuration tab](#configuration-tab)</li><ul><li>[Usage](#usage)</li><li>[Filters](#filters)</li><li>[General Options](#general-options)</li><li>[Global Options](#global-options)</li></ul><li>[Testing Tab](#testing-tab)</li><li>[Full configuration example](#full-configuration-example)</li></ul>

## Installation

Clone the repo into your `plugins.local` directory. You can find more instructions on how to find this directory [here](https://git.tt-rss.org/fox/ttrss-docker-compose/wiki#how-do-i-add-plugins-and-themes).

- Config
    - File format: json, yml, ini
    - locally stored, can be extended by pulling from external source (git repos)
    - each root level entry is a label that will be treated as a regex pattern to match article links against
        - wildcards are allowed here
- Inputs
    - xpath
        - returns content based on the submitted xpath
        - can be used as is with a single standard xpath declaration or extended by using additional modifier fields
        - modifiers:
    - regex
        - returns content based on the submitted regex (PCRE)
        - default is to return only exact match, can be modified to return the entire line match or multi-line match
        - modifiers:
            - match:
                - exact (default)
                - line
                - multiline
- Actions
    - unless otherwise specified, input for an action will be either xpath or regex in form defined in the Inputs section and multiple inputs of varied types are accepted
    - join
        - joins together any content passed to it
        - input can also be a generic string (declared with "string" field)
        - modifiers:
            - delimiter - optional, default none, string representation of what to join all inputs with, can be a single literal character, set of literal characters, or regex
    - split
        - splits apart any content passed to it
        - input can also be a generic string (declared with "string" field)
        - modifiers:
            - delimiter - optional, default none, string representation of what to join all inputs with, can be a single literal character, set of literal characters, or regex
    - modify
        - modifies the article html, in-place modification which means that any actions after this one will be on modified content and not original
        - can be used to insert, alter, or remove content
        - modifiers
            - search - required, can be regex, xpath, or string literal
            - replace - optional, default will be empty, which will be assumed to mean remove matched content
    - add-tag
        - add tag(s)
        - values can be a string/ literal to add, or xpath/regex to search upon
        - modifiers:
            - replace-tags - optional, default false, will overwrite existing tags if true
    - remove-tag
        - remove tag(s)
        - values can be a string/ literal to add, or xpath/regex to search upon
        - modifiers:
            - all - optional, default false, will remove all tags currently assigned to article
    - modify-tag
        - modify tag(s) according to a string literal or regex input
- Useful Links For Dev
    - Dojo: <https://dojotoolkit.org/documentation/>
    - xpath
        - <https://www.w3schools.com/xml/xpath_syntax.asp>
        - <https://www.tutorialspoint.com/xpath/xpath_quick_guide.htm>
        - <https://en.wikipedia.org/wiki/XPath>

