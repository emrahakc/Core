<?php

////////////////////////////////////////////////////////////////////////////////
//                                                                            //
//   Copyright (C) 2007  Phorum Development Team                              //
//   http://www.phorum.org                                                    //
//                                                                            //
//   This program is free software. You can redistribute it and/or modify     //
//   it under the terms of either the current Phorum License (viewable at     //
//   phorum.org) or the Phorum License that was distributed with this file    //
//                                                                            //
//   This program is distributed in the hope that it will be useful,          //
//   but WITHOUT ANY WARRANTY, without even the implied warranty of           //
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.                     //
//                                                                            //
//   You should have received a copy of the Phorum License                    //
//   along with this program.                                                 //
////////////////////////////////////////////////////////////////////////////////
//                                                                            //
//   Author: Maurice Makaay <maurice@phorum.org>                              //
//   Initial development of this message pruning interface was                //
//   generously sponsored by Juan Antonio Ruiz Zwollo.                        //
//                                                                            //
////////////////////////////////////////////////////////////////////////////////

if(!defined("PHORUM_ADMIN")) return;

define("ADMIN_MODULE", "message_prune");

require_once("./include/format_functions.php");

// ----------------------------------------------------------------------
// Possible filter rules description
// ----------------------------------------------------------------------

// Build the match list for the forums.
$folder_list=phorum_get_folder_info();

$ruledefs = array
(
    "body" => array(
        "label"         => "Message body",
        "matches"       => array(
            "contains"            => "message.body  = *QUERY*",
            "does not contain"    => "message.body != *QUERY*",
        ),
        "queryfield"    => "string"
    ),

    "subject" => array(
        "label"         => "Message subject",
        "matches"       => array(
            "is"                  => "message.subject  = QUERY",
            "is not"              => "message.subject != QUERY",
            "contains"            => "message.subject  = *QUERY*",
            "does not contain"    => "message.subject != *QUERY*",
        ),
        "queryfield"    => "string"
    ),

    "username" => array(
        "label"         => "Message username",
        "matches"       => array(
            "is"                  => "user.username  = QUERY",
            "is not"              => "user.username != QUERY",
            "starts with"         => "user.username  = QUERY*",
            "does not start with" => "user.username != QUERY*",
            "ends with"           => "user.username  = *QUERY",
            "does not end with"   => "user.username != *QUERY",
        ),
        "queryfield"    => "string"
    ),

    "author" => array(
        "label"         => "Message author name",
        "matches"       => array(
            "is"                  => "message.author  = QUERY",
            "is not"              => "message.author != QUERY",
            "contains"            => "message.author  = *QUERY*",
            "does not contain"    => "message.author != *QUERY*",
            "starts with"         => "message.author  = QUERY*",
            "does not start with" => "message.author != QUERY*",
            "ends with"           => "message.author  = *QUERY",
            "does not end with"   => "message.author != QUERY*",
        ),
        "queryfield"    => "string"
    ),

    "date" => array(
        "label"         => "Message date",
        "matches"       => array(
            "posted on"           => "function:prepare_filter_date",
            "posted on or before" => "function:prepare_filter_date",
            "posted before"       => "function:prepare_filter_date",
            "posted after"        => "function:prepare_filter_date",
            "posted on or after"  => "function:prepare_filter_date",
        ),
        "queryfield"    => "date"
    ),

    "ipaddress" => array(
        "label"         => "Author IP/hostname",
        "matches"       => array(
            "is"                  => "message.ip  = QUERY",
            "is not"              => "message.ip != QUERY",
            "starts with"         => "message.ip  = QUERY*",
            "does not start with" => "message.ip != QUERY*",
            "ends with"           => "message.ip  = *QUERY",
            "does not end with"   => "message.ip != *QUERY",
        ),
        "queryfield"    => "string"
    ),

    "status" => array(
        "label"         => "Message status",
        "matches"       => array(
            "approved"
                              => "message.status = ".PHORUM_STATUS_APPROVED,
            "on hold (waiting for approval)"
                              => "message.status = ".PHORUM_STATUS_HOLD, 
            "disapproved by moderator"
                              => "message.status = ".PHORUM_STATUS_HIDDEN,
            "hidden (on hold or disapproved)"
                              => "message.status != ".PHORUM_STATUS_APPROVED,
        ),
    ),

    "threadstate" => array(
        "label"         => "Thread status",
        "matches"       => array(
            "open for posting"    => "thread.closed = 0",
            "closed for posting"  => "thread.closed = 1",
        ),
    ),

    "messagetype" => array(
        "label"         => "Message type",
        "matches"       => array(
            "thread starting messages"
                                  => "message.parent_id  = 0",
            "reply messages"      => "message.parent_id != 0",
        )
    ),
);

