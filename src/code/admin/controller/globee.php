<?php

if (true === version_compare(VERSION, '2.3.0', '<')) {
    require __DIR__.'/../../../system/library/globee/autoload.php';
} else {
    require __DIR__.'/../../../../system/library/globee/autoload.php';
}

use GloBee\PaymentApi\Connectors\GloBeeCurlConnector;
use GloBee\PaymentApi\Exceptions\Http\AuthenticationException;
use GloBee\PaymentApi\Models\PaymentRequest;
use GloBee\PaymentApi\PaymentApi;

/** Used by OC 2.1 */
class ControllerPaymentGlobee extends ControllerExtensionPaymentGlobee {}

/**
 * Class ControllerExtensionPaymentGlobee
 */
class ControllerExtensionPaymentGlobee extends Controller
{
    /** @var array */
    protected $error = array();

    /** @var $registry */
    protected $registry;
    
    /** @var string  */
    protected $token = 'user_token';

    /** @var string  */
    protected $paymentBreadcrumbLink = 'marketplace/extension';

    /** @var string  */
    protected $paymentExtensionLink = 'extension/payment';

    /** @var string  */
    protected $code = 'payment_globee';

    /** @var string  */
    protected $viewPath = 'extension/payment/globee';

    /**
     * ControllerExtensionPaymentGlobee constructor.
     *
     * @param $registry
     */
    public function __construct($registry)
    {
        parent::__construct($registry);

        if (!empty($missingRequirements = $this->missingRequirements())) {
            echo $missingRequirements;
            exit;
        }

        $this->registry = $registry;

        if (true === version_compare(VERSION, '2.3.0', '<')) {
            $this->token = 'token';
            $this->viewPath = 'payment/globee.tpl';
            $this->paymentExtensionLink = 'payment';
            $this->paymentBreadcrumbLink = 'extension/payment';
            $this->code = 'globee';
        } elseif (true === version_compare(VERSION, '3.0.0', '<')) {
            $this->token = 'token';
            $this->paymentBreadcrumbLink = 'extension/extension';
            $this->code = 'globee';
        }

        $this->load->language($this->paymentExtensionLink.'/globee');
    }

    /**
     * Magic function to allow calling $this->db instead of $this->registry->get('db')
     *
     * @param $name
     *
     * @return mixed
     */
    public function __get($name)
    {
        return $this->registry->get($name);
    }

    /**
     * Logger function for debugging
     *
     * @param $message
     */
    public function log($message)
    {
        if ($this->config->get($this->code.'_logging') != true) {
            return;
        }
        $log = new Log('globee.log');
        $log->write($message);
    }

    /**
     * Plugin installer
     */
    public function install()
    {
        $this->log('Installing');
        $this->load->model('localisation/order_status');
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_status','0','0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_sort_order','1','0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_livenet','1','0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_payment_api_key','','0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_risk_speed','medium','0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_paid_status',2,'0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_confirmed_status',15,'0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_completed_status',5,'0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_notification_url','".str_replace("admin/", "", $this->url->link($this->paymentExtensionLink.'/globee/callback', $this->config->get('config_secure')))."','0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_redirect_url','".str_replace("admin/", "", $this->url->link($this->paymentExtensionLink.'/globee/success', $this->config->get('config_secure')))."','0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_logging','1','0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','".$this->code."','".$this->code."_geo_zone_id','0','0');");
    }

    /**
     * Plugin uninstaller
     */
    public function uninstall()
    {
        $this->log('Uninstalling');
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting($this->code);
    }

    /**
     * Setting manager
     */
    public function index()
    {
        // Activate array that passes data to twig template
        $data = array();

        // Saving settings
        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->request->post['action'] === 'save') {
            $data = $this->validate();
            if (empty($data)) {
                $this->load->model('setting/setting');
                $this->model_setting_setting->editSetting($this->code, $this->request->post);
                $this->log('Settings Updated.');
                $this->session->data['success'] = $this->language->get('notification_success');
                $this->response->redirect($this->url->link($this->paymentExtensionLink.'/globee', $this->token.'='.$this->session->data[$this->token], true));
            }
        }

        $this->document->setTitle($this->language->get('globee'));

