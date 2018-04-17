<?php
defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Check if company using invoice with different currencies
 * @return boolean
 */
function is_using_multiple_currencies($table = 'tblinvoices')
{
    $CI =& get_instance();
    $CI->load->model('currencies_model');
    $currencies            = $CI->currencies_model->get();
    $total_currencies_used = 0;
    $other_then_base       = false;
    $base_found            = false;
    foreach ($currencies as $currency) {
        $CI->db->where('currency', $currency['id']);
        $total = $CI->db->count_all_results($table);
        if ($total > 0) {
            $total_currencies_used++;
            if ($currency['isdefault'] == 0) {
                $other_then_base = true;
            } else {
                $base_found = true;
            }
        }
    }

    if ($total_currencies_used > 1 && $base_found == true && $other_then_base == true) {
        return true;
    } elseif ($total_currencies_used == 1 && $base_found == false && $other_then_base == true) {
        return true;
    } elseif ($total_currencies_used == 0 || $total_currencies_used == 1) {
        return false;
    }

    return true;
}

/**
 * Check if client have invoices with multiple currencies
 * @return booelan
 */
function is_client_using_multiple_currencies($clientid = '', $table = 'tblinvoices')
{
    $CI =& get_instance();

    $clientid = $clientid == '' ? get_client_user_id() : $clientid;
    $CI->load->model('currencies_model');
    $currencies            = $CI->currencies_model->get();
    $total_currencies_used = 0;
    foreach ($currencies as $currency) {
        $CI->db->where('currency', $currency['id']);
        $CI->db->where('clientid', $clientid);
        $total = $CI->db->count_all_results($table);
        if ($total > 0) {
            $total_currencies_used++;
        }
    }
    if ($total_currencies_used > 1) {
        return true;
    } elseif ($total_currencies_used == 0 || $total_currencies_used == 1) {
        return false;
    }

    return true;
}
/**
 * Get invoice total left for paying if not payments found the original total from the invoice will be returned
 * @since  Version 1.0.1
 * @param  mixed $id     invoice id
 * @param  mixed $invoice_total
 * @return mixed  total left
 */
function get_invoice_total_left_to_pay($id, $invoice_total = null)
{
    $CI =& get_instance();

    if ($invoice_total === null) {
        $CI->db->select('total')
        ->where('id', $id);
        $invoice_total = $CI->db->get('tblinvoices')->row()->total;
    }

    if (!class_exists('payments_model')) {
        $CI->load->model('payments_model');
    }

    if (!class_exists('credit_notes_model')) {
        $CI->load->model('credit_notes_model');
    }

    $payments = $CI->payments_model->get_invoice_payments($id);
    $credits = $CI->credit_notes_model->get_applied_invoice_credits($id);

    $payments = array_merge($payments, $credits);

    $totalPayments = 0;

    $bcadd = function_exists('bcadd');

    foreach ($payments as $payment) {
        if ($bcadd) {
            $totalPayments = bcadd($totalPayments, $payment['amount'], get_decimal_places());
        } else {
            $totalPayments += $payment['amount'];
        }
    }

    if (function_exists('bcsub')) {
        return bcsub($invoice_total, $totalPayments, get_decimal_places());
    } else {
        return number_format($invoice_total - $totalPayments, get_decimal_places(), '.', '');
    }
}

/**
 * Sum total credits applied for invoice
 * @param  mixed $id invoice id
 * @return mixed
 */
function total_credits_applied_to_invoice($id)
{
    $total = sum_from_table('tblcredits', array('field'=>'amount', 'where'=>array('invoice_id'=>$id)));

    if ($total == 0) {
        return false;
    }

    return $total;
}
/**
 * Check invoice restrictions - hash, clientid
 * @since  Version 1.0.1
 * @param  mixed $id   invoice id
 * @param  string $hash invoice hash
 */
function check_invoice_restrictions($id, $hash)
{
    $CI =& get_instance();
    $CI->load->model('invoices_model');
    if (!$hash || !$id) {
        show_404();
    }
    if (!is_client_logged_in() && !is_staff_logged_in()) {
        if (get_option('view_invoice_only_logged_in') == 1) {
            redirect(site_url('clients/login'));
        }
    }
    $invoice = $CI->invoices_model->get($id);
    if (!$invoice || ($invoice->hash != $hash)) {
        show_404();
    }

    // Do one more check
    if (!is_staff_logged_in()) {
        if (get_option('view_invoice_only_logged_in') == 1) {
            if ($invoice->clientid != get_client_user_id()) {
                show_404();
            }
        }
    }
}
/**
 * Check estimate restrictions - hash, clientid
 * @since  Version 1.0.1
 * @param  mixed $id   estimate id
 * @param  string $hash estimate hash
 */
function check_estimate_restrictions($id, $hash)
{
    $CI =& get_instance();
    $CI->load->model('estimates_model');
    if (!$hash || !$id) {
        show_404();
    }
    if (!is_client_logged_in() && !is_staff_logged_in()) {
        if (get_option('view_estimate_only_logged_in') == 1) {
            redirect(site_url('clients/login'));
        }
    }
    $estimate = $CI->estimates_model->get($id);
    if (!$estimate || ($estimate->hash != $hash)) {
        show_404();
    }
    // Do one more check
    if (!is_staff_logged_in()) {
        if (get_option('view_estimate_only_logged_in') == 1) {
            if ($estimate->clientid != get_client_user_id()) {
                show_404();
            }
        }
    }
}
/**
 * Check if proposal hash is equal
 * @param  mixed $id   proposal id
 * @param  string $hash proposal hash
 * @return void
 */
function check_proposal_restrictions($id, $hash)
{
    $CI =& get_instance();
    $CI->load->model('proposals_model');
    if (!$hash || !$id) {
        show_404();
    }
    $proposal = $CI->proposals_model->get($id);
    if (!$proposal || ($proposal->hash != $hash)) {
        show_404();
    }
}
/**
 * Forat number with 2 decimals
 * @param  mixed $total
 * @return string
 */
function _format_number($total, $force_checking_zero_decimals = false)
{
    if (!is_numeric($total)) {
        return $total;
    }
    $decimal_separator  = get_option('decimal_separator');
    $thousand_separator = get_option('thousand_separator');

    $d = get_decimal_places();
    if (get_option('remove_decimals_on_zero') == 1 || $force_checking_zero_decimals == true) {
        if (!is_decimal($total)) {
            $d = 0;
        }
    }

    return do_action('number_after_format', number_format($total, $d, $decimal_separator, $thousand_separator));
}
/**
 * Unformat formatted number. THIS FUNCTION IS NOT WELL TESTED
 * @param  mixed  $number
 * @param  boolean $force_number
 * @return mixed
 */
function number_unformat($number, $force_number = true)
{
    if ($force_number) {
        $number = preg_replace('/^[^\d]+/', '', $number);
    } elseif (preg_match('/^[^\d]+/', $number)) {
        return false;
    }
    $dec_point     = get_option('decimal_separator');
    $thousands_sep = get_option('thousand_separator');
    $type          = (strpos($number, $dec_point) === false) ? 'int' : 'float';
    $number        = str_replace(array(
        $dec_point,
        $thousands_sep,
    ), array(
        '.',
        '',
    ), $number);
    settype($number, $type);

    return $number;
}
/**
 * Format money with 2 decimal based on symbol
 * @param  mixed $total
 * @param  string $symbol Money symbol
 * @return string
 */
function format_money($total, $symbol = '')
{
    if (!is_numeric($total) && $total != 0) {
        return $total;
    }

    $decimal_separator  = get_option('decimal_separator');
    $thousand_separator = get_option('thousand_separator');
    $currency_placement = get_option('currency_placement');
    $d                  = get_decimal_places();

    if (get_option('remove_decimals_on_zero') == 1) {
        if (!is_decimal($total)) {
            $d = 0;
        }
    }

    $total = number_format($total, $d, $decimal_separator, $thousand_separator);
    $total = do_action('money_after_format_without_currency', $total);

    if ($currency_placement === 'after') {
        $_formatted = $total . '' . $symbol;
    } else {
        $_formatted = $symbol . '' . $total;
    }

    $_formatted = do_action('money_after_format_with_currency', $_formatted);

    return $_formatted;
}
/**
 * Check if passed number is decimal
 * @param  mixed  $val
 * @return boolean
 */
function is_decimal($val)
{
    return is_numeric($val) && floor($val) != $val;
}
/**
 * Function that will loop through taxes and will check if there is 1 tax or multiple
 * @param  array $taxes
 * @return boolean
 */
function mutiple_taxes_found_for_item($taxes)
{
    $names = array();
    foreach ($taxes as $t) {
        array_push($names, $t['taxname']);
    }
    $names = array_map("unserialize", array_unique(array_map("serialize", $names)));
    if (count($names) == 1) {
        return false;
    }

    return true;
}
/**
 * Format invoice status
 * @param  integer  $status
 * @param  string  $classes additional classes
 * @param  boolean $label   To include in html label or not
 * @return mixed
 */
function format_invoice_status($status, $classes = '', $label = true)
{
    $id          = $status;
    $label_class = get_invoice_status_label($status);
    if ($status == 1) {
        $status = _l('invoice_status_unpaid');
    } elseif ($status == 2) {
        $status = _l('invoice_status_paid');
    } elseif ($status == 3) {
        $status = _l('invoice_status_not_paid_completely');
    } elseif ($status == 4) {
        $status = _l('invoice_status_overdue');
    } elseif ($status == 5) {
        $status = _l('invoice_status_cancelled');
    } else {
        // status 6
        $status = _l('invoice_status_draft');
    }
    if ($label == true) {
        return '<span class="label label-' . $label_class . ' ' . $classes . ' s-status invoice-status-' . $id . '">' . $status . '</span>';
    } else {
        return $status;
    }
}
/**
 * Return invoice status label class baed on twitter bootstrap classses
 * @param  mixed $status invoice status id
 * @return string
 */
