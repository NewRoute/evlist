<?php
// +--------------------------------------------------------------------------+
// | evList A calendar solution for glFusion                                  |
// +--------------------------------------------------------------------------+
// | evlist_functions.inc.php                                                 |
// |                                                                          |
// | Misc. plugin-specific functions                                          |
// +--------------------------------------------------------------------------+
// | Copyright (C) 2008 by the following authors:                             |
// |                                                                          |
// | Mark R. Evans          mark AT glfusion DOT org                          |
// |                                                                          |
// | Based on the evList Plugin for Geeklog CMS                               |
// | Copyright (C) 2007 by the following authors:                             |
// |                                                                          |
// | Authors: Alford Deeley     - ajdeeley AT summitpages.ca                  |
// +--------------------------------------------------------------------------+
// |                                                                          |
// | This program is free software; you can redistribute it and/or            |
// | modify it under the terms of the GNU General Public License              |
// | as published by the Free Software Foundation; either version 2           |
// | of the License, or (at your option) any later version.                   |
// |                                                                          |
// | This program is distributed in the hope that it will be useful,          |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the            |
// | GNU General Public License for more details.                             |
// |                                                                          |
// | You should have received a copy of the GNU General Public License        |
// | along with this program; if not, write to the Free Software Foundation,  |
// | Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.          |
// |                                                                          |
// +--------------------------------------------------------------------------+

/**
*   Plugin-specific functions for the EvList plugin
*
*   @author     Lee P. Garner <lee@leegarner.com
*   @copyright  Copyright (c) 2008 - 2010 Mark R. Evans mark AT glfusion DOT org
*   @package    evlist
*   @version    1.3.0
*   @license    http://opensource.org/licenses/gpl-2.0.php
*               GNU Public License v2 or later
*   @filesource
*/


USES_lglib_class_datecalc();

/**
*   Get the Google-style page navigation for the list display
*
*   @param  string  $start  Starting date
*   @param  string  $end    Ending date
*   @param  integer $cat    Category ID (optional)
*   @param  integer $page   Current page number
*   @param  integer $range  Range being displayed (upcoming, past, etc)
*   @return string          HTML for page navigation
*/
function EVLIST_pagenav($start, $end, $cat=0, $page = 0, $range = 0, $cal = 0)
{
    global $_TABLES, $_EV_CONF;

    $cat = (int)$cat;
    $range = (int)$range;
    $cal = (int)$cal;
    $limit = (int)$_EV_CONF['limit_list'];
    if ($limit < 1) return '';

    $base_url = EVLIST_URL.
        "/index.php?cat=$cat&amp;cal=$cal&amp;range=$range&amp;view=list";

    if (!empty($cat)) {
        $cat_join = " LEFT JOIN {$_TABLES['evlist_lookup']} l
                    ON l.eid = ev.id ";
        $cat_where = " AND l.cid = '$cat' ";
    } else {
        $cat_join = '';
        $cat_where = '';
    }

    if ($cal> 0) {
        $cal_where = ' AND cal.cal_id = ' . $cal;
    } else {
        $cal_where = '';
    }

    $sql = "SELECT count(rep.rp_id) as cnt
            FROM {$_TABLES['evlist_repeat']} rep
            LEFT JOIN {$_TABLES['evlist_events']} ev
                ON ev.id = rep.rp_ev_id
            LEFT JOIN {$_TABLES['evlist_calendars']} cal
                ON cal.cal_id = ev.cal_id
            $cat_join
            WHERE ev.status = 1 
            AND (
                (rep.rp_date_start <= '$end' AND rep.rp_date_end >= '$start')
                OR
                (rep.rp_date_end >= '$start' AND rep.rp_date_start <= '$start')
                OR
                (rep.rp_date_end <= '$end' AND rep.rp_date_start >= '$start')
            ) " . 
            COM_getPermSQL('AND', 0, 2, 'ev') . ' ' .
            COM_getPermSQL('AND', 0, 2, 'cal') . 
            " $cat_where $cal_where
            ORDER BY rep.rp_date_start ASC";
    //echo $sql;die;
    $res = DB_query($sql);
    list($numrows) = DB_fetchArray($res);

    if ($numrows > $limit) {
        $numpages = ceil($numrows / $limit);
        //$baseurl = EVLIST_URL . '/index.php?' . $range . $andcat;

        $retval = COM_printPageNavigation($base_url, $page, $numpages);
    }

    return $retval;
}


