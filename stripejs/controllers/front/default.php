<?php
class StripeJsDefaultModuleFrontController extends ModuleFrontController
{
	public function __construct()
	{
		$this->auth = false;
		parent::__construct();
		$this->context = Context::getContext();
		include_once($this->module->getLocalPath().'stripejs.php');
	}

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent()
	{
		$this->display_column_left = false;
		$this->display_column_right = false;
		parent::initContent();
		if (Tools::getValue('process') == 'validation')
			$this->validation();
		else if (Tools::getValue('process') == 'webhook')
			$this->webhook();
	}
	public function validation() {
		$stripe = new StripeJs();
		if ($stripe->active && Tools::getIsset('stripeToken')) {
			$stripe->processPayment(Tools::getValue('stripeToken'));
		}
		else {
			$this->context->cookie->__set("stripe_error", 'There was a problem with your payment');
			$controller = Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc.php' : 'order.php';
			$location = $this->context->link->getPageLink($controller).(strpos($controller, '?') !== false ? '&' : '?').'step=3#stripe_error';
			header('Location: '.$location);
		}
	}

	/**
	 * Assign summary template
	 */
	public function webhook()
	{
		$stripe = new StripeJs();
		if ($stripe->active)
		{
			if (Tools::getIsset('token') && Configuration::get('STRIPE_WEBHOOK_TOKEN') == Tools::getValue('token'))
			{
				include($this->module->getLocalPath().'lib/Stripe.php');
				Stripe::setApiKey(Configuration::get('STRIPE_MODE') ? Configuration::get('STRIPE_PRIVATE_KEY_LIVE') : Configuration::get('STRIPE_PRIVATE_KEY_TEST'));

				$event_json = Tools::jsonDecode(@Tools::file_get_contents('php://input'));
				if (isset($event_json->id))
				{
					/* In case there is an issue with the event, Stripe throw an exception, just ignore it. */
					try
					{
						/* To double-check and for more security, we retrieve the original event directly from Stripe */
						$event = Stripe_Event::retrieve($event_json->id);
						/* We are only handling chargebacks, other events are ignored */
						if ($event->type == 'charge.dispute.created')
						{
							$id_order = (int)Db::getInstance()->getValue('SELECT id_order FROM '._DB_PREFIX_.'stripe_transaction WHERE id_stripe_transaction = \''.pSQL($event->id).'\' AND `charge_back` = 0');
							if ($id_order)
							{
								$order = new Order((int)$id_order);
								if (Validate::isLoadedObject($order))
								{
									if (Configuration::get('STRIPE_CHARGEBACKS_ORDER_STATUS') != -1)
										if ($order->getCurrentState() != Configuration::get('STRIPE_CHARGEBACKS_ORDER_STATUS'))
										{
											$order->changeIdOrderState((int)Configuration::get('STRIPE_CHARGEBACKS_ORDER_STATUS'), (int)$id_order);
											Db::getInstance()->getValue('UPDATE `'._DB_PREFIX_.'stipe_transaction` SET `charge_back` = 1 WHERE `id_stripe_transaction` = \''.pSQL($event->id).'\' AND `charge_back` = 0');
										}

									$message = new Message();
									$message->message = $stripe->l('A chargeback occured on this order and was reported by Stripe on').' '.date('Y-m-d H:i:s');
									$message->id_order = (int)$order->id;
									$message->id_employee = 1;
									$message->private = 1;
									$message->date_add = date('Y-m-d H:i:s');
									$message->add();
								}
							}
						}
					}
					catch (Exception $e)
					{
						header('HTTP/1.1 200 OK');
						exit;
					}
					header('HTTP/1.1 200 OK');
					exit;
				}
			}
		}
		header('HTTP/1.1 200 OK');
		exit;
	}
}