function get_invoice_status_label($status)
{
    $label_class = '';
    if ($status == 1) {
        $label_class = 'danger';
    } elseif ($status == 2) {
        $label_class = 'success';
    } elseif ($status == 3) {
        $label_class = 'warning';
    } elseif ($status == 4) {
        $label_class = 'warning';
    } elseif ($status == 5 || $status == 6) {
        $label_class = 'default';
    } else {
        if (!is_numeric($status)) {
            if ($status == 'not_sent') {
                $label_class = 'default';
            }
        }
    }

    return $label_class;
}

function invoice_status_color_pdf($status_id)
{
    $statusColor = '';

    if ($status_id == 1) {
        $statusColor = '252, 45, 66';
    } elseif ($status_id == 2) {
        $statusColor = '0, 191, 54';
    } elseif ($status_id == 3) {
        $statusColor = '255, 111, 0';
    } elseif ($status_id == 4) {
        $statusColor = '255, 111, 0';
    } elseif ($status_id == 5 || $status_id == 6) {
        $statusColor = '114, 123, 144';
    }

    return $statusColor;
}

function credit_note_status_color_pdf($status_id)
{
    $statusColor = '';

    if ($status_id == 1) {
        $statusColor = '3, 169, 244';
    } elseif ($status_id == 2) {
        $statusColor = '132, 197, 41';
    } else {
        // Status VOID
        $statusColor = '119, 119, 119';
    }

    return $statusColor;
}

function invoices_statuses_available_for_credits(){
    return array(1, 3, 6, 4);
}

function credits_can_be_applied_to_invoice($status_id)
{
    return in_array($status_id, invoices_statuses_available_for_credits());
}

function estimate_status_color_pdf($status_id)
{
    if ($status_id == 1) {
        $statusColor = '119, 119, 119';
    } elseif ($status_id == 2) {
        // Sent
        $statusColor = '3, 169, 244';
    } elseif ($status_id == 3) {
        //Declines
        $statusColor = '252, 45, 66';
    } elseif ($status_id == 4) {
        //Accepted
        $statusColor = '0, 191, 54';
    } else {
        // Expired
        $statusColor = '255, 111, 0';
    }

    return $statusColor;
}
/**
 * Format estimate status
 * @param  integer  $status
 * @param  string  $classes additional classes
 * @param  boolean $label   To include in html label or not
 * @return mixed
 */
function format_estimate_status($status, $classes = '', $label = true)
{
    $id          = $status;
    $label_class = estimate_status_color_class($status);
    $status      = estimate_status_by_id($status);
    if ($label == true) {
        return '<span class="label label-' . $label_class . ' ' . $classes . ' s-status estimate-status-' . $id . ' estimate-status-' . $label_class . '">' . $status . '</span>';
    } else {
        return $status;
    }
}
/**
 * Return estimate status translated by passed status id
 * @param  mixed $id estimate status id
 * @return string
 */
function estimate_status_by_id($id)
{
    $status = '';
    if ($id == 1) {
        $status = _l('estimate_status_draft');
    } elseif ($id == 2) {
        $status = _l('estimate_status_sent');
    } elseif ($id == 3) {
        $status = _l('estimate_status_declined');
    } elseif ($id == 4) {
        $status = _l('estimate_status_accepted');
    } elseif ($id == 5) {
        // status 5
        $status = _l('estimate_status_expired');
    } else {
        if (!is_numeric($id)) {
            if ($id == 'not_sent') {
                $status = _l('not_sent_indicator');
            }
        }
    }

    $hook_data = do_action('estimate_status_label', array(
        'id' => $id,
        'label' => $status,
    ));
    $status    = $hook_data['label'];

    return $status;
}
/**
 * Return estimate status color class based on twitter bootstrap
 * @param  mixed  $id
 * @param  boolean $replace_default_by_muted
 * @return string
 */
function estimate_status_color_class($id, $replace_default_by_muted = false)
{
    $class = '';
    if ($id == 1) {
        $class = 'default';
        if ($replace_default_by_muted == true) {
            $class = 'muted';
        }
    } elseif ($id == 2) {
        $class = 'info';
    } elseif ($id == 3) {
        $class = 'danger';
    } elseif ($id == 4) {
        $class = 'success';
    } elseif ($id == 5) {
        // status 5
        $class = 'warning';
    } else {
        if (!is_numeric($id)) {
            if ($id == 'not_sent') {
                $class = 'default';
                if ($replace_default_by_muted == true) {
                    $class = 'muted';
                }
            }
        }
    }

    $hook_data = do_action('estimate_status_color_class', array(
        'id' => $id,
        'class' => $class,
    ));
    $class     = $hook_data['class'];

    return $class;
}
/**
 * Return proposal status color class based on twitter bootstrap
 * @param  mixed  $id
 * @param  boolean $replace_default_by_muted
 * @return string
 */
function proposal_status_color_class($id, $replace_default_by_muted = false)
{
    if ($id == 1) {
        $class = 'default';
    } elseif ($id == 2) {
        $class = 'danger';
    } elseif ($id == 3) {
        $class = 'success';
    } elseif ($id == 4 || $id == 5) {
        // status sent and revised
        $class = 'info';
    } elseif ($id == 6) {
        $class = 'default';
    }
    if ($class == 'default') {
        if ($replace_default_by_muted == true) {
            $class = 'muted';
        }
    }

    return $class;
}
/**
 * Format proposal status with label or not
 * @param  mixed  $status  proposal status id
 * @param  string  $classes additional label classes
 * @param  boolean $label   to include the label or return just translated text
 * @return string
 */
function format_proposal_status($status, $classes = '', $label = true)
{
    $id = $status;
    if ($status == 1) {
        $status      = _l('proposal_status_open');
        $label_class = 'default';
    } elseif ($status == 2) {
        $status      = _l('proposal_status_declined');
        $label_class = 'danger';
    } elseif ($status == 3) {
        $status      = _l('proposal_status_accepted');
        $label_class = 'success';
    } elseif ($status == 4) {
        $status      = _l('proposal_status_sent');
        $label_class = 'info';
    } elseif ($status == 5) {
        $status      = _l('proposal_status_revised');
        $label_class = 'info';
    } elseif ($status == 6) {
        $status      = _l('proposal_status_draft');
        $label_class = 'default';
    }

    if ($label == true) {
        return '<span class="label label-' . $label_class . ' ' . $classes . ' s-status proposal-status-' . $id . '">' . $status . '</span>';
    } else {
        return $status;
    }
}
/**
 * Update invoice status
 * @param  mixed $id invoice id
 * @return mixed invoice updates status / if no update return false
 * @return boolean $prevent_logging do not log changes if the status is updated for the invoice activity log
 */
function update_invoice_status($id, $force_update = false, $prevent_logging = false)
{
    $CI =& get_instance();

    $CI->load->model('invoices_model');
    $invoice = $CI->invoices_model->get($id);

    $original_status = $invoice->status;

    if (($original_status == 6 && $force_update == false) || ($original_status == 5 && $force_update == false)) {
        return false;
    }

    $CI->db->select('amount')
    ->where('invoiceid', $id)
    ->order_by('tblinvoicepaymentrecords.id', 'asc');
    $payments = $CI->db->get('tblinvoicepaymentrecords')->result_array();

    if (!class_exists('credit_notes_model')) {
        $CI->load->model('credit_notes_model');
    }

    $credits = $CI->credit_notes_model->get_applied_invoice_credits($id);
    // Merge credits applied with payments, credits in this function are casted as payments directly to invoice
    // This merge will help to update the status
    $payments = array_merge($payments, $credits);

    $totalPayments = array();
    $status         = 1;

    // Check if the first payments is equal to invoice total
    if (isset($payments[0])) {
        if ($payments[0]['amount'] == $invoice->total) {
            // Paid status
            $status = 2;
        } else {
            foreach ($payments as $payment) {
                array_push($totalPayments, $payment['amount']);
            }

            $totalPayments = array_sum($totalPayments);

            if ((function_exists('bccomp')
                ?  bccomp($invoice->total, $totalPayments, get_decimal_places()) === 0
                || bccomp($invoice->total, $totalPayments, get_decimal_places()) === -1
                : number_format(($invoice->total-$totalPayments), get_decimal_places(), '.', '') == '0')
                || $totalPayments > $invoice->total) {
                // Paid status
                $status = 2;
            } elseif ($totalPayments == 0) {
                // Unpaid status
                $status = 1;
            } else {
                if ($invoice->duedate != null) {
                    if ($totalPayments > 0) {
                        // Not paid completely status
                        $status = 3;
                    } elseif (date('Y-m-d', strtotime($invoice->duedate)) < date('Y-m-d')) {
                        $status = 4;
                    }
                } else {
                    // Not paid completely status
                    $status = 3;
                }
            }
        }
    } else {
        if ($invoice->total == 0) {
            $status = 2;
        } else {
            if ($invoice->duedate != null) {
                if (date('Y-m-d', strtotime($invoice->duedate)) < date('Y-m-d')) {
                    // Overdue status
                    $status = 4;
                }
            }
        }
    }

    $CI->db->where('id', $id);
    $CI->db->update('tblinvoices', array(
        'status' => $status,
    ));

    if ($CI->db->affected_rows() > 0) {
        do_action('invoice_status_changed', array('invoice_id'=>$id, 'status'=>$status));
        if ($prevent_logging == true) {
            return $status;
        }
        logActivity('Invoice Status Updated [Invoice Number: ' . format_invoice_number($invoice->id) . ', From: ' . format_invoice_status($original_status, '', false) . ' To: ' . format_invoice_status($status, '', false) . ']', null);

        $additional_activity = serialize(array(
            '<original_status>' . $original_status . '</original_status>',
            '<new_status>' . $status . '</new_status>',
        ));

        $CI->invoices_model->log_invoice_activity($invoice->id, 'invoice_activity_status_updated', false, $additional_activity);

        return $status;
    }

    return false;
}