/**
*   Display a small monthly calendar for the current month.
*   Dates that have events scheduled are highlighted.
*
*   @param  integer $year   Year to display, default is current year
*   @param  integer $month  Starting month
*   @return string          HTML for calendar page
*/
function EVLIST_smallmonth($year=0, $month=0, $opts=array())
{
    global $_CONF, $_EV_CONF, $LANG_MONTH, $_SYSTEM;

    $retval = '';

    // Default to the current year
    if ($year == 0) $year = date('Y');
    if ($month == 0) $month = date('m');
    $monthnum_str = sprintf("%02d", (int)$month);

    // Get all the dates in the period
    $starting_date = date('Y-m-d', mktime(0, 0, 0, $month, 1, $year));
    $ending_date = date('Y-m-d', mktime(23, 59, 59, $month, 31, $year));
    $calendarView = Date_Calc::getCalendarMonth($month, $year, '%Y-%m-%d');
    $events = EVLIST_getEvents($starting_date, $ending_date, $opts);

    $T = new Template(EVLIST_PI_PATH . '/templates');
    $T->set_file(array(
        'smallmonth'  => 'phpblock_month.thtml',
    ) );

    $T->set_var('thisyear', $year);
    $T->set_var('month', $month);
    $T->set_var('monthname', $LANG_MONTH[(int)$month]);

    // Set each day column header to the first letter of the day name
    $T->set_block('smallmonth', 'daynames', 'nBlock');
    $daynames = EVLIST_getDayNames(1);
    foreach ($daynames as $key=>$dayname) {
        $T->set_var('dayname', $dayname);
        $T->parse('nBlock', 'daynames', true);
    }

    $T->set_block('smallmonth', 'week', 'wBlock');

    USES_class_date();
    $dt = new Date('now', $_CONF['timezone']);

    foreach ($calendarView as $weeknum => $weekdata) {
        list($weekYear, $weekMonth, $weekDay) = explode('-', $weekdata[0]);
        $T->set_var(array(
                    'weekyear'  => $weekYear,
                    'weekmonth' => $weekMonth,
                    'weekday'   => $weekDay,
        ) );
        $T->set_block('smallmonth', 'day', 'dBlock');
        foreach ($weekdata as $daynum => $daydata) {
            list($y, $m, $d) = explode('-', $daydata);
            $T->clear_var('no_day_link');
            if ($daydata == $_EV_CONF['_today']) {
                $dayclass = 'monthtoday';
            } elseif ($m == $monthnum_str) {
                $dayclass = 'monthon';
            } else {
                $T->set_var('no_day_link', 'true');
                $dayclass = 'monthoff';
            }
            $popup = '';
            if (isset($events[$daydata])) {
                // Create the tooltip hover text
                $daylinkclass = $dayclass == 'monthoff' ?
                                'nolink-events' : 'day-events';
                foreach ($events[$daydata] as $event) {
                    // Show event titles on different lines if more than one
                    if (!empty($popup)) $popup .= EVLIST_tooltip_newline();
                    // Don't show a time for all-day events
                    if ($event['allday'] == 0 && 
                            $event['rp_date_start'] == $event['rp_date_end']) {
                        $dt->setTimestamp(strtotime($event['rp_date_start'] . 
                                ' ' . $event['rp_time_start1']));
                        // Time is a localized string, not a timestamp, so 
                        // don't adjust for the timezone
                        $popup .= $dt->format($_CONF['timeonly'], false) . ': ';
                    }
                    $popup .= htmlentities($event['title']);
                }
                $T->set_var('popup', $popup);
            } else {
                $daylinkclass = 'day-noevents';
                $T->clear_var('popup');
            }
            $T->set_var(array(
                'daylinkclass'      => $daylinkclass,
                'dayclass'          => $dayclass,
                'day'               => substr($daydata, 8, 2),
                'pi_url'            => EVLIST_URL,
            ) );
            $T->parse('dBlock', 'day', true);
        }
        $T->parse('wBlock', 'week', true);
        $T->clear_var('dBlock');
    }

    $T->parse('output', 'smallmonth');

    return $T->finish($T->get_var('output'));
}