// ----------------------------------------------------------------------
// Handle a posted form
// ----------------------------------------------------------------------

$messages   = null;        // selected messages (based on a filter)
$filters    = array();     // active filters
$filtermode = "and";       // active filter mode (and / or)

// If a filterdesc field is in the post data, then query the database
// based on this filterdesc. The results will be shown later on,
// below the filter form.
if (count($_POST) && isset($_POST["filterdesc"]))
{
    // The filter rules are separated by "&" or "|" based on
    // respectively an "AND" or an "OR" query.
    $split = preg_split(
        '/([&|])/',
        $_POST["filterdesc"],
        -1, PREG_SPLIT_DELIM_CAPTURE
    );

    // The $split array should now contain an alternating list of
    // rules and AND/OR specifications. Walk over the list and
    // try to construct a metaquery to find messages based on
    // this filter.

    $meta = array();

    foreach ($split as $index => $spec)
    {
        // Even indexes contain a rule.
        if ($index % 2 == 0) {
            if (preg_match('/^(.*),(.*),(.*)$/', $spec, $m)) {
                $field = rawurldecode($m[1]);
                $match = rawurldecode($m[2]);
                $query = rawurldecode($m[3]);
                if (isset($ruledefs[$field]) && 
                    isset($ruledefs[$field]["matches"][$match]))
                {
                    $condition = $ruledefs[$field]["matches"][$match];

                    // Use a custom function for filling the metaquery.
                    if (substr($condition, 0, 9) == "function:"){
                        $func = substr($condition, 9);  
                        if (!function_exists($func)) {
                            die("Internal error: filter function \"" .
                                htmlspecialchars($func) . "\" from the match ".
                                "specification for \"" .
                                htmlspecialchars($field) . "/" .
                                htmlspecialchars($match) . 
                                "\" does not exist.");
                        } else {
                            $meta = call_user_func($func,$meta,$match,$query); 
                        }
                    }
                    // Standard metaquery addition. 
                    else {
                        $meta[] = array(
                            "condition" => $condition,
                            "query"     => $query
                        );
                    }

                    // For rebuilding the filter form.
                    $filter = array($field, $match, $query);
                    $filters[] = $filter;

                    continue;
                }
            }
        }
        // Uneven indexes contain the AND/OR spec.
        else {
            if     ($spec == '&') {$meta[]="AND"; $filtermode="and"; continue;}
            elseif ($spec == '|') {$meta[]="OR" ; $filtermode="or" ; continue;}
        }

        die('Internal error: illegal filter specification (' .
            'unexpected token "'.htmlspecialchars($spec).'")');
    }

    // Let the database layer turn the metaquery into a real query 
    // and run it against the database.
    $messages = phorum_db_metaquery_messagesearch($meta);
    if ($messages === NULL) {
        phorum_admin_error("Internal error: failed to run a message search");
    } else {
        phorum_admin_okmsg("Found " . count($messages) . " message(s)");
    }
}