/**
 * Check if is last credit note created
 * @param  mixed  $id credit note id
 * @return boolean
 */
function is_last_credit_note($id)
{
    $CI =& get_instance();
    $CI->db->select('id')->from('tblcreditnotes')->order_by('id', 'desc')->limit(1);
    $query           = $CI->db->get();
    $last_credit_note = $query->row();

    if ($last_credit_note && $last_credit_note->id == $id) {
        return true;
    }

    return false;
}

/**
 * Check if the invoice id is last invoice
 * @param  mixed  $id invoice id
 * @return boolean
 */
function is_last_invoice($id)
{
    $CI =& get_instance();
    $CI->db->select('id')->from('tblinvoices')->order_by('id', 'desc')->limit(1);
    $query           = $CI->db->get();
    $last_invoice_id = $query->row()->id;
    if ($last_invoice_id == $id) {
        return true;
    }

    return false;
}
/**
 * Check if the estimate id is last invoice
 * @since Version 1.0.2
 * @param  mixed  $id estimateid
 * @return boolean
 */
function is_last_estimate($id)
{
    $CI =& get_instance();
    $CI->db->select('id')->from('tblestimates')->order_by('id', 'desc')->limit(1);
    $query            = $CI->db->get();
    $last_estimate_id = $query->row()->id;
    if ($last_estimate_id == $id) {
        return true;
    }

    return false;
}
/**
 * If there is more then 100 items in the script the search when creating eq invoice, estimate, proposal
 * will be ajax based
 * @return int
 */
function ajax_on_total_items()
{
    return do_action('ajax_on_total_items', 200);
}
/**
 * Format invoice number based on description
 * @param  mixed $id
 * @return string
 */
function format_invoice_number($id)
{
    $CI =& get_instance();
    $CI->db->select('date,number,prefix,number_format')->from('tblinvoices')->where('id', $id);
    $invoice = $CI->db->get()->row();

    if (!$invoice) {
        return '';
    }

    $format = $invoice->number_format;
    $prefix = $invoice->prefix;
    $number = $invoice->number;
    $date = $invoice->date;
    $prefixPadding = get_option('number_padding_prefixes');

    if ($format == 1) {
        // Number based
        return $prefix . str_pad($number, $prefixPadding, '0', STR_PAD_LEFT);
    } elseif ($format == 2) {
        return $prefix . date('Y', strtotime($date)) . '/' . str_pad($number, $prefixPadding, '0', STR_PAD_LEFT);
    }

    return $number;
}
/**
 * Format estimate number based on description
 * @since  Version 1.0.2
 * @param  mixed $id
 * @return string
 */
function format_estimate_number($id)
{
    $CI =& get_instance();
    $CI->db->select('date,number,prefix,number_format')->from('tblestimates')->where('id', $id);
    $estimate = $CI->db->get()->row();

    if (!$estimate) {
        return '';
    }

    $format = $estimate->number_format;
    $prefix = $estimate->prefix;
    $number = $estimate->number;
    $date = $estimate->date;
    $prefixPadding = get_option('number_padding_prefixes');

    // Number based
    if ($format == 1) {
        return $prefix . str_pad($number, $prefixPadding, '0', STR_PAD_LEFT);
    } elseif ($format == 2) {
        return $prefix . date('Y', strtotime($date)) . '/' . str_pad($number, $prefixPadding, '0', STR_PAD_LEFT);
    }

    return $number;
}
/**
 * Function that format proposal number based on the prefix option and the proposal id
 * @param  mixed $id proposal id
 * @return string
 */
function format_proposal_number($id)
{
    return get_option('proposal_number_prefix') . str_pad($id, get_option('number_padding_prefixes'), '0', STR_PAD_LEFT);
}
/**
 * Function that format credit note number based on the prefix option and the credit note number
 * @param  mixed $id credit note id
 * @return string
 */
function format_credit_note_number($id)
{
    $CI =& get_instance();
    $CI->db->select('number,prefix')
    ->from('tblcreditnotes')
    ->where('id', $id);
    $credit_note = $CI->db->get()->row();

    if (!$credit_note) {
        return '';
    }

    $prefix = $credit_note->prefix;
    $number = $credit_note->number;
    $prefixPadding = get_option('number_padding_prefixes');

    return $prefix . str_pad($number, $prefixPadding, '0', STR_PAD_LEFT);
}

/**
 * Format credit note status
 * @param  mixed  $status credit note current status
 * @param  boolean $text   to return text or with applied styles
 * @return string
 */
function format_credit_note_status($status, $text = false)
{
    $CI = &get_instance();
    if (!class_exists('credit_notes_model')) {
        $CI->load->model('credit_notes_model');
    }

    $statuses = $CI->credit_notes_model->get_statuses();
    $statusArray = false;
    foreach ($statuses as $s) {
        if ($s['id'] == $status) {
            $statusArray = $s;
            break;
        }
    }

    if (!$statusArray) {
        return $status;
    }

    if ($text) {
        return $statusArray['name'];
    }

    $style = 'border: 1px solid '.$statusArray['color'].';color:'.$statusArray['color'].';';
    $class = 'label s-status';

    return '<span class="'.$class.'" style="'.$style.'">' . $statusArray['name'] . '</span>';
}

/**
 * Helper function to get tax by passedid
 * @param  integer $id taxid
 * @return object
 */
function get_tax_by_id($id)
{
    $CI =& get_instance();
    $CI->db->where('id', $id);

    return $CI->db->get('tbltaxes')->row();
}
/**
 * Helper function to get tax by passed name
 * @param  string $name tax name
 * @return object
 */
function get_tax_by_name($name)
{
    $CI =& get_instance();
    $CI->db->where('name', $name);

    return $CI->db->get('tbltaxes')->row();
}
/**
 * Function that return invoice item taxes based on passed item id
 * @param  mixed $itemid
 * @return array
 */
function get_invoice_item_taxes($itemid)
{
    $CI =& get_instance();
    $CI->db->where('itemid', $itemid);
    $CI->db->where('rel_type', 'invoice');
    $taxes = $CI->db->get('tblitemstax')->result_array();
    $i     = 0;
    foreach ($taxes as $tax) {
        $taxes[$i]['taxname'] = $tax['taxname'] . '|' . $tax['taxrate'];
        $i++;
    }

    return $taxes;
}

/**
 * Function that return credit note item taxes based on passed item id
 * @param  mixed $itemid
 * @return array
 */
function get_credit_note_item_taxes($itemid)
{
    $CI =& get_instance();
    $CI->db->where('itemid', $itemid);
    $CI->db->where('rel_type', 'credit_note');
    $taxes = $CI->db->get('tblitemstax')->result_array();
    $i     = 0;
    foreach ($taxes as $tax) {
        $taxes[$i]['taxname'] = $tax['taxname'] . '|' . $tax['taxrate'];
        $i++;
    }

    return $taxes;
}

/**
 * Function that return estimate item taxes based on passed item id
 * @param  mixed $itemid
 * @return array
 */
function get_estimate_item_taxes($itemid)
{
    $CI =& get_instance();
    $CI->db->where('itemid', $itemid);
    $CI->db->where('rel_type', 'estimate');
    $taxes = $CI->db->get('tblitemstax')->result_array();
    $i     = 0;
    foreach ($taxes as $tax) {
        $taxes[$i]['taxname'] = $tax['taxname'] . '|' . $tax['taxrate'];
        $i++;
    }

    return $taxes;
}
/**
 * Function that return proposal item taxes based on passed item id
 * @param  mixed $itemid
 * @return array
 */
function get_proposal_item_taxes($itemid)
{
    $CI =& get_instance();
    $CI->db->where('itemid', $itemid);
    $CI->db->where('rel_type', 'proposal');
    $taxes = $CI->db->get('tblitemstax')->result_array();
    $i     = 0;
    foreach ($taxes as $tax) {
        $taxes[$i]['taxname'] = $tax['taxname'] . '|' . $tax['taxrate'];
        $i++;
    }

    return $taxes;
}
/**
 * Check if payment mode is allowed for specific invoice
 * @param  mixed  $id payment mode id
 * @param  mixed  $invoiceid invoice id
 * @return boolean
 */
function is_payment_mode_allowed_for_invoice($id, $invoiceid)
{
    $CI =& get_instance();
    $CI->db->select('tblcurrencies.name as currency_name,allowed_payment_modes')->from('tblinvoices')->join('tblcurrencies', 'tblcurrencies.id = tblinvoices.currency', 'left')->where('tblinvoices.id', $invoiceid);
    $invoice       = $CI->db->get()->row();
    $allowed_modes = $invoice->allowed_payment_modes;
    if (!is_null($allowed_modes)) {
        $allowed_modes = unserialize($allowed_modes);
        if (count($allowed_modes) == 0) {
            return false;
        } else {
            foreach ($allowed_modes as $mode) {
                if ($mode == $id) {
                    // is offline payment mode
                    if (is_numeric($id)) {
                        return true;
                    }
                    // check currencies
                    $currencies = explode(',', get_option('paymentmethod_' . $id . '_currencies'));
                    foreach ($currencies as $currency) {
                        $currency = trim($currency);
                        if (mb_strtoupper($currency) == mb_strtoupper($invoice->currency_name)) {
                            return true;
                        }
                    }

                    return false;
                }
            }
        }
    } else {
        return false;
    }

    return false;
}
/**
 * Check if invoice mode exists in invoice
 * @since  Version 1.0.1
 * @param  array  $modes     all invoice modes
 * @param  mixed  $invoiceid invoice id
 * @param  boolean $offline   should check offline or online modes
 * @return boolean
 */