/**
*   Get an array of option lists for year, month, day, etc.
*
*   @param  string  $prefix     Prefix to use for ampm variable name
*   @param  string  $curdate    SQL-formatted date to use as default
*   @param  string  $curtime    SQL-formatted time to use as default
*   @return array               Array of option lists, indexed by type
*/
function EVLIST_TimeSelect($prefix, $curtime = '')
{
    global $_CONF;

    // Use "now" as the default if nothing else sent.  Also helps make sure
    // that the explode() function works right.
    if (empty($curtime)) {
        $curtime = date('H:i:s');
    }
    list($hour, $minute, $second) = explode(':', $curtime);

    // Set up the time if we're using 12-hour mode
    if ($_CONF['hour_mode'] == 12) {
        $ampm = $hour < 12 ? 'am' : 'pm';
        if ($hour == 0)
            $hour = 12;
        elseif ($hour > 12)
            $hour -= 12;
    }

    $hourselect     = COM_getHourFormOptions($hour, $_CONF['hour_mode']);
    $minuteselect   = COM_getMinuteFormOptions($minute, 15);

    // This function gets the entire selection, not just the <option> parts,
    // so we use $prefix to create the variable name.
    $ampm_select    = COM_getAmPmFormSelection($prefix . '_ampm', $ampm);

    return array(
            'hour'      => $hourselect,
            'minute'    => $minuteselect,
            'ampm'      => $ampm_select
    );

}


/**
*   Convert the hour from 12-hour time to 24-hour.
*   This is meant to convert incoming values from forms to 24-hour format.  If
*   the site uses 24-hour time, the form values should already be that way
*   (and there will be no am/pm indicator), so the hour is returned unchanged.
*
*   @param  integer $hour   Hour to check (0 - 23)
*   @param  string  $ampm   Either 'am' or 'pm'
*   @return integer         Hour after switching it to 24-hour time.
*/
function EVLIST_12to24($hour, $ampm='')
{
    global $_CONF;

    $hour = (int)$hour;

    if ($hour < 0 || $hour > 23) $hour = 0;
    if ($_CONF['hour_mode'] == 24) return $hour;

    if ($ampm == 'am' && $hour == 12) $hour = 0;
    if ($ampm == 'pm' && $hour < 12) $hour += 12;

    return $hour;
}