// Custom filter preparation for the "date" filter.
function prepare_filter_date($meta, $match, $query)
{
    $start_of_day = null;
    $end_of_day = null;

    $query = trim($query);
    if (preg_match('/^(\d\d\d\d)\D(\d\d?)\D(\d\d?)$/', $query, $m)) {
        $dy = $m[1]; $dm = $m[2]; $dd = $m[3];
        if ($dm >= 1 && $dm <= 31 && $dm >= 1 && $dm <= 12) {
            // Okay, we've got a possibly valid date. 
            $start_of_day = mktime(0,0,0,$dm,$dd,$dy); 
            $end_of_day   = mktime(23,59,59,$dm,$dd,$dy); 
        }
    }

    if ($start_of_day !== null)
    {
        if ($match == "posted on") {
            $meta[] = "(";
            $meta[] = array(
                "condition" => "message.datestamp >= QUERY",
                "query"     => $start_of_day
            );
            $meta[] = "AND";
            $meta[] = array(
                "condition" => "message.datestamp <= QUERY",
                "query"     => $end_of_day
            );
            $meta[] = ")";
        }
        elseif ($match == "posted on or before") {
            $meta[] = array(
                "condition" => "message.datestamp <= QUERY",
                "query"     => $end_of_day
            );
        }
        elseif ($match == "posted before") {
            $meta[] = array(
                "condition" => "message.datestamp < QUERY",
                "query"     => $end_of_day
            );
        }
        elseif ($match == "posted after") {
            $meta[] = array(
                "condition" => "message.datestamp > QUERY",
                "query"     => $end_of_day
            );
        }
        elseif ($match == "posted on or after") {
            $meta[] = array(
                "condition" => "message.datestamp >= QUERY",
                "query"     => $start_of_day
            );
        }
        else {
            die("prepare_filter_date(): illegal match \"" .
                htmlspecialchars($match) . "\"");
        }
    }
    else
    {
        // We have to insert a condition to not disturb the query.
        // We'll add a condition that will never match.
        $meta[] = array(
            "condition" => "message.datestamp = 0",
            "query"     => null,
        );
    }

    return $meta;
}

// ----------------------------------------------------------------------
// Javascript representation of the possible filter rules description
// ----------------------------------------------------------------------
?>
<script type="text/javascript">
//<![CDATA[

// The available filters and their configuration.
var ruledefs = {
<?php
$count = count($ruledefs);
foreach($ruledefs as $filter => $def) { 
    $count--;
    print "  '$filter':{\n" .
          "    'label':'{$def["label"]}',\n" .
          "    'queryfield':" . 
               (isset($def["queryfield"])?"'{$def["queryfield"]}'":"null") .
               ",\n" .
          "    'matches':{\n";
    $mcount = count($def["matches"]); 
    $idx = 0;
    foreach ($def["matches"] as $k => $v) {
        print "      '$idx':'$k'" . (--$mcount ? ",\n" : "\n");
        $idx ++;
    }
    print "    }\n" .
          "  }" .
          ($count ? "," : "") . "\n";
} ?>
};
//]]>
</script>

<?php
// ----------------------------------------------------------------------
// Display the filter form
// ----------------------------------------------------------------------
?>

<div class="input-form-td-break">
  Select messages to delete
</div>
<div class="input-form-td-message">
  ATTENTION!<br/>
  This script can delete A LOT of messages at once. So be careful 
  which messages you select for deleting. Use it at your own risk.
  If you do not feel comfortable with this, please make a good
  database backup before deleting any messages.<br/>
  <br/>
  The first step in deleting messages, is setting up a set of filters,
  which select the messages that you want to delete. Please add these
  filters below. After you are done, click the "Show selected messages"
  button to preview what messages you will be deleting.<br/>
  <br/>
  <form id="filterform" method="post" action="<?php $_SERVER["PHP_SELF"] ?>"
        onsubmit="filter.getFilterDescription()">
  <input type="hidden" name="module" value="<?php print ADMIN_MODULE ?>" />
  <input type="hidden" name="filterdesc" id="filterdesc" value="" />
  <div style="margin-bottom: 5px">
    <input id="filtermode_and" type="radio" checked="checked" name="filtermode" value="and">
      <label for="filtermode_and">Match all of the following</label>
    </input>
    <input id="filtermode_or" type="radio" name="filtermode" value="or">
      <label for="filtermode_or">Match any of the following</label>
    </input>
  </div>
  <table style="
      width: 95%;
      margin-bottom: 5px;
      border-collapse: collapse;
      background-color: #f0f0f0;
      border: 1px solid #ccc">
    <tbody id="ruleset">
      <!-- only used for pushing the query field cell 100% wide -->
      <tr>
        <th></th>
        <th></th>
        <th style="width: 100%"></th>
        <th></th>
        <th></th>
      </tr>
      <!-- filter rules will be added dynamically in this table -->
    </tbody>
  </table>
  <input type="submit" value="Show selected messages" />
  </form>