function found_invoice_mode($modes, $invoiceid, $offline = true, $show_on_pdf = false)
{
    $CI =& get_instance();
    $CI->db->select('tblcurrencies.name as currency_name,allowed_payment_modes')->from('tblinvoices')->join('tblcurrencies', 'tblcurrencies.id = tblinvoices.currency', 'left')->where('tblinvoices.id', $invoiceid);
    $invoice = $CI->db->get()->row();
    if (!is_null($invoice->allowed_payment_modes)) {
        $invoice->allowed_payment_modes = unserialize($invoice->allowed_payment_modes);
        if (count($invoice->allowed_payment_modes) == 0) {
            return false;
        } else {
            foreach ($modes as $mode) {
                if ($offline == true) {
                    if (is_numeric($mode['id']) && is_array($invoice->allowed_payment_modes)) {
                        foreach ($invoice->allowed_payment_modes as $allowed_mode) {
                            if ($allowed_mode == $mode['id']) {
                                if ($show_on_pdf == false) {
                                    return true;
                                } else {
                                    if ($mode['show_on_pdf'] == 1) {
                                        return true;
                                    } else {
                                        return false;
                                    }
                                }
                            }
                        }
                    }
                } else {
                    if (!is_numeric($mode['id']) && !empty($mode['id'])) {
                        foreach ($invoice->allowed_payment_modes as $allowed_mode) {
                            if ($allowed_mode == $mode['id']) {
                                // Check for currencies
                                $currencies = explode(',', get_option('paymentmethod_' . $mode['id'] . '_currencies'));
                                foreach ($currencies as $currency) {
                                    $currency = trim($currency);
                                    if (strtoupper($currency) == strtoupper($invoice->currency_name)) {
                                        return true;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    return false;
}
/**
 * Pdf language loaded based on few options
 * @param  mixed $clientid client id
 * @return void
 */
function load_pdf_language($clientid)
{
    $CI =& get_instance();
    $lang     = get_option('active_language');
    // When cron or email sending pdf document the pdfs need to be on the client language
    $language = get_client_default_language($clientid);
    if (DEFINED('CRON') || DEFINED('EMAIL_TEMPLATE_SEND')) {
        if (!empty($language)) {
            $lang = $language;
        }
    } else {
        if (get_option('output_client_pdfs_from_admin_area_in_client_language') == 1) {
            if (!empty($language)) {
                $lang = $language;
            }
        }
    }

    if (file_exists(APPPATH . 'language/' . $lang)) {
        $CI->lang->load($lang . '_lang', $lang);
    }

    if (file_exists(APPPATH . 'language/' . $lang . '/custom_lang.php')) {
        $CI->lang->load('custom_lang', $lang);
    }
}
/**
 * Fetches custom pdf logo url for pdf or use the default logo uploaded for the company
 * Additional statements applied because this function wont work on all servers. All depends how the server is configured.
 * @return [type] [description]
 */
function pdf_logo_url()
{
    $custom_pdf_logo_image_url = get_option('custom_pdf_logo_image_url');
    $width                     = get_option('pdf_logo_width');
    if ($width == '') {
        $width = 120;
    }
    if ($custom_pdf_logo_image_url != '') {
        if (strpos($custom_pdf_logo_image_url, 'localhost') !== false) {
            $cimg = $custom_pdf_logo_image_url;
        } elseif (strpos($custom_pdf_logo_image_url, 'http') === false) {
            $cimg = FCPATH . $custom_pdf_logo_image_url;
        } else {
            /*  $cimg = do_curl_pdf_image($custom_pdf_logo_image_url);
            $formImage = imagecreatefromstring(base64_decode(strafter($cimg,'base64,')));
            $w = imagesx($formImage);
            $h = imagesy($formImage);
            */
            if (_startsWith($custom_pdf_logo_image_url, site_url()) !== false) {
                $temp = str_replace(site_url(), '/', $custom_pdf_logo_image_url);
                $cimg = FCPATH . $temp;
                if (!file_exists($cimg)) {
                    $cimg = do_curl_pdf_image($custom_pdf_logo_image_url);
                }
            } else {
                $cimg = do_curl_pdf_image($custom_pdf_logo_image_url);
            }
        }
    } else {
        if (get_option('company_logo') != '') {
            $cimg = get_upload_path_by_type('company') . get_option('company_logo');
        } else {
            $cimg = '';
        }
    }
    $logo_url = '';
    if ($cimg != '') {
        $logo_url = '<img width="' . $width . 'px" src="' . $cimg . '">';
    }

    return do_action('pdf_logo_url', $logo_url);
}
/**
 * Fetch curl image and returns as data base64
 * @param  string $url
 * @return string
 */
function do_curl_pdf_image($url)
{
    $path_parts = pathinfo($url);

    if (!isset($path_parts['extension']) || (isset($path_parts['extension']) && $path_parts['extension'] == null)) {
        $extension = get_file_extension($url);
    } else {
        $extension = $path_parts['extension'];
    }
    // On some hosting providers you cant access directly the url and throwing error unable to get image size
    // Will simulate like browser access to get the image.
    $ch = curl_init();
    // set url
    curl_setopt($ch, CURLOPT_URL, $url);
    // Return the transfer as a image
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');
    // $output contains the output image
    $output = curl_exec($ch);
    // close curl resource to free up system resources
    curl_close($ch);

    return 'data:image/' . $extension . ';base64,' . base64_encode($output);
}
/**
 * Get PDF format page
 * Based on the options will return the formatted string that will be used in the PDF library
 * @param  string $option_name
 * @return array
 */
function get_pdf_format($option_name)
{
    $oFormat = strtoupper(get_option($option_name));
    $data    = array(
        'orientation' => '',
        'format' => '',
    );

    if ($oFormat == 'A4-PORTRAIT') {
        $data['orientation'] = 'P';
        $data['format']      = 'A4';
    } elseif ($oFormat == 'A4-LANDSCAPE') {
        $data['orientation'] = 'L';
        $data['format']      = 'A4';
    } elseif ($oFormat == 'LETTER-PORTRAIT') {
        $data['orientation'] = 'P';
        $data['format']      = 'LETTER';
    } else {
        // LETTER-LANDSCAPE
        $data['orientation'] = 'L';
        $data['format']      = 'LETTER';
    }

    return do_action('pdf_format_data', $data);
}
/**
 * Prepare general invoice pdf
 * @param  object $invoice Invoice as object with all necessary fields
 * @return mixed object
 */
function invoice_pdf($invoice, $tag = '')
{
    $GLOBALS['invoice_pdf'] = $invoice;
    $CI =& get_instance();
    load_pdf_language($invoice->clientid);
    $CI->load->library('pdf');
    $invoice_number = format_invoice_number($invoice->id);

    $font_name      = get_option('pdf_font');
    $font_size      = get_option('pdf_font_size');

    if ($font_size == '') {
        $font_size = 10;
    }

    $CI->load->model('payment_modes_model');
    $payment_modes = $CI->payment_modes_model->get();

    // In case user want to include {invoice_number} in PDF offline mode description
    foreach ($payment_modes as $key => $mode) {
        if (isset($mode['description'])) {
            $payment_modes[$key]['description'] = str_replace('{invoice_number}', $invoice_number, $mode['description']);
        }
    }

    $whereCF = array('show_on_pdf'=>1);
    if (is_custom_fields_for_customers_portal()) {
        $whereCF['show_on_client_portal'] = 1;
    }

    // check for invoice custom fields which is checked show on pdf
    $pdf_custom_fields = get_custom_fields('invoice', $whereCF);

    $formatArray = get_pdf_format('pdf_format_invoice');
    if (!file_exists(APPPATH.'libraries/Invoice_pdf.php')) {
        $pdf         = new Pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false, 'invoice');
    } else {
        include_once(APPPATH.'libraries/Invoice_pdf.php');
        $pdf         = new Invoice_pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false);
    }

    if (defined('APP_PDF_MARGIN_LEFT') && defined('APP_PDF_MARGIN_TOP') && defined('APP_PDF_MARGIN_RIGHT')) {
        $pdf->SetMargins(APP_PDF_MARGIN_LEFT, APP_PDF_MARGIN_TOP, APP_PDF_MARGIN_RIGHT);
    }

    $pdf->SetTitle($invoice_number);

    $pdf->SetAutoPageBreak(true, (defined('APP_PDF_MARGIN_BOTTOM') ? APP_PDF_MARGIN_BOTTOM : PDF_MARGIN_BOTTOM));

    $pdf->SetAuthor(get_option('company'));
    $pdf->SetFont($font_name, '', $font_size);
    $pdf->setImageScale(1.53);
    $pdf->setJPEGQuality(100);
    $pdf->AddPage($formatArray['orientation'], $formatArray['format']);

    if ($CI->input->get('print') == 'true') {
        // force print dialog
        $js = 'print(true);';
        $pdf->IncludeJS($js);
    }

    $status = $invoice->status;
    $swap   = get_option('swap_pdf_info');
    $CI->load->library('numberword', array(
        'clientid' => $invoice->clientid,
    ));
    $invoice = do_action('invoice_html_pdf_data', $invoice);

    _bulk_pdf_export_maybe_tag($tag, $pdf);
    if (file_exists(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_invoicepdf.php')) {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_invoicepdf.php');
    } else {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/invoicepdf.php');
    }

    if (ob_get_length() > 0 && ENVIRONMENT == 'production') {
        ob_end_clean();
    }

    return $pdf;
}


/**
 * Prepare general credit note pdf
 * @param  object $credit_note Credit note as object with all necessary fields
 * @return mixed object
 */
function credit_note_pdf($credit_note, $tag = '')
{
    $GLOBALS['credit_note_pdf'] = $credit_note;
    $CI =& get_instance();
    load_pdf_language($credit_note->clientid);
    $CI->load->library('pdf');
    $credit_note_number = format_credit_note_number($credit_note->id);

    $font_name      = get_option('pdf_font');
    $font_size      = get_option('pdf_font_size');

    if ($font_size == '') {
        $font_size = 10;
    }

    $whereCF = array('show_on_pdf'=>1);
    if (is_custom_fields_for_customers_portal()) {
        $whereCF['show_on_client_portal'] = 1;
    }

    // check for credit note custom fields which is checked show on pdf
    $pdf_custom_fields = get_custom_fields('credit_note', $whereCF);

    $formatArray = get_pdf_format('pdf_format_credit_note');

    if (!file_exists(APPPATH.'libraries/Credit_note_pdf.php')) {
        $pdf         = new Pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false, 'credit_note');
    } else {
        include_once(APPPATH.'libraries/Credit_note_pdf.php');
        $pdf         = new Credit_note_pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false);
    }

    if (defined('APP_PDF_MARGIN_LEFT') && defined('APP_PDF_MARGIN_TOP') && defined('APP_PDF_MARGIN_RIGHT')) {
        $pdf->SetMargins(APP_PDF_MARGIN_LEFT, APP_PDF_MARGIN_TOP, APP_PDF_MARGIN_RIGHT);
    }

    $pdf->SetTitle($credit_note_number);

    $pdf->SetAutoPageBreak(true, (defined('APP_PDF_MARGIN_BOTTOM') ? APP_PDF_MARGIN_BOTTOM : PDF_MARGIN_BOTTOM));

    $pdf->SetAuthor(get_option('company'));
    $pdf->SetFont($font_name, '', $font_size);
    $pdf->setImageScale(1.53);
    $pdf->setJPEGQuality(100);
    $pdf->AddPage($formatArray['orientation'], $formatArray['format']);

    if ($CI->input->get('print') == 'true') {
        // force print dialog
        $js = 'print(true);';
        $pdf->IncludeJS($js);
    }

    $swap   = get_option('swap_pdf_info');

    $CI->load->library('numberword', array(
        'clientid' => $credit_note->clientid,
    ));

    $credit_note = do_action('credit_note_html_pdf_data', $credit_note);

    _bulk_pdf_export_maybe_tag($tag, $pdf);
     if (file_exists(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_credit_note_pdf.php')) {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_credit_note_pdf.php');
    } else {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/credit_note_pdf.php');
    }

    if (ob_get_length() > 0 && ENVIRONMENT == 'production') {
        ob_end_clean();
    }

    return $pdf;
}

/**
 * Prepare general estimate pdf
 * @since  Version 1.0.2
 * @param  object $estimate estimate as object with all necessary fields
 * @return mixed object
 */
function estimate_pdf($estimate, $tag = '')
{
    $GLOBALS['estimate_pdf'] = $estimate;
    $CI =& get_instance();
    load_pdf_language($estimate->clientid);
    $CI->load->library('pdf');
    $estimate_number = format_estimate_number($estimate->id);
    $font_name       = get_option('pdf_font');
    $font_size       = get_option('pdf_font_size');

    if ($font_size == '') {
        $font_size = 10;
    }

    $whereCF = array('show_on_pdf'=>1);
    if (is_custom_fields_for_customers_portal()) {
        $whereCF['show_on_client_portal'] = 1;
    }

    $pdf_custom_fields = get_custom_fields('estimate', $whereCF);

    $formatArray = get_pdf_format('pdf_format_estimate');

    if (!file_exists(APPPATH.'libraries/Estimate_pdf.php')) {
        $pdf         = new Pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false, 'estimate');
    } else {
        include_once(APPPATH.'libraries/Estimate_pdf.php');
        $pdf         = new Estimate_pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false);
    }

    if (defined('APP_PDF_MARGIN_LEFT') && defined('APP_PDF_MARGIN_TOP') && defined('APP_PDF_MARGIN_RIGHT')) {
        $pdf->SetMargins(APP_PDF_MARGIN_LEFT, APP_PDF_MARGIN_TOP, APP_PDF_MARGIN_RIGHT);
    }

    $pdf->SetTitle($estimate_number);

    $pdf->SetAutoPageBreak(true, (defined('APP_PDF_MARGIN_BOTTOM') ? APP_PDF_MARGIN_BOTTOM : PDF_MARGIN_BOTTOM));
    $pdf->setImageScale(1.53);
    $pdf->SetAuthor(get_option('company'));
    $pdf->SetFont($font_name, '', $font_size);
    $pdf->setJPEGQuality(100);
    $pdf->AddPage($formatArray['orientation'], $formatArray['format']);
    if ($CI->input->get('print') == 'true') {
        // force print dialog
        $js = 'print(true);';
        $pdf->IncludeJS($js);
    }
    $status = $estimate->status;
    $swap   = get_option('swap_pdf_info');
    $CI->load->library('numberword', array(
        'clientid' => $estimate->clientid,
    ));
    $estimate = do_action('estimate_html_pdf_data', $estimate);

    _bulk_pdf_export_maybe_tag($tag, $pdf);
    if (file_exists(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_estimatepdf.php')) {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_estimatepdf.php');
    } else {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/estimatepdf.php');
    }

    if (ob_get_length() > 0 && ENVIRONMENT == 'production') {
        ob_end_clean();
    }

    return $pdf;
}
/**
 * Function that generates proposal pdf for admin and clients area
 * @param  object $proposal
 * @param  string $tag      tag to include in the proposal when using the bulk pdf exported
 * @return object
 */
function proposal_pdf($proposal, $tag = '')
{
    $GLOBALS['proposal_pdf'] = $proposal;
    $CI =& get_instance();

    if ($proposal->rel_id != null && $proposal->rel_type == 'customer') {
        load_pdf_language($proposal->rel_id);
    }

    $CI->load->library('pdf');

    $number_word_lang_rel_id = 'unknown';
    if ($proposal->rel_type == 'customer') {
        $number_word_lang_rel_id = $proposal->rel_id;
    }
    $CI->load->library('numberword', array(
        'clientid' => $number_word_lang_rel_id,
    ));

    $formatArray = get_pdf_format('pdf_format_proposal');

    if (!file_exists(APPPATH.'libraries/Proposal_pdf.php')) {
        $pdf         = new Pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false, 'proposal');
    } else {
        include_once(APPPATH.'libraries/Proposal_pdf.php');
        $pdf         = new Proposal_pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false);
    }


    $font_name = get_option('pdf_font');
    $font_size = get_option('pdf_font_size');
    if ($font_size == '') {
        $font_size = 10;
    }

    $proposal_url = site_url('viewproposal/' . $proposal->id . '/' . $proposal->hash);
    $number       = format_proposal_number($proposal->id);

    $pdf->setImageScale(1.53);
    $pdf->SetAutoPageBreak(true, (defined('APP_PDF_MARGIN_BOTTOM') ? APP_PDF_MARGIN_BOTTOM : PDF_MARGIN_BOTTOM));
    $pdf->setJPEGQuality(100);

    if (defined('APP_PDF_MARGIN_LEFT') && defined('APP_PDF_MARGIN_TOP') && defined('APP_PDF_MARGIN_RIGHT')) {
        $pdf->SetMargins(APP_PDF_MARGIN_LEFT, APP_PDF_MARGIN_TOP, APP_PDF_MARGIN_RIGHT);
    }

    $pdf->SetTitle($number);
    $pdf->SetDisplayMode('default', 'OneColumn');
    $pdf->SetAuthor(get_option('company'));
    $pdf->SetFont($font_name, '', $font_size);
    $pdf->AddPage($formatArray['orientation'], $formatArray['format']);
    if ($CI->input->get('print') == 'true') {
        // force print dialog
        $js = 'print(true);';
        $pdf->IncludeJS($js);
    }
    $swap = get_option('swap_pdf_info');
    $CI->load->model('currencies_model');
    $total = '';
    if ($proposal->total != 0) {
        $total = format_money($proposal->total, $CI->currencies_model->get($proposal->currency)->symbol);
        $total = _l('proposal_total') . ': ' . $total;
    }
    # Don't remove these lines - important for the PDF layout
    // Add <br /> tag and wrap over div element every image to prevent overlaping over text
    $proposal->content = preg_replace('/(<img[^>]+>(?:<\/img>)?)/i', '<div>$1</div>', $proposal->content);
    // Add cellpadding to all tables inside the html
    $proposal->content = preg_replace('/(<table\b[^><]*)>/i', '$1 cellpadding="4">', $proposal->content);
    // Remove white spaces cased by the html editor ex. <td>  item</td>
    $proposal->content = preg_replace('/[\t\n\r\0\x0B]/', '', $proposal->content);
    $proposal->content = preg_replace('/([\s])\1+/', ' ', $proposal->content);

    // Tcpdf does not support float css we need to adjust this here
    $proposal->content = str_replace('float: right', 'text-align: right', $proposal->content);
    $proposal->content = str_replace('float: left', 'text-align: left', $proposal->content);
    // Image center
    $proposal->content = str_replace('margin-left: auto; margin-right: auto;', 'text-align:center;', $proposal->content);

    $proposal          = do_action('proposal_html_pdf_data', $proposal);

    _bulk_pdf_export_maybe_tag($tag, $pdf);
    if (file_exists(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_proposalpdf.php')) {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_proposalpdf.php');
    } else {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/proposalpdf.php');
    }

    if (ob_get_length() > 0 && ENVIRONMENT == 'production') {
        ob_end_clean();
    }

    return $pdf;
}
/**
 * Generate contract pdf
 * @param  object $contract object db
 * @return mixed object
 */
function contract_pdf($contract)
{
    $GLOBALS['contract_pdf'] = $contract;
    $CI =& get_instance();
    $CI->load->library('pdf');

    $formatArray = get_pdf_format('pdf_format_contract');

    if (!file_exists(APPPATH.'libraries/Contract_pdf.php')) {
        $pdf         = new Pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false, 'contract');
    } else {
        include_once(APPPATH.'libraries/Contract_pdf.php');
        $pdf         = new Contract_pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false);
    }

    $font_name = get_option('pdf_font');
    $font_size = get_option('pdf_font_size');
    if ($font_size == '') {
        $font_size = 10;
    }

    $pdf->SetAutoPageBreak(true, (defined('APP_PDF_MARGIN_BOTTOM') ? APP_PDF_MARGIN_BOTTOM : PDF_MARGIN_BOTTOM));
    $pdf->setImageScale(1.53);

    if (defined('APP_PDF_MARGIN_LEFT') && defined('APP_PDF_MARGIN_TOP') && defined('APP_PDF_MARGIN_RIGHT')) {
        $pdf->SetMargins(APP_PDF_MARGIN_LEFT, APP_PDF_MARGIN_TOP, APP_PDF_MARGIN_RIGHT);
    }

    $pdf->SetTitle($contract->subject);
    $pdf->SetAuthor(get_option('company'));
    $pdf->SetFont($font_name, '', $font_size);
    $pdf->AddPage($formatArray['orientation'], $formatArray['format']);
    if ($CI->input->get('print') == 'true') {
        // force print dialog
        $js = 'print(true);';
        $pdf->IncludeJS($js);
    }
    # Don't remove these lines - important for the PDF layout
    // Add <br /> tag and wrap over div element every image to prevent overlaping over text
    $contract->content = preg_replace('/(<img[^>]+>(?:<\/img>)?)/i', '<div>$1</div>', $contract->content);
    // Add cellpadding to all tables inside the html
    $contract->content = preg_replace('/(<table\b[^><]*)>/i', '$1 cellpadding="4">', $contract->content);
    // Remove white spaces cased by the html editor ex. <td>  item</td>
    $contract->content = preg_replace('/[\t\n\r\0\x0B]/', '', $contract->content);
    $contract->content = preg_replace('/([\s])\1+/', ' ', $contract->content);

    // Tcpdf does not support float css we need to adjust this here
    $contract->content = str_replace('float: right', 'text-align: right', $contract->content);
    $contract->content = str_replace('float: left', 'text-align: left', $contract->content);
    // Image center
    $contract->content = str_replace('margin-left: auto; margin-right: auto;', 'text-align:center;', $contract->content);

    if (file_exists(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_contractpdf.php')) {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_contractpdf.php');
    } else {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/contractpdf.php');
    }

    if (ob_get_length() > 0 && ENVIRONMENT == 'production') {
        ob_end_clean();
    }

    return $pdf;
}
/**
 * Generate payment pdf
 * @since  Version 1.0.1
 * @param  object $payment All payment data
 * @return mixed object
 */