/**
*   Organizes events by hour, and separates all-day events.
*
*   @param  array   $events     Array of all events
*   @param  string  $today      Current date, YYYY-MM-DD.  Optional.
*   @return array               Array of 2 arrays, allday and hourly
*/
function EVLIST_getDayViewData($events, $today = '')
{
    global $_CONF;

    // If no date/time passed used current timestamp
    if (empty($today)) $today = $_EV_CONF['_today'];

    $hourlydata = array(
        0   => array(), 1   => array(), 2   => array(), 3   => array(),
        4   => array(), 5   => array(), 6   => array(), 7   => array(),
        8   => array(), 9   => array(), 10  => array(), 11  =>array(),
        12  => array(), 13  => array(), 14  => array(), 15  => array(),
        16  => array(), 17  => array(), 18  => array(), 19  => array(),
        20  => array(), 21  => array(), 22  => array(), 23  => array(),
    );
    $alldaydata = array();

    USES_class_date();

    // Events are keyed by hour, so read through each hour
    foreach ($events as $date=>$E) {
        // Now read each event contained in each hour
        foreach ($E as $id=>$A) {
            // remove serialized data, not needed for display and interferes
            // with json encoding.
            unset($A['rec_data']);
            unset($A['options']);

            if ($A['allday'] == 1 ||
                ( ($A['rp_date_start'] < $today) &&
                    ($A['rp_date_end'] > $today) )
            ) {
                // This is an allday event, or spans days
                $alldaydata[] = $A;
            } else {
                // This is an event with start/end times.  For non-recurring
                // events, see if it actually starts before or after today
                // and adjust the times accordingly.
                if ($A['rp_date_start'] < $today) {
                    list($hr, $min, $sec) = explode(':', $A['rp_time_start1']);
                    $hr = '00';
                    $A['rp_times_start1'] = implode(':', array($hr, $min, $sec));
                //} else {
                //    $starthour = date('G', strtotime($A['rp_date_start'] .
                //                    ' ' . $A['rp_time_start^1']));
                }
                if ($A['rp_date_end'] > $today) {
                    list($hr, $min, $sec) = explode(':', $A['rp_time_end1']);
                    $hr = '23';
                    $A['rp_times_end1'] = implode(':', array($hr, $min, $sec));
                //} else {
                //    $endhour = date('G', strtotime($A['rp_date_end'] .
                //                    ' ' . $A['rp_time_end1']));
                }
                $dtStart = new Date(strtotime($A['rp_date_start'] .
                                    ' ' . $A['rp_time_start1']));
                $dtEnd = new Date(strtotime($A['rp_date_end'] .
                                    ' ' . $A['rp_time_end1']));

                //if (date('i', strtotime($A['rp_date_end'] . ' ' . 
                //            $A['rp_time_end1'])) == '00') {
                //    $endhour = $endhour - 1;
                //}

                // Save the start & end times in separate variables.
                // This way we can add $A to a different hour if it's a split.
                //if (!isset($hourlydata[$starthour]))
                //    $hourlydata[$starthour] = array();
                // Set localized, formatted start and end time fields
                $starthour = $dtStart->format('G', false); // array index
                $time_start = $dtStart->format($_CONF['timeonly'], false);
                $time_end = $dtEnd->format($_CONF['timeonly'], false);
                $hourlydata[(int)$starthour][] = array(
                    'starthour'  => $starthour,
                    'time_start' => $time_start,
                    'time_end'   => $time_end,
                    'data'       => $A,
                );

                if ($A['split'] == 1 && 
                        $A['rp_time_end2'] > $A['rp_time_start2']) {
                    // This is a split event, second half occurs later today.
                    // Events spanning multiple days can't be split, so we
                    // know that the start and end times are on the same day.
                    //$starthour = date('G', strtotime($A['rp_date_start'] .
                    //                ' ' . $A['rp_time_start2']));
                    $dtStart->setTimestamp(strtotime($event['rp_date_start'] . 
                                ' ' . $event['rp_time_start2']));
                    $starthour = $dtStart->format('G', false);
                    $time_start = $dtStart->format($_CONF['timeonly'], false);
                    $dtEnd->setTimestamp(strtotime($event['rp_date_start'] . 
                                ' ' . $event['rp_time_end2']));
                    $time_end = $dtEnd->format($_CONF['timeonly'], false);
                    $hourlydata[(int)$starthour][] = array(
                        'starthour' => $starthour,
                        'time_start' => $time_start,
                        'time_end'   => $time_end,
                        'data'       => $A,
                    );

                }
            }
        }
    }

    return array($alldaydata, $hourlydata);
}


/**
* Return link to "delete event" image
*
* Note: Personal events can be deleted if the current user is the owner of the
*       calendar and has _read_ access to them.
*
* @param    array   $A      event permissions and id
* @param    string  $token  security token
* @return   string          link or empty string
* @TODO         This needs to bring up the javascript menu to delete the
*               event or just an instance
*/
function EVLIST_deleteImageLink($A, $token)
{
    global $_CONF, $LANG_ADMIN, $LANG_EVLIST;

    $retval = '';
    if (SEC_hasRights('evlist.admin') ||
        SEC_hasAccess($A['owner_id'], $A['group_id'], $A['perm_owner'],
                $A['perm_group'], $A['perm_members'], $A['perm_anon']) == 3) {

        $img = "<img data-uk-tooltip
            src=\"{$_CONF['layout_url']}/images/admin/delete.png\"
            alt=\"{$LANG_ADMIN['delete']}\"
            title=\"{$LANG_ADMIN['delete']}\"
            width=\"14\" height=\"14\"
            class=\"gl_mootip\"> ";

            $retval = COM_createLink($img, EVLIST_URL .
                    '/event.php?delrepeat=x&amp;rp_id=' . $A['rp_id'] . '&amp;'
                    . CSRF_TOKEN . '=' . $token,
                array('onclick'=>
                    "return confirm('{$LANG_EVLIST['conf_del_repeat']}');",
                    'title' => $LANG_ADMIN['delete'],
                ) );

    }

    return $retval;
}


