<?php

if (true === version_compare(VERSION, '2.3.0', '<')) {
    require __DIR__.'/../../../system/library/globee/autoload.php';
} else {
    require __DIR__.'/../../../../system/library/globee/autoload.php';
}

use GloBee\PaymentApi\Connectors\GloBeeCurlConnector;
use GloBee\PaymentApi\Exceptions\Validation\ValidationException;
use GloBee\PaymentApi\Models\PaymentRequest;
use GloBee\PaymentApi\PaymentApi;

class ControllerPaymentGlobee extends ControllerExtensionPaymentGloBee {}

/**
 * Class ControllerExtensionPaymentGloBee
 */
class ControllerExtensionPaymentGloBee extends Controller
{
    /** @var string  */
    protected $code = 'payment_globee';

    /** @var string  */
    protected $confirmPath = 'extension/payment';

    /** @var string  */
    protected $viewPath = 'extension/payment/globee';

    /** @var string  */
    protected $languagePath = 'extension/payment/globee';

    /**
     * ControllerExtensionPaymentGloBee constructor.
     *
     * @param $registry
     */
    public function __construct($registry)
    {
        parent::__construct($registry);

        if (true === version_compare(VERSION, '3.0.0', '<')) {
            $this->code = 'globee';
        }
        if (true === version_compare(VERSION, '2.3.0', '<')) {
            $this->viewPath = 'payment/globee.tpl';
            $this->confirmPath = 'payment';
            $this->languagePath = 'payment/globee';
        }
        if (true === version_compare(VERSION, '2.2.0', '<')) {
            $this->viewPath = 'default/template/payment/globee.tpl';
        }

        $this->load->language($this->languagePath);
    }

    /**
     * @return mixed
     */
    public function index()
    {
        $data['testnet'] = ($this->config->get($this->code.'_livenet') == 0) ? true : false;
        $data['text_title'] = $this->language->get('text_title');
        $data['warning_testnet'] = $this->language->get('warning_testnet');
        $data['url_redirect'] = $this->url->link($this->confirmPath.'/globee/confirm', $this->config->get('config_secure'));
        $data['button_confirm'] = $this->language->get('button_confirm');
        if (isset($this->session->data['error_globee'])) {
            $data['error_globee'] = $this->session->data['error_globee'];
            unset($this->session->data['error_globee']);
        }

        if (file_exists(DIR_TEMPLATE.$this->config->get('config_template').'/template/extension/payment/globee')) {
            return $this->load->view($this->config->get('config_template').'/template/'.$this->viewPath, $data);
        }
        return $this->load->view($this->viewPath, $data);
    }

    /**
     * Confirmation handler that creates payment request and redirects user to GloBee
     */
    public function confirm()
    {
        $this->load->model('checkout/order');

        if (!isset($this->session->data['order_id'])) {
            $this->response->redirect($this->url->link('checkout/cart'));

            return;
        }

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        if (false === $order_info) {
            $this->response->redirect($this->url->link('checkout/cart'));

            return;
        }

        $connector = new GloBeeCurlConnector($this->config->get($this->code.'_payment_api_key'), $this->config->get($this->code.'_livenet'));
        $paymentApi = new PaymentApi($connector);

        $paymentRequest = new PaymentRequest();
        $paymentRequest->total = $order_info['total'];
        $paymentRequest->customerName = trim($order_info['firstname'].' '.$order_info['lastname']);
        $paymentRequest->customerEmail = $order_info['email'];
        $paymentRequest->confirmationSpeed = $this->config->get($this->code.'_risk_speed');
        $paymentRequest->successUrl = $this->config->get($this->code.'_redirect_url');
        $paymentRequest->ipnUrl = $this->config->get($this->code.'_notification_url');
        $paymentRequest->cancelUrl = $this->url->link('checkout/cart', $this->config->get('config_secure'));
        $paymentRequest->currency = $order_info['currency_code'];
        $paymentRequest->customPaymentId = $this->session->data['order_id'];

        try {
            $response = $paymentApi->createPaymentRequest($paymentRequest);
        } catch (ValidationException $exception) {
            $errors = '';
            foreach ($exception->getErrors() as $error) {
                $errors .= $error['message']."<br/>";
            }
            $this->log('Error Checking Out: '.$errors);
            $this->session->data['error_globee'] = 'Sorry, but there was a problem creating the Payment Request on GloBee:</br>'.$errors;
            $this->response->redirect($this->url->link('checkout/checkout'));

            return;
        }

        $this->session->data['globee_invoice_id'] = $response->id;

        $this->response->redirect($response->redirectUrl);
    }