function payment_pdf($payment, $tag = '')
{
    $GLOBALS['payment_pdf'] = $payment;
    $CI =& get_instance();
    load_pdf_language($payment->invoice_data->clientid);
    $CI->load->library('pdf');

    $formatArray = get_pdf_format('pdf_format_payment');

    if (!file_exists(APPPATH.'libraries/Payment_pdf.php')) {
        $pdf         = new Pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false, 'payment');
    } else {
        include_once(APPPATH.'libraries/Payment_pdf.php');
        $pdf         = new Payment_pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false);
    }

    $amountDue = ($payment->invoice_data->status != 2 && $payment->invoice_data->status != 5 ? true : false);

    $font_name = get_option('pdf_font');
    $font_size = get_option('pdf_font_size');

    if ($font_size == '') {
        $font_size = 10;
    }

    $swap = get_option('swap_pdf_info');

    if (defined('APP_PDF_MARGIN_LEFT') && defined('APP_PDF_MARGIN_TOP') && defined('APP_PDF_MARGIN_RIGHT')) {
        $pdf->SetMargins(APP_PDF_MARGIN_LEFT, APP_PDF_MARGIN_TOP, APP_PDF_MARGIN_RIGHT);
    }
    $pdf->SetTitle(_l('payment') . ' #' . $payment->paymentid);

    $pdf->SetAutoPageBreak(true, (defined('APP_PDF_MARGIN_BOTTOM') ? APP_PDF_MARGIN_BOTTOM : PDF_MARGIN_BOTTOM));
    $pdf->setImageScale(1.53);
    $pdf->setJPEGQuality(100);
    $pdf->SetAuthor(get_option('company'));
    $pdf->SetFont($font_name, '', $font_size);
    $pdf->AddPage($formatArray['orientation'], $formatArray['format']);

    if ($CI->input->get('print') == 'true') {
        // force print dialog
        $js = 'print(true);';
        $pdf->IncludeJS($js);
    }

    _bulk_pdf_export_maybe_tag($tag, $pdf);
    if (file_exists(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_paymentpdf.php')) {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_paymentpdf.php');
    } else {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/paymentpdf.php');
    }

    if (ob_get_length() > 0 && ENVIRONMENT == 'production') {
        ob_end_clean();
    }

    return $pdf;
}

