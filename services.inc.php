<?php
/**
*   Web service functions for the EvList plugin.
*   Handles ticket purchases.
*
*   @author     Lee Garner <lee@leegarner.com>
*   @copyright  Copyright (c) 2015 Lee Garner <lee@leegarner.com>
*   @package    evlist
*   @version    1.4.0
*   @license    http://opensource.org/licenses/gpl-2.0.php 
*               GNU Public License v2 or later
*   @filesource
*/

if (!defined ('GVERSION')) {
    die ('This file can not be used on its own!');
}


/**
*   Get information about a specific item.
*
*   @param  array   $args       Item Info (pi_name, item_type, item_id)
*   @param  array   &$output    Array of output data
*   @param  string  &$svc_msg   Unused
*   @return integer     Return value
*/
function service_productinfo_evlist($args, &$output, &$svc_msg)
{
    global $_TABLES, $_CONF, $LANG_EVLIET;

    $retval = PLG_RET_OK;

    // Create a return array with values to be populated later.
    // The actual paypal product ID is evlist:type:id
    $output = array('product_id' => implode(':', $args),
            'name' => 'Unknown',
            'short_description' => 'Unknown Evlist Item',
            'price' => '0.00',
    );

    if (!is_array($args) || !isset($args[2])) return $output;
    $product_id = $args[1];
    $item_id = $args[2];

    switch ($product_id) {
    case 'eventfee':
        $item_parts = explode('/', $item_id);
        $ev_id = $item_parts[0];
        $tick_type = isset($item_parts[1]) ? $item_parts[1] : 0;
        $rp_id = isset($item_parts[2]) ? $item_parts[2] : 0;
        if ($tick_type == 0) {
            return PLG_RET_ERROR;
        }
        USES_evlist_class_tickettype();
        USES_evlist_class_event();
        $Tick = new evTicketType($tick_type);
        $Ev = new evEvent($ev_id);
        if (isset($Ev->ptions['tickets'][$tick_type])) {
            $fee = (float)$Ev->options['tickets'][$tick_type];
        } else {
            $fee = 0;
        }

        $short_desc = $Tick->description. ': ' . $Ev->Detail->title;
        if ($rp_id > 0) {
            USES_evlist_class_repeat();
            $Ev = new evRepeat($rp_id);
            if ($Ev->rp_id == $rp_id) { // valid repeat ID
                $short_desc .=  
                    ', ' . $Ev->start_date1 . ' ' . $Ev->start_time1;
            } else {
                return PLG_RET_ERROR;
            }
        }
        $output['name'] = $short_desc;
        $output['short_description'] = $short_desc;
        $output['price'] = (float)$fee;
        break;
    }
    return $retval;
}