/**
*   Display a formatted error message.
*
*   @param  string  $msg    Error message to display
*   @param  string  $type   Type of message, used for style and header
*   @param  string  $header Optional header text.  
*   @return string      Formatted error message
*/
function EVLIST_alertMessage($msg = '', $type = '', $header = '')
{
    global $LANG_EVLIST;

    // Require a valid message
    if ($msg == '')
        return '';

    return COM_showMessageText($msg, $header, true, $type);
}


/**
*   Create the calendar selection checkboxes to be shown in the javascript
*   dropdown.
*
*   @param  array       key=>name array of calendars
*   @return string      Input elements for each available calendar
*/
function EVLIST_cal_checkboxes($cals)
{
    $boxes = '';
    if (!is_array($cals)) return $boxes;

    asort($cals);
    if (!empty($cals)) {
        foreach ($cals as $key=>$cal) {
            $boxes .= '<div style="float:left;width:100%;' . 
                'color:' . $cal['fgcolor'] . '">
                <input checked="checked" type="checkbox" id="cal' . $key .
                '" onclick="SelectCal(this)">&nbsp;' . 
                $cal['cal_name'] . '</div><br >' . LB;
        }
    }
    return $boxes;
}


/**
*   Get the options for a select list.
*   Similar to COM_optionList, but expects a value=>name array of
*   elements, which will typically be from a language array.
*
*   @param  array   $options    value=>description array of elements
*   @param  mixed   $selected   Optional value to preselect
*   @param  integer $bias       Amount to add to each value
*   @return string          HTML for <option></option> elements
*/
function EVLIST_GetOptions($options, $selected = '', $bias=0)
{
    if (!is_array($options)) return '';

    $retval = '';
    $bias = (int)$bias;

    foreach ($options as $value=>$name) {
        $value += $bias;
        $retval .= '<option value="' . $value . '"';
        if ($value == $selected) {
                $retval .= ' selected="selected"';
        }
        $retval .= ">$name</option>" . LB;
    }

    return $retval;
}


/**
*   Get the ISO language.
*   This is to load the correct language for the calendar popup, so make 
*   sure a corresponding language file exists.  
*   Default to English if not found.
*
*   @return string      ISO language string, 'en' by default
*/
function EVLIST_getIsoLang()
{
    global $_CONF, $_EV_CONF;

    $iso_lang = $_CONF['iso_lang'];
    if (!is_file($_CONF['path_html'] . $_EV_CONF['pi_name'] . 
            '/js/calendar/lang/calendar-' . $iso_lang . '.js')) {
        $iso_lang = 'en';
    }
    return $iso_lang;
}