/**
 * Prepare customer statement pdf
 * @param  object $statement statement
 * @return mixed
 */
function statement_pdf($statement)
{
    $GLOBALS['statement_pdf'] = $statement;
    $CI =& get_instance();
    load_pdf_language($statement['client_id']);
    $CI->load->library('pdf');

    $font_name      = get_option('pdf_font');
    $font_size      = get_option('pdf_font_size');

    if ($font_size == '') {
        $font_size = 10;
    }

    $formatArray = get_pdf_format('pdf_format_statement');
    if (!file_exists(APPPATH.'libraries/Statement_pdf.php')) {
        $pdf         = new Pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false, 'statement');
    } else {
        include_once(APPPATH.'libraries/Statement_pdf.php');
        $pdf         = new Statement_pdf($formatArray['orientation'], 'mm', $formatArray['format'], true, 'UTF-8', false, false);
    }

    if (defined('APP_PDF_MARGIN_LEFT') && defined('APP_PDF_MARGIN_TOP') && defined('APP_PDF_MARGIN_RIGHT')) {
        $pdf->SetMargins(APP_PDF_MARGIN_LEFT, APP_PDF_MARGIN_TOP, APP_PDF_MARGIN_RIGHT);
    }
    $pdf->SetTitle(_l('account_summary'));

    $pdf->SetAutoPageBreak(true, (defined('APP_PDF_MARGIN_BOTTOM') ? APP_PDF_MARGIN_BOTTOM : PDF_MARGIN_BOTTOM));

    $pdf->SetAuthor(get_option('company'));
    $pdf->SetFont($font_name, '', $font_size);
    $pdf->setImageScale(1.53);
    $pdf->setJPEGQuality(100);
    $pdf->AddPage($formatArray['orientation'], $formatArray['format']);

    if ($CI->input->get('print') == 'true') {
        // force print dialog
        $js = 'print(true);';
        $pdf->IncludeJS($js);
    }

    if (file_exists(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_statementpdf.php')) {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/my_statementpdf.php');
    } else {
        include(APPPATH . 'views/themes/' . active_clients_theme() . '/views/statementpdf.php');
    }

    if (ob_get_length() > 0 && ENVIRONMENT == 'production') {
        ob_end_clean();
    }

    return $pdf;
}

function _bulk_pdf_export_maybe_tag($tag, &$pdf) {
    // Tag - used in BULK pdf exporter
    if ($tag != '') {
        $font_name      = get_option('pdf_font');
        $font_size      = get_option('pdf_font_size');

        if ($font_size == '') {
            $font_size = 10;
        }
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetDrawColor(245, 245, 245);
        $pdf->SetXY(0, 0);
        $pdf->SetFont($font_name, 'B', 15);
        $pdf->SetTextColor(0);
        $pdf->SetLineWidth(0.75);
        $pdf->StartTransform();
        $pdf->Rotate(-35, 109, 235);
        $pdf->Cell(100, 1, mb_strtoupper($tag, 'UTF-8'), 'TB', 0, 'C', '1');
        $pdf->StopTransform();
        $pdf->SetFont($font_name, '', $font_size);
        $pdf->setX(10);
        $pdf->setY(10);
    }
}

/**
 * Calculate estimates percent by status
 * @param  mixed $status          estimate status
 * @param  mixed $total_estimates in case the total is calculated in other place
 * @return array
 */
function get_estimates_percent_by_status($status, $total_estimates = '')
{
    $has_permission_view = has_permission('estimates', '', 'view');

    if (!is_numeric($total_estimates)) {
        $where_total = array();
        if (!$has_permission_view) {
            $where_total['addedfrom'] = get_staff_user_id();
        }
        $total_estimates = total_rows('tblestimates', $where_total);
    }

    $data            = array();
    $total_by_status = 0;

    if (!is_numeric($status)) {
        if ($status == 'not_sent') {
            $total_by_status = total_rows('tblestimates', 'sent=0 AND status NOT IN(2,3,4)' . (!$has_permission_view ? ' AND addedfrom=' . get_staff_user_id() : ''));
        }
    } else {
        $where = array(
            'status' => $status,
        );
        if (!$has_permission_view) {
            $where = array_merge($where, array(
                'addedfrom' => get_staff_user_id(),
            ));
        }
        $total_by_status = total_rows('tblestimates', $where);
    }

    $percent                 = ($total_estimates > 0 ? number_format(($total_by_status * 100) / $total_estimates, 2) : 0);
    $data['total_by_status'] = $total_by_status;
    $data['percent']         = $percent;
    $data['total']           = $total_estimates;

    return $data;
}
/**
 * Calculate proposal percent by status
 * @param  mixed $status          proposal status
 * @param  mixed $total_estimates in case the total is calculated in other place
 * @return array
 */