/**
*   Handle the purchase of a product via IPN message.
*
*   @param  array   $args       Array of item and IPN data
*   @param  array   &$output    Return array
*   @param  string  &$svc_msg   Unused
*   @return integer     Return value
*/
function service_handlePurchase_evlist($args, &$output, &$svc_msg)
{
    global $_TABLES, $LANG_PHOTO, $_CONF;

    $item = $args['item'];
    $paypal_data = $args['ipn_data'];
    $item_id = explode(':', $item['item_id']);
    $quantity = (int)$item['quantity'];

    // Must have an item ID following the plugin name
    if (!is_array($item_id) || !isset($item_id[1]))
        return PLG_RET_ERROR;

    // Initialize the output array
    $output = array('product_id' => $item['item_id'],
            'name' => $item['name'],
            'short_description' => $item['name'],
            'price' => (float)$item['price'],
            'expiration' => NULL,
            'download' => 0,
            'file' => '',
    );

    // User ID is provided in the 'custom' field, so make sure it's numeric.
    if (is_numeric($paypal_data['custom']['uid']))
        $uid = (int)$paypal_data['custom']['uid'];
    else
        $uid = 1;

    // Initialize an array of payment info to log
    $pmt_info = array(
        'type'          => 'payment',
        'payment_date'  => $paypal_data['sql_date'],
        'txn_id'        => $paypal_data['txn_id'],
        'amount'        => (float)$item['price'],
    );

    switch ($item_id[1]) {
    case 'eventfee':
        // Get event, ticket_type and repeat ID
        $item_parts = explode('/', $item_id[2]);
        if (count($item_parts) < 3) {
            return PLG_RET_ERROR;
        }
        $ev_id = $item_parts[0];
        $tick_type = $item_parts[1];
        $rp_id = $item_parts[2];

        USES_evlist_class_tickettype();
        USES_evlist_class_repeat(); // includes event class
        $TickType = new evTicketType($tick_type);
        $repeats = array();
        if ($rp_id > 0) {
            $repeats[] = $rp_id;
            // Ticket to a single occurrence
            $Rp = new evRepeat($rp_id);
            $Ev = $Rp->Event;
            $dt_info = $Rp->start_date1 . ' ' . $Rp->start_time1;
        } else {
            // rp_id = 0, make sure it's an event pass
            if ($TickType->event_pass) {
                $Ev = new evEvent($ev_id);
                $dt_info = $Ev->date_start1 . ' ' . $Ev->time_start1;
            } else {
                return PLG_RET_ERROR;
            }
        }
        $ev_fee = (float)$Ev->options['tickets'][$tick_type]['fee'];

        $output['price'] = $ev_fee;
        $output['name'] = $TickType->description . ': ' . $Ev->Detail->title .
                ', ' . $dt_info;
        $output['short_description'] = $output['name'];

        // TODO: fix to handle qty > 1, need loop and calc per-item pmt amt.
        USES_evlist_class_ticket();
        $unpaid = evTicket::MarkPaid($quantity, $ev_id, $rp_id, $uid);
        if ($unpaid < 0) {
            EVLIST_Log("ALERT: $quantity tickets paid for user $uid for event $ev_id, exceeds unpaid count by $unpaid");
        } else {
            EVLIST_Log("$quantity tickets paid for user $uid, event $ev_id/$rp_id");
        }
        /*$tick = new evTicket();
        $tick->ev_id = $ev_id;
        $tick->tic_type = $tick_type;
        $tick->fee = $ev_fee;
        $tick->uid = $uid;
        $tick->paid = $tick->fee;
        //foreach ($repeats as $save_rp_id) {
            $tick->rp_id = $rp_id;
            $tick->Save();
        //}
        //$tick_id = evTicket::Create($ev_id, $rp_id, $tick_type, $ev_fee, $uid);
        //evTicket::AddPayment($tick_id, $ev_fee);
        */
        break;
    }

    return PLG_RET_OK;
}


/**
*   Handle a product refund
*
*   @param  array   $args       Array of item and IPN data
*   @param  array   &$output    Return array
*   @param  string  &$svc_msg   Unused
*   @return integer     Return value
*/
function service_handleRefund_evlist($args, &$output, &$svc_msg)
{
    global $_TABLES;

    $item = $args['item'];      // array of item number info
    $paypal_data = $args['ipn_data'];

    // Must have an item ID following the plugin name
    if (!is_array($item) || !isset($item[1]))
        return PLG_RET_ERROR;

    // User ID is provided in the 'custom' field, so make sure it's numeric.
    if (is_numeric($paypal_data['custom']['uid']))
        $uid = (int)$paypal_data['custom']['uid'];
    else
        $uid = 1;

    switch ($item[1]) {
    case 'eventfee':
        // Handle the refund of an event fee.  Only type handled for now

        if (isset($item[2]) && is_numeric($item[2])) {
            $event_id = (int)$item[2];
        } else {
            $event_id = 0;
        }

        if ($event_id < 1 || $uid < 2) {
            return PLG_RET_ERROR;
        }

        DB_delete($_TABLES['evlist_payments'],
            array('uid', 'event_id', 'section_id', 'entry_id'),
            array($uid, $event_id, 0, 0));
        DB_query("UPDATE {$_TABLES['evlist_entry']}
                SET paid = 0
                WHERE uid = $uid AND eventID = $event_id");
        break;
    }
    return PLG_RET_OK;
}


?>