</div>

<?php
// ----------------------------------------------------------------------
// Javascript filter form implementation
// ----------------------------------------------------------------------
?>

<script type="text/javascript">
//<![CDATA[

// Class PhorumFilterRule
// This class describes a single Phorum filter rule.
function PhorumFilterRule(conf)
{
    // Check if we have all required config information.
    if (conf == null) {
        throw("Illegal call of PhorumFilterRule(): no config set");
        return;
    }
    if (conf.parent == null) {
        throw("Illegal call of PhorumFilterRule(): no parent in the config");
        return;
    }
    if (conf.index == null) {
        throw("Illegal call of PhorumFilterRule(): no index in the config");
        return;
    }

    // Object properties -------------------------------------------------

    // Information relating to the PhorumFilter object which created this rule.
    this.parent = conf.parent;
    this.index  = conf.index;

    // The properties that represent the rule state.
    this.field = (conf.field ? conf.field : 'body');
    this.match = (conf.match ? conf.match : 'contains');
    this.query = (conf.query ? conf.query : '');
    this.query_input_type = null;

    // Object methods ----------------------------------------------------

    // Method for handling actions after selecting a rule field.
    this.onSelectFieldChange = function()
    {
        var idx;

        // Store the current rule state.
        idx = this.field_input.selectedIndex;
        this.field = this.field_input.options[idx].value;

        // Populate the match_input selection.
        for (idx=this.match_input.options.length; idx>=0; idx--) {
            this.match_input.options[idx] = null;
        }
        for (var id in ruledefs[this.field].matches) {
            var o = document.createElement('option');
            o.value = ruledefs[this.field].matches[id];
            o.innerHTML = o.value;
            if (o.value == this.match) o.selected = true; 
            this.match_input.appendChild(o);
        }

        // Clean up the current query_input if we do not need a query
        // input or if we have to create a different type of query input.
        if (this.query_input_type == null || 
            (ruledefs[this.field].queryfield != null &&
             ruledefs[this.field].queryfield != this.query_input_type)) {
            if (this.query_input && this.query_input.calendar) {
                this.query_input.calendar = null;
            }
            if (this.query_input && this.query_input.helptext) {
                this.query_input_td.removeChild(this.query_input.helptext);
                this.query_input.helptext = null;
            }
            if (this.query_input) {
                this.query_input_td.removeChild(this.query_input);
                this.query_input = null;
                this.query_input_type = null;
            }
        }

        // If the rule type uses a query input, then we use a separate
        // table cell for that input. If we do not use that, we make
        // the match_input cell span two cells.
        if (ruledefs[this.field].queryfield == null)
        {
            // Take two cells for the match selection.
            this.match_input_td.colSpan = 2;

            // Remove the query input cell from the form.
            for (var i=0; i<this.container.childNodes.length; i++) {
                if (this.container.childNodes[i] == this.query_input_td) {
                    this.container.removeChild(this.query_input_td);
                }
            }
        }
        else
        {
            // Take one cell for the match selection.
            this.match_input_td.colSpan = 1;

            // Create a new query input if neccessary.
            if (this.query_input_type == null)
            {
                this.query_input = document.createElement('input');
                this.query_input.ruleobj = this;
                this.query_input.type = 'text';
                this.query_input.style.width='100%';
                this.query_input.value = this.query;

                this.query_input.onkeyup = function() {
                    this.ruleobj.onQueryInputChange(this.value);
                }
                this.query_input.onchange = function() {
                    this.ruleobj.onQueryInputChange(this.value);
                }

                this.query_input_type = ruledefs[this.field].queryfield;
            }

            // Add the query cell + input to the table.
            this.query_input_td.appendChild(this.query_input);
            this.container.insertBefore(
                this.query_input_td,
                this.del_button_td
            );

            // Extra options for dat fields.
            if (ruledefs[this.field].queryfield == 'date')
            {
                this.query_input_td.style.whiteSpace = 'nowrap';

                this.query_input.style.width = '90px';
                this.query_input.style.paddingLeft = '3px';
                this.query_input.style.fontSize = '11px';
                this.query_input.maxLength = 10;
                this.query_input.style.marginRight = '6px';

                this.query_input.helptext=document.createTextNode("yyyy/mm/dd");
                this.query_input_td.style.fontSize = '11px';
                this.query_input_td.appendChild(this.query_input.helptext);
            }
        }

        // Delegate further handling to onSelectMatchChange().
        this.onSelectMatchChange();
    }

    // Method for handling actions after selecting a rule match.
    this.onSelectMatchChange = function()
    {
        var idx;

        // Store the current rule state.
        idx = this.match_input.selectedIndex;
        this.match = this.match_input.options[idx].value;
    }

    // Method for handling actions after changing the query input.
    this.onQueryInputChange = function(data) {
        this.query = data;
    }

    // Method for destroying a rule object.
    this.destroy = function()
    {
        this.field_input_td.removeChild(this.field_input);
        this.container.removeChild(this.field_input_td);
        this.match_input_td.removeChild(this.match_input);
        this.container.removeChild(this.match_input_td);
        if (this.query_input) {
            this.query_input_td.removeChild(this.query_input);
        }
        for (var i=0; i<this.container.childNodes.length; i++) {
            if (this.container.childNodes[i] == this.query_input_td) {
                this.container.removeChild(this.query_input_td);
            }
        }
        this.add_button_td.removeChild(this.add_button);
        this.container.removeChild(this.add_button_td);
        this.del_button_td.removeChild(this.del_button);
        this.container.removeChild(this.del_button_td);

        this.add_button = null;
        this.add_button_td = null;
        this.del_button = null;
        this.del_button_td = null;
        this.field_input = null;
        this.field_input_td = null;
        this.match_input = null;
        this.match_input_td = null;
        this.query_input = null;
        this.query_input_td = null;
        this.container = null;

        this.type = null;
        this.match = null;
        this.query = null;
    }

    // Build the interface -----------------------------------------------

    // Create a new table row for holding the filter rule.
    this.container = document.createElement('tr');
        this.container.style.borderBottom = '1px dashed #ccc';

    // The field on which to match.
    this.field_input = document.createElement('select'); 
        this.field_input.ruleobj = this;
        this.field_input.onchange = function() {
            this.ruleobj.onSelectFieldChange();
        }

    // The type of match to use.
    this.match_input = document.createElement('select');
        this.match_input.ruleobj = this;
        this.match_input.onchange = function() {
            this.ruleobj.onSelectMatchChange();
        }

    // Button for adding a filter.
    this.add_button = document.createElement('img');
        this.add_button.src = '<?php print $PHORUM["http_path"] ?>/images/add.png';  
        this.add_button.style.cursor = 'pointer';
        this.add_button.ruleobj = this;
        this.add_button.onclick = function() {
            this.ruleobj.parent.addFilterRule();
        }

    // Button for deleting a filter.
    this.del_button = document.createElement('img');
        this.del_button.src = '<?php print $PHORUM["http_path"] ?>/images/delete.png';  
        this.del_button.style.cursor = 'pointer';
        this.del_button.ruleobj = this;
        this.del_button.onclick = function() {
            this.ruleobj.parent.deleteFilterRule(this.ruleobj);
        }

    // Add cells to the table row.
    this.field_input_td = document.createElement('td');
        this.field_input_td.style.padding= '5px';
        this.field_input_td.appendChild(this.field_input);
        this.container.appendChild(this.field_input_td);
    this.match_input_td = document.createElement('td');
        this.match_input_td.colspan = 2;
        this.match_input_td.style.padding = '5px';
        this.match_input_td.appendChild(this.match_input);
        this.container.appendChild(this.match_input_td);
    // Will be filled and displayed when neccessary (based on fiele type).
    this.query_input_td = document.createElement('td');
        this.query_input_td.style.padding = '5px';
    this.del_button_td = document.createElement('td');
        this.del_button_td.style.padding = '5px 2px';
        this.del_button_td.appendChild(this.del_button);
        this.container.appendChild(this.del_button_td);
    this.add_button_td = document.createElement('td');
        this.add_button_td.style.padding = '5px 5px 5px 2px';
        this.add_button_td.appendChild(this.add_button);
        this.container.appendChild(this.add_button_td);

    // Populate the field_input selection.
    for (var id in ruledefs) {
        var o = document.createElement('option');
        o.innerHTML = ruledefs[id]["label"];
        o.value     = id;
        if (o.value == this.field) o.selected = true; 
        this.field_input.appendChild(o);
    }

    // Create match select list and possibly a query
    // input by faking a filter field selection event.
    this.onSelectFieldChange();
}