function get_proposals_percent_by_status($status, $total_proposals = '')
{
    $has_permission_view = has_permission('proposals', '', 'view');
    $has_permission_view_own = has_permission('proposals', '', 'view_own');
    $allow_staff_view_proposals_assigned = get_option('allow_staff_view_proposals_assigned');
    $staffId = get_staff_user_id();

    $whereUser = '';
    if (!$has_permission_view) {
        if ($has_permission_view_own) {
            $whereUser = '(addedfrom='.$staffId;
            if ($allow_staff_view_proposals_assigned == 1) {
                $whereUser .= ' OR assigned='.$staffId;
            }
            $whereUser .= ')';
        } else {
            $whereUser .= 'assigned='.$staffId;
        }
    }

    if (!is_numeric($total_proposals)) {
        $total_proposals = total_rows('tblproposals', $whereUser);
    }

    $data            = array();
    $total_by_status = 0;
    $where           ='status='.$status;
    if (!$has_permission_view) {
        $where .= ' AND (' .$whereUser .')';
    }

    $total_by_status = total_rows('tblproposals', $where);
    $percent = ($total_proposals > 0 ? number_format(($total_by_status * 100) / $total_proposals, 2) : 0);

    $data['total_by_status'] = $total_by_status;
    $data['percent']         = $percent;
    $data['total']           = $total_proposals;

    return $data;
}
/**
 * This function do not work with cancelled status
 * Calculate invoices percent by status
 * @param  mixed $status          estimate status
 * @param  mixed $total_invoices in case the total is calculated in other place
 * @return array
 */
function get_invoices_percent_by_status($status, $total_invoices = '')
{
    $has_permission_view = has_permission('invoices', '', 'view');

    if (!is_numeric($total_invoices)) {
        $where_total = 'status NOT IN(5)';
        if (!$has_permission_view) {
            $where_total .= ' AND addedfrom=' . get_staff_user_id();
        }
        $total_invoices = total_rows('tblinvoices', $where_total);
    }
    $data            = array();
    $total_by_status = 0;
    if (!is_numeric($status)) {
        if ($status == 'not_sent') {
            $total_by_status = total_rows('tblinvoices', 'sent=0 AND status NOT IN(2,5)' . (!$has_permission_view ? ' AND addedfrom=' . get_staff_user_id() : ''));
        }
    } else {
        $total_by_status = total_rows('tblinvoices', 'status = ' . $status . ' AND status NOT IN(5)' . (!$has_permission_view ? ' AND addedfrom=' . get_staff_user_id() : ''));
    }
    $percent                 = ($total_invoices > 0 ? number_format(($total_by_status * 100) / $total_invoices, 2) : 0);
    $data['total_by_status'] = $total_by_status;
    $data['percent']         = $percent;
    $data['total']           = $total_invoices;

    return $data;
}
/**
 * Load invoices total templates
 * This is the template where is showing the panels Outstanding Invoices, Paid Invoices and Past Due invoices
 * @return string
 */
function load_invoices_total_template()
{
    $CI = &get_instance();
    $CI->load->model('invoices_model');
    $_data = $CI->input->post();
    if (!$CI->input->post('customer_id')) {
        $multiple_currencies = call_user_func('is_using_multiple_currencies');
    } else {
        $_data['customer_id'] = $CI->input->post('customer_id');
        $multiple_currencies  = call_user_func('is_client_using_multiple_currencies', $CI->input->post('customer_id'));
    }

    if ($CI->input->post('project_id')) {
        $_data['project_id'] = $CI->input->post('project_id');
    }

    if ($multiple_currencies) {
        $CI->load->model('currencies_model');
        $data['invoices_total_currencies'] = $CI->currencies_model->get();
    }
    $data['invoices_years']       = $CI->invoices_model->get_invoices_years();
    $data['total_result'] = $CI->invoices_model->get_invoices_total($_data);
    $data['_currency']    = $data['total_result']['currencyid'];
    $CI->load->view('admin/invoices/invoices_total_template', $data);
}

/**
 * This function replace <br /> only nothing exists in the line and first line other then <br />
 *  Replace first <br /> lines to prevent new spaces
 * @param  string $text The text to perform the action
 * @return string
 */
function _maybe_remove_first_and_last_br_tag($text)
{
    $text = preg_replace('/^<br ?\/?>/is', '', $text);
    // Replace last <br /> lines to prevent new spaces while there is new line
    while (preg_match('/<br ?\/?>$/', $text)) {
        $text = preg_replace('/<br ?\/?>$/is', '', $text);
    }

    return $text;
}
function _info_format_replace($mergeCode, $val, $txt)
{
    $tmpVal = strip_tags($val);

    if ($tmpVal != '') {
        $result = preg_replace('/({'.$mergeCode.'})/i', $val, $txt);
    } else {
        $re = '/\s{0,}{'.$mergeCode.'}(<br ?\/?>(\n))?/i';
        $result = preg_replace($re, '', $txt);
    }

    return $result;
}
function _info_format_custom_field($id, $label, $value, $txt)
{
    if ($value != '') {
        $result = preg_replace('/({cf_'.$id.'})/i', $label .': ' . $value, $txt);
    } else {
        $re = '/\s{0,}{cf_'.$id.'}(<br ?\/?>(\n))?/i';
        $result = preg_replace($re, '', $txt);
    }

    return $result;
}

function _info_format_custom_fields_check($custom_fields, $txt)
{
    if (count($custom_fields) == 0 || preg_match_all('/({cf_[0-9]{1,}})/i', $txt, $matches, PREG_SET_ORDER, 0) > 0) {
        $txt = preg_replace('/\s{0,}{cf_[0-9]{1,}}(<br ?\/?>(\n))?/i', '', $txt);
    }

    return $txt;
}

if (!function_exists('format_customer_info')) {
    function format_customer_info($data, $for, $type, $companyLink = false)
    {
        $format = get_option('customer_info_format');
        $clientId = '';

        if ($for == 'statement') {
            $clientId = $data->userid;
        } elseif ($type == 'billing') {
            $clientId = $data->clientid;
        }

        $companyName = '';
        if ($for == 'statement') {
            $companyName = get_company_name($clientId);
        } elseif ($type == 'billing') {
            $companyName = $data->client->company;
        }

        if ($for == 'invoice' || $for == 'estimate' || $for == 'payment' || $for == 'credit_note') {
            if ($data->client->show_primary_contact == 1) {
                $primaryContactId = get_primary_contact_user_id($clientId);
                if ($primaryContactId) {
                    $companyName = get_contact_full_name($primaryContactId) .'<br />' . $companyName;
                }
            }
        }

        $street = '';
        if ($type == 'billing') {
            $street = $data->billing_street;
        } elseif ($type == 'shipping') {
            $street = $data->shipping_street;
        }

        $city = '';
        if ($type == 'billing') {
            $city = $data->billing_city;
        } elseif ($type == 'shipping') {
            $city = $data->billing_city;
        }
        $state = '';
        if ($type == 'billing') {
            $state = $data->billing_state;
        } elseif ($type == 'shipping') {
            $state = $data->shipping_state;
        }
        $zipCode = '';
        if ($type == 'billing') {
            $zipCode = $data->billing_zip;
        } elseif ($type == 'shipping') {
            $zipCode = $data->shipping_zip;
        }

        $countryCode = '';
        $countryName = '';
        $country = null;
        if ($type == 'billing') {
            $country = get_country($data->billing_country);
        } elseif ($type == 'shipping') {
            $country = get_country($data->shipping_country);
        }

        if ($country) {
            $countryCode = $country->iso2;
            $countryName = $country->short_name;
        }

        $phone = '';
        if ($for == 'statement') {
            $phone = $data->phonenumber;
        } elseif ($type == 'billing') {
            $phone = $data->client->phonenumber;
        }

        $vat = '';
        if ($for == 'statement') {
            $vat = $data->vat;
        } elseif ($type == 'billing') {
            $vat = $data->client->vat;
        }

        if ($companyLink) {
            $companyName = '<a href="'.admin_url('clients/client/'.$clientId).'" target="_blank"><b>'.$companyName.'</b></a>';
        } elseif ($companyName != '') {
            $companyName  = '<b>'.$companyName.'</b>';
        }

        $format = _info_format_replace('company_name', $companyName, $format);
        $format = _info_format_replace('street', $street, $format);
        $format = _info_format_replace('city', $city, $format);
        $format = _info_format_replace('state', $state, $format);
        $format = _info_format_replace('zip_code', $zipCode, $format);
        $format = _info_format_replace('country_code', $countryCode, $format);
        $format = _info_format_replace('country_name', $countryName, $format);
        $format = _info_format_replace('phone', $phone, $format);
        $format = _info_format_replace('vat_number', $vat, $format);
        $format = _info_format_replace('vat_number_with_label', $vat == '' ? '' : _l('client_vat_number').': '. $vat, $format);

        $customFieldsCustomer = array();

        // On shipping address no custom fields are shown
        if ($type != 'shipping') {
            $whereCF = array();

            if (is_custom_fields_for_customers_portal()) {
                $whereCF['show_on_client_portal'] = 1;
            }

            $customFieldsCustomer = get_custom_fields('customers', $whereCF);
        }

        foreach ($customFieldsCustomer as $field) {
            $value = get_custom_field_value($clientId, $field['id'], 'customers');
            $format = _info_format_custom_field($field['id'], $field['name'], $value, $format);
        }

        // If no custom fields found replace all custom fields merge fields to empty
        $format = _info_format_custom_fields_check($customFieldsCustomer, $format);
        $format = _maybe_remove_first_and_last_br_tag($format);

        // Remove multiple white spaces
        $format = preg_replace('/\s+/', ' ', $format);
        $format = trim($format);

        return $format;
    }
}