    /**
     * Redirect handler after successful payment
     */
    public function success()
    {
        $this->load->model('checkout/order');

        $order_id = $this->session->data['order_id'];
        if (is_null($order_id)) {
            $this->response->redirect($this->url->link('checkout/success'));

            return;
        }

        $order = $this->model_checkout_order->getOrder($order_id);
        try {
            $connector = new GloBeeCurlConnector($this->config->get($this->code.'_payment_api_key'), $this->config->get($this->code.'_livenet'));
            $paymentApi = new PaymentApi($connector);
            $paymentRequest = $paymentApi->getPaymentRequest($this->session->data['globee_invoice_id']);
        } catch (Exception $e) {
            $this->response->redirect($this->url->link('checkout/success'));

            return;
        }

        $order_status_id = null;

        switch ($paymentRequest->status) {
            case 'paid':
                $order_status_id = $this->config->get($this->code.'_paid_status');
                break;
            case 'confirmed':
                $order_status_id = $this->config->get($this->code.'_confirmed_status');
                break;
            case 'complete':
                $order_status_id = $this->config->get($this->code.'_completed_status');
                break;
            default:
                $this->response->redirect($this->url->link('checkout/checkout'));

                return;
        }

        $this->model_checkout_order->addOrderHistory($order_id, $order_status_id);
        $this->session->data['globee_invoice_id'] = null;
        $this->response->redirect($this->url->link('checkout/success'));
    }

    /**
     * The IPN Handler
     */
    public function callback()
    {
        $this->load->model('checkout/order');

        $post = file_get_contents("php://input");
        if (true === empty($post)) {
            $this->log('GloBee plugin received empty POST data for an IPN message.');
            return;
        }

        $json = json_decode($post, true);
        if (false === array_key_exists('id', $json)) {
            $this->log('GloBee plugin received an invalid JSON payload sent to IPN handler: '.$post);
            return;
        }

        if (false === array_key_exists('custom_payment_id', $json)) {
            $this->log('GloBee plugin did not receive a Payment ID present in JSON payload: '.var_export($json, true));
            return;
        }

        if (false === array_key_exists('status', $json)) {
            error_log('GloBee plugin did not receive a status present in JSON payload: '.var_export($json, true));
            return;
        }

        $connector = new GloBeeCurlConnector($this->config->get($this->code.'_payment_api_key'), $this->config->get($this->code.'_livenet'));
        $paymentApi = new PaymentApi($connector);
        $paymentRequest = $paymentApi->getPaymentRequest($json['id']);
        $order_status_id = null;

        switch ($paymentRequest->status) {
            case 'paid':
                $this->log('Marking order as paid for Payment Request with GloBee ID: '.$json['id']);
                $order_status_id = $this->config->get($this->code.'_paid_status');
                break;
            case 'confirmed':
                $this->log('Marking order as confirmed for Payment Request with GloBee ID: '.$json['id']);
                $order_status_id = $this->config->get($this->code.'_confirmed_status');
                break;
            case 'complete':
                $this->log('Marking order as completed for Payment Request with GloBee ID: '.$json['id']);
                $order_status_id = $this->config->get($this->code.'_completed_status');
                break;
            default:
                return;
        }

        $this->model_checkout_order->addOrderHistory($paymentRequest->customPaymentId, $order_status_id);
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

}