        // System template
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        // Links
        $data['url_action'] = $this->url->link($this->paymentExtensionLink.'/globee', $this->token.'='.$this->session->data[$this->token], 'SSL');
        $data['url_reset'] = $this->url->link($this->paymentExtensionLink.'/globee/reset', $this->token.'='.$this->session->data[$this->token], 'SSL');
        $data['url_clear'] = $this->url->link($this->paymentExtensionLink.'/globee/clear', $this->token.'='.$this->session->data[$this->token], 'SSL');
        $data['cancel'] = $this->url->link($this->paymentExtensionLink, $this->token.'='.$this->session->data[$this->token].'&type=payment', 'SSL');

        // Buttons
        $data['button_save'] = $this->language->get('button_save');
        $data['button_cancel'] = $this->language->get('button_cancel');
        $data['button_clear'] = $this->language->get('button_clear');

        // Breadcrumbs
        $data['breadcrumbs'] = array(
            array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', $this->token.'='.$this->session->data[$this->token], true)
            ),
            array(
                'text' => $this->language->get('text_payment'),
                'href' => $this->url->link($this->paymentBreadcrumbLink, $this->token.'='.$this->session->data[$this->token] . '&type=payment', true)
            ),
            array(
                'text' => $this->language->get('globee'),
                'href' => $this->url->link($this->paymentExtensionLink.'/globee', $this->token.'='.$this->session->data[$this->token], true)
            ),
        );

        // Tabs
        $data['tab_settings'] = $this->language->get('tab_settings');
        $data['tab_log'] = $this->language->get('tab_log');

        // Headings
        $data['heading_title'] = $this->language->get('globee');

        // Labels
        $data['label_edit'] = $this->language->get('label_edit');
        $data['label_enabled'] = $this->language->get('label_enabled');
        $data['label_sort_order'] = $this->language->get('label_sort_order');
        $data['label_network'] = $this->language->get('label_network');
        $data['label_payment_api_key'] = $this->language->get('label_payment_api_key');
        $data['label_risk_speed'] = $this->language->get('label_risk_speed');
        $data['label_paid_status'] = $this->language->get('label_paid_status');
        $data['label_confirmed_status'] = $this->language->get('label_confirmed_status');
        $data['label_completed_status'] = $this->language->get('label_completed_status');
        $data['label_notification_url'] = $this->language->get('label_notification_url');
        $data['label_redirect_url'] = $this->language->get('label_redirect_url');
        $data['label_debugging'] = $this->language->get('label_debugging');

        // Text
        $data['text_payment'] = $this->language->get('text_payment');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['text_low'] = $this->language->get('text_low');
        $data['text_medium'] = $this->language->get('text_medium');
        $data['text_high'] = $this->language->get('text_high');
        $data['text_livenet'] = $this->language->get('text_livenet');
        $data['text_testnet'] = $this->language->get('text_testnet');

        // Validation
        $data['success'] = '';
        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        }

        // Load saved values
        $data['value_enabled'] = $this->config->get($this->code.'_status');
        $data['value_sort_order'] = $this->config->get($this->code.'_sort_order');
        $data['value_livenet'] = $this->config->get($this->code.'_livenet');
        $data['value_payment_api_key'] = $this->config->get($this->code.'_payment_api_key');
        $data['value_risk_speed'] = $this->config->get($this->code.'_risk_speed');
        $data['value_paid_status'] = $this->config->get($this->code.'_paid_status');
        $data['value_confirmed_status'] = $this->config->get($this->code.'_confirmed_status');
        $data['value_completed_status'] = $this->config->get($this->code.'_completed_status');
        $data['value_notification_url'] = $this->config->get($this->code.'_notification_url');
        $data['value_redirect_url'] = $this->config->get($this->code.'_redirect_url');
        $data['value_debugging'] = $this->config->get($this->code.'_logging');

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        // Log data
        $data['log'] = '';
        $file = DIR_LOGS.'globee.log';
        if (file_exists($file)) {
            foreach (file($file, FILE_USE_INCLUDE_PATH, null) as $line) {
                $data['log'] .= $line."<br/>\n";
            }
        }
        if (empty($data['log'])) {
            $data['log'] = '<i>No log data available. Is Debugging switched on?</i>';
        }

        // Send output to browser
        $this->response->setOutput($this->load->view($this->viewPath, $data));
    }

    /**
     * Clear the GloBee Log
     */
    public function clear()
    {
        fclose(fopen(DIR_LOGS.'globee.log', 'w'));
        $this->session->data['success'] = $this->language->get('notification_log_success');
        $this->response->redirect($this->url->link($this->paymentExtensionLink.'/globee', $this->token.'='.$this->session->data[$this->token], 'SSL'));
    }

    /**
     * Validates the input data before submitting
     *
     * @return bool
     */
    private function validate()
    {
        $data = array();
        // Ensure the user has the permission to modify the plugin
        if (!$this->user->hasPermission('modify', $this->paymentExtensionLink.'/globee')) {
            $data['error_warning'] = $this->language->get('warning_permission');
        }

        // Ensure the notification URL is set and a valid URL
        if (!empty($this->request->post[$this->code.'_notification_url']) && false === filter_var($this->request->post[$this->code.'_notification_url'], FILTER_VALIDATE_URL)) {
            $data['error_notification_url'] = $this->language->get('notification_error_notification_url');
        }

        // Ensure the redirect URL is set and a valid URL
        if (!empty($this->request->post[$this->code.'_redirect_url']) && false === filter_var($this->request->post[$this->code.'_redirect_url'], FILTER_VALIDATE_URL)) {
            $data['error_redirect_url'] = $this->language->get('notification_error_redirect_url');
        }

        // Ensure the plugin cannot be activated without a payment api key
        if ($this->request->post[$this->code.'_status'] == 1 && empty($this->request->post[$this->code.'_payment_api_key'])) {
            $data['error_enabled'] = $this->language->get('notification_error_payment_api_key_enabled');
            $data['error_payment_api_key'] = $this->language->get('notification_error_payment_api_key');
        }

        // Ensure the plugin cannot be activated without a callback URL
        if ($this->request->post[$this->code.'_status'] == 1 && empty($this->request->post[$this->code.'_notification_url'])) {
            $data['error_enabled'] = $this->language->get('notification_error_notification_url_enabled');
            $data['error_notification_url'] = $this->language->get('notification_error_notification_url');
        }

        // Ensure the plugin cannot be activated without a redirect URL
        if ($this->request->post[$this->code.'_status'] == 1 && empty($this->request->post[$this->code.'_redirect_url'])) {
            $data['error_enabled'] = $this->language->get('notification_error_redirect_url_enabled');
            $data['error_redirect_url'] = $this->language->get('notification_error_redirect_url');
        }

        // Check that if the Payment API Key was changed, it can communicate with GloBee
        if (
            !empty($this->request->post[$this->code.'_payment_api_key']) && (
                $this->config->get($this->code.'_payment_api_key') != $this->request->post[$this->code.'_payment_api_key'] ||
                $this->config->get($this->code.'_livenet') != $this->request->post[$this->code.'_livenet']
            )
        ) {
            $connector = new GloBeeCurlConnector($this->request->post[$this->code.'_payment_api_key'], $this->request->post[$this->code.'_livenet']);
            $paymentApi = new PaymentApi($connector);

            try {
               $paymentApi->getAccount();
            } catch (AuthenticationException $exception) {
                $data['error_payment_api_key'] = $this->language->get('notification_error_communication_failed');
            }
        }

        return $data;
    }

    /**
     * Checks that the system meets the minimum requirements to use the GloBee plugin.
     *
     * @return string
     */
    private function missingRequirements()
    {
        $errors = [];
        $contactYourWebAdmin = " in order to function. Please contact your web server administrator for assistance.";

        # PHP
        if (true === version_compare(PHP_VERSION, '5.5.0', '<')) {
            $errors[] = 'Your PHP version is too old. The GloBee payment plugin requires PHP 5.4 or higher'
                .$contactYourWebAdmin;
        }

        # OpenSSL
        if (extension_loaded('openssl') === false) {
            $errors[] = 'The GloBee payment plugin requires the OpenSSL extension for PHP'.$contactYourWebAdmin;
        }

        # GMP or BCMath
        if (false === extension_loaded('gmp') && false === extension_loaded('bcmath')) {
            $errors[] = 'The GloBee payment plugin requires the GMP extension or BCMath extension for PHP'
                .$contactYourWebAdmin;
        }

        # Json
        if (extension_loaded('json') === false) {
            $errors[] = 'The GloBee payment plugin requires the JSON extension for PHP'.$contactYourWebAdmin;
        }

        # Curl required
        if (false === extension_loaded('curl')) {
            $errors[] = 'The GloBee payment plugin requires the Curl extension for PHP'.$contactYourWebAdmin;
        }

        if (!empty($errors)) {
            return implode("<br>\n", $errors);
        }
    }
}