// Class PhorumFilter
// This class describes a set of Phorum filter rules.
function PhorumFilter(conf)
{
    // Check if we have all required config information.
    if (conf == null) {
        throw("Illegal call of PhorumFilter(): no config set");
        return;
    }
    if (conf.parent == null) {
        throw("Illegal call of PhorumFilter(): no parent in the config");
        return;
    }

    // Object properties -------------------------------------------------

    this.rules = new Array();
    this.rulecount = 0;
    this.index = 0;
    this.parent = conf.parent;

    // Object methods ----------------------------------------------------

    this.addFilterRule = function(conf)
    {
        // Create a PhorumFilterRule object.
        if (conf == null) conf = {};
        conf.parent = this;
        conf.index  = this.index++;
        var ruleobj = new PhorumFilterRule(conf);

        // Add the rule to the filter.
        this.parent.appendChild(ruleobj.container);
        this.rules[ruleobj.index] = ruleobj;

        this.rulecount ++;
    }

    this.deleteFilterRule = function(ruleobj)
    {
        // Do not delete the last rule.
        if (this.rulecount == 1) return;

        // Delete the rule from the filter.
        this.parent.removeChild(ruleobj.container);
        this.rules[ruleobj.index] = null;
        ruleobj.destroy();

        this.rulecount --;
    }

    // Construct a textual description of the filter.
    this.getFilterDescription = function()
    {
        var filterdesc = '';

        // Determine the glue symbol to use.
        // & for AND matches, | for OR matches
        var glue = document.getElementById('filtermode_or').checked?'|':'&';
       
        // Walk over all available filters and create a 
        // textual filter config line for them.
        for (var i = 0 ; i < this.index; i++) {
            if (this.rules[i] == null) continue;
            var rule = this.rules[i];
            if (filterdesc != '') filterdesc += glue;
            filterdesc += escape(rule.field) + "," + 
                          escape(rule.match) + "," + 
                          escape(rule.query);
        }

        document.getElementById('filterdesc').value = filterdesc;
        document.getElementById('filterform').submit();
    }
}

// Create the filter object.
var filter = new PhorumFilter({
    "parent": document.getElementById("ruleset")
});

// Add filter rules.
<?php
if (count($filters)) {
    foreach ($filters as $filter) {
        print "filter.addFilterRule({\n" .
              "    'field': '".addslashes($filter[0])."',\n" .
              "    'match': '".addslashes($filter[1])."',\n" .
              "    'query': '".addslashes($filter[2])."'\n" .
              "});\n";
    }
} else {
    print "filter.addFilterRule();\n";
}
?>

//]]>
</script>

<?php
// ----------------------------------------------------------------------
// Show selected messages.
// ----------------------------------------------------------------------

if (isset($messages) && is_array($messages)) {
?>
    <div class="input-form-td-break" style="margin-bottom: 10px">
      Overview of selected messages 
    </div>
<?php

    foreach ($messages as $id => $data) {
        print "$id : {$data["subject"]} ";
        print phorum_date("%Y/%m/%d", $data["datestamp"]);
        print "<br/>";
    }
}
?>



