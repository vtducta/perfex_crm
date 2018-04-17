<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Cron extends CRM_Controller
{
    public function __construct()
    {
        parent::__construct();
        update_option('cron_has_run_from_cli', 1);
        $this->load->model('cron_model');
    }

    public function index($key = "")
    {
        if(defined('APP_CRON_KEY') && (APP_CRON_KEY != $key)){
            header('HTTP/1.0 401 Unauthorized');
            die('Passed cron job key is not correct. The cron job key should be the same like the one defined in APP_CRON_KEY constant.');
        }

        do_action('before_cron_run');

        $last_cron_run = get_option('last_cron_run');
        if ($last_cron_run == '' || (time() > ($last_cron_run + do_action('cron_functions_execute_seconds',300)))) {
            $this->cron_model->run();
            $this->email->send_queue();
        }

        $last_email_queue_retry = get_option('last_email_queue_retry');

        // Retry queue failed emails every 4 minutes
        if ($last_email_queue_retry == '' || (time() > ($last_email_queue_retry + do_action('cron_retry_email_queue_seconds',240)))) {
            $this->email->retry_queue();
            update_option('last_email_queue_retry',time());
        }

        do_action('after_cron_run');
    }
}