if (!function_exists('format_proposal_info')) {
    function format_proposal_info($proposal, $for = '')
    {
        $format = get_option('proposal_info_format');

        $countryCode = '';
        $countryName = '';
        $country = get_country($proposal->country);

        if ($country) {
            $countryCode = $country->iso2;
            $countryName = $country->short_name;
        }

        $proposalTo = '<b>'.$proposal->proposal_to.'</b>';
        $phone = $proposal->phone;
        $email = $proposal->email;

        if ($for == 'admin') {
            $hrefAttrs = '';
            if ($proposal->rel_type == 'lead') {
                $hrefAttrs = ' href="#" onclick="init_lead('.$proposal->rel_id.'); return false;" data-toggle="tooltip" data-title="'._l('lead').'"';
            } else {
                $hrefAttrs = ' href="'.admin_url('clients/client/'.$proposal->rel_id).'" data-toggle="tooltip" data-title="'._l('client').'"';
            }
            $proposalTo = '<a' . $hrefAttrs . '>' . $proposalTo . '</a>';
        }

        if ($for == 'html' || $for == 'admin') {
            $phone = '<a href="tel:'.$proposal->phone.'">'.$proposal->phone.'</a>';
            $email = '<a href="mailto:'.$proposal->email.'">'.$proposal->email.'</a>';
        }

        $format = _info_format_replace('proposal_to', $proposalTo, $format);
        $format = _info_format_replace('address', $proposal->address, $format);
        $format = _info_format_replace('city', $proposal->city, $format);
        $format = _info_format_replace('state', $proposal->state, $format);

        $format = _info_format_replace('country_code', $countryCode, $format);
        $format = _info_format_replace('country_name', $countryName, $format);

        $format = _info_format_replace('zip_code', $proposal->zip, $format);
        $format = _info_format_replace('phone', $phone, $format);
        $format = _info_format_replace('email', $email, $format);

        $whereCF = array();
        if (is_custom_fields_for_customers_portal()) {
            $whereCF['show_on_client_portal'] = 1;
        }
        $customFieldsProposals = get_custom_fields('proposal', $whereCF);

        foreach ($customFieldsProposals as $field) {
            $value = get_custom_field_value($proposal->id, $field['id'], 'proposal');
            $format = _info_format_custom_field($field['id'], $field['name'], $value, $format);
        }

        // If no custom fields found replace all custom fields merge fields to empty
        $format = _info_format_custom_fields_check($customFieldsProposals, $format);
        $format = _maybe_remove_first_and_last_br_tag($format);

        // Remove multiple white spaces
        $format = preg_replace('/\s+/', ' ', $format);
        $format = trim($format);

        return $format;
    }
}

if (!function_exists('format_organization_info')) {
    function format_organization_info()
    {
        $format = get_option('company_info_format');
        $vat = get_option('company_vat');

        $format = _info_format_replace('company_name', '<b style="color:black" class="company-name-formatted">'.get_option('invoice_company_name').'</b>', $format);
        $format = _info_format_replace('address', get_option('invoice_company_address'), $format);
        $format = _info_format_replace('city', get_option('invoice_company_city'), $format);
        $format = _info_format_replace('state', get_option('company_state'), $format);

        $format = _info_format_replace('zip_code', get_option('invoice_company_postal_code'), $format);
        $format = _info_format_replace('country_code', get_option('invoice_company_country_code'), $format);
        $format = _info_format_replace('phone', get_option('invoice_company_phonenumber'), $format);
        $format = _info_format_replace('vat_number', $vat, $format);
        $format = _info_format_replace('vat_number_with_label', $vat == '' ? '':_l('company_vat_number').': '.$vat, $format);

        $custom_company_fields = get_company_custom_fields();

        foreach ($custom_company_fields as $field) {
            $format = _info_format_custom_field($field['id'], $field['label'], $field['value'], $format);
        }

        $format = _info_format_custom_fields_check($custom_company_fields, $format);
        $format = _maybe_remove_first_and_last_br_tag($format);

        // Remove multiple white spaces
        $format = preg_replace('/\s+/', ' ', $format);
        $format = trim($format);

        return $format;
    }
}

/**
 * Return decimal places
 * The srcipt do not support more then 2 decimal places but developers can use action hook to change the decimal places
 * @return [type] [description]
 */
function get_decimal_places()
{
    return do_action('app_decimal_places', 2);
}

/**
 * Get all items by type eq. invoice, proposal, estimates, credit note
 * @param  string $type rel_type value
 * @return array
 */
function get_items_by_type($type, $id)
{
    $CI = &get_instance();
    $CI->db->select();
    $CI->db->from('tblitems_in');
    $CI->db->where('rel_id', $id);
    $CI->db->where('rel_type', $type);
    $CI->db->order_by('item_order', 'asc');

    return $CI->db->get()->result_array();
}
/**
* Function that update total tax in sales table eq. invoice, proposal, estimates, credit note
* @param  mixed $id
* @return void
*/
function update_sales_total_tax_column($id, $type, $table)
{
    $CI = &get_instance();
    $CI->db->select('discount_percent, discount_type');
    $CI->db->from($table);
    $data = $CI->db->get()->row();

    $items = get_items_by_type($type, $id);

    $total_tax         = 0;
    $taxes             = array();
    $_calculated_taxes = array();

    $func_taxes = 'get_'.$type.'_item_taxes';

    foreach ($items as $item) {
        $item_taxes = call_user_func($func_taxes, $item['id']);
        if (count($item_taxes) > 0) {
            foreach ($item_taxes as $tax) {
                $calc_tax     = 0;
                $tax_not_calc = false;
                if (!in_array($tax['taxname'], $_calculated_taxes)) {
                    array_push($_calculated_taxes, $tax['taxname']);
                    $tax_not_calc = true;
                }

                if ($tax_not_calc == true) {
                    $taxes[$tax['taxname']]          = array();
                    $taxes[$tax['taxname']]['total'] = array();
                    array_push($taxes[$tax['taxname']]['total'], (($item['qty'] * $item['rate']) / 100 * $tax['taxrate']));
                    $taxes[$tax['taxname']]['tax_name'] = $tax['taxname'];
                    $taxes[$tax['taxname']]['taxrate']  = $tax['taxrate'];
                } else {
                    array_push($taxes[$tax['taxname']]['total'], (($item['qty'] * $item['rate']) / 100 * $tax['taxrate']));
                }
            }
        }
    }

    foreach ($taxes as $tax) {
        $total = array_sum($tax['total']);
        if ($data->discount_percent != 0 && $data->discount_type == 'before_tax') {
            $total_tax_calculated = ($total * $data->discount_percent) / 100;
            $total                = ($total - $total_tax_calculated);
        }
        $total_tax += $total;
    }

    $CI->db->where('id', $id);
    $CI->db->update($table, array(
            'total_tax' => $total_tax,
    ));
}

/**
 * Function used for sales eq. invoice, estimate, proposal, credit note
 * @param  mixed $item_id   item id
 * @param  array $post_item $item from $_POST
 * @param  mixed $rel_id    rel_id
 * @param  string $rel_type  where this item tax is related
 */
function _maybe_insert_post_item_tax($item_id, $post_item, $rel_id, $rel_type)
{
    $affectedRows = 0;
    if (isset($post_item['taxname']) && is_array($post_item['taxname'])) {
        $CI = &get_instance();
        foreach ($post_item['taxname'] as $taxname) {
            if ($taxname != '') {
                $tax_array    = explode('|', $taxname);
                if (isset($tax_array[0]) && isset($tax_array[1])) {
                    $tax_name = trim($tax_array[0]);
                    $tax_rate = trim($tax_array[1]);
                    if (total_rows('tblitemstax', array(
                        'itemid'=>$item_id,
                        'taxrate'=>$tax_rate,
                        'taxname'=>$tax_name,
                        'rel_id'=>$rel_id,
                        'rel_type'=>$rel_type,
                    )) == 0) {
                        $CI->db->insert('tblitemstax', array(
                                'itemid' => $item_id,
                                'taxrate' => $tax_rate,
                                'taxname' => $tax_name,
                                'rel_id' => $rel_id,
                                'rel_type' => $rel_type,
                        ));
                        $affectedRows++;
                    }
                }
            }
        }
    }
    return $affectedRows > 0 ? true : false;
}

function add_new_sales_item_post($item, $rel_id, $rel_type)
{
    $CI = &get_instance();
    $CI->db->insert('tblitems_in', array(
                    'description' => $item['description'],
                    'long_description' => nl2br($item['long_description']),
                    'qty' => $item['qty'],
                    'rate' => number_format($item['rate'], get_decimal_places(), '.', ''),
                    'rel_id' => $rel_id,
                    'rel_type' => $rel_type,
                    'item_order' => $item['order'],
                    'unit' => $item['unit'],
                ));

    return $CI->db->insert_id();
}

function update_sales_item_post($item_id, $data, $field = '')
{
    $update = array();
    if ($field !== '') {
        if ($field == 'long_description') {
            $update[$field] = nl2br($data[$field]);
        } elseif ($field == 'rate') {
            $update[$field] = number_format($data[$field], get_decimal_places(), '.', '');
        } elseif ($field == 'item_order') {
            $update[$field] = $data['order'];
        } else {
            $update[$field] = $data[$field];
        }
    } else {
        $update = array(
            'item_order' => $data['order'],
            'description' => $data['description'],
            'long_description' => nl2br($data['long_description']),
            'rate' => number_format($data['rate'], get_decimal_places(), '.', ''),
            'qty' => $data['qty'],
            'unit' => $data['unit'],
        );
    }

    $CI = &get_instance();
    $CI->db->where('id', $item_id);
    $CI->db->update('tblitems_in', $update);

    return $CI->db->affected_rows() > 0 ? true : false;
}

function handle_removed_sales_item_post($id, $rel_type)
{
    $CI = &get_instance();
    $CI->db->where('id', $id);
    $CI->db->delete('tblitems_in');
    if ($CI->db->affected_rows() > 0) {
        $CI->db->where('itemid', $id)
                    ->where('rel_type', $rel_type)
                    ->delete('tblitemstax');

        return true;
    }

    return false;
}

function delete_taxes_from_item($item_id, $rel_type)
{
    $CI = &get_instance();
    $CI->db->where('itemid', $item_id)
    ->where('rel_type', $rel_type)
    ->delete('tblitemstax');

    return $CI->db->affected_rows() > 0 ? true : false;
}