/**
*   Get the RSS feed links only
*
*   @return array   Array of links & titles
*/
function EVLIST_getFeedLinks()
{
    global $_EV_CONF, $_TABLES;

    $retval = array();

    if (COM_isAnonUser() && $_EV_CONF['allow_anon_view'] != 1)
        return $retval;

    // Get the feed info for configured feeds
    $result = DB_query("SELECT title, filename 
            FROM {$_TABLES['syndication']}
            WHERE type='" . DB_escapeString($_EV_CONF['pi_name']) . "'");

    if (DB_numRows($result) > 0) {
        $feed_url = SYND_getFeedUrl();
        while ($A = DB_fetchArray($result, false)) {
            $retval[] = array(
                'feed_title'   => $A['title'],
                'feed_url'     => $feed_url . $A['filename'],
            );
        }
    }
    return $retval;
}


/**
*   Get the feed subscription urls & icons.
*   This returns a ready-to-display set of icons for visitors
*   to subscribe to RSS feeds
*
*   @return string  HTML for icons
*/
function EVLIST_getFeedIcons()
{
    global $_CONF, $_EV_CONF, $_TABLES;

    $retval = '';

    // Anon access required for feed access anyway
    if ($_EV_CONF['allow_anon_view'] != 1)
        return $retval;

    // Get the feed info for configured feeds
    $result = DB_query("SELECT title, filename 
            FROM {$_TABLES['syndication']}
            WHERE type='" . DB_escapeString($_EV_CONF['pi_name']) . "'");

    if (DB_numRows($result) > 0) {
        $T = new Template(EVLIST_PI_PATH . '/templates/');
        $T->set_file('feed', 'rss_icon.thtml');
        $feed_url = SYND_getFeedUrl();
        while ($A = DB_fetchArray($result, false)) {
            $T->set_var(array(
                'feed_title'    => $A['title'],
                'feed_url'     => $feed_url . $A['filename'],
            ) );
            $T->parse('output', 'feed', true);
        }
        $retval = $T->finish($T->get_var('output'));
    }
    return $retval;
}


/**
*   Administer user registrations.
*   This will appear in the admin area for administrators, and as part of
*   the event detail for event owners.  Owners can delete registrations.
*
*   @param  integer $rp_id      Repeat ID being viewed or checked
*   @return string              HTML for admin list
*/
function EVLIST_adminRSVP($rp_id)
{
    global $LANG_EVLIST, $LANG_ADMIN, $_TABLES, $_CONF, $_IMAGE_TYPE;
/*
+-------------------+----------+-------------------+-------+-------+-------+-----+------+------+
| tic_id            | tic_type | ev_id             | rp_id | fee   | paid  | uid | used | dt   |
+-------------------+----------+-------------------+-------+-------+-------+-----+------+------+
| 20150209083155975 |        1 | 20150209081055236 |  7552 | 15.00 | 15.00 |   3 |    0 |    0 |
+-------------------+----------+-------------------+-------+-------+-------+-----+------+------+
1 row in set (0.00 sec)
*/
    USES_lib_admin();
    USES_evlist_class_repeat();
    $Ev = new evRepeat($rp_id);
    if ($Ev->rp_id == 0) return '';

    DB_query("SET @tk_count = 0;");
    $sql = "SELECT @tk_count := @tk_count +1 as tic_count,
                    tk.dt, tk.tic_id, tk.tic_type, tk.rp_id, tk.fee, tk.paid,
                    tk.uid, tk.used, tt.description, u.fullname,
                    {$Ev->Event->options['max_rsvp']} as max_rsvp
            FROM {$_TABLES['evlist_tickets']} tk
            LEFT JOIN {$_TABLES['evlist_tickettypes']} tt
                ON tt.id = tk.tic_type
            LEFT JOIN {$_TABLES['users']} u
                ON u.uid = tk.uid
            WHERE tk.ev_id = '{$Ev->Event->id}' ";

    $title = $LANG_EVLIST['pi_title'] . ': ' . 
        $LANG_EVLIST['admin_rsvp'] . ' -- ' .
        COM_createLink($Ev->Event->Detail->title . ' (' . $Ev->date_start . ')',
        EVLIST_URL . '/event.php?eid=' . $rp_id);
    $title .= '&nbsp;&nbsp;&nbsp;<a href="'.$_CONF['site_admin_url'] .
            '/plugins/evlist/index.php?printtickets&eid=' . $Ev->ev_id .
            '" class="lgButton blue" target="_new">' . $LANG_EVLIST['print_tickets'] . '</a>';
    $title .= '&nbsp;&nbsp;&nbsp;<a href="'.$_CONF['site_admin_url'] .
            '/plugins/evlist/index.php?exporttickets&eid=' . $Ev->rp_id .
            '" class="lgButton blue">' . $LANG_EVLIST['export_list'] . '</a>';
 
    if ($Ev->Event->options['use_reg'] == EV_RSVP_REPEAT) {
        $sql .= " AND rp_id = '{$Ev->rp_id}' ";
    }

    $defsort_arr = array('field' => 'dt', 'direction' => 'ASC');
    $text_arr = array(
        'has_menu'     => false,
        'has_extras'   => false,
        'title'        => $title,
        'form_url'     => EVLIST_URL . '/event.php?rp_id=' . $rp_id,
        'help_url'     => '',
    );

    $header_arr = array(
        array(  'text'  => $LANG_EVLIST['rsvp_date'],
                'field' => 'dt', 
                'sort'  => true,
        ),
        array(  'text'  => $LANG_EVLIST['name'],
                'field' => 'fullname',
                'sort'  => false,
        ),
        array(  'text'  => $LANG_EVLIST['fee'],
                'field' => 'fee',
                'sort'  => false,
        ),
        array(  'text'  => $LANG_EVLIST['paid'],
                'field' => 'paid',
                'sort'  => false,
        ),
        array(  'text'  => $LANG_EVLIST['ticket_num'],
                'field' => 'tic_id',
                'sort'  => false,
        ),
        array(  'text'  => $LANG_EVLIST['date_used'],
                'field' => 'used',
                'sort'  => false,
        ),
        array(  'text'  => $LANG_EVLIST['waitlisted'],
                'field' => 'tic_count',
                'sort'  => false,
        ),
    );

    $options_arr = array(
        'chkdelete' => true,
        'chkfield'  => 'tic_id',
        'chkname'   => 'delrsvp',
        'chkactions' => '<input data-uk-tooltip name="tickdelete" type="image" src="'
            . $_CONF['layout_url'] . '/images/admin/delete.' . $_IMAGE_TYPE
            . '" style="vertical-align:text-bottom;" title="' . $LANG_ADMIN['delete']
            . '" class="gl_mootip"'
            . ' onclick="return confirm(\'' . $LANG_EVLIST['conf_del_item']
            . '\');" />&nbsp;'
            . $LANG_ADMIN['delete'] . '&nbsp;&nbsp;' .

            '<input data-uk-tooltip name="tickreset" type="image" src="'
            . $_CONF['site_url'] . '/evlist/images/reset.png'
            . '" style="vertical-align:text-bottom;" title="'
            . $LANG_EVLIST['reset_usage'] 
            . '" class="gl_mootip"' 
            . ' onclick="return confirm(\'' . $LANG_EVLIST['conf_reset']
            . '\');" />&nbsp;' . $LANG_EVLIST['reset_usage']
            . '<input type="hidden" name="ev_id" value="' . $rp_id . '"/>',
 
    );

    $query_arr = array(
        'sql'       => $sql,
    );

    $retval .= ADMIN_list('evlist', 'EVLIST_getField_rsvp', 
                $header_arr, $text_arr, $query_arr, $defsort_arr,
                '', '', $options_arr);
    return $retval;
}


/**
*   Display fields for the RSVP admin list
*
*   @param  string  $fieldname      Name of field
*   @param  mixed   $fieldvalue     Value of field
*   @param  array   $A              Array of all fields ($name=>$value)
*   @param  array   $icon_arr       Handy array of icon images
*   @return string                  Field value formatted for display
*/
function EVLIST_getField_rsvp($fieldname, $fieldvalue, $A, $icon_arr)
{
    global $_CONF, $LANG_ACCESS, $LANG_ADMIN;

    USES_class_date();

    $retval = '';

    switch($fieldname) {
    case 'tic_count':
        if ($fieldvalue <= $A['max_rsvp']) {
            $retval = '';
        } else {
            $retval = 'Yes';
        }
        break;
            
    case 'uid':
        $retval = COM_getDisplayName($fieldvalue);
        break;

    case 'rank':
        if ($fieldvalue > $A['max_signups']) {
            $retval = 'Yes';
        } else {
            $retval = 'No';
        }
        break;

    case 'dt':
    case 'used':
        if ($fieldvalue > 0) {
            $d = new Date($fieldvalue, $_CONF['timezone']);
            $retval = $d->format($_CONF['shortdate'] . ' ' . $_CONF['timeformat'], false);
        } else {
            $retval = '';
        }
        break;
                
    default:
        $retval = $fieldvalue;
        break;
    }

    return $retval;

}


/**
*   Get the day names for a week based on week start day of Sun or Mon.
*   Used to create calendar headers for weekly, monthly and yearly views.
*
*   @param  integer $letters    Optional number of letters to return
*   @return array       Array of day names for a week, 0-indexed
*/
function EVLIST_getDayNames($letters = 0)
{
    global $_CONF, $LANG_WEEK;

    $retval = array();

    if ($_CONF['week_start'] == 'Sun') {
        $keys = array(1, 2, 3, 4, 5, 6, 7);
    } else {
        $keys = array(2, 3, 4, 5, 6, 7, 1);
    }

    for ($i = 0; $i < 7; $i++) {
        if ($letters > 0) {
            $retval[$i] = substr($LANG_WEEK[$keys[$i]], 0, $letters);
        } else {
            $retval[$i] = $LANG_WEEK[$keys[$i]];
        }
    }
    return $retval;
}


?>